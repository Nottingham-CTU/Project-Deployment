<?php

namespace Nottingham\ProjectDeployment;

// Do not allow exports where the user does not have the rights.
$projectID = $module->getProjectId();
if ( $projectID === null || ! $module->canAccessDeployment( $projectID ) )
{
	exit;
}

// Prepare the output.
$outputData = [ 'dictionary' => '', 'forms' => [], 'arms' => '', 'events' => '', 'eventforms' => '',
                'fdl' => '', 'dataquality' => '', 'alerts' => '', 'roles' => '' ];

// Get the data dictionary and instrument names.
$dictionary = $module->getPage( '/Design/data_dictionary_download.php?delimiter=,' );
if ( substr( $dictionary['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['dictionary'] =
			preg_replace_callback( '/((href|src)="")(http[^"]+)"/',
			                       function ( $m ) use ( $module )
			                       { return $m[1] . $module->fileUrlToFileHash( $m[3] ) . '"'; },
			                       $dictionary['data'] );
}
$outputData['forms'] = \REDCap::getInstrumentNames();

// Get the arms and events.
$arms = $module->getPage( '/Design/arm_download.php' );
if ( substr( $arms['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['arms'] = $arms['data'];
}
$events = $module->getPage( '/Design/event_download.php' );
if ( substr( $events['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['events'] = $events['data'];
}
$eventforms = $module->getPage( '/Design/instrument_event_mapping_download.php' );
if ( substr( $eventforms['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['eventforms'] = $eventforms['data'];
}

// Get the form display logic.
$fdl = $module->getPage( '/Design/online_designer.php?FormDisplayLogicSetup-export=' );
if ( substr( $fdl['headers']['content-type'], 0, 24 ) == 'application/octet-stream' )
{
	$outputData['fdl'] = $fdl['data'];
}

// Get the data quality rules.
$dataquality = $module->getPage( '/DataQuality/download_dq_rules.php' );
if ( substr( $dataquality['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['dataquality'] = $dataquality['data'];
}

// Get the alerts.
$alerts = $module->getPage( '/index.php?route=AlertsController:downloadAlerts' );
if ( substr( $alerts['headers']['content-type'], 0, 15 ) == 'application/csv' )
{
	$outputData['alerts'] = $alerts['data'];
}

// Get the user roles.
$roles = $module->getPage( '/UserRights/import_export_roles.php?action=download' );
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
