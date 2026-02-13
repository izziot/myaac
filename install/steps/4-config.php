<?php
defined('MYAAC') or die('Direct access not allowed!');

$clients = array();
foreach($config['clients'] as $client) {
	$client_version = (string)($client / 100);
	if(strpos($client_version, '.') == false)
		$client_version .= '.0';

	$clients[$client] = $client_version;
}

if (empty($_SESSION['var_site_url'])) {
	//require SYSTEM . 'base.php';
	$serverUrl = 'http' . (isHttps() ? 's' : '') . '://' . $baseHost;
	$siteURL = $serverUrl . $baseDir;

	$_SESSION['var_site_url'] = $siteURL;
}

// Docker mode detection
$isDocker = getenv('DOCKER_BUILD') === '1' || file_exists('/.dockerenv');

// Default values for server configuration - convert to servers array format
if (!isset($_SESSION['var_servers']) || empty($_SESSION['var_servers'])) {
	$_SESSION['var_servers'] = [[
		'name' => $_SESSION['var_server_name'] ?? 'sovereign',
		'git_repo' => $_SESSION['var_git_repo'] ?? 'git@github.com:izziot/canary.git',
		'git_branch' => $_SESSION['var_git_branch'] ?? 'test/sovereign',
		'config_path' => $_SESSION['var_config_path'] ?? 'config/config.sovereign.lua',
		'data_path' => $_SESSION['var_data_path'] ?? 'data/'
	]];
}

$twig->display('install.config.html.twig', array(
	'clients' => $clients,
	'timezones' => DateTimeZone::listIdentifiers(),
	'locale' => $locale,
	'session' => $_SESSION,
	'errors' => isset($errors) ? $errors : null,
	'buttons' => next_buttons(),
	'is_docker' => $isDocker,
	'show_ssh_key_env' => !empty(getenv('MYAAC_CANARY_REPO_KEY')),
	'servers' => $_SESSION['var_servers']
));
