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
$hasSource = false;
$needsLogin = false;
if ( $sourceServer != '' && $sourceProject != '' )
{
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
	                    '&page=projectexport&pid=' . $sourceProject );
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
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $loginData );
			$sourceData = curl_exec( $curl );
		}
		else
		{
			$needsLogin = true;
		}
	}
	if ( $sourceHeaders['content-type'] == 'application/json' &&
	     substr( $sourceHeaders['content-disposition'], 0, 11 ) == 'attachment;' )
	{
		$sourceData = json_decode( $sourceData, true );
	}
	elseif ( ! $needsLogin )
	{
		$hasSource = false;
	}
	curl_setopt( $curl, CURLOPT_COOKIELIST, 'FLUSH' );
	curl_close( $curl );
	$_SESSION['modprojdeploy_session'] = file_get_contents( $cookieFile );
}

if ( $hasSource && ! $needsLogin )
{
	$thisData = getThisData();
	$hasAnyChanges = ( $thisData !== $sourceData );
	$listHasChanges = [];
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
				$thisDataExtMod[ $v['attrs']['name'] ] = $v['items'] ?? [];
				unset( $thisData[ $thisExtModID ]['items'][ $k ] );
			}
		}
		if ( $sourceExtModID !== null )
		{
			foreach ( $sourceData[ $sourceExtModID ]['items'] as $k => $v )
			{
				$sourceDataExtMod[ $v['attrs']['name'] ] = $v['items'] ?? [];
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
if ( $hasSource )
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
<form method="post">
 <table>
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
   <td></td>
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
   <td></td>
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
   <td></td>
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
   <td></td>
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
   <td></td>
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
   <td></td>
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

// Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
