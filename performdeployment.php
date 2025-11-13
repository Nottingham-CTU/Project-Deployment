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
if ( $sourceServer == '' )
{
	$sourceServer = $module->getSystemSetting( 'default-source-server' );
}
$sourceServerAllowlist = trim( $module->getSystemSetting( 'source-server-allowlist' ) );
if ( $sourceServerAllowlist != '' )
{
	$sourceServerAllowlist = explode( "\n", str_replace( "\r\n", "\n", $sourceServerAllowlist ) );
	if ( ! in_array( $sourceServer, $sourceServerAllowlist ) )
	{
		$sourceServer = '';
	}
}
$sourceProject = preg_replace( '/[^0-9]/', '', $module->getProjectSetting( 'source-project' ) );
$sourceToken = preg_replace( '/[^0-9A-F]/', '',
                             $module->getProjectSetting( 'source-project-token' ) );
$allowClientSide = $module->getSystemSetting( 'allow-client-connection' );
$performUpdates = false;
$hasSource = false;
$needsLogin = false;
$tryClientSide = false;
if ( $sourceServer != '' && ( $sourceProject != '' || $sourceToken != '' ) )
{
	$performUpdates = isset( $_POST['update'] ) && ! empty( $_POST['update'] );
	$hasSource = true;
	// Attempt to get the project export from the source server.
	// If the login page is returned, prompt for username and password for source server.
	// If the export is returned from the source server, get the export from this server and
	// perform a comparison.

	// Initialise the cookie file (if cookies previously saved in session load them).
	$cookieFile = $module->createTempFile();
	if ( isset( $_SESSION['modprojdeploy_session'] ) )
	{
		file_put_contents( $cookieFile, $_SESSION['modprojdeploy_session'] );
	}

	// Set up cURL.
	$curl = curl_init();
	if ( $sourceToken != '' ) // API token supplied, perform API request
	{
		curl_setopt( $curl, CURLOPT_URL, $sourceServer . '/api/');
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS,
		             'content=externalModule&prefix=project_deployment&action=' .
		             ( $performUpdates ? 'getfeatureexports' : 'projectexport' ) .
		             '&sourceProjectID=' . $sourceProject . '&token=' . $sourceToken );
	}
	else // API token not supplied, get login session to source server
	{
		curl_setopt( $curl, CURLOPT_URL,
		             $sourceServer . '/api/?type=module&prefix=project_deployment&page=' .
		             ( $performUpdates ? 'getfeatureexports' : 'projectexport' ) .
		             '&pid=' . $sourceProject );
	}
	curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, ( $allowClientSide ? 4 : 10 ) );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $curl, CURLOPT_COOKIEFILE, $cookieFile );
	curl_setopt( $curl, CURLOPT_COOKIEJAR, $cookieFile );
	// Use cacert file provided with REDCap unless an alternative is specified in php.ini.
	if ( ini_get( 'curl.cainfo' ) == '' )
	{
		curl_setopt( $curl, CURLOPT_CAINFO, APP_PATH_DOCROOT . '/Resources/misc/cacert.pem' );
	}
	curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );

	// Perform request to source server and get headers and data.
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

	// Response is HTML or XML which indicates failure or further action required...
	if ( substr( $sourceHeaders['content-type'], 0, 9 ) == 'text/html' ||
	     substr( $sourceHeaders['content-type'], 0, 8 ) == 'text/xml' )
	{
		if ( strpos( $sourceData, 'REDCap' ) === false )
		{
			// The string 'REDCap' is not in the response, which means this is probably not the
			// REDCap login page and since it is also not a successful response there is nothing
			// more we can do and this connection has failed.
			$hasSource = false;
		}
		elseif ( isset( $_POST['action'] ) && $_POST['action'] == 'login' )
		{
			// The response is probably the login page and a username and password have been
			// submitted which means we can pass those across to the source server to try and
			// establish a login session.
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
			// The response is probably the login page but a username and password have not been
			// submitted so we need to request them.
			$needsLogin = true;
		}
	}

	// A successful response should be of type JSON.
	if ( substr( $sourceHeaders['content-type'], 0, 16 ) == 'application/json' )
	{
		$sourceData = json_decode( $sourceData, true );
	}
	// If the response is not successful and we are not prompting the user for username and password
	// then we have failed to connect to the source server. If client-side connections are allowed
	// we can now fall back to that (if not using API token).
	elseif ( ! $needsLogin )
	{
		$hasSource = false;
		$tryClientSide = ( $sourceToken == '' && $allowClientSide );
	}

	// Write any cookies into the session and terminate cURL.
	curl_setopt( $curl, CURLOPT_COOKIELIST, 'FLUSH' );
	curl_close( $curl );
	$_SESSION['modprojdeploy_session'] = file_get_contents( $cookieFile );
}



// Handle request to update the project.
if ( $performUpdates )
{
	// If we are attempting a client-side connection and source data has been submitted from the
	// client, then update the hasSource variable to indicate we now have source data.
	if ( $tryClientSide && isset( $_POST['sourcedata'] ) )
	{
		$sourceData = json_decode( base64_decode( $GLOBALS['_POST']['sourcedata'] ), true );
		if ( $sourceData !== null )
		{
			$hasSource = true;
		}
	}

	// If a connection to the source server (either server-side or client-side) was successful, and
	// was not just to the login page...
	if ( $hasSource && ! $needsLogin )
	{
		$listDeploymentErrors = [];
		$listNewFields = [];
		// If the REDCap UI Tweaker module is enabled, get the module object for it.
		$UITweaker = null;
		if ( $module->isModuleEnabled( 'redcap_ui_tweaker' ) )
		{
			$UITweaker = \ExternalModules\ExternalModules::getModuleInstance( 'redcap_ui_tweaker' );
		}
		// If saving files before deployment, check that the folder exists and create it if not.
		$saveFolder = null;
		if ( $module->getSystemSetting( 'save-before-deploy' ) &&
		     \REDCap::versionCompare( REDCAP_VERSION, '15.5.0', '>=' ) )
		{
			$infoSaveFolder =
				$module->query( 'SELECT folder_id FROM redcap_docs_folders ' .
				                'WHERE name = ? AND project_id = ? AND deleted = 0',
				                [ $module->getModuleName(), $projectID ] )->fetch_assoc();
			if ( $infoSaveFolder )
			{
				$saveFolder = $infoSaveFolder['folder_id'];
			}
			else
			{
				$module->query( 'INSERT INTO redcap_docs_folders (project_id, name, admin_only) ' .
				                'VALUES (?,?,?)', [ $projectID, $module->getModuleName(), 1 ] );
				$saveFolder = $module->query( 'SELECT LAST_INSERT_ID() i', [] )->fetch_assoc()['i'];
			}
		}
		// If saving before deployment and the UI Tweaker codebook simplified view is enabled, then
		// save the simplified view export if either the data dictionary or form display logic is
		// being updated. Note that we do not need to save the data dictionary itself as REDCap
		// makes snapshots of this anyway.
		if ( $saveFolder !== null && $UITweaker !== null &&
		     $UITweaker->getSystemSetting( 'codebook-simplified-view' ) &&
		     ( ( isset( $_POST['update']['dictionary'] ) &&
		         ! empty( $sourceData['dictionary'] ) && ! empty( $sourceData['forms'] ) ) ||
		       ( isset( $_POST['update']['fdl'] ) && ! empty( $sourceData['fdl'] ) ) ) )
		{
			$module->savePage( '/ExternalModules/?prefix=redcap_ui_tweaker&page=codebook_simplified',
			                   'sv_codebook.svc.json', $saveFolder, 'application/json',
			                   'simp_view_diff_mode=export' );
		}
		// Apply data dictionary changes.
		if ( isset( $_POST['update']['dictionary'] ) &&
		     ! empty( $sourceData['dictionary'] ) && ! empty( $sourceData['forms'] ) )
		{
			// Get server/project specific URLs from the current data dictionary and map to hashes.
			$currentDictionary =
				$module->getPage( '/Design/data_dictionary_download.php?delimiter=,' );
			$listDictionaryURLs = [];
			if ( preg_match_all( '/((href|src)="")(http[^"]+)"/', $currentDictionary['data'],
			                     $listDictionaryURLMatches, PREG_PATTERN_ORDER ) )
			{
				foreach ( $listDictionaryURLMatches[3] as $dictionaryURLMatch )
				{
					$dictionaryURLHash = $module->fileUrlToFileHash( $dictionaryURLMatch );
					if ( $dictionaryURLHash != $dictionaryURLMatch )
					{
						$listDictionaryURLs[ $dictionaryURLHash ] = $dictionaryURLMatch;
					}
				}
			}
			unset( $currentDictionary, $listDictionaryURLMatches );
			// Replace hashes in the source data dictionary with URLs.
			$sourceData['dictionary'] =
				preg_replace_callback( '/((href|src)="")(data:[^"]+)"/',
				                       function ( $m ) use ( $listDictionaryURLs )
				                       {
				                           if ( isset( $listDictionaryURLs[ $m[3] ] ) )
				                           {
				                               return $m[1] . $listDictionaryURLs[ $m[3] ] . '"';
				                           }
				                           else
				                           {
				                               return $m[0];
				                           }
				                       },
				                       $sourceData['dictionary'] );
			// Get the field names from the source data dictionary.
			$listSourceDictionary = $module->csvToArray( $sourceData['dictionary'] );
			$sourceDictionaryFieldHdr = array_keys( $listSourceDictionary[0] )[0];
			$sourceDictionaryFormHdr = array_keys( $listSourceDictionary[0] )[1];
			foreach ( $listSourceDictionary as $infoSourceDictionary )
			{
				$listNewFields[] = [ $infoSourceDictionary[ $sourceDictionaryFieldHdr ],
				                     $infoSourceDictionary[ $sourceDictionaryFormHdr ] ];
			}
			unset( $listSourceDictionary, $infoSourceDictionary,
			       $sourceDictionaryFieldHdr, $sourceDictionaryFormHdr );
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
			$dictionaryResponse =
				$module->postPage( 'Design/data_dictionary_upload.php',
				                   [ 'commit' => '1', 'fname' => $dictionaryFileName,
				                     'delimiter' => ',' ] )['data'];
			$dictionaryError = false;
			if ( strpos( $dictionaryResponse, $GLOBALS['lang']['database_mods_59'] ) !== false )
			{
				preg_match( '!' .  preg_quote( $GLOBALS['lang']['database_mods_60'], '!' ) .
				            '.*?</p>(.*?)</div>!s', $dictionaryResponse, $dictionaryError );
				$listDeploymentErrors[ $GLOBALS['lang']['global_09'] ] =
					$module->cleanHTML( $dictionaryError[1] );
			}
			unset( $dictionaryFileName, $dictionaryResponse );
			// If in draft mode, amend the instrument display names as required.
			// Don't do this if not in prod/draft, because in dev status updating the form label
			// will also update the form name.
			if ( $isDraftMode && $dictionaryError === false )
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
			unset( $isDraftMode, $submitDraftMode, $dictionaryError );
		}
		// Apply event/arm changes.
		if ( isset( $_POST['update']['events'] ) && ! empty( $sourceData['arms'] ) &&
		     ! empty( $sourceData['events'] ) && ! empty( $sourceData['eventforms'] ) )
		{
			// If saving before deployment, save the events, arms and event/instrument mapping
			// exports. If the UI Tweaker instrument mapping simplified view is enabled, then also
			// save the simplified view export.
			if ( $saveFolder !== null )
			{
				$module->savePage( '/Design/arm_download.php',
				                   'arms.csv', $saveFolder, 'application/csv' );
				$module->savePage( '/Design/event_download.php',
				                   'events.csv', $saveFolder, 'application/csv' );
				$module->savePage( '/Design/instrument_event_mapping_download.php',
				                   'events-instruments.csv', $saveFolder, 'application/csv' );
				if ( $UITweaker !== null &&
				     $UITweaker->getSystemSetting( 'instrument-simplified-view' ) )
				{
					$module->savePage( '/ExternalModules/?prefix=redcap_ui_tweaker&page=' .
					                   'instrument_simplified', 'sv_instruments.svi.json',
					                   $saveFolder, 'application/json',
					                   'simp_view_diff_mode=export' );
				}
			}
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
			// If saving before deployment, save the form display logic export.
			if ( $saveFolder !== null )
			{
				$module->savePage( '/Design/online_designer.php?FormDisplayLogicSetup-export=',
				                   'formdisplaylogic.csv', $saveFolder,
				                   'application/octet-stream' );
			}
			// Submit the form display logic.
			$module->postPage( '/Design/online_designer.php',
			                   [ 'FormDisplayLogicSetup-import' => '',
			                     'files' => new \CURLStringFile( $sourceData['fdl'],
			                                                     'fdl.csv' ) ], true );
		}
		// Apply survey settings changes.
		if ( isset( $_POST['update']['surveys'] ) && ! empty( $sourceData['surveys'] ) )
		{
			// If saving before deployment, save the survey settings export.
			if ( $saveFolder !== null )
			{
				$module->savePage( '/Design/online_designer.php?SurveySettings-export=',
				                   'surveys.csv', $saveFolder, 'application/octet-stream' );
			}
			// Submit the survey settings.
			$surveySettingsResponse =
				$module->postPage( '/Design/online_designer.php',
				                   [ 'SurveySettings-import' => '',
				                     'files' => new \CURLStringFile( $sourceData['surveys'],
				                                                     'surveys.csv' ) ],
				                   true )['data'];
			$surveySettingsResponseOb = json_decode( $surveySettingsResponse, true );
			if ( $surveySettingsResponseOb === null )
			{
				$listDeploymentErrors[ $GLOBALS['lang']['multilang_63'] ] =
							$module->cleanHTML( $surveySettingsResponse );
			}
			elseif ( isset( $surveySettingsResponseOb['error'] ) &&
			         $surveySettingsResponseOb['error'] )
			{
				if ( is_array( $surveySettingsResponseOb['message'] ) )
				{
					$surveySettingsResponseOb['message'] =
						implode( '', $surveySettingsResponseOb['message'] );
				}
				$surveySettingsResponseOb['message'] =
					preg_replace( '!</?span[^>]*>!', '', $surveySettingsResponseOb['message'] );
				$listDeploymentErrors[ $GLOBALS['lang']['multilang_63'] ] =
							$module->cleanHTML( $surveySettingsResponseOb['message'] );
			}
			unset( $surveySettingsResponse, $surveySettingsResponseOb );
		}
		// Apply data quality rules changes.
		if ( isset( $_POST['update']['dataquality'] ) && ! empty( $sourceData['dataquality'] ) )
		{
			// If saving before deployment, save the data quality rules export. If the UI Tweaker
			// data quality simplified view is enabled, then also save the simplified view export.
			if ( $saveFolder !== null )
			{
				$module->savePage( '/DataQuality/download_dq_rules.php',
				                   'dataquality.csv', $saveFolder, 'application/csv' );
				if ( $UITweaker !== null &&
				     $UITweaker->getSystemSetting( 'quality-rules-simplified-view' ) )
				{
					$module->savePage( '/ExternalModules/?prefix=redcap_ui_tweaker&page=' .
					                   'quality_rules_simplified', 'sv_dataquality.svq.json',
					                   $saveFolder, 'application/json',
					                   'simp_view_diff_mode=export' );
				}
			}
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
			// If source data array is not empty...
			if ( ! empty( $sourceData['dataquality'] ) )
			{
				// Convert source data quality rules back to CSV.
				$sourceData['dataquality'] = $module->arrayToCsv( $sourceData['dataquality'] );
				// Submit the data quality rules.
				$module->postPage( '/DataQuality/upload_dq_rules.php',
				                   [ 'csv_content' => $sourceData['dataquality'] ], true );
			}
			unset( $listDQNames, $listDQLogic, $queryDQ, $infoDQ );
		}
		// Apply alerts changes.
		if ( isset( $_POST['update']['alerts'] ) && ! empty( $sourceData['alerts'] ) )
		{
			// If saving before deployment, save the alerts export. If the UI Tweaker alerts
			// simplified view is enabled, then also save the simplified view export.
			if ( $saveFolder !== null )
			{
				$module->savePage( '/index.php?route=AlertsController:downloadAlerts',
				                   'alerts.csv', $saveFolder, 'application/csv' );
				if ( $UITweaker !== null &&
				     $UITweaker->getSystemSetting( 'alerts-simplified-view' ) )
				{
					$module->savePage( '/ExternalModules/?prefix=redcap_ui_tweaker&page=' .
					                   'alerts_simplified', 'sv_alerts.sva.json',
					                   $saveFolder, 'application/json',
					                   'simp_view_diff_mode=export' );
				}
			}
			// Get existing alerts.
			$currentAlerts = $module->getPage( '/index.php?route=AlertsController:downloadAlerts' );
			if ( substr( $currentAlerts['headers']['content-type'], 0, 15 ) == 'application/csv' )
			{
				// Convert existing alerts to array.
				$currentAlerts = $module->csvToArray( $currentAlerts['data'] );
				// Determine the submission URL for alerts. Uses the built-in REDCap URL by default,
				// but if the REDCap UI Tweaker module is enabled and custom alert senders turned on
				// then the module's alerts submission URL is used instead.
				$alertsSubmitURL = '/index.php?route=AlertsController:uploadAlerts';
				if ( $UITweaker !== null && $UITweaker->getSystemSetting( 'custom-alert-sender' ) )
				{
					$alertsSubmitURL = '/ExternalModules/?prefix=redcap_ui_tweaker' .
					                   '&page=alerts_submit&mode=upload';
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
				// If at least one source alert...
				if ( ! empty( $sourceData['alerts'] ) )
				{
					// Convert source alerts back to CSV.
					$sourceData['alerts'] = $module->arrayToCsv( $sourceData['alerts'] );
					// Submit the alerts.
					$alertsResponse =
						$module->postPage( $alertsSubmitURL,
						                 [ 'csv_content' => $sourceData['alerts'] ], true )['data'];
					if ( strpos( $alertsResponse, $GLOBALS['lang']['design_640'] ) !== false )
					{
						preg_match( '/' .  preg_quote( $GLOBALS['lang']['design_640'], '/' ) .
						            '(?(?<=\\\\).|[^\'])+/', $alertsResponse, $alertsError );
						$listDeploymentErrors[ $GLOBALS['lang']['global_154'] ] =
							$module->cleanHTML( $alertsError[0] );
					}
				}
			}
			unset( $currentAlerts, $alertsSubmitURL, $infoAlert, $infoCurrentAlert,
			       $alertMatchingKeys, $alertTotalKeys, $alertsResponse, $alertsError );
		}
		// Apply user roles changes.
		if ( isset( $_POST['update']['roles'] ) && ! empty( $sourceData['roles'] ) )
		{
			// If saving before deployment, save the user roles export. If the UI Tweaker user roles
			// simplified view is enabled, then also save the simplified view export.
			if ( $saveFolder !== null )
			{
				$module->savePage( '/UserRights/import_export_roles.php?action=download',
				                   'userroles.csv', $saveFolder, 'application/csv' );
				if ( $UITweaker !== null &&
				     $UITweaker->getSystemSetting( 'user-rights-simplified-view' ) )
				{
					$module->savePage( '/ExternalModules/?prefix=redcap_ui_tweaker&page=' .
					                   'user_rights_simplified', 'sv_userroles.svu.json',
					                   $saveFolder, 'application/json',
					                   'simp_view_diff_mode=export' );
				}
			}
			// Get the unique role names for the roles in this project.
			$listRoleNames = [];
			foreach ( \UserRights::getRoles( $projectID ) as $infoRoleName )
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
		$_SESSION['mod_project_deployment_deployed'] = true;
		if ( ! empty( $listDeploymentErrors ) )
		{
			foreach ( $listDeploymentErrors as $errorTitle => $errorDetails )
			{
				// Amend $errorDetails to remove references to columns as the user is not uploading
				// a spreadsheet. Cell references are converted to field/form names.
				$errorDetails = str_replace( $GLOBALS['lang']['database_mods_55'],
				                             $module->tt('deploy_error_branch_logic'),
				                             $errorDetails );
				$errorDetails = str_replace( $GLOBALS['lang']['database_mods_45'],
				                             $module->tt('deploy_error_calc_eq'), $errorDetails );
				if ( $errorTitle == $GLOBALS['lang']['global_09'] )
				{
					$errorDetails =
						preg_replace_callback( '/\\([C-Z]([1-9][0-9]*)\\)/',
						                       function( $m ) use ( $module, $listNewFields )
						                       {
						                           $field = $listNewFields[ intval( $m[1] ) - 2 ]
						                                    ?? null;
						                           if ( $field === null ) return $m[0];
						                           return '(' .
						                                  $module->tt('deploy_error_field_form',
						                                              $field[0], $field[1]) . ')';
						                       }, $errorDetails );
				}
				$listDeploymentErrors[ $errorTitle ] = $errorDetails;
			}
			$_SESSION['mod_project_deployment_errors'] = $listDeploymentErrors;
		}
	}
	header( 'Location: http' . ( empty( $_SERVER['HTTPS'] ) || $_SERVER['HTTPS'] == 'off' ? '' : 's' ) .
	        '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
	exit;
}


// If we are attempting a client-side connection and source data has been submitted from the client,
// then update the hasSource variable to indicate we now have source data.
if ( $tryClientSide && isset( $_POST['sourcedata'] ) )
{
	$sourceData = json_decode( base64_decode( $_POST['sourcedata'] ), true );
	if ( $sourceData !== null )
	{
		$hasSource = true;
	}
}

// If a connection to the source server (either server-side or client-side) was successful, and was
// not just to the login page, get the data for this project and prepare a summary of any changes.
if ( $hasSource && ! $needsLogin )
{
	$thisData = getThisData();
	$hasAnyChanges = false;
	$listHasChanges = [];
	$studyNamesMatch = true;
	if ( $thisData !== $sourceData )
	{
		// Extract and compare the main settings for each project.
		$thisDataMainSettings = [];
		$sourceDataMainSettings = [];
		$thisGlobalVarsID = null;
		$sourceGlobalVarsID = null;
		$listGlobalVars =
				[ 'StudyName', 'StudyDescription', 'ProtocolName', 'RecordAutonumberingEnabled',
				  'CustomRecordLabel', 'SecondaryUniqueField', 'SecondaryUniqueFieldDisplayValue',
				  'SecondaryUniqueFieldDisplayLabel', 'SchedulingEnabled', 'SurveysEnabled',
				  'SurveyInvitationEmailField', 'DisplayTodayNowButton',
				  'PreventBranchingEraseValues', 'RequireChangeReason', 'DataHistoryPopup',
				  'OrderRecordsByField', 'MyCapEnabled', 'Purpose', 'PurposeOther', 'ProjectNotes',
				  'MissingDataCodes', 'DataResolutionEnabled' ];
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
		// For this project and the source project, check if the ProtocolName is equal to the
		// StudyName and if so remove the ProtocolName. Also if the StudyName is in the
		// StudyDescription then remove it from there. This ensures the StudyName only appears once
		// and can then be compared according to the name matching setting.
		if ( $thisDataMainSettings['StudyName'] == $thisDataMainSettings['ProtocolName'] )
		{
			unset( $thisDataMainSettings['ProtocolName'] );
		}
		if ( $sourceDataMainSettings['StudyName'] == $sourceDataMainSettings['ProtocolName'] )
		{
			unset( $sourceDataMainSettings['ProtocolName'] );
		}
		$thisDataMainSettings['StudyDescription'] =
			str_replace( $thisDataMainSettings['StudyName'], '',
			             $thisDataMainSettings['StudyDescription'] );
		$sourceDataMainSettings['StudyDescription'] =
			str_replace( $sourceDataMainSettings['StudyName'], '',
			             $sourceDataMainSettings['StudyDescription'] );
		$nameMatching = $module->getSystemSetting('project-name-matching');
		if ( $nameMatching == 'P' )
		{
			$studyNamesMatch =
				( ( strlen( $thisDataMainSettings['StudyName'] ) >
				    strlen( $sourceDataMainSettings['StudyName'] ) &&
				    substr( $thisDataMainSettings['StudyName'], 0,
				            strlen( $sourceDataMainSettings['StudyName'] ) ) ==
				    $sourceDataMainSettings['StudyName'] ) ||
				  ( strlen( $thisDataMainSettings['StudyName'] ) <=
				    strlen( $sourceDataMainSettings['StudyName'] ) &&
				    substr( $sourceDataMainSettings['StudyName'], 0,
				            strlen( $thisDataMainSettings['StudyName'] ) ) ==
				    $thisDataMainSettings['StudyName'] ) );
		}
		elseif ( $nameMatching == 'R' )
		{
			$nameMatchingRegex = $module->getSystemSetting('project-name-matching-regex');
			$studyNamesMatch = ( preg_match( '(' . $nameMatchingRegex . ')', '' ) !== false &&
			                     preg_replace( '(' . $nameMatchingRegex . ')', '',
			                                   $thisDataMainSettings['StudyName'] ) ==
			                     preg_replace( '(' . $nameMatchingRegex . ')', '',
			                                   $sourceDataMainSettings['StudyName'] ) );
		}
		elseif ( $nameMatching != 'D' )
		{
			$studyNamesMatch =
				( $thisDataMainSettings['StudyName'] === $sourceDataMainSettings['StudyName'] );
		}
		unset( $thisDataMainSettings['StudyName'], $sourceDataMainSettings['StudyName'] );
		$listHasChanges['MainSettings'] = ( $thisDataMainSettings !== $sourceDataMainSettings );

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

		// Extract and compare the data access groups for each project.
		$thisDataDataAccessGroups = [];
		$sourceDataDataAccessGroups = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'DataAccessGroupsGroup' )
			{
				$thisDataDataAccessGroups = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'DataAccessGroupsGroup' )
			{
				$sourceDataDataAccessGroups = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['DataAccessGroups'] =
				( $thisDataDataAccessGroups !== $sourceDataDataAccessGroups );

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
		$thisUserRolesID = null;
		$sourceUserRolesID = null;
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'UserRolesGroup' )
			{
				$thisUserRolesID = $k;
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'UserRolesGroup' )
			{
				$sourceUserRolesID = $k;
				break;
			}
		}
		if ( $thisUserRolesID !== null )
		{
			foreach ( $thisData[ $thisGlobalVarsID ]['items'][ $thisUserRolesID ]['items']
			          as $k => $v )
			{
				$thisDataUserRoles[ $v['_role_name'] ] = $v ?? [];
			}
			unset( $thisData[ $thisGlobalVarsID ]['items'][ $thisUserRolesID ] );
		}
		if ( $sourceUserRolesID !== null )
		{
			foreach ( $sourceData[ $sourceGlobalVarsID ]['items'][ $sourceUserRolesID ]['items']
			          as $k => $v )
			{
				$sourceDataUserRoles[ $v['_role_name'] ] = $v ?? [];
			}
			unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $sourceUserRolesID ] );
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

		// Extract and compare the multilanguage settings for each project.
		$thisDataMultiLang = [];
		$sourceDataMultiLang = [];
		foreach ( $thisData[ $thisGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'MultilanguageSettingsGroup' )
			{
				$thisDataMultiLang = $thisData[ $thisGlobalVarsID ]['items'][ $k ];
				unset( $thisData[ $thisGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		foreach ( $sourceData[ $sourceGlobalVarsID ]['items'] as $k => $v )
		{
			if ( $v['name'] == 'MultilanguageSettingsGroup' )
			{
				$sourceDataMultiLang = $sourceData[ $sourceGlobalVarsID ]['items'][ $k ];
				unset( $sourceData[ $sourceGlobalVarsID ]['items'][ $k ] );
				break;
			}
		}
		$listHasChanges['MultiLang'] = ( $thisDataMultiLang !== $sourceDataMultiLang );

		// Compare other settings for each project.
		$thisData[ $thisGlobalVarsID ]['items'] =
				array_values( $thisData[ $thisGlobalVarsID ]['items'] );
		$sourceData[ $sourceGlobalVarsID ]['items'] =
				array_values( $sourceData[ $sourceGlobalVarsID ]['items'] );
		$listHasChanges['Other'] = ( $thisData !== $sourceData );

		foreach ( $listHasChanges as $item )
		{
			if ( $item )
			{
				$hasAnyChanges = true;
				break;
			}
		}
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
    <i class="fas fa-file-code fs14"></i> <?php echo $module->tt('object_download'), "\n"; ?>
   </button>
  </p>
  <p>
   <?php echo $module->tt('object_download_desc'), "\n"; ?>
  </p>
 </div>
</div>
<p>&nbsp;</p>
<?php

if ( isset( $_SESSION['mod_project_deployment_deployed'] ) )
{
	if ( isset( $_SESSION['mod_project_deployment_errors'] ) )
	{
?>
<div class="round yellow"
     style="padding:10px;max-width:800px;display:grid;grid-template-columns:min-content;column-gap:5px">
 <img src="<?php echo APP_PATH_WEBROOT; ?>/Resources/images/exclamation_orange.png"
      style="grid-column:1;align-self:center">
 <b style="grid-column:2;align-self:center"><?php echo $module->tt('deployment_complete_error'); ?></b>
<?php
		foreach ( $_SESSION['mod_project_deployment_errors'] as $errorTitle => $errorDetails )
		{
?>
 <div style="grid-column:2;padding-top:10px">
  <b><?php echo $module->escape( $errorTitle ); ?></b>
  <div style="padding-left:10px"><?php echo $errorDetails; ?></div>
 </div>
<?php
		}
?>
</div>
<p>&nbsp;</p>
<?php
	}
	else
	{
?>
<div class="round green"
     style="padding:10px;max-width:800px;display:grid;grid-template-columns:min-content;column-gap:5px">
 <img src="<?php echo APP_PATH_WEBROOT; ?>/Resources/images/tick_circle.png"
      style="grid-column:1;align-self:center">
 <b style="grid-column:2;align-self:center"><?php echo $module->tt('deployment_complete'); ?></b>
 <span style="grid-column:2">
  <?php echo $module->tt('deployment_complete_desc'), "\n"; ?>
 </span>
</div>
<p>&nbsp;</p>
<?php
	}
	unset( $_SESSION['mod_project_deployment_deployed'], $_SESSION['mod_project_deployment_errors'] );
}


if ( $tryClientSide && ! $hasSource )
{
?>
<h4><?php echo $module->tt('get_source_client'); ?></h4>
<p><?php echo $module->tt( 'get_source_client_desc', $sourceServer ); ?></p>
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
<h4><?php echo $module->tt('get_source_login'); ?></h4>
<p><?php echo $module->tt( 'get_source_login_desc', $sourceServer ); ?></p>
<form method="post">
 <table>
  <tr>
   <td><?php echo $module->escape( $GLOBALS['lang']['global_11'] ); /* Username */ ?>:</td>
   <td><input type="text" name="username" autocomplete="new-password"></td>
  </tr>
  <tr>
   <td><?php echo $module->escape( $GLOBALS['lang']['global_32'] ); /* Password */ ?>:</td>
   <td><input type="password" name="password" autocomplete="new-password"></td>
  </tr>
  <tr>
   <td></td>
   <td>
    <input type="hidden" name="action" value="login">
     <input type="submit" value="<?php echo $module->escape( $GLOBALS['lang']['global_148'] ); ?>">
   </td>
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
<div class="yellow" style="max-width:800px;margin-bottom:10px">
 <img src="<?php echo APP_PATH_WEBROOT; ?>/Resources/images/exclamation_orange.png">
 <?php echo $module->tt('project_name_mismatch'), "\n"; ?>
</div>
<?php
		}
?>
<h4><?php echo $module->tt('changes_for_deployment'); ?></h4>
<?php
		if ( $hasAnyChanges )
		{
			$listEnabledModules = array_keys( $module->getEnabledModules( $module->getProjectId() ) );
?>
<p><?php echo $module->tt('changes_for_deployment_desc'); ?></p>
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
    <b><?php echo $module->escape( ucwords( $GLOBALS['lang']['setup_105'] ) ); /* Main Proj Settings */ ?></b>
    <br>
    <?php echo $module->tt('main_settings_desc'), "\n"; ?>
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
    <b><?php echo $module->escape( $GLOBALS['lang']['global_09'] ); /* Data Dictionary */ ?></b><br>
    <?php
				echo $module->tt('data_dictionary_desc');
				if ( $module->getProjectStatus() == 'PROD' )
				{
					echo '<br><i class="fas fa-circle-info"></i> ';
					echo $module->query( 'SELECT 1 FROM redcap_projects WHERE project_id = ? AND ' .
					                     'draft_mode = 0', [ $projectID ] )->fetch_assoc()
					     ? $module->tt('data_dictionary_desc_prod')
					     : $module->tt('data_dictionary_desc_draft');
				}
				echo "\n";
?>
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
    <b>
     <?php echo $module->escape( $GLOBALS['lang']['global_45'] ); /* Events */ ?> /
     <?php echo $module->escape( $GLOBALS['lang']['api_97'] ), "\n"; /* Arms */ ?>
    </b>
    <br>
    <?php echo $module->tt('events_desc'), "\n"; ?>
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
    <b><?php echo $module->escape( $GLOBALS['lang']['rep_forms_events_01'] ); /* Repeat Inst/Ev */ ?></b><br>
    <?php echo $module->tt('repeat_desc'), "\n"; ?>
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
    <b><?php echo $module->escape( $GLOBALS['lang']['design_985'] ); /* Form Disp Logic */ ?></b><br>
    <?php echo $module->tt('fdl_desc'), "\n"; ?>
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
    <b><?php echo $module->escape( $GLOBALS['lang']['dataqueries_81'] ); /* DQ Rules */ ?></b><br>
    <?php echo $module->tt('data_quality_desc'), "\n"; ?>
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['DataAccessGroups'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b><?php echo $module->escape( $GLOBALS['lang']['global_22'] ); /* DAGs */ ?></b><br>
    <?php echo $module->tt('dag_desc'), "\n"; ?>
   </td>
  </tr>
<?php
			}
			if ( $listHasChanges['Surveys'] )
			{
?>
  <tr>
   <td>
<?php
				if ( \REDCap::versionCompare(REDCAP_VERSION, '15.8.0') >= 0 )
				{
?>
    <input type="checkbox" name="update[surveys]" value="1">
<?php
				}
?>
   </td>
   <td>
    <b><?php echo $module->escape( $GLOBALS['lang']['multilang_63'] ); /* Survey Settings */ ?></b><br>
    <?php echo $module->tt('survey_desc'), "\n"; ?>
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
    <b><?php echo $module->escape( ucwords( $GLOBALS['lang']['mycap_mobile_app_637'] ) );
                  /* MyCap Settings */ ?></b>
    <br>
    <?php echo $module->tt('mycap_settings_desc'), "\n"; ?>
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
    <b><?php echo $module->escape( $GLOBALS['lang']['mycap_mobile_app_986']
                                   ?? 'MyCap Tasks' ); ?></b><br>
    <?php echo $module->tt('mycap_tasks_desc'), "\n"; ?>
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
    <b><?php echo $module->escape( $GLOBALS['lang']['global_154'] ); /* Alerts */ ?></b><br>
    <?php echo $module->tt('alerts_desc'), "\n"; ?>
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
    <b><?php echo $module->escape( $GLOBALS['lang']['api_162'] ); /* User Roles */ ?></b><br>
    <?php echo $module->tt('user_roles_desc'), "\n"; ?>
    <ul style="margin-bottom:0.5em">
<?php
				foreach ( call_user_func( function($a){sort($a);return $a;},
				                   array_keys( $thisDataUserRoles + $sourceDataUserRoles ) ) as $k )
				{
					if ( ! isset( $sourceDataUserRoles[ $k ] ) ||
					     $sourceDataUserRoles[ $k ] !== $thisDataUserRoles[ $k ] )
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
			if ( $listHasChanges['Reports'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b><?php echo $module->escape( $GLOBALS['lang']['app_06'] ); /* Reports */ ?></b><br>
    <?php echo $module->tt('reports_desc'),
               in_array( 'redcap_ui_tweaker', $listEnabledModules )
               ? '<br>' . $module->tt('reports_desc_uit') : '',
               in_array( 'advanced_reports', $listEnabledModules )
               ? '<br>' . $module->tt('reports_desc_advrep') : '', "\n"; ?>
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
    <b><?php echo $module->tt('extmod_settings_lbl'); ?></b><br>
    <?php echo $module->tt('extmod_settings_desc'), "\n"; ?>
    <ul style="margin-bottom:0.5em">
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
			if ( $listHasChanges['MultiLang'] )
			{
?>
  <tr>
   <td></td>
   <td>
    <b><?php echo $module->escape( $GLOBALS['lang']['multilang_01'] ); /* Multilanguage */ ?></b><br>
    <?php echo $module->tt('multilang_desc'), "\n"; ?>
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
    <b><?php echo $module->tt('other_settings_lbl'); ?></b><br>
    <?php echo $module->tt('other_settings_desc'), "\n"; ?>
   </td>
  </tr>
<?php
			}
?>
 </table>
 <p>
  <input type="submit" value="Deploy Changes" id="deploybtn">
<?php
			if ( $tryClientSide && isset( $_POST['sourcedata'] ) )
			{
?>
  <input type="hidden" name="sourcedata" id="sourcedatafe" value="">
  <input type="hidden" name="update[x]" value="1">
<?php
			}
?>
 </p>
</form>
<script type="text/javascript">
 $(function()
 {
   $('input[type="checkbox"][name^="update["]').on('click',function()
   {
     $('#deploybtn').prop('disabled',$('input[type="checkbox"][name^="update["]:checked').length==0)
   })
   $('#deploybtn').prop('disabled',true)
 })
</script>
<?php
		}
		else
		{
?>
<p><?php echo $module->tt('no_changes'); ?></p>
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
