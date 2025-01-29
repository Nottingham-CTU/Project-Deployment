<?php

namespace Nottingham\ProjectDeployment;

// Do not allow exports where the user does not have the rights.
$projectID = $module->getProjectId();
if ( $projectID === null || ! $module->canAccessDeployment( $projectID ) )
{
	exit;
}

function getThisData()
{
	global $module, $app_title, $Proj;
	$returnOutput = true;
	require 'projectexport.php';
	return $outputData;
}

// Check if there is a project defined to deploy from.
$sourceServer = $module->getProjectSetting( 'source-server' );
$sourceProject = $module->getProjectSetting( 'source-project' );
$performUpdates = false;
$hasSource = false;
$needsLogin = false;
$tryClientSide = false;
if ( $sourceServer != '' && $sourceProject != '' )
{
	$performUpdates = isset( $_POST['update'] ) && ! empty( $_POST['update'] );
	$hasSource = true;
	// Attempt to get the project export from the source server.
	// If the login page is returned, prompt for username and password for source server.
	// If the export is returned from the source server, get the export from this server and
	// perform a comparison.
	$cookieFile = $module->createTempFile();
	if ( isset( $_SESSION['modprojdeploy_session'] ) )
	{
		file_put_contents( $cookieFile, $_SESSION['modprojdeploy_session'] );
	}
	$curl = curl_init();
	curl_setopt( $curl, CURLOPT_URL, $sourceServer . '/api/?type=module&prefix=project_deployment' .
	                    '&page=' . ( $performUpdates ? 'getfeatureexports' : 'projectexport' ) .
	                    '&pid=' . $sourceProject );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $curl, CURLOPT_COOKIEFILE, $cookieFile );
	curl_setopt( $curl, CURLOPT_COOKIEJAR, $cookieFile );
	if ( ini_get( 'curl.cainfo' ) == '' )
	{
		curl_setopt( $curl, CURLOPT_CAINFO, APP_PATH_DOCROOT . '/Resources/misc/cacert.pem' );
	}
	curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
	$sourceHeaders = [];
	curl_setopt( $curl, CURLOPT_HEADERFUNCTION,
	             function ( $curl, $header ) use ( &$sourceHeaders )
	             {
	                 $headerParts = explode( ':', $header, 2 );
	                 if ( count( $headerParts ) == 2 )
	                 {
	                     $sourceHeaders[ trim( strtolower( $headerParts[0] ) ) ] =
	                             trim( $headerParts[1] );
	                 }
	                 return strlen( $header );
	             });
	$sourceData = curl_exec( $curl );
	if ( substr( $sourceHeaders['content-type'], 0, 9 ) == 'text/html' )
	{
		if ( strpos( $sourceData, 'REDCap' ) === false )
		{
			$hasSource = false;
		}
		elseif ( isset( $_POST['action'] ) && $_POST['action'] == 'login' )
		{
			preg_match_all( '/<input [^>]*?(?>[^>]*?(?>(?>name=[\'"]([^\'"]*)[\'"])|' .
			                '(?>value=[\'"]([^\'"]*)[\'"]))){2}[^>]*?>/',
			                $sourceData, $inputFields, PREG_SET_ORDER );
			$loginData = [];
			foreach ( $inputFields as $inputField )
			{
				$loginData[ $inputField[1] ] = $inputField[2];
			}
			$loginData['username'] = $_POST['username'];
			$loginData['password'] = $_POST['password'];
			curl_setopt( $curl, CURLOPT_POSTFIELDS, http_build_query( $loginData ) );
			$sourceData = curl_exec( $curl );
		}
		else
		{
			$needsLogin = true;
		}
	}
	if ( $sourceHeaders['content-type'] == 'application/json' )
	{
		$sourceData = json_decode( $sourceData, true );
	}
	elseif ( ! $needsLogin )
	{
		$hasSource = false;
		$tryClientSide = $module->getSystemSetting('allow-client-connection');
	}
	curl_setopt( $curl, CURLOPT_COOKIELIST, 'FLUSH' );
	curl_close( $curl );
	$_SESSION['modprojdeploy_session'] = file_get_contents( $cookieFile );
}


// Handle request to update the project.
if ( $performUpdates )
{
	if ( $tryClientSide && isset( $_POST['sourcedatafe'] ) )
	{
		$sourceData = json_decode( base64_decode( $_POST['sourcedatafe'] ), true );
		if ( $sourceData !== null )
		{
			$hasSource = true;
		}
	}
	if ( $hasSource && ! $needsLogin )
	{
		// Apply data dictionary changes.
		if ( isset( $_POST['update']['dictionary'] ) &&
		     ! empty( $sourceData['dictionary'] ) && ! empty( $sourceData['forms'] ) )
		{
			// Get server/project specific URLs from the current data dictionary and map to hashes.
			$currentDictionary =
				$module->getPage( '/Design/data_dictionary_download.php?delimiter=,' );
			$listDictionaryURLs = [];
			preg_replace_callback( '/((href|src)="")(http[^"]+)"/',
			                       function ( $m ) use ( $module, $listDictionaryURLs )
			                       {
			                           $h = $module->fileUrlToFileHash( $m[3] );
			                           if ( $h != $m[3] )
			                           {
			                               $listDictionaryURLs[ $h ] = $m[3];
			                           }
			                           return $m[0];
			                       },
			                       $currentDictionary['data'] );
			unset( $currentDictionary );
			// Replace hashes in the source data dictionary with URLs.
			$sourceData['dictionary'] =
				preg_replace_callback( '/((href|src)="")(data:[^"]+)"/',
				                       function ( $m ) use ( $listDictionaryURLs )
				                       {
				                           if ( isset( $listDictionaryURLs[ $m[3] ] ) )
				                           {
				                               return $m[1] . $listDictionaryURLs( $m[3] ) . '"';
				                           }
				                           else
				                           {
				                               return $m[0];
				                           }
				                       },
				                       $sourceData['dictionary'] );
			// Write the source data dictionary to the temp folder.
			$dictionaryFileName = date('YmdHis') . $projectID . 'projdepmoduledatadictionary.csv';
			file_put_contents( APP_PATH_TEMP . $dictionaryFileName, $sourceData['dictionary'] );
			// Check project status, enable draft mode if required.
			$isDraftMode = false;
			$submitDraftMode = false;
			if ( $module->getProjectStatus() == 'PROD' )
			{
				$isDraftMode = true;
				if ( $module->query( 'SELECT 1 FROM redcap_projects WHERE project_id = ? AND ' .
				                     'draft_mode = 0', [ $projectID ] )->fetch_assoc() )
				{
					$module->getPage( '/Design/draft_mode_enter.php' );
					$submitDraftMode = true;
				}
			}
			// Submit the updated data dictionary.
			$module->postPage( 'Design/data_dictionary_upload.php',
			                   [ 'commit' => '1', 'fname' => $dictionaryFileName,
			                     'delimiter' => ',' ] );
			unset( $dictionaryFileName );
			// If in draft mode, amend the instrument display names as required.
			// Don't do this if not in prod/draft, because in dev status updating the form label
			// will also update the form name.
			if ( $isDraftMode )
			{
				foreach ( $sourceData['forms'] as $formName => $formLabel )
				{
					\REDCap::setFormName( $projectID, $formName, $formLabel );
				}
			}
			// Submit draft mode changes if required.
			if ( $submitDraftMode )
			{
				$module->getPage( '/Design/draft_mode_review.php' );
				if ( SUPER_USER &&
				     $module->query( 'SELECT 1 FROM redcap_projects WHERE project_id = ? AND ' .
				                     'draft_mode = 0', [ $projectID ] )->fetch_assoc() )
				{
					$module->getPage( '/Design/draft_mode_approve.php' );
				}
			}
			unset( $isDraftMode, $submitDraftMode );
		}
		// Apply event/arm changes.
		if ( isset( $_POST['update']['events'] ) && ! empty( $sourceData['arms'] ) &&
		     ! empty( $sourceData['events'] ) && ! empty( $sourceData['eventforms'] ) )
		{
			// Submit the arms.
			$module->postPage( '/Design/arm_upload.php',
			                   [ 'csv_content' => $sourceData['arms'] ], true );
			// Submit the events.
			$module->postPage( '/Design/event_upload.php',
			                   [ 'csv_content' => $sourceData['events'] ], true );
			// Submit the event/instrument mapping.
			$module->postPage( '/Design/instrument_event_mapping_upload.php',
			                   [ 'csv_content' => $sourceData['eventforms'] ], true );
		}
		// Apply form display logic changes.
		if ( isset( $_POST['update']['fdl'] ) && ! empty( $sourceData['fdl'] ) )
		{
			// Submit the form display logic.
			$module->postPage( '/Design/online_designer.php',
			                   [ 'FormDisplayLogicSetup-import' => '',
			                     'files' => new \CURLStringFile( $sourceData['fdl'],
			                                                     'fdl.csv' ) ], true );
		}
		// Apply data quality rules changes.
		if ( isset( $_POST['update']['dataquality'] ) && ! empty( $sourceData['dataquality'] ) )
		{
			// Get existing data quality rule names/logic.
			$listDQNames = [];
			$listDQLogic = [];
			$queryDQ = $module->query( 'SELECT rule_name, rule_logic ' .
			                           'FROM redcap_data_quality_rules ' .
			                           'WHERE project_id = ?', [ $projectID ] );
			while ( $infoDQ = $queryDQ->fetch_assoc() )
			{
				$listDQNames[] = $infoDQ['rule_name'];
				$listDQLogic[] = $infoDQ['rule_logic'];
			}
			// Convert source data quality rules to array.
			$sourceData['dataquality'] = $module->csvToArray( $sourceData['dataquality'] );
			// Remove source data quality rules which already exist in the project.
			foreach ( $sourceData['dataquality'] as $i => $infoDQ )
			{
				if ( in_array( $infoDQ['rule_name'], $listDQNames ) ||
				     in_array( $infoDQ['rule_logic'], $listDQLogic ) )
				{
					unset( $sourceData['dataquality'][$i] );
				}
			}
			// Convert source data quality rules back to CSV.
			$sourceData['dataquality'] = $module->arrayToCsv( $sourceData['dataquality'] );
			// Submit the data quality rules.
			$module->postPage( '/DataQuality/upload_dq_rules.php',
			                   [ 'csv_content' => $sourceData['dataquality'] ], true );
			unset( $listDQNames, $listDQLogic, $queryDQ, $infoDQ );
		}
		// Apply alerts changes.
		if ( isset( $_POST['update']['alerts'] ) && ! empty( $sourceData['alerts'] ) )
		{
			// Get existing alerts.
			$currentAlerts = $module->getPage( '/index.php?route=AlertsController:downloadAlerts' );
			if ( substr( $currentAlerts['headers']['content-type'], 0, 15 ) == 'application/csv' )
			{
				$currentAlerts = $module->csvToArray( $currentAlerts['data'] );
				// Determine the submission URL for alerts. Uses the built-in REDCap URL by default,
				// but if the REDCap UI Tweaker module is enabled and custom alert senders turned on
				// then the module's alerts submission URL is used instead.
				$alertsSubmitURL = '/index.php?route=AlertsController:uploadAlerts';
				if ( $module->isModuleEnabled('redcap_ui_tweaker') )
				{
					$UITweaker =
						\ExternalModules\ExternalModules::getModuleInstance('redcap_ui_tweaker');
					if ( $UITweaker->getSystemSetting( 'custom-alert-sender' ) )
					{
						$alertsSubmitURL = '/ExternalModules/?prefix=redcap_ui_tweaker' .
						                   '&page=alerts_submit&mode=upload';
					}
				}
				// Convert source alerts to array.
				$sourceData['alerts'] = $module->csvToArray( $sourceData['alerts'] );
				// Amend source alerts with this project's unique alert IDs.
				foreach ( $sourceData['alerts'] as $i => $infoAlert )
				{
					$sourceData['alerts'][$i]['alert-unique-id'] = '';
					foreach ( $currentAlerts as $j => $infoCurrentAlert )
					{
						if ( $infoAlert['alert-title'] == $infoCurrentAlert['alert-title'] &&
						     $infoAlert['alert-type'] == $infoCurrentAlert['alert-type'] )
						{
							$sourceData['alerts'][$i]['alert-unique-id'] =
									$infoCurrentAlert['alert-unique-id'];
							unset( $currentAlerts[$j] );
							break;
						}
					}
				}
				foreach ( $sourceData['alerts'] as $i => $infoAlert )
				{
					if ( $infoAlert['alert-unique-id'] != '' )
					{
						continue;
					}
					foreach ( $currentAlerts as $j => $infoCurrentAlert )
					{
						$alertMatchingKeys = 0;
						$alertTotalKeys = 0;
						foreach ( $infoCurrentAlert as $k => $v )
						{
							if ( $k == 'alert-unique-id' || ! isset( $infoAlert[ $k ] ) )
							{
								continue;
							}
							$alertTotalKeys++;
							if ( $v == $infoAlert[ $k ] )
							{
								$alertMatchingKeys++;
							}
						}
						if ( $alertTotalKeys - $alertMatchingKeys < 5 )
						{
							$sourceData['alerts'][$i]['alert-unique-id'] =
									$infoCurrentAlert['alert-unique-id'];
							unset( $currentAlerts[$j] );
							break;
						}
					}
				}
				// Convert source alerts back to CSV.
				$sourceData['alerts'] = $module->arrayToCsv( $sourceData['alerts'] );
				// Submit the alerts.
				$module->postPage( $alertsSubmitURL,
				                   [ 'csv_content' => $sourceData['alerts'] ], true );
			}
			unset( $currentAlerts, $alertsSubmitURL, $infoAlert, $infoCurrentAlert,
			       $alertMatchingKeys, $alertTotalKeys );
		}
		// Apply user roles changes.
		if ( isset( $_POST['update']['roles'] ) && ! empty( $sourceData['roles'] ) )
		{
			// Get the unique role names for the roles in this project.
			$listRoleNames = [];
			$queryRoleNames = $module->query( 'SELECT role_name, unique_role_name FROM ' .
			                                  'redcap_user_roles WHERE project_id = ?',
			                                  [ $projectID ] );
			while ( $infoRoleName = $queryRoleNames->fetch_assoc() )
			{
				$listRoleNames[ $infoRoleName['role_name'] ] = $infoRoleName['unique_role_name'];
			}
			// Convert source roles to array.
			$sourceData['roles'] = $module->csvToArray( $sourceData['roles'] );
			// Amend source roles with this project's unique role names.
			foreach ( $sourceData['roles'] as $i => $infoRole )
			{
				if ( isset( $listRoleNames[ $infoRole['role_label'] ] ) )
				{
					$sourceData['roles'][$i]['unique_role_name'] =
						$listRoleNames[ $infoRole['role_label'] ];
				}
				else
				{
					$sourceData['roles'][$i]['unique_role_name'] = '';
				}
			}
			// Convert source roles back to CSV.
			$sourceData['roles'] = $module->arrayToCsv( $sourceData['roles'] );
			// Submit the roles.
			$module->postPage( '/UserRights/import_export_roles.php',
			                   [ 'csv_content' => $sourceData['roles'] ], true );
			unset( $listRoleNames, $queryRoleNames, $infoRoleName, $infoRole );
		}
	}
	header( 'Location: http' . ( empty( $_SERVER['HTTPS'] ) ? '' : 's' ) . '://' .
	        $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
	exit;
}


if ( $tryClientSide && isset( $_POST['sourcedata'] ) )
{
	$sourceData = json_decode( base64_decode( $_POST['sourcedata'] ), true );
	if ( $sourceData !== null )
	{
		$hasSource = true;
	}
}

if ( $hasSource && ! $needsLogin )
{
	$thisData = getThisData();
	$hasAnyChanges = ( $thisData !== $sourceData );
	$listHasChanges = [];
	$studyNamesMatch = true;
	if ( $hasAnyChanges )
	{
		// Extract and compare the main settings for each project.
		$thisDataMainSettings = [];
		$sourceDataMainSettings = [];
		$thisGlobalVarsID = null;
		$sourceGlobalVarsID = null;
		$listGlobalVars =
				[ 'StudyName', 'StudyDescription', 'ProtocolName', 'RecordAutonumberingEnabled',
				  'CustomRecordLabel', 'SecondaryUniqueField', 'SchedulingEnabled',
				  'SurveysEnabled', 'SurveyInvitationEmailField', 'DisplayTodayNowButton',
				  'PreventBranchingEraseValues', 'RequireChangeReason', 'DataHistoryPopup',
				  'OrderRecordsByField', 'MyCapEnabled', 'Purpose', 'PurposeOther', 'ProjectNotes',
				  'MissingDataCodes' ];
		foreach ( $thisData as $k => $v )
		{
			if ( $v['name'] == 'GlobalVariables' )
			{
				$thisGlobalVarsID = $k;
				break;
			}
		}
		foreach ( $sourceData as $k => $v )
		{
			if ( $v['name'] == 'GlobalVariables' )
			{
				$sourceGlobalVarsID = $k;
				break;
			}
		}
		if ( $thisGlobalVarsID !== null )
		{
			foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
			{
				if ( in_array( $v['name'], $listGlobalVars ) )
				{
					$thisDataMainSettings[ $v['name'] ] = $v['data'] ?? '';
					unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				}
			}
		}
		if ( $sourceGlobalVarsID !== null )
		{
			foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
			{
				if ( in_array( $v['name'], $listGlobalVars ) )
				{
					$sourceDataMainSettings[ $v['name'] ] = $v['data'] ?? '';
					unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				}
			}
		}
		$listHasChanges['MainSettings'] = ( $thisDataMainSettings !== $sourceDataMainSettings );
		$studyNamesMatch =
			( $thisDataMainSettings['StudyName'] === $sourceDataMainSettings['StudyName'] );

		// Extract and compare the data dictionary and event/instrument mapping for each project.
		$thisDataDictionary = [];
		$sourceDataDictionary = [];
		$thisDataEvents = [];
		$sourceDataEvents = [];
		$thisMetadataID = null;
		$sourceMetadataID = null;
		$listEventsItems = [ 'Protocol', 'StudyEventDef' ];
		$listDictionaryItems = [ 'FormDef', 'ItemGroupDef', 'ItemDef', 'CodeList' ];
		foreach ( $thisData as $k => $v )
		{
			if ( $v['name'] == 'MetaDataVersion' )
			{
				$thisMetadataID = $k;
				break;
			}
		}
		foreach ( $sourceData as $k => $v )
		{
			if ( $v['name'] == 'MetaDataVersion' )
			{
				$sourceMetadataID = $k;
				break;
			}
		}
		if ( $thisMetadataID !== null )
		{
			foreach ( $thisData[ $thisMetadataID ]['items'] as $k => $v )
			{
				if ( in_array( $v['name'], $listEventsItems ) )
				{
					$thisDataEvents[] = $v;
					unset( $thisData[ $thisMetadataID ]['items'][ $k ] );
				}
				elseif ( in_array( $v['name'], $listDictionaryItems ) )
				{
					$thisDataDictionary[] = $v;
					unset( $thisData[ $thisMetadataID ]['items'][ $k ] );
				}
			}
		}
		if ( $sourceMetadataID !== null )
		{
			foreach ( $sourceData[ $sourceMetadataID ]['items'] as $k => $v )
			{
				if ( in_array( $v['name'], $listEventsItems ) )
				{
					$sourceDataEvents[] = $v;
					unset( $sourceData[ $sourceMetadataID ]['items'][ $k ] );
				}
				elseif ( in_array( $v['name'], $listDictionaryItems ) )
				{
					$sourceDataDictionary[] = $v;
					unset( $sourceData[ $sourceMetadataID ]['items'][ $k ] );
				}
			}
		}
		$listHasChanges['Dictionary'] = ( $thisDataDictionary !== $sourceDataDictionary );
		$listHasChanges['Events'] = ( $thisDataEvents !== $sourceDataEvents );

		// Extract and compare the repeating instruments/events for each project.
		$thisDataRepeating = [];
		$sourceDataRepeating = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'RepeatingInstrumentsAndEvents' )
			{
				$thisDataRepeating = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'RepeatingInstrumentsAndEvents' )
			{
				$sourceDataRepeating = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['Repeating'] = ( $thisDataRepeating !== $sourceDataRepeating );

		// Extract and compare the form display logic for each project.
		$thisDataFormDisplayLogic = [];
		$sourceDataFormDisplayLogic = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'FormDisplayLogicConditionsGroup' )
			{
				$thisDataFormDisplayLogic = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'FormDisplayLogicConditionsGroup' )
			{
				$sourceDataFormDisplayLogic = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['FormDisplayLogic'] =
				( $thisDataFormDisplayLogic !== $sourceDataFormDisplayLogic );

		// Extract and compare the data quality rules for each project.
		$thisDataDataQuality = [];
		$sourceDataDataQuality = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'DataQualityRulesGroup' )
			{
				$thisDataDataQuality = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'DataQualityRulesGroup' )
			{
				$sourceDataDataQuality = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['DataQuality'] = ( $thisDataDataQuality !== $sourceDataDataQuality );

		// Extract and compare the surveys for each project.
		$thisDataSurveys = [];
		$sourceDataSurveys = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'SurveysGroup' )
			{
				$thisDataSurveys = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'SurveysGroup' )
			{
				$sourceDataSurveys = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['Surveys'] = ( $thisDataSurveys !== $sourceDataSurveys );

		// Extract and compare the Mycap settings for each project.
		$thisDataMycapSettings = [];
		$sourceDataMycapSettings = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'MycapProjectsGroup' || $v['name'] == 'MycapAboutpagesGroup' ||
			     $v['name'] == 'MycapThemesGroup' )
			{
				$thisDataMycapSettings =
						array_merge( $thisDataMycapSettings,
						             $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'MycapProjectsGroup' || $v['name'] == 'MycapAboutpagesGroup' ||
			     $v['name'] == 'MycapThemesGroup' )
			{
				$sourceDataMycapSettings =
						array_merge( $sourceDataMycapSettings,
						             $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['MycapSettings'] = ( $thisDataMycapSettings !== $sourceDataMycapSettings );

		// Extract and compare the Mycap tasks for each project.
		$thisDataMycapTasks = [];
		$sourceDataMycapTasks = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'MycapTasksGroup' || $v['name'] == 'MycapTasksSchedulesGroup' )
			{
				$thisDataMycapTasks =
						array_merge( $thisDataMycapTasks,
						             $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'MycapTasksGroup' || $v['name'] == 'MycapTasksSchedulesGroup' )
			{
				$sourceDataMycapTasks =
						array_merge( $sourceDataMycapTasks,
						             $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['MycapTasks'] = ( $thisDataMycapTasks !== $sourceDataMycapTasks );

		// Extract and compare the alerts for each project.
		$thisDataAlerts = [];
		$sourceDataAlerts = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'AlertsGroup' )
			{
				$thisDataAlerts = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'AlertsGroup' )
			{
				$sourceDataAlerts = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['Alerts'] = ( $thisDataAlerts !== $sourceDataAlerts );

		// Extract and compare the user roles for each project.
		$thisDataUserRoles = [];
		$sourceDataUserRoles = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'UserRolesGroup' )
			{
				$thisDataUserRoles = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'UserRolesGroup' )
			{
				$sourceDataUserRoles = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['UserRoles'] = ( $thisDataUserRoles !== $sourceDataUserRoles );

		// Extract and compare the reports for each project.
		$thisDataReports = [];
		$sourceDataReports = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'ReportsGroup' )
			{
				$thisDataReports = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'ReportsGroup' )
			{
				$sourceDataReports = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['Reports'] = ( $thisDataReports !== $sourceDataReports );

		// Extract and compare the external module settings for each project.
		$thisDataExtMod = [];
		$sourceDataExtMod = [];
		$thisExtModID = null;
		$sourceExtModID = null;
		foreach ( $thisData as $k => $v )
		{
			if ( $v['name'] == 'ExternalModules' )
			{
				$thisExtModID = $k;
				break;
			}
		}
		foreach ( $sourceData as $k => $v )
		{
			if ( $v['name'] == 'ExternalModules' )
			{
				$sourceExtModID = $k;
				break;
			}
		}
		if ( $thisExtModID !== null )
		{
			foreach ( $thisData[ $thisExtModID ]['items'] as $k => $v )
			{
				$thisDataExtMod[ $v['_name'] ] = $v['items'] ?? [];
				unset( $thisData[ $thisExtModID ]['items'][ $k ] );
			}
		}
		if ( $sourceExtModID !== null )
		{
			foreach ( $sourceData[ $sourceExtModID ]['items'] as $k => $v )
			{
				$sourceDataExtMod[ $v['_name'] ] = $v['items'] ?? [];
				unset( $sourceData[ $sourceExtModID ]['items'][ $k ] );
			}
		}
		$listHasChanges['ExtMod'] = ( $thisDataExtMod !== $sourceDataExtMod );

		// Compare other settings for each project.
		$listHasChanges['Other'] = ( $thisData !== $sourceData );
	}
}


// Display the project header
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

?>
<div class="projhdr">
 Project Deployment
</div>
<div class="round chklist" style="padding:15px 20px 0">
 <div class="chklisthdr">
  Download Project Object
 </div>
 <div>
  <p>
   <button class="btn btn-sm btn-defaultrc fs13 nowrap"
           onclick="window.location.href='<?php echo $module->getUrl( 'projectexport.php' ); ?>'">
    <i class="fas fa-file-code fs14"></i> Download project object
   </button>
  </p>
  <p>
   Download project object for comparison in an external application.
  </p>
 </div>
</div>
<p>&nbsp;</p>
<?php
if ( $tryClientSide && ! $hasSource )
{
?>
<h4>Fetch Source Project Data</h4>
<p>Log in to the source server and then return here to fetch the source data.</p>
<form method="post" onsubmit="return clientFetch()">
 <p>
  <input type="submit" value="Fetch source data">
  <input type="hidden" id="sourcedata" name="sourcedata" value="">
 </p>
</form>
<?php
}
elseif ( $hasSource )
{
	if ( $needsLogin )
	{
?>
<h4>Login to Source Project</h4>
<p>Enter your username and password below to log in to <b><?php
		echo $module->escape( $module->getProjectSetting( 'source-server' ) );
?></b></p>
<form method="post">
 <table>
  <tr>
   <td>Username:</td>
   <td><input type="text" name="username" autocomplete="new-password"></td>
  </tr>
  <tr>
   <td>Password:</td>
   <td><input type="password" name="password" autocomplete="new-password"></td>
  </tr>
  <tr>
   <td></td>
   <td><input type="hidden" name="action" value="login"><input type="submit" value="Login"></td>
  </tr>
 </table>
</form>
<?php
	}
	else
	{
		if ( ! $studyNamesMatch )
		{
?>
<p><b>Warning:</b> The name of the source project does not match this project.</p>
<?php
		}
?>
<h4>Changes For Deployment</h4>
<?php
		if ( $hasAnyChanges )
		{
?>
<p>
 Changes have been identified in the source project. Here is a summary of the changes.<br>
 Please download the project object for this project and the source project to see the differences
 in more detail.
</p>
<script type="text/javascript">
  $('head').append('<style type="text/css">.changestbl td{vertical-align:top;padding:2px}</style>')
</script>
<form method="post"<?php echo $tryClientSide && isset( $_POST['sourcedata'] ) ?
                              ' onsubmit="return clientFetchFE()"' : ''; ?>>
 <table class="changestbl">
<?php
			if ( $listHasChanges['MainSettings'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b>Main Project Settings</b><br>
    These settings include the project title, purpose, and additional customizations.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['Dictionary'] )
			{
?>
  <tr>
   <td><input type="checkbox" name="update[dictionary]" value="1"></td>
   <td>
    <b>Data Dictionary</b><br>
    These are the instrument and field definitions.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['Events'] )
			{
?>
  <tr>
   <td><input type="checkbox" name="update[events]" value="1"></td>
   <td>
    <b>Events and Arms</b><br>
    These are the event and arm definitions.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['Repeating'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b>Repeating Instruments and Events</b><br>
    These are the instruments and events which are configured to be repeating, plus any custom
    labels configured for them.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['FormDisplayLogic'] )
			{
?>
  <tr>
   <td><input type="checkbox" name="update[fdl]" value="1"></td>
   <td>
    <b>Form Display Logic</b><br>
    These are the form display logic conditions.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['DataQuality'] )
			{
?>
  <tr>
   <td><input type="checkbox" name="update[dataquality]" value="1"></td>
   <td>
    <b>Data Quality Rules</b><br>
    These are the data quality rules.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['Surveys'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b>Survey Settings</b><br>
    These are the survey settings for each instrument enabled as a survey.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['MycapSettings'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b>MyCap Settings</b><br>
    These are the project MyCap settings, MyCap about pages and MyCap themes.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['MycapTasks'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b>MyCap Tasks</b><br>
    These are the MyCap tasks and schedules.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['Alerts'] )
			{
?>
  <tr>
   <td><input type="checkbox" name="update[alerts]" value="1"></td>
   <td>
    <b>Alerts and Notifications</b><br>
    These are the alerts as defined in alerts and notifications.<br>This does not include automated
    survey invitations and survey completion emails.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['UserRoles'] )
			{
?>
  <tr>
   <td><input type="checkbox" name="update[roles]" value="1"></td>
   <td>
    <b>User Roles</b><br>
    These are the user roles as defined on the user rights page.<br>This does not include the
    user/role assignments or any users with custom rights that are not part of a role.
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['Reports'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b>Reports</b><br>
    REDCap Reports
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['ExtMod'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b>External Module Settings</b><br>
    These are all the external module settings which are defined against the current/source projects
    and are available for export.<br>
    Modules with setting changes:
    <ul>
<?php
				foreach ( call_user_func( function($a){sort($a);return $a;},
				                         array_keys( $thisDataExtMod + $sourceDataExtMod ) ) as $k )
				{
					if ( ! isset( $sourceDataExtMod[ $k ] ) ||
					     $sourceDataExtMod[ $k ] !== $thisDataExtMod[ $k ] )
					{
?>
     <li><?php echo $module->escape( $k ); ?></li>
<?php
					}
				}
?>
    </ul>
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['Other'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b>Other Settings</b><br>
    Any settings not included in the categories above.
   </td>
  </tr>
<?php
			}
?>
 </table>
 <p>
  <input type="submit" value="Deploy Changes">
<?php
			if ( $tryClientSide && isset( $_POST['sourcedata'] ) )
			{
?>
  <input type="hidden" name="sourcedata" id="sourcedatafe" value="">
<?php
			}
?>
 </p>
</form>
<?php
		}
		else
		{
?>
<p>
 No changes have been identified in the source project.<br>
 This project is up to date.
</p>
<?php
		}
	}
}

if ( $tryClientSide )
{
?>
<script type="text/javascript">
 function clientFetch()
 {
   if ( $('#sourcedata').val() == '' )
   {
     var vSourceURL = '<?php echo $sourceServer; ?>/api/?type=module&prefix=project_deployment' +
                      '&page=projectexport&pid=<?php echo $sourceProject; ?>&returnfunction=1'
     $('body').append( '<script type="text/javascript" src="' + vSourceURL + '"></' + 'script>' )
     return false
   }
   return true
 }
 function clientPDResponse( vData )
 {
   $('#sourcedata').val( vData )
   $('#sourcedata').closest('form').submit()
 }
 function clientFetchFE()
 {
   if ( $('#sourcedatafe').val() == '' )
   {
     var vSourceURL = '<?php echo $sourceServer; ?>/api/?type=module&prefix=project_deployment' +
                      '&page=getfeatureexports&pid=<?php echo $sourceProject; ?>&returnfunction=1'
     $('body').append( '<script type="text/javascript" src="' + vSourceURL + '"></' + 'script>' )
     return false
   }
   return true
 }
 function clientPDFEResponse( vData )
 {
   $('#sourcedatafe').val( vData )
   $('#sourcedatafe').closest('form').submit()
 }
</script>
<?php
}

// Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
