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

// Docker mode: auto-detect server_path from servers.json
$isDocker = getenv('DOCKER_BUILD') === '1' || file_exists('/.dockerenv');
if ($isDocker && empty($_SESSION['var_server_path'])) {
	$serversFile = BASE . 'config/servers.json';
	if (file_exists($serversFile)) {
		$servers = json_decode(file_get_contents($serversFile), true);
		if (!empty($servers['servers'])) {
			$firstServer = $servers['servers'][0];
			$_SESSION['var_server_path'] = '/srv/servers/' . $firstServer['id'] . '/';
		}
	}
}

$twig->display('install.config.html.twig', array(
	'clients' => $clients,
	'timezones' => DateTimeZone::listIdentifiers(),
	'locale' => $locale,
	'session' => $_SESSION,
	'errors' => isset($errors) ? $errors : null,
	'buttons' => next_buttons()
));

