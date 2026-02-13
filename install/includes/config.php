<?php
defined('MYAAC') or die('Direct access not allowed!');

$isDocker = getenv('DOCKER_BUILD') === '1' || file_exists('/.dockerenv') || strpos(getenv('HOSTNAME') ?: '', 'docker') !== false;

// Check if we're using multi-server UI configuration
$usingMultiServer = !empty($_SESSION['var_servers']) && is_array($_SESSION['var_servers']);

if(!isset($_SESSION['var_server_path'])) {
	error($locale['step_database_error_config']);
	$error = true;
}

$config['server_path'] = $_SESSION['var_server_path'];
// take care of trailing slash at the end
if(isset($config['server_path']) && $config['server_path'][strlen($config['server_path']) - 1] != '/')
	$config['server_path'] .= '/';

// Get config filename from session - try servers array first, then legacy var
$configFileName = 'config.lua';
if (!empty($_SESSION['var_servers']) && is_array($_SESSION['var_servers'])) {
	$firstServer = reset($_SESSION['var_servers']);
	if (!empty($firstServer['config_path'])) {
		$configFileName = basename($firstServer['config_path']);
	}
} elseif (!empty($_SESSION['var_config_path'])) {
	$configFileName = basename($_SESSION['var_config_path']);
}

// Skip config.lua validation in Docker mode OR when using multi-server UI
// In these cases, config will be loaded from database after installation
$skipConfigValidation = $isDocker || $usingMultiServer;

if(!$skipConfigValidation && (!isset($error) || !$error) && isset($config['server_path']) && !file_exists($config['server_path'] . $configFileName)) {
	error($locale['step_database_error_config'] . ' Looking for: ' . $config['server_path'] . $configFileName);
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
		$locale['step_config_server_path_error_only_mysql'] = str_replace('$DATABASE_TYPE$', '<b>' . $config['database_type'] . '</b>', $locale['step_config_server_path_error_only_mysql'] ?? 'Only MySQL/MariaDB is supported. Your OTS uses: $DATABASE_TYPE$');
		error($locale['step_config_server_path_error_only_mysql']);
		$error = true;
	}
}
