<?php

namespace Nottingham\ProjectDeployment;

// Do not allow exports where the user does not have the rights.
$projectID = $module->getProjectId();
if ( $projectID === null || ! $module->canAccessDeployment( $projectID ) )
{
	exit;
}

$returnOutput = isset( $returnOutput ) ? $returnOutput : false;

$listEvents = \REDCap::getEventNames( true );

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
		$attrs[ $attrName ] = (string)$attrVal;
	}
	foreach ( $xmlObj->attributes( 'https://projectredcap.org' ) as $attrName => $attrVal )
	{
		$attrs[ $attrName ] = (string)$attrVal;
	}
	if ( !empty( $attrs ) )
	{
		foreach ( $attrs as $attrName => $attrVal )
		{
			if ( $listEvents !== false &&
			     strpos( $attrName, 'event_id' ) && isset( $listEvents[ $attrVal ] ) )
			{
				$attrs[ $attrName ] = $listEvents[ $attrVal ];
			}
		}
		$array[ 'attrs' ] = $attrs;
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
		$array[ 'items' ] = $children;
	}
	$data = trim( $xmlObj );
	if ( !empty( $data ) )
	{
		$array[ 'data' ] = $dataIsJson ? json_decode( $data, true ) : $data;
	}
	if ( $array[ 'name' ] == 'MultilanguageSettings' )
	{
		$array[ 'attrs' ][ 'settings' ] =
				unserialize( base64_decode( $array[ 'attrs' ][ 'settings' ] ) );
		unset( $array[ 'attrs' ][ 'settings' ][ 'version' ] );
		unset( $array[ 'attrs' ][ 'settings' ][ 'projectId' ] );
		unset( $array[ 'attrs' ][ 'settings' ][ 'status' ] );
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
$xml .= \ODM::getOdmMetadata($Proj, false, false, '', true);
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
}

// Remove unique report name/ID/hash from reports.
foreach ( $xml->xpath('//redcap:Reports') as $redcapReport )
{
	unset( $redcapReport['unique_report_name'], $redcapReport['hash'], $redcapReport['ID'] );
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

// Convert survey logos and email attachment to use file hash.
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

// Remove ODM Length attributes.
foreach ( $xml->xpath('//main:ItemDef') as $odmItemDef )
{
	unset( $odmItemDef['Length'] );
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
		echo json_encode( $outputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}
