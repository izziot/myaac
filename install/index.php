<?php

use Twig\Environment as Twig_Environment;
use Twig\Loader\FilesystemLoader as Twig_FilesystemLoader;

const MYAAC_INSTALL = true;

require '../common.php';

// includes
require SYSTEM . 'functions.php';
require BASE . 'install/includes/functions.php';
require BASE . 'install/includes/locale.php';
require SYSTEM . 'clients.conf.php';

// ignore undefined index from Twig autoloader
$config['env'] = 'prod';

$twig_loader = new Twig_FilesystemLoader(SYSTEM . 'templates');
$twig = new Twig_Environment($twig_loader, array(
	'cache' => CACHE . 'twig/',
	'auto_reload' => true
));

// load installation status
$step = $_REQUEST['step'] ?? 'welcome';

$install_status = array();
if(file_exists(CACHE . 'install.txt')) {
	$install_status = unserialize(file_get_contents(CACHE . 'install.txt'));

	if(!isset($_REQUEST['step'])) {
		$step = isset($install_status['step']) ? $install_status['step'] : '';
	}
}

if(isset($_POST['vars']))
{
	foreach($_POST['vars'] as $key => $value) {
		$_SESSION['var_' . $key] = $value;
		$install_status[$key] = $value;
	}
}
else {
	foreach($install_status as $key => $value) {
		$_SESSION['var_' . $key] = $value;
	}
}

if($step == 'finish' && (!isset($config['installed']) || !$config['installed'])) {
	$step = 'welcome';
}

// step verify
$steps = array(1 => 'welcome', 2 => 'license', 3 => 'requirements', 4 => 'config', 5 => 'database', 6 => 'admin', 7 => 'finish');
if(!in_array($step, $steps)) // check if step is valid
	throw new RuntimeException('ERROR: Unknown step.');

$install_status['step'] = $step;
$errors = array();

if($step == 'database') {
	// Handle server configuration from UI - clone repository if needed
	// Improved Docker detection - check multiple indicators
	$isDocker = getenv('DOCKER_BUILD') === '1' || 
				file_exists('/.dockerenv') || 
				strpos(getenv('HOSTNAME') ?: '', 'docker') !== false ||
				strpos(getenv('HOSTNAME') ?: '', 'myaac') !== false;
	
	// Check if we're using multi-server UI configuration (forces Docker-like behavior)
	$usingMultiServer = !empty($_SESSION['var_servers']) && is_array($_SESSION['var_servers']);
	
	// If using multi-server UI, treat as Docker mode even if not in container
	$isInstallMode = $isDocker || $usingMultiServer;
	
	// Check if we need to clone repositories (Docker mode or multi-server UI)
	// Support both new array format (var_servers) and legacy individual variables
	$servers = $_SESSION['var_servers'] ?? [];
	
	// If no servers array, create from legacy individual variables for backward compatibility
	if (empty($servers) && !empty($_SESSION['var_git_repo'])) {
		$servers = [[
			'name' => $_SESSION['var_server_name'] ?? 'default',
			'git_repo' => $_SESSION['var_git_repo'],
			'git_branch' => $_SESSION['var_git_branch'] ?? 'main',
			'config_path' => $_SESSION['var_config_path'] ?? 'config/config.lua',
			'data_path' => $_SESSION['var_data_path'] ?? 'data/'
		]];
	}
	
	$cloneErrors = [];
	
	// Clone each server repository (Docker mode or multi-server UI)
	if ($isInstallMode && !empty($servers)) {
		$sshKey = !empty($_SESSION['var_ssh_private_key']) ? $_SESSION['var_ssh_private_key'] : getenv('MYAAC_CANARY_REPO_KEY');
		
		foreach ($servers as $server) {
			$serverName = !empty($server['name']) ? $server['name'] : 'default';
			$gitRepo = !empty($server['git_repo']) ? $server['git_repo'] : '';
			$gitBranch = !empty($server['git_branch']) ? $server['git_branch'] : 'main';
			$configPath = !empty($server['config_path']) ? $server['config_path'] : 'config/config.lua';
			$dataPath = !empty($server['data_path']) ? $server['data_path'] : 'data/';
			
			if (empty($gitRepo)) {
				continue;
			}
			
			$serverPath = '/srv/servers/' . $serverName . '/';
			
			// Check if we already cloned this server
			if (!file_exists($serverPath . $configPath)) {
				// Need to clone the repository
				$cloneError = null;
				
				// Setup SSH key if provided
				if (!empty($sshKey)) {
					$sshDir = '/tmp/ssh_' . uniqid();
					mkdir($sshDir, 0700, true);
					file_put_contents($sshDir . '/id_rsa', $sshKey);
					chmod($sshDir . '/id_rsa', 0600);
					putenv('HOME=' . $sshDir);
					
					// Create SSH config
					$sshConfig = "Host github.com\n    HostName github.com\n    User git\n    IdentityFile {$sshDir}/id_rsa\n    StrictHostKeyChecking no\n";
					file_put_contents($sshDir . '/config', $sshConfig);
				}
				
				// Clone with sparse checkout
				$cloneCommands = array();
				$cloneCommands[] = "mkdir -p /srv/servers";
				$cloneCommands[] = "git clone --depth=1 --branch '" . escapeshellcmd($gitBranch) . "' --sparse '" . escapeshellcmd($gitRepo) . "' " . escapeshellcmd($serverPath);
				$cloneCommands[] = "cd " . escapeshellcmd($serverPath) . " && git sparse-checkout set " . escapeshellcmd($configPath) . " " . escapeshellcmd($dataPath);
				
				$fullCommand = implode(' && ', $cloneCommands);
				
				if (!empty($sshKey)) {
					$fullCommand = 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no" ' . $fullCommand;
				}
				
				$output = array();
				$returnCode = 0;
				exec($fullCommand . ' 2>&1', $output, $returnCode);
				
				// Cleanup SSH temp files
				if (!empty($sshKey) && !empty($sshDir)) {
					exec("rm -rf " . escapeshellcmd($sshDir));
				}
				
				if ($returnCode !== 0 || !file_exists($serverPath . $configPath)) {
					$cloneError = "Failed to clone repository for server '{$serverName}'. Output: " . implode("\n", $output);
					$cloneErrors[] = $cloneError;
					error_log($cloneError);
				}
			}
		}
	}
	
	// Store clone errors in session to display to user
	if (!empty($cloneErrors)) {
		$_SESSION['clone_errors'] = $cloneErrors;
	}
	
	// ALWAYS set var_server_path from servers array (first server as default)
	// This ensures the installation can proceed even outside Docker mode
	if (!isset($_SESSION['var_server_path']) && !empty($servers)) {
		$firstServer = reset($servers);
		$serverName = !empty($firstServer['name']) ? $firstServer['name'] : 'default';
		$_SESSION['var_server_path'] = '/srv/servers/' . $serverName . '/';
	}
	
	// Validate required fields
	foreach($_SESSION as $key => $value) {
		if(strpos($key, 'var_') === false) {
			continue;
		}

		$key = str_replace('var_', '', $key);

		if(in_array($key, array('account', 'account_id', 'password', 'password_confirm', 'email', 'player_name', 'ssh_private_key'))) {
			continue;
		}

		// Skip validation for optional fields in Docker mode or multi-server UI
		if ($isInstallMode && in_array($key, array('config_path', 'data_path', 'servers'))) {
			continue;
		}
		
		// Skip servers array validation (handled separately)
		if ($key === 'servers') {
			continue;
		}

		if($key != 'usage' && empty($value)) {
			$errors[] = $locale['please_fill_all'];
			break;
		}
		else if($key == 'server_path') {
			$config['server_path'] = $value;

			// take care of trailing slash at the end
			if($config['server_path'][strlen($config['server_path']) - 1] != '/') {
				$config['server_path'] .= '/';
			}

			// Determine config file name from servers array (first server) or legacy var
			$configFileName = 'config.lua';
			if (!empty($servers)) {
				$firstServer = reset($servers);
				$configFileName = !empty($firstServer['config_path']) ? $firstServer['config_path'] : 'config.lua';
			} else if (!empty($_SESSION['var_config_path'])) {
				$configFileName = $_SESSION['var_config_path'];
			}
			
			// Extract just the filename from config_path if it's a path
			if (strpos($configFileName, '/') !== false) {
				$configFileName = basename($configFileName);
			}

			// In Docker mode or multi-server UI, skip config file validation if path doesn't exist yet (will be cloned)
			if (!$isInstallMode || file_exists($config['server_path'] . $configFileName)) {
				// OK - file exists or we're not in Docker mode
			} else {
				// In Docker mode or multi-server UI, config will be cloned later, skip validation
				continue;
			}
		}
		else if($key == 'timezone' && !in_array($value, DateTimeZone::listIdentifiers())) {
			$errors[] = $locale['step_config_timezone_error'];
			break;
		}
		else if($key == 'client' && !in_array($value, $config['clients'])) {
			$errors[] = $locale['step_config_client_error'];
			break;
		}
	}

	if(!empty($errors)) {
		$step = 'config';
	}
}
else if($step == 'admin') {
	if(!file_exists(BASE . 'config.local.php') || !isset($config['installed']) || !$config['installed']) {
		$step = 'database';
	}
	else {
		$_SESSION['saved'] = true;
	}
}
else if($step == 'finish') {
	$email = $_SESSION['var_email'];
	$password = $_SESSION['var_password'];
	$password_confirm = $_SESSION['var_password_confirm'];
	$player_name = $_SESSION['var_player_name'] ?? null;

	// email check
	if(empty($email)) {
		$errors[] = $locale['step_admin_email_error_empty'];
	}
	else if(!Validator::email($email)) {
		$errors[] = $locale['step_admin_email_error_format'];
	}

	// account check
	if(isset($_SESSION['var_account_id'])) {
		if(empty($_SESSION['var_account_id'])) {
			$errors[] = $locale['step_admin_account_id_error_empty'];
		}
		else if(!Validator::accountId($_SESSION['var_account_id'])) {
			$errors[] = $locale['step_admin_account_id_error_format'];
		}
		else if($_SESSION['var_account_id'] == $password) {
			$errors[] = $locale['step_admin_account_id_error_same'];
		}
	}
	else if(isset($_SESSION['var_account'])) {
		if(empty($_SESSION['var_account'])) {
			$errors[] = $locale['step_admin_account_error_empty'];
		}
		else if(!Validator::accountName($_SESSION['var_account'])) {
			$errors[] = $locale['step_admin_account_error_format'];
		}
		else if(strtoupper($_SESSION['var_account']) == strtoupper($password)) {
			$errors[] = $locale['step_admin_account_error_same'];
		}
	}

	// password check
	if(empty($password)) {
		$errors[] = $locale['step_admin_password_error_empty'];
	}
	else if(!Validator::password($password)) {
		$errors[] = $locale['step_admin_password_error_format'];
	}
	else if($password != $password_confirm) {
		$errors[] = $locale['step_admin_password_confirm_error_not_same'];
	}

	if (isset($player_name)) {
		// player name check
		if (empty($player_name)) {
			$errors[] = $locale['step_admin_player_name_error_empty'];
		} else if (!Validator::characterName($player_name)) {
			$errors[] = $locale['step_admin_player_name_error_format'];
		}
	}

	if(!empty($errors)) {
		$step = 'admin';
	}
}

if(empty($errors)) {
	file_put_contents(CACHE . 'install.txt', serialize($install_status));
}

$error = false;

clearstatcache();
if(is_writable(CACHE) && (MYAAC_OS != 'WINDOWS' || win_is_writable(CACHE))) {
	// Skip IP check when running in Docker or using multi-server UI
	$isDockerCheck = getenv('DOCKER_BUILD') === '1' || 
					 file_exists('/.dockerenv') || 
					 strpos(getenv('HOSTNAME') ?: '', 'docker') !== false ||
					 strpos(getenv('HOSTNAME') ?: '', 'myaac') !== false;
	
	$usingMultiServer = !empty($_SESSION['var_servers']) && is_array($_SESSION['var_servers']);
	$isInstallMode = $isDockerCheck || $usingMultiServer;
	
	// Show clone errors if any
	if (!empty($_SESSION['clone_errors'])) {
		foreach ($_SESSION['clone_errors'] as $cloneError) {
			$errors[] = $cloneError;
		}
		$step = 'config';
	}
	
	if(!$isInstallMode && !file_exists(BASE . 'install/ip.txt')) {
		$content = warning('AAC installation is disabled. To enable it make file <b>ip.txt</b> in install/ directory and put there your IP.<br/>
		Your IP is:<br /><b>' . get_browser_real_ip() . '</b>', true);
	}
	else if(!$isInstallMode) {
		$file_content = trim(file_get_contents(BASE . 'install/ip.txt'));
		$allow = false;
		$listIP = preg_split('/\s+/', $file_content);
		foreach($listIP as $ip) {
			if(get_browser_real_ip() == $ip) {
				$allow = true;
			}
		}

		if(!$allow)
		{
			$content = warning('In file <b>install/ip.txt</b> must be your IP!<br/>
			In file is:<br /><b>' . nl2br($file_content) . '</b><br/>
			Your IP is:<br /><b>' . get_browser_real_ip() . '</b>', true);
		}
		else {
			ob_start();

			$step_id = array_search($step, $steps);
			require 'steps/' . $step_id . '-' . $step . '.php';
			$content = ob_get_contents();
			ob_end_clean();
		}
	}
	else if($isInstallMode) {
		// Running in Docker or using multi-server UI - skip IP check and proceed with installation
		ob_start();

		$step_id = array_search($step, $steps);
		require 'steps/' . $step_id . '-' . $step . '.php';
		$content = ob_get_contents();
		ob_end_clean();
	}
}
else {
	$content = error(file_get_contents(BASE . 'install/includes/twig_error.html'), true);
}

// render
require 'template/template.php';
//$_SESSION['laststep'] = $step;
