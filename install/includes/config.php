<?php
defined('MYAAC') or die('Direct access not allowed!');

if(!isset($_SESSION['var_server_path'])) {
	// Check if in Docker mode and servers.json exists
	$isDocker = getenv('DOCKER_BUILD') === '1' || file_exists('/.dockerenv');
	if ($isDocker && file_exists(BASE . 'config/servers.json')) {
		$servers = json_decode(file_get_contents(BASE . 'config/servers.json'), true);
		if (!empty($servers['servers'])) {
			$firstServer = $servers['servers'][0];
			$_SESSION['var_server_path'] = '/srv/servers/' . $firstServer['id'] . '/';
		}
	}
	
	if (!isset($_SESSION['var_server_path'])) {
		error($locale['step_database_error_config']);
		$error = true;
	}
}

$config['server_path'] = $_SESSION['var_server_path'];
// take care of trailing slash at the end
if(isset($config['server_path']) && $config['server_path'][strlen($config['server_path']) - 1] != '/')
	$config['server_path'] .= '/';

// Get actual config.lua path from servers.json if in Docker mode
$configFileName = 'config.lua';
$isDocker = getenv('DOCKER_BUILD') === '1' || file_exists('/.dockerenv');
if ($isDocker && file_exists(BASE . 'config/servers.json')) {
	$servers = json_decode(file_get_contents(BASE . 'config/servers.json'), true);
	if (!empty($servers['servers'])) {
		$firstServer = $servers['servers'][0];
		if (!empty($firstServer['config_path'])) {
			$configFileName = $firstServer['config_path'];
		}
	}
}

if((!isset($error) || !$error) && isset($config['server_path']) && !file_exists($config['server_path'] . $configFileName)) {
	error($locale['step_database_error_config']);
	$error = true;
}

if(!isset($error) || !$error) {
	$config['lua'] = load_config_lua($config['server_path'] . $configFileName);
	if(isset($config['lua']['sqlType'])) // tfs 0.3
		$config['database_type'] = $config['lua']['sqlType'];
	else if(isset($config['lua']['mysqlHost'])) // tfs 0.2/1.0
		$config['database_type'] = 'mysql';
	else if(isset($config['lua']['database_type'])) // otserv
		$config['database_type'] = $config['lua']['database_type'];
	else if(isset($config['lua']['sql_type'])) // otserv
		$config['database_type'] = $config['lua']['sql_type'];
	else {
		$config['database_type'] = '';
	}

	$config['database_type'] = strtolower($config['database_type']);
	if(empty($config['database_type'])) {
		error($locale['step_database_error_database_empty']);
		$error = true;
	}
	else if($config['database_type'] != 'mysql') {
		$locale['step_database_error_only_mysql'] = str_replace('$DATABASE_TYPE$', '<b>' . $config['database_type'] . '</b>', $locale['step_database_error_only_mysql']);
		error($locale['step_database_error_only_mysql']);
		$error = true;
	}
}
