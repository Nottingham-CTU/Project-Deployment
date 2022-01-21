<?php

namespace Nottingham\ProjectDeployment;

class ProjectDeployment extends \ExternalModules\AbstractExternalModule
{
	// Always show module links.
	function redcap_module_link_check_display( $project_id, $link )
	{
		return $link;
	}



	// Always show module 'configure' button (this is the default).
	function redcap_module_configure_button_display( $project_id )
	{
		return true;
	}



	// The following functions are hooks, which allow code to be injected into REDCap pages.
	function redcap_every_page_before_render( $project_id = null )
	{
	}



	function redcap_every_page_top( $project_id )
	{
	}



	// Determine whether the user is allowed to export the project.

	function canExportProject( $project_id )
	{
		// TODO: Add role based logic here.
		return true;
	}



	// Given an arm ID and project ID, return the corresponding arm number.

	public function getArmNumFromID( $armID, $projectID )
	{
		if ( $armID == '' )
		{
			return $armID;
		}
		$this->loadArmIDsNums( $projectID );
		return $this->listArmIDsNums[ $projectID ][ $armID ];
	}



	// Given an arm number and project ID, return the corresponding arm ID.

	public function getArmIDFromNum( $armNum, $projectID )
	{
		if ( $armNum == '' )
		{
			return $armNum;
		}
		$this->loadArmIDsNums( $projectID );
		return strval( array_search( $armNum, $this->listArmIDsNums[ $projectID ] ) );
	}



	// Given an event ID and project ID, return the corresponding unique event name.

	public function getEventNameFromID( $eventID, $projectID )
	{
		if ( $eventID == '' )
		{
			return $eventID;
		}
		$this->loadUniqueEventNames( $projectID );
		return $this->listUniqueEventNames[ $projectID ][ $eventID ];
	}



	// Given a unique event name and project ID, return the corresponing event ID.

	public function getEventIDFromName( $eventName, $projectID )
	{
		if ( $eventName == '' )
		{
			return $eventName;
		}
		$this->loadUniqueEventNames( $projectID );
		return strval( array_search( $eventName, $this->listUniqueEventNames[ $projectID ] ) );
	}



	// Given a role ID and project ID, return the corresponding role name.

	public function getRoleNameFromID( $roleID, $projectID )
	{
		if ( $roleID == '' )
		{
			return $roleID;
		}
		$this->loadRoleNames( $projectID );
		return $this->listRoleNames[ $projectID ][ $roleID ];
	}



	// Given a role name and project ID, return the corresponding role ID.

	public function getRoleIDFromName( $roleName, $projectID )
	{
		if ( $roleName == '' )
		{
			return $roleName;
		}
		$this->loadRoleNames( $projectID );
		return strval( array_search( $roleName, $this->listRoleNames[ $projectID ] ) );
	}



	// Given a project ID, return the corresponding project name.

	public function getProjectNameFromID( $projectID )
	{
		if ( $projectID == '' )
		{
			return $projectID;
		}
		$this->loadProjectNames();
		return $this->listProjectNames[ $projectID ];
	}



	// Given a project name, return the corresponding project ID.
	// (If multiple projects have the same name, the first one found will be returned.)

	public function getProjectIDFromName( $projectName )
	{
		if ( $projectName == '' )
		{
			return $projectName;
		}
		$this->loadProjectNames();
		return strval( array_search( $projectName, $this->listProjectNames ) );
	}



	// Get a list of all config fields for export, given the project-settings section of an external
	// module configuration. This will exclude descriptive fields (as these have no value), DAG
	// fields (as DAGs are assumed to differ between REDCap instances) and file fields (as files
	// could be too large to transfer).

	public function getModuleConfigFields( $listConfig )
	{
		$listFields = [];
		foreach ( $listConfig as $infoConfig )
		{
			if ( $infoConfig['type'] != 'descriptive' && $infoConfig['type'] != 'dag-list' &&
			     $infoConfig['type'] != 'file' )
			{
				$listFields[ $infoConfig['key'] ] = $infoConfig['type'];
			}
			if ( $infoConfig['type'] == 'sub_settings' )
			{
				$listFields += $this->getModuleConfigFields( $infoConfig['sub_settings'] );
			}
		}
		return $listFields;
	}



	// Get a list of all the modules for which the project settings are to be exported.

	public function getModulesForExport( $projectID )
	{
		$listModules = array_keys( $this->getEnabledModules( $projectID ) );
		$listExclude = preg_split( "/\r\n|\n|\r/", $this->getSystemSetting( 'exclude-modules' ),
		                           -1, PREG_SPLIT_NO_EMPTY );
		$listExclude[] = preg_replace( '/_[^_]*$/', '', $this->getModuleDirectoryName() );
		$listModules = array_diff( $listModules, $listExclude );
		return $listModules;
	}



	// Get the settings for the specified module and project.

	public function getSettingsForModule( $prefix, $projectID )
	{
		// Get the instance of the module, and check if it has an exportProjectSettings function.
		// If it does, just return the output of this function.
		$module = \ExternalModules\ExternalModules::getModuleInstance( $prefix );
		if ( method_exists( $module, 'exportProjectSettings' ) )
		{
			return $module->exportProjectSettings( $projectID );
		}
		// If the module does not define an exportProjectSettings function, then just return all the
		// project settings (excluding descriptive, dag-list and file fields), after the arm IDs,
		// event IDs, project IDs and role IDs are converted to exportable values.
		$transformSettings = [ 'arm-list' => 'getArmNumFromID',
		                       'event-list' => 'getEventNameFromID',
		                       'project-id' => 'getProjectNameFromID',
		                       'user-role-list' => 'getRoleNameFromID' ];
		$transformTypes = array_keys( $transformSettings );
		$moduleConfig = $module->getConfig();
		$moduleSettings = [];
		if ( isset( $moduleConfig['project-settings'] ) )
		{
			// If the module has project settings, get the field names and types.
			$moduleSettings = $this->getModuleConfigFields( $moduleConfig['project-settings'] );
		}
		$exportSettings = [];
		foreach ( $moduleSettings as $setting => $type )
		{
			// Get the setting value.
			$settingValue = $module->getProjectSetting( $setting, $projectID );
			// If conversion to an exportable value is required, perform the conversion.
			if ( in_array( $type, $transformTypes ) )
			{
				$transformFunction = $transformSettings[ $type ];
				// Where the value is an array (because it is within a sub-settings block), perform
				// the conversion on each leaf node of the array.
				if ( is_array( $settingValue ) )
				{
					array_walk_recursive( $settingValue,
						function( &$val ) use ( $projectID, $transformFunction )
						{
							$val = $this->{$transformFunction}( $val, $projectID );
						}
					);
				}
				// Otherwise, just convert the value.
				else
				{
					$settingValue = $this->{$transformFunction}( $settingValue, $projectID );
				}
			}
			// Add the value to the list of settings for export.
			$exportSettings[$setting] = $settingValue;
		}
		// Return the exported settings.
		return $exportSettings;
	}



	// Load arm ID -- arm number mapping for a project.

	private function loadArmIDsNums( $projectID )
	{
		if ( isset( $this->listArmIDsNums[ $projectID ] ) )
		{
			return;
		}
		$result = $this->query( 'SELECT arm_id, arm_num FROM redcap_events_arms ' .
		                        'WHERE project_id = ?', [ $projectID ] );
		$this->listArmIDsNums[ $projectID ] = [];
		while ( $row = $result->fetch_assoc() )
		{
			$row = $this->convertIntsToStrings( $row );
			$this->listArmIDsNums[ $projectID ][ $row['arm_id'] ] = $row['arm_num'];
		}
	}



	// Load project ID -- project name mapping.

	private function loadProjectNames()
	{
		if ( !empty( $this->listProjectNames ) )
		{
			return;
		}
		$result = $this->query( 'SELECT project_id, app_title FROM redcap_projects', [] );
		$this->listProjectNames = [];
		while ( $row = $result->fetch_assoc() )
		{
			$row = $this->convertIntsToStrings( $row );
			$this->listProjectNames[ $row['project_id'] ] = $row['app_title'];
		}
	}



	// Load role ID -- role name mapping for a project.

	private function loadRoleNames( $projectID )
	{
		if ( isset( $this->listRoleNames[ $projectID ] ) )
		{
			return;
		}
		$result = $this->query( 'SELECT role_id, role_name FROM redcap_user_roles ' .
		                        'WHERE project_id = ?', [ $projectID ] );
		$this->listRoleNames[ $projectID ] = [];
		while ( $row = $result->fetch_assoc() )
		{
			$row = $this->convertIntsToStrings( $row );
			$this->listRoleNames[ $projectID ][ $row['role_id'] ] = $row['role_name'];
		}
	}



	// Load event ID -- unique event name mapping for a project.

	private function loadUniqueEventNames( $projectID )
	{
		if ( isset( $this->listUniqueEventNames[ $projectID ] ) )
		{
			return;
		}
		$obProject = new \Project( $projectID );
		$this->listUniqueEventNames[ $projectID ] = $obProject->getUniqueEventNames();
	}

	private $listArmIDsNums;
	private $listProjectNames;
	private $listRoleNames;
	private $listUniqueEventNames;

}
