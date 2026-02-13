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

// Default values for server configuration
if (!isset($_SESSION['var_git_repo'])) {
	$_SESSION['var_git_repo'] = 'git@github.com:izziot/canary.git';
}
if (!isset($_SESSION['var_git_branch'])) {
	$_SESSION['var_git_branch'] = 'test/sovereign';
}
if (!isset($_SESSION['var_config_path'])) {
	$_SESSION['var_config_path'] = 'config/config.sovereign.lua';
}
if (!isset($_SESSION['var_data_path'])) {
	$_SESSION['var_data_path'] = 'data/';
}
if (!isset($_SESSION['var_server_name'])) {
	$_SESSION['var_server_name'] = 'sovereign';
}

$twig->display('install.config.html.twig', array(
	'clients' => $clients,
	'timezones' => DateTimeZone::listIdentifiers(),
	'locale' => $locale,
	'session' => $_SESSION,
	'errors' => isset($errors) ? $errors : null,
	'buttons' => next_buttons(),
	'is_docker' => $isDocker,
	'show_ssh_key_env' => !empty(getenv('MYAAC_CANARY_REPO_KEY'))
));
