<?php

header( 'Content-Type: application/json' );
$projectID = $module->getProjectID();

// Do not allow exports where the user does not have the rights.
if ( $projectID === null || ! $module->canExportProject( $projectID ) )
{
	echo 'false';
	exit;
}

$exportData = [];



// Get the instrument-field mapping.
$instruments = REDCap::getInstrumentNames();
foreach ( $instruments as $instrument => $instrumentName )
{
	$exportData['instruments'][ $instrument ]['fields'] =
			$module->getFieldNames( $instrument, $projectID );
	$exportData['instruments'][ $instrument ]['name'] = $instrumentName;
}



// Get the external module settings exports.
$projectModules = $module->getModulesForExport( $projectID );

$exportData['external-modules'] = [];
foreach ( $projectModules as $projectModule )
{
	$exportData['external-modules'][ $projectModule ] =
			$module->getSettingsForModule( $projectModule, $projectID );
}



echo json_encode( $exportData );
