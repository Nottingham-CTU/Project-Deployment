<?php

namespace Nottingham\ProjectDeployment;

// Do not allow exports where the user does not have the rights.
$projectID = $module->getProjectId();
if ( $projectID === null || ! $module->canAccessDeployment( $projectID ) )
{
	exit;
}

$returnOutput = isset( $returnOutput ) ? $returnOutput : false;

$GLOBALS['listEvents'] = \REDCap::getEventNames( true );

function parseXML( $xmlObj, $dataIsJson = false )
{
	global $listEvents;
	$array = [ 'name' => $xmlObj->getName() ];
	if ( $array[ 'name' ] == 'ExternalModule' )
	{
		$dataIsJson = true;
	}
	$attrs = [];
	foreach ( $xmlObj->attributes() as $attrName => $attrVal )
	{
		$attrs[ '_' . $attrName ] = (string)$attrVal;
	}
	foreach ( $xmlObj->attributes( 'https://projectredcap.org' ) as $attrName => $attrVal )
	{
		$attrs[ '-' . $attrName ] = (string)$attrVal;
	}
	if ( !empty( $attrs ) )
	{
		foreach ( $attrs as $attrName => $attrVal )
		{
			if ( $listEvents !== false &&
			     strpos( $attrName, 'event_id' ) && isset( $listEvents[ $attrVal ] ) )
			{
				$attrVal = $listEvents[ $attrVal ];
			}
			if ( $array['name'] != 'MultilanguageSettings' )
			{
				$attrVal = explode( "\n", $attrVal );
				$attrLength = count( $attrVal );
				array_walk( $attrVal, function(&$v, $i, $l){ if( $i < $l-1 ) $v .= "\n"; },
				            $attrLength );
				$attrVal = $attrLength == 1 ? $attrVal[0] : $attrVal;
			}
			$array[ $attrName ] = $attrVal;
		}
	}
	$children = [];
	foreach ( $xmlObj->children() as $child )
	{
		$children[] = parseXML( $child, $dataIsJson );
	}
	foreach ( $xmlObj->children( 'https://projectredcap.org' ) as $child )
	{
		$children[] = parseXML( $child, $dataIsJson );
	}
	if ( !empty( $children ) )
	{
		$array['items'] = $children;
	}
	$data = trim( $xmlObj );
	if ( !empty( $data ) )
	{
		if ( $dataIsJson )
		{
			$array['data'] = json_decode( $data, true );
		}
		else
		{
			$data = explode( "\n", $data );
			$dataLength = count( $data );
			array_walk( $data, function(&$v, $i, $l){ if( $i < $l-1 ) $v .= "\n"; }, $dataLength );
			$array['data'] = $dataLength == 1 ? $data[0] : $data;
		}
	}
	if ( $array['name'] == 'MultilanguageSettings' )
	{
		$array['_settings'] = unserialize( base64_decode( $array['_settings'] ) );
		unset( $array['_settings']['version'] );
		unset( $array['_settings']['projectId'] );
		unset( $array['_settings']['status'] );
		sortByInstrument( $array['_settings']['asiSources'], 'key' );
	}
	return $array;
}

function sortByInstrument( &$list, $fnGetFormName )
{
	$listForms = array_keys( \REDCap::getInstrumentNames() );
	$fnSort = ( $fnGetFormName === 'key' ? 'uksort' : 'uasort' );
	return $fnSort( $list, function ( $a, $b ) use ( $fnGetFormName, $listForms )
	{
		$aForm = array_search( $fnGetFormName === 'key' ? $a : $fnGetFormName( $a ), $listForms );
		$bForm = array_search( $fnGetFormName === 'key' ? $b : $fnGetFormName( $b ), $listForms );
		return ( $aForm == $bForm ? 0 : ( $aForm < $bForm ? -1 : 1 ) );
	} );
}

// Increase memory limit.
\System::increaseMemory(2048);

// Obtain ODM XML export data.
$xml = \ODM::getOdmOpeningTag($app_title);
$xml .= \ODM::getOdmMetadata($Proj, false, false, 'alertsenable,asienable', true);
$xml .= \ODM::getOdmClosingTag();

// Fix the XML data.
$xml = preg_replace( '/>[\r\n\t]+</', '><', $xml );
$xml = str_replace( ["\r\n", "\n"], '&#10;', $xml );

// Load into simplexml.
$xml = simplexml_load_string( $xml );
$xml->registerXPathNamespace( 'main', 'http://www.cdisc.org/ns/odm/v1.3' );

// Remove file timestamp.
foreach ( $xml->xpath('//main:MetaDataVersion') as $metaDataVersion )
{
	unset( $metaDataVersion['OID'], $metaDataVersion['Name'] );
}

// Remove data access groups.
foreach( $xml->xpath('//redcap:DataAccessGroupsGroup') as $dataAccessGroup )
{
	unset( $dataAccessGroup[0] );
}

// Check if Data Resolution Workflow is enabled.
$drwEnabledValue = $module->query('SELECT data_resolution_enabled FROM redcap_projects ' .
                                  'WHERE project_id = ?', [ $module->getProjectId() ] )
                                  ->fetch_assoc()['data_resolution_enabled'];
$drwEnabled = ( $drwEnabledValue == '2' );
foreach ( $xml->xpath('//main:GlobalVariables') as $globalVarsItem )
{
	$globalVarsItem->addChild( 'redcap:DataResolutionEnabled',
	                           $drwEnabledValue, 'https://projectredcap.org' );
}

// Check if MyCap is enabled.
$mycapEnabled = false;
foreach ( $xml->xpath('//main:GlobalVariables/redcap:MyCapEnabled') as $mycapEnabledItem )
{
	if ( (string)$mycapEnabledItem[0] == '1' )
	{
		$mycapEnabled = true;
	}
}

// Check if scheduling is enabled.
$schedulingEnabled = false;
foreach ( $xml->xpath('//main:GlobalVariables/redcap:SchedulingEnabled') as $schedulingEnabledItem )
{
	if ( (string)$schedulingEnabledItem[0] == '1' )
	{
		$schedulingEnabled = true;
	}
}

// Remove the offset and range values from events if scheduling is not enabled.
if ( ! $schedulingEnabled )
{
	foreach ( $xml->xpath('//main:StudyEventDef') as $studyEvent )
	{
		$attrObj = $studyEvent->attributes('https://projectredcap.org');
		unset( $attrObj['DayOffset'], $attrObj['OffsetMin'], $attrObj['OffsetMax'] );
	}
}

// Remove unique role name from user roles and split out entry/export rights.
foreach ( $xml->xpath('//redcap:UserRoles') as $userRole )
{
	$userRoleName = $userRole['role_name'];
	$listRoleForms = [];
	foreach ( explode( '][', substr( $userRole['data_entry'], 1, -1 ) ) as $infoEntry )
	{
		$infoEntry = explode( ',', $infoEntry );
		$listRoleForms[ $infoEntry[0] ] = [ 'data_entry' => $infoEntry[1] ];
	}
	foreach ( explode( '][', substr( $userRole['data_export_instruments'], 1, -1 ) )
	          as $infoExport )
	{
		$infoExport = explode( ',', $infoExport );
		$listRoleForms[ $infoExport[0] ][ 'data_export' ] = $infoExport[1];
	}
	sortByInstrument( $listRoleForms, 'key' );
	foreach ( $listRoleForms as $roleFormName => $infoRoleForm )
	{
		$xmlRoleForm = $userRole->addChild( 'redcap:UserRoleForm', null,
		                                    'https://projectredcap.org' );
		$xmlRoleForm->addAttribute( 'role_name', $userRoleName );
		$xmlRoleForm->addAttribute( 'form_name', $roleFormName );
		if ( isset( $infoRoleForm['data_entry'] ) )
		{
			$xmlRoleForm->addAttribute( 'data_entry', $infoRoleForm['data_entry'] );
		}
		if ( isset( $infoRoleForm['data_export'] ) )
		{
			$xmlRoleForm->addAttribute( 'data_export', $infoRoleForm['data_export'] );
		}
	}
	unset( $userRole['unique_role_name'], $userRole['data_entry'],
	       $userRole['data_export_instruments'] );
	if ( ! $mycapEnabled )
	{
		unset( $userRole['mycap_participants'] );
	}
	if ( ! $drwEnabled )
	{
		unset( $userRole['data_quality_resolution'] );
	}
}

// Remove report folders which correspond to namespaces (defined in REDCap UI Tweaker module).
$listNamespacedReports = [];
$hasReportFolders = false;
foreach ( $xml->xpath('//redcap:ReportsFolders') as $redcapReportFolder )
{
	if ( in_array( (string)$redcapReportFolder['name'],
	               $module->getReportNamespaces( $projectID ) ) )
	{
		$listFolderReportIDs =
				explode( ',', (string)$redcapReportFolder['redcap_reports_folders_items'] );
		$listNamespacedReports = array_merge( $listNamespacedReports, $listFolderReportIDs );
		unset( $redcapReportFolder[0] );
		continue;
	}
	$hasReportFolders = true;
	unset( $redcapReportFolder['position'] );
}
if ( ! $hasReportFolders )
{
	foreach ( $xml->xpath('//redcap:ReportsFoldersGroup') as $redcapReportFolderGroup )
	{
		unset( $redcapReportFolderGroup[0] );
	}
}

// Remove unique report name/ID/hash from reports.
$reportCount = 1;
$listReportIDs = [];
foreach ( $xml->xpath('//redcap:Reports') as $redcapReport )
{
	if ( in_array( (string)$redcapReport['ID'], $listNamespacedReports ) )
	{
		unset( $redcapReport[0] );
		continue;
	}
	unset( $redcapReport['unique_report_name'], $redcapReport['hash'],
	       $redcapReport['report_order'] );
	$listReportIDs[ (string)$redcapReport['ID'] ] = (string)$reportCount;
	$redcapReport['ID'] = (string)$reportCount;
	$reportCount++;
}
if ( $reportCount < 2 )
{
	foreach ( $xml->xpath('//redcap:ReportsGroup') as $redcapReportGroup )
	{
		unset( $redcapReportGroup[0] );
	}
}

// Replace unique report IDs in folders with sequential IDs.
foreach ( $xml->xpath('//redcap:ReportsFolders') as $redcapReportFolder )
{
	$listFolderReportIDs =
			explode( ',', (string)$redcapReportFolder['redcap_reports_folders_items'] );
	for ( $i = 0; $i < count( $listFolderReportIDs ); $i++ )
	{
		$listFolderReportIDs[ $i ] = $listReportIDs[ $listFolderReportIDs[ $i ] ];
	}
	$redcapReportFolder['redcap_reports_folders_items'] = implode( ',', $listFolderReportIDs );
}

// Identify file attachments and map ID to hash of data.
$fileAttachments = [];
foreach ( $xml->xpath('//redcap:OdmAttachment') as $fileAttachment )
{
	$fileAttachments[ (string)$fileAttachment['ID'] ] = sha1((string)$fileAttachment);
	unset( $fileAttachment[0] );
}
foreach ( $xml->xpath('//redcap:OdmAttachmentGroup') as $fileAttachmentGroup )
{
	if ( empty( $fileAttachmentGroup->children ) )
	{
		unset( $fileAttachmentGroup[0] );
	}
}

// Sort the econsent items by form and reassign the ID.
$listEconsent = [];
$listEconsentID = [];
foreach ( $xml->xpath('//redcap:Econsent') as $redcapEconsent )
{
	$itemEconsent = [];
	foreach ( $redcapEconsent->attributes() as $attrName => $attrVal )
	{
		$itemEconsent[ (string)$attrName ] = (string)$attrVal;
	}
	foreach ( $redcapEconsent->attributes( 'https://projectredcap.org' ) as $attrName => $attrVal )
	{
		$itemEconsent[ (string)$attrName ] = (string)$attrVal;
	}
	$listEconsent[] = $itemEconsent;
}
sortByInstrument( $listEconsent, function( $item ) { return $item['survey_id']; } );
$listEconsent = array_values( $listEconsent );
$i = 0;
foreach ( $xml->xpath('//redcap:Econsent') as $redcapEconsent )
{
	foreach ( $listEconsent[$i] as $attrName => $attrVal )
	{
		$redcapEconsent[ $attrName ] = $attrVal;
	}
	$listEconsentID[ (string)$redcapEconsent['ID'] ] = ++$i;
	$redcapEconsent['ID'] = $i;
}
$listPdfSnapshots = [];
$i = 0;
foreach ( $xml->xpath('//redcap:PdfSnapshots') as $redcapPdfSnapshot )
{
	$itemPdfSnapshot = [];
	foreach ( $redcapPdfSnapshot->attributes() as $attrName => $attrVal )
	{
		$itemPdfSnapshot[ (string)$attrName ] = (string)$attrVal;
	}
	foreach ( $redcapPdfSnapshot->attributes( 'https://projectredcap.org' ) as $attrName => $attrVal )
	{
		$itemPdfSnapshot[ (string)$attrName ] = (string)$attrVal;
	}
	if ( $itemPdfSnapshot['consent_id'] != '' )
	{
		$itemPdfSnapshot['consent_id'] = $listEconsentID[ $itemPdfSnapshot['consent_id'] ];
	}
	$pdfSnapshotID = ( $itemPdfSnapshot['consent_id'] == '' ? '0' : $itemPdfSnapshot['consent_id'] ) .
	                 ':' . $itemPdfSnapshot['trigger_surveycomplete_survey_id'] . ':' . $i++;
	$listPdfSnapshots[ $pdfSnapshotID ] = $itemPdfSnapshot;
}
ksort( $listPdfSnapshots );
$i = 0;
foreach ( $xml->xpath('//redcap:PdfSnapshots') as $redcapPdfSnapshot )
{
	foreach ( $listPdfSnapshots[ array_keys( $listPdfSnapshots )[$i] ] as $attrName => $attrVal )
	{
		$redcapPdfSnapshot[ $attrName ] = $attrVal;
	}
	$i++;
}

// Convert survey logos and email attachment to use file hash, and remove the e-consent attributes
// if the new e-consent section exists.
foreach ( $xml->xpath('//redcap:Surveys') as $redcapSurvey )
{
	if ( (string)$redcapSurvey['logo'] != '' )
	{
		$redcapSurvey['logo'] = $fileAttachments[ (string)$redcapSurvey['logo'] ];
	}
	if ( (string)$redcapSurvey['confirmation_email_attachment'] != '' )
	{
		$redcapSurvey['confirmation_email_attachment'] =
			$fileAttachments[ (string)$redcapSurvey['confirmation_email_attachment'] ];
	}
	if ( ! empty( $listEconsent ) )
	{
		unset( $redcapSurvey['pdf_auto_archive'], $redcapSurvey['pdf_save_to_field'],
		       $redcapSurvey['pdf_save_to_event_id'], $redcapSurvey['pdf_save_translated'],
		       $redcapSurvey['pdf_econsent_version'], $redcapSurvey['pdf_econsent_type'],
		       $redcapSurvey['pdf_econsent_firstname_field'],
		       $redcapSurvey['pdf_econsent_firstname_event_id'],
		       $redcapSurvey['pdf_econsent_lastname_field'],
		       $redcapSurvey['pdf_econsent_lastname_event_id'],
		       $redcapSurvey['pdf_econsent_dob_field'], $redcapSurvey['pdf_econsent_dob_event_id'],
		       $redcapSurvey['pdf_econsent_allow_edit'],
		       $redcapSurvey['pdf_econsent_signature_field1'],
		       $redcapSurvey['pdf_econsent_signature_field2'],
		       $redcapSurvey['pdf_econsent_signature_field3'],
		       $redcapSurvey['pdf_econsent_signature_field4'],
		       $redcapSurvey['pdf_econsent_signature_field5'] );
	}
}

// Convert MyCap about page logos to use file hash.
foreach ( $xml->xpath('//redcap:MycapAboutpages') as $redcapMycapAbout )
{
	if ( (string)$redcapMycapAbout['custom_logo'] != '' )
	{
		$redcapMycapAbout['custom_logo'] =
			$fileAttachments[ (string)$redcapMycapAbout['custom_logo'] ];
	}
	unset( $redcapMycapAbout['identifier'] );
}

// Remove MyCap baseline date field attribute if it is empty.
foreach ( $xml->xpath('//redcap:MycapProjects') as $redcapMycapProjects )
{
	if ( (string)$redcapMycapProjects['baseline_date_field'] == '' )
	{
		unset( $redcapMycapProjects['baseline_date_field'] );
	}
}

// Remove sent timestamps from Alerts and convert email attachments to use file hash.
foreach ( $xml->xpath('//redcap:Alerts') as $redcapAlert )
{
	unset( $redcapAlert['email_sent'], $redcapAlert['email_timestamp_sent'] );
	for ( $i = 1; $i <= 5; $i++ )
	{
		if ( (string)$redcapAlert["email_attachment$i"] != '' )
		{
			$redcapAlert["email_attachment$i"] =
				$fileAttachments[ (string)$redcapAlert["email_attachment$i"] ];
		}
	}
	if ( (string)$redcapAlert['sendgrid_template_data'] == '' )
	{
		$redcapAlert['sendgrid_template_data'] = '{}';
	}
	if ( (string)$redcapAlert['sendgrid_mail_send_configuration'] == '' )
	{
		$redcapAlert['sendgrid_mail_send_configuration'] = '{}';
	}
}

// Convert field attachment data to hash.
foreach ( $xml->xpath('//main:ItemDef/redcap:Attachment') as $dataDictAttachment )
{
	$dataDictAttachment[0] = sha1( (string)$dataDictAttachment[0] );
}

// Convert URLs for files to file hashes.
foreach ( [ 'redcap:FormattedTranslatedText', 'main:ItemGroupDef', 'main:ItemDef' ]
          as $contentElemName )
{
	$attr = ( $contentElemName == 'redcap:FormattedTranslatedText' ? 0 :
	          ( $contentElemName == 'main:ItemGroupDef' ? 'Name' : 'SectionHeader' ) );
	foreach ( $xml->xpath( '//' . $contentElemName ) as $contentElem )
	{
		$attrObj = $contentElemName == 'main:ItemDef'
		           ? $contentElem->attributes('https://projectredcap.org')
		           : $contentElem;
		$text = (string)($attrObj[ $attr ]);
		$newText = preg_replace_callback( '/((href|src)=")(http[^"]+)"/',
		                                  function ( $m ) use ( $module )
		                                  { return $m[1] .
		                                           $module->fileUrlToFileHash( $m[3] ) . '"'; },
		                                  $text );
		if ( $text != $newText )
		{
			$attrObj[ $attr ] = $newText;
		}
	}
}

// Remove MyCap identifiers.
foreach ( $xml->xpath('//redcap:MycapProjects') as $redcapMycap )
{
	unset( $redcapMycap['code'], $redcapMycap['hmac_key'],
	       $redcapMycap['flutter_conversion_time'] );
}

// Remove MyCap participants.
foreach( $xml->xpath('//redcap:MycapParticipantsGroup') as $mycapParticipants )
{
	unset( $mycapParticipants[0] );
}

// Trim whitespace on CheckboxChoices.
foreach ( $xml->xpath('//main:CodeList') as $codelistElem )
{
	$attrObj = $codelistElem->attributes('https://projectredcap.org');
	$text = (string)($attrObj['CheckboxChoices']);
	if ( $text != '' )
	{
		$newText = trim( preg_replace( '/[ ]+\\|[ ]+/', '|', $text ) );
		if ( $text != $newText )
		{
			$attrObj['CheckboxChoices'] = $newText;
		}
	}
}

// Remove ODM Length attributes, and any CustomAlignment attributes with value 'RV'.
foreach ( $xml->xpath('//main:ItemDef') as $odmItemDef )
{
	unset( $odmItemDef['Length'] );
}
foreach ( $xml->xpath('//main:ItemDef[@redcap:CustomAlignment="RV"]') as $odmItemDef )
{
	$attrObj = $odmItemDef->attributes('https://projectredcap.org');
	unset( $attrObj['CustomAlignment'] );
}

// Insert field SQL for SQL fields.
foreach ( $xml->xpath('//main:ItemDef[@redcap:FieldType="sql"]') as $odmItemDef )
{
	$fieldName = (string)$odmItemDef['Name'];
	$fieldSQL = \REDCap::getDataDictionary( 'array', false, $fieldName, null, false )
	            [ $fieldName ]['select_choices_or_calculations'];
	$fieldSQL = str_replace( "\r\n", "\n", $fieldSQL );
	$odmItemDef->addAttribute( 'redcap:SQL', $fieldSQL, 'https://projectredcap.org' );
}

// Add external module settings.
$modulesRoot = $xml->xpath('/main:ODM/main:Study')[0]->addChild( 'redcap:ExternalModules', null,
                                                                 'https://projectredcap.org');
$listModules = $module->getModulesForExport( $projectID );
foreach ( $listModules as $moduleName )
{
	$listModuleSettings = $module->getSettingsForModule( $moduleName, $projectID );
	if ( ! empty( $listModuleSettings ) )
	{
		$moduleNode = $modulesRoot->addChild( 'redcap:ExternalModule', null,
		                                      'https://projectredcap.org' );
		$moduleNode->addAttribute( 'name', $moduleName );
		foreach ( $listModuleSettings as $moduleSettingName => $moduleSettingValue )
		{
			$moduleNode->addChild( 'redcap:Setting-' . $moduleSettingName,
			                      json_encode( $moduleSettingValue ), 'https://projectredcap.org' );
		}
	}
}


if ( ! $returnOutput )
{
	header( 'Content-Type: application/json' );
	$projTitleShort = substr( str_replace( ' ', '', ucwords( preg_replace( '/[^a-zA-Z0-9 ]/', '',
	                                                html_entity_decode( $GLOBALS['app_title'],
	                                                                    ENT_QUOTES ) ) ) ), 0, 20 );
	header( 'Content-Disposition: attachment; filename="' .
	        $projTitleShort . date('_Ymd') . '.json"' );
}
$outputData = parseXML( $xml );
$outputData = $outputData['items'][0]['items'];
if ( ! $returnOutput )
{
	if ( isset( $_GET['returnfunction'] ) )
	{
		header( 'Content-Type: text/javascript' );
		echo 'clientPDResponse(',
		     json_encode( base64_encode( json_encode( $outputData, JSON_UNESCAPED_SLASHES ) ) ),
		     ')';
	}
	else
	{
		echo preg_replace( '/^([ ]+)\\g1/m', '$1',
		                   json_encode( $outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
