<?php

namespace Nottingham\ProjectDeployment;

// Do not allow exports where the user does not have the rights.
$projectID = $module->getProjectId();
if ( $projectID === null || ! $module->canAccessDeployment( $projectID ) )
{
	exit;
}

function getPage( $path )
{
	$path .= ( ( strpos( $path, '?' ) === false ) ? '?' : '&' ) . 'pid=' . $GLOBALS['projectID'];
	$url = 'https://' . SERVER_NAME . APP_PATH_WEBROOT . $path;
	$curl = curl_init();
	curl_setopt( $curl, CURLOPT_URL, $url );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $curl, CURLOPT_COOKIE, session_name() . '=' . session_id() );
	if ( ini_get( 'curl.cainfo' ) == '' )
	{
		curl_setopt( $curl, CURLOPT_CAINFO, APP_PATH_DOCROOT . '/Resources/misc/cacert.pem' );
	}
	$pageHeaders = [];
	curl_setopt( $curl, CURLOPT_HEADERFUNCTION,
	             function ( $curl, $header ) use ( &$pageHeaders )
	             {
	                 $headerParts = explode( ':', $header, 2 );
	                 if ( count( $headerParts ) == 2 )
	                 {
	                     $pageHeaders[ trim( strtolower( $headerParts[0] ) ) ] =
	                             trim( $headerParts[1] );
	                 }
	                 return strlen( $header );
	             });
	$pageData = curl_exec( $curl );
	return [ 'headers' => $pageHeaders, 'data' => $pageData ];
}

// Prepare the output.
$outputData = [ 'dictionary' => '', 'forms' => [], 'arms' => '', 'events' => '', 'eventforms' => '',
                'fdl' => '', 'dataquality' => '', 'alerts' => '', 'roles' => '' ];

// Get the data dictionary and instrument names.
$dictionary = getPage( '/Design/data_dictionary_download.php?delimiter=,' );
if ( substr( $dictionary['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['dictionary'] = $dictionary['data'];
}
$outputData['forms'] = \REDCap::getInstrumentNames();

// Get the arms and events.
$arms = getPage( '/Design/arm_download.php' );
if ( substr( $arms['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['arms'] = $arms['data'];
}
$events = getPage( '/Design/event_download.php' );
if ( substr( $events['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['events'] = $events['data'];
}
$eventforms = getPage( '/Design/instrument_event_mapping_download.php' );
if ( substr( $eventforms['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['eventforms'] = $eventforms['data'];
}

// Get the form display logic.
$fdl = getPage( '/Design/online_designer.php?FormDisplayLogicSetup-export=' );
if ( substr( $fdl['headers']['content-type'], 0, 24 ) == 'application/octet-stream' )
{
	$outputData['fdl'] = $fdl['data'];
}

// Get the data quality rules.
$dataquality = getPage( '/DataQuality/download_dq_rules.php' );
if ( substr( $dataquality['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['dataquality'] = $dataquality['data'];
}

// Get the alerts.
$alerts = getPage( '/index.php?route=AlertsController:downloadAlerts' );
if ( substr( $alerts['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['alerts'] = $alerts['data'];
}

// Get the user roles.
$roles = getPage( '/UserRights/import_export_roles.php?action=download' );
if ( substr( $roles['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['roles'] = $roles['data'];
}

if ( isset( $_GET['returnfunction'] ) )
{
	header( 'Content-Type: text/javascript' );
	echo 'clientPDFEResponse(',
	     json_encode( base64_encode( json_encode( $outputData, JSON_UNESCAPED_SLASHES ) ) ),
	     ')';
}
else
{
	header( 'Content-Type: application/json' );
	echo json_encode( $outputData, JSON_UNESCAPED_SLASHES );
}
