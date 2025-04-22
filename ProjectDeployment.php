<?php

namespace Nottingham\ProjectDeployment;

class ProjectDeployment extends \ExternalModules\AbstractExternalModule
{
	// Always show module links and module 'configure' button if the user has access.
	function redcap_module_link_check_display( $project_id, $link )
	{
		return $this->canAccessDeployment( $project_id ) ? $link : null;
	}


	function redcap_module_configure_button_display()
	{
		return $this->canAccessDeployment( $this->getProjectId() );
	}



	// Determine whether the user is allowed to access project deployment.

	function canAccessDeployment( $project_id )
	{
		// If module specific rights enabled, show link based on this.
		if ( $this->getSystemSetting( 'config-require-user-permission' ) == 'true' )
		{
			return in_array( preg_replace( '/_[^_]*$/', '', $this->getModuleDirectoryName() ),
			                 $this->getUser()->getRights()['external_module_config'] );
		}

		// Otherwise show link based on project setup/design rights.
		return $this->getUser()->hasDesignRights();
	}



	// Convert CSV data to an array.

	public function csvToArray( $csv )
	{
		$headers = [];
		$array = [];
		$fp = fopen( 'php://memory', 'r+b' );
		fwrite( $fp, $csv );
		fseek( $fp, 0 );
		while ( ( $line = fgetcsv( $fp, 0, ',', '"', '' ) ) !== false )
		{
			if ( empty( $headers ) )
			{
				$headers = $line;
				continue;
			}
			$item = [];
			foreach ( $line as $i => $val )
			{
				$item[ $headers[ $i ] ] = $val;
			}
			$array[] = $item;
		}
		fclose( $fp );
		return $array;
	}

	// Convert array to CSV.

	public function arrayToCsv( $array )
	{
		$headers = array_keys( $array[0] );
		$fp = fopen( 'php://memory', 'r+b' );
		fputcsv( $fp, $headers, ',', '"', '', "\n" );
		for ( $i = 0; $i < count( $array ); $i++ )
		{
			fputcsv( $fp, $array[ $i ], ',', '"', '', "\n" );
		}
		fseek( $fp, 0 );
		$csv = '';
		while ( ! feof( $fp ) )
		{
			$csv .= fread( $fp, 1024 );
		}
		fclose( $fp );
		return $csv;
	}



	// Convert a URL to an uploaded file within the project to a hashed representation.
	// If not an uploaded file, the URL is returned unchanged.

	public function fileUrlToFileHash( $url )
	{
		static $mapHash = [];
		if ( array_key_exists( $url, $mapHash ) )
		{
			return $mapHash[ $url ];
		}
		$pregWebroot = preg_quote( $_SERVER['HTTP_HOST'] . APP_PATH_WEBROOT, '!' );
		$pregWebroot = preg_replace( '!/(redcap_v[0-9]+\\\.[0-9]+\\\.[0-9]+)/!',
		                             '/($1|redcap)/', $pregWebroot );
		if ( ! preg_match( '!^(https?://' .
		                   preg_quote( $_SERVER['HTTP_HOST'] . APP_PATH_SURVEY, '!' ) . '|' .
		                   preg_quote( APP_PATH_SURVEY_FULL, '!' ) . ')\?__file=!', $url ) &&
		     ! preg_match( '!^https?://' . $pregWebroot .
		                   'DataEntry/(image_view|file_download)\.php\?!', $url ) )
		{
			// Not a file url, return the url unchanged.
			$mapHash[ $url ] = $url;
			return $mapHash[ $url ];
		}
		// If the URL is the survey endpoint, get the file ID from the hash.
		if ( strpos( $url, '/surveys/?__file=' ) )
		{
			preg_match( '!/surveys/\?__file=([^&]*)!', $url, $matches );
			$fileAttrs = \FileRepository::getFileByHash( $matches[1] );
			if ( $fileAttrs === false )
			{
				$mapHash[ $url ] = $url;
				return $mapHash[ $url ];
			}
			$fileID = $fileAttrs['doc_id'];
		}
		// Otherwise, get the file ID from the query parameter.
		else
		{
			preg_match( '!(image_view|file_download)\.php\?([^&]+&)?id=([^&]*)!', $url, $matches );
			if ( $matches[3] == '' )
			{
				$mapHash[ $url ] = $url;
				return $mapHash[ $url ];
			}
			$fileID = $matches[3];
		}
		$fileAttrs = \Files::getEdocContentsAttributes( $fileID );
		if ( $fileAttrs === false )
		{
			$mapHash[ $url ] = $url;
			return $mapHash[ $url ];
		}
		// The file has been found, return a URI which is the hash of the file contents.
		$mapHash[ $url ] = 'data:' . $fileAttrs[0] . ';sha1,' . sha1( $fileAttrs[2] );
		return $mapHash[ $url ];
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
			if ( $infoConfig['type'] == 'sub_settings' )
			{
				$listFields += $this->getModuleConfigFields( $infoConfig['sub_settings'] );
			}
			elseif ( $infoConfig['type'] != 'descriptive' && $infoConfig['type'] != 'dag-list' &&
			         $infoConfig['type'] != 'file' )
			{
				$listFields[ $infoConfig['key'] ] = $infoConfig['type'];
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
		sort( $listModules );
		return $listModules;
	}



	// Get the report namespaces if defined in the REDCap UI Tweaker module.

	public function getReportNamespaces( $projectID )
	{
		$listModules = array_keys( $this->getEnabledModules( $projectID ) );
		if ( ! in_array( 'redcap_ui_tweaker', $listModules ) )
		{
			return [];
		}
		$uiTweaker = \ExternalModules\ExternalModules::getModuleInstance( 'redcap_ui_tweaker' );
		$listNamespaces = $uiTweaker->getProjectSetting( 'report-namespace-name', $projectID );
		for ( $i = 0, $n = count( $listNamespaces ); $i < $n; $i++ )
		{
			if ( $listNamespaces[$i] == '' )
			{
				unset( $listNamespaces[$i] );
			}
		}
		return array_values( $listNamespaces );
	}



	// Get the settings for the specified module and project.

	public function getSettingsForModule( $prefix, $projectID )
	{
		// Get the instance of the module, and check if it has an exportProjectSettings function.
		// If it does, just return the output of this function.
		$module = \ExternalModules\ExternalModules::getModuleInstance( $prefix );
		if ( method_exists( $module, 'exportProjectSettings' ) )
		{
			$moduleSettings = $module->exportProjectSettings( $projectID );
			$exportSettings = [];
			foreach ( $moduleSettings as $key => $setting )
			{
				if ( is_string( $key ) )
				{
					$exportSettings[ $key ] = $setting;
					continue;
				}
				if ( ! isset( $setting['key'] ) || ! isset( $setting['value'] ) )
				{
					continue;
				}
				if ( isset( $setting['type'] ) )
				{
					if ( $setting['type'] == 'boolean' )
					{
						$setting['value'] = ( $setting['value'] === '' || $setting['value'] === 'null'
						                      ? null : ( $setting['value'] == 'true' ) );
					}
					elseif ( $setting['type'] == 'integer' )
					{
						$setting['value'] = ( $setting['value'] === '' || $setting['value'] === 'null'
						                      ? null : intval( $setting['value'] ) );
					}
					elseif ( $setting['type'] == 'json' || $setting['type'] == 'json-array' )
					{
						$setting['value'] = json_decode( $setting['value'], true );
					}
				}
				$exportSettings[ $setting['key'] ] = $setting['value'];
			}
			return $exportSettings;
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
			// If the setting value is an array with a single value, just use the value.
			if ( is_array( $settingValue ) &&
			     count( $settingValue ) == 1 && array_key_exists( 0, $settingValue ) )
			{
				$settingValue = $settingValue[0];
			}
			// Add the value to the list of settings for export.
			$exportSettings[$setting] = $settingValue;
		}
		// Return the exported settings.
		return $exportSettings;
	}



	// Retrieve page headers and content.

	public function getPage( $path )
	{
		$path .= ( ( strpos( $path, '?' ) === false ) ? '?' : '&' ) . 'pid=' . $this->getProjectId();
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
		\System::generateCsrfToken();
		return [ 'headers' => $pageHeaders, 'data' => $pageData ];
	}



	// Submit data to page and retrieve page headers and content.

	public function postPage( $path, $data, $formData = false )
	{
		if ( is_array( $data ) )
		{
			$data['redcap_csrf_token'] = \System::getCsrfToken();
			if ( ! $formData )
			{
				$data = http_build_query( $data, '', null, PHP_QUERY_RFC3986 );
			}
		}
		else
		{
			$data .= ( $data == '' ? '' : '&' );
			$data .= 'redcap_csrf_token=' . \System::getCsrfToken();
		}
		$path .= ( ( strpos( $path, '?' ) === false ) ? '?' : '&' ) . 'pid=' . $this->getProjectId();
		$url = 'https://' . SERVER_NAME . APP_PATH_WEBROOT . $path;
		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, $url );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $data );
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
		\System::generateCsrfToken();
		return [ 'headers' => $pageHeaders, 'data' => $pageData ];
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
