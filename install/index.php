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
	// ============================================================
	// FASE 1.1: Debug - Recebendo dados do formulário
	// ============================================================
	error_log("=== DEBUG: Recebendo dados do formulário ===");
	error_log("POST vars keys: " . implode(', ', array_keys($_POST['vars'])));
	
	// Debug: Check for servers array specifically
	if (isset($_POST['vars']['servers'])) {
		$serversFromPost = $_POST['vars']['servers'];
		error_log("POST servers array count: " . count($serversFromPost));
		foreach ($serversFromPost as $idx => $server) {
			error_log("POST server[{$idx}]: name={$server['name']}, repo={$server['git_repo']}, branch={$server['git_branch']}");
		}
	}
	
	// Debug: Check for ssh_private_key
	if (isset($_POST['vars']['ssh_private_key'])) {
		$sshKeyLen = strlen($_POST['vars']['ssh_private_key']);
		error_log("POST ssh_private_key received: yes (length: {$sshKeyLen})");
	} else {
		error_log("POST ssh_private_key received: no");
	}
	
	foreach($_POST['vars'] as $key => $value) {
		$_SESSION['var_' . $key] = $value;
		$install_status[$key] = $value;
	}
	
	// Debug: Log session vars after setting
	error_log("Session var_servers set: " . (isset($_SESSION['var_servers']) ? 'yes - ' . count($_SESSION['var_servers']) . ' servers' : 'no'));
	error_log("Session var_ssh_private_key set: " . (isset($_SESSION['var_ssh_private_key']) ? 'yes' : 'no'));
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
		// ============================================================
		// FASE 1: Validação de Entrada
		// ============================================================
		error_log("=== DEBUG: FASE 1 - Validando dados de entrada ===");
		error_log("Number of servers configured: " . count($servers));
		
		// Check SSH key
		$sshKey = !empty($_SESSION['var_ssh_private_key']) ? $_SESSION['var_ssh_private_key'] : getenv('MYAAC_CANARY_REPO_KEY');
		
		error_log("SSH key source: " . (!empty($_SESSION['var_ssh_private_key']) ? 'SESSION' : (getenv('MYAAC_CANARY_REPO_KEY') ? 'ENV' : 'NONE')));
		
		// Debug: Check if git is available
		exec('git --version 2>&1', $gitVersionOutput, $gitVersionReturn);
		if ($gitVersionReturn !== 0) {
			$cloneErrors[] = "FATAL: Git is not available in the system.";
			error_log("DEBUG: Git not found. Return code: " . $gitVersionReturn);
		} else {
			error_log("DEBUG: Git version: " . implode(" ", $gitVersionOutput));
		}
		
		// ============================================================
		// FASE 2: Setup e Validação SSH
		// ============================================================
		error_log("=== DEBUG: FASE 2 - Setup SSH ===");
		
		$sshDir = null;
		if (!empty($sshKey)) {
			// 2.1: Validar formato da chave SSH
			$keyFirstLine = trim(explode("\n", $sshKey)[0] ?? '');
			$isValidKeyFormat = strpos($keyFirstLine, '-----BEGIN') !== false;
			
			error_log("SSH key format check: " . ($isValidKeyFormat ? "VALID" : "INVALID"));
			error_log("SSH key first line: " . $keyFirstLine);
			error_log("SSH key total length: " . strlen($sshKey));
			
			if (!$isValidKeyFormat) {
				$cloneErrors[] = "SSH key format is invalid. Expected to start with '-----BEGIN'.";
				error_log("ERROR: SSH key format invalid!");
			} else {
				// 2.2-2.4: Setup do diretório temporário
				$sshDir = '/tmp/ssh_' . uniqid();
				$mkdirResult = @mkdir($sshDir, 0700, true);
				error_log("SSH temp dir created: " . $sshDir . " (result: " . ($mkdirResult ? 'success' : 'failed') . ")");
				
				$keyWriteResult = @file_put_contents($sshDir . '/id_rsa', $sshKey);
				error_log("SSH key written: " . ($keyWriteResult ? "success ({$keyWriteResult} bytes)" : "failed"));
				
				@chmod($sshDir . '/id_rsa', 0600);
				@chmod($sshDir, 0700);
				$keyPerms = substr(sprintf('%o', fileperms($sshDir . '/id_rsa')), -4);
				$dirPerms = substr(sprintf('%o', fileperms($sshDir)), -4);
				error_log("Permissions - dir: {$dirPerms}, key: {$keyPerms}");
				
				// 2.5: Criar SSH config
				$sshConfig = "Host github.com\n    HostName github.com\n    User git\n    IdentityFile {$sshDir}/id_rsa\n    StrictHostKeyChecking no\n    IdentitiesOnly yes\n";
				@file_put_contents($sshDir . '/config', $sshConfig);
				putenv('HOME=' . $sshDir);
				error_log("SSH config written to {$sshDir}/config");
			}
		} else {
			error_log("WARNING: No SSH key provided, will try anonymous clone");
		}
		
		// ============================================================
		// FASE 3: Teste de Conectividade SSH
		// ============================================================
		error_log("=== DEBUG: FASE 3 - Teste de Conectividade SSH ===");
		
		if (!empty($sshKey) && !empty($sshDir)) {
			// 3.1: Testar conexão SSH
			$sshTestCmd = 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o IdentitiesOnly=yes" ssh -T git@github.com 2>&1';
			exec($sshTestCmd, $sshTestOutput, $sshTestReturn);
			$sshTestOutputStr = implode("\n", $sshTestOutput);
			error_log("SSH connection test return code: " . $sshTestReturn);
			error_log("SSH connection test output: " . $sshTestOutputStr);
			
			// GitHub retorna código 1 para "success" (porque não é shell interativo)
			// O output deve conter "successfully authenticated" ou "You've successfully authenticated"
			if (stripos($sshTestOutputStr, 'successfully authenticated') !== false || 
				stripos($sshTestOutputStr, 'Permission granted') !== false) {
				error_log("SSH AUTHENTICATION: SUCCESS");
			} elseif ($sshTestReturn === 0 || stripos($sshTestOutputStr, 'no such identity') === false) {
				error_log("SSH AUTHENTICATION: Possibly OK (GitHub response: " . $sshTestOutputStr . ")");
			} else {
				error_log("SSH AUTHENTICATION: FAILED - " . $sshTestOutputStr);
			}
		}
		
		// ============================================================
		// FASE 4: Validação do Repositório
		// ============================================================
		error_log("=== DEBUG: FASE 4 - Validação do Repositório ===");
		
		foreach ($servers as $serverIndex => $server) {
			$serverName = !empty($server['name']) ? $server['name'] : 'default';
			$gitRepo = !empty($server['git_repo']) ? $server['git_repo'] : '';
			$gitBranch = !empty($server['git_branch']) ? $server['git_branch'] : 'main';
			$configPath = !empty($server['config_path']) ? $server['config_path'] : 'config/config.lua';
			$dataPath = !empty($server['data_path']) ? $server['data_path'] : 'data/';
			
			error_log("=== Server #{$serverIndex}: {$serverName} ===");
			error_log("  Repo: {$gitRepo}");
			error_log("  Branch: {$gitBranch}");
			error_log("  Config path: {$configPath}");
			error_log("  Data path: {$dataPath}");
			
			if (empty($gitRepo)) {
				$cloneErrors[] = "Server '{$serverName}': Git repository URL is empty.";
				continue;
			}
			
			// 4.1: Verificar se repo existe usando git ls-remote
			$lsRemoteCmd = 'git ls-remote --heads ' . escapeshellcmd($gitRepo) . ' 2>&1';
			if (!empty($sshKey)) {
				$lsRemoteCmd = 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o IdentitiesOnly=yes" ' . $lsRemoteCmd;
			}
			exec($lsRemoteCmd, $lsRemoteOutput, $lsRemoteReturn);
			$lsRemoteOutputStr = implode("\n", $lsRemoteOutput);
			error_log("  git ls-remote return code: " . $lsRemoteReturn);
			error_log("  git ls-remote output: " . $lsRemoteOutputStr);
			
			if ($lsRemoteReturn !== 0 || empty($lsRemoteOutputStr)) {
				$cloneErrors[] = "Server '{$serverName}': Cannot access repository. Check URL and SSH key permissions.";
				error_log("  ERROR: Repository not accessible!");
				continue;
			}
			
			// 4.2: Verificar se branch existe
			$branchExists = false;
			foreach ($lsRemoteOutput as $line) {
				$parts = preg_split('/\s+/', $line);
				$refBranch = $parts[1] ?? '';
				$refBranch = str_replace('refs/heads/', '', $refBranch);
				if ($refBranch === $gitBranch) {
					$branchExists = true;
					break;
				}
			}
			error_log("  Branch '{$gitBranch}' exists: " . ($branchExists ? 'YES' : 'NO'));
			
			if (!$branchExists) {
				// Listar branches disponíveis
				$availableBranches = [];
				foreach ($lsRemoteOutput as $line) {
					$parts = preg_split('/\s+/', $line);
					$refBranch = $parts[1] ?? '';
					$availableBranches[] = str_replace('refs/heads/', '', $refBranch);
				}
				$cloneErrors[] = "Server '{$serverName}': Branch '{$gitBranch}' does not exist. Available: " . implode(', ', $availableBranches);
				error_log("  ERROR: Branch not found! Available branches: " . implode(', ', $availableBranches));
				continue;
			}
			
			// ============================================================
			// FASE 5: Clone com Debug
			// ============================================================
			error_log("=== DEBUG: FASE 5 - Executando Clone ===");
			
			$serverPath = '/srv/servers/' . $serverName . '/';
			error_log("  Target path: {$serverPath}");
			
			// Check if already cloned
			if (file_exists($serverPath . $configPath)) {
				error_log("  Already cloned, skipping");
				continue;
			}
			
			// 5.1: Criar diretório base
			$mkdirCmd = "mkdir -p /srv/servers 2>&1";
			exec($mkdirCmd, $mkdirOutput, $mkdirReturn);
			error_log("  mkdir result: " . ($mkdirReturn === 0 ? 'success' : 'failed - ' . implode(' ', $mkdirOutput)));
			
			// 5.2: Executar git clone
			$cloneCmd = 'git clone --depth=1 --branch "' . $gitBranch . '" --sparse "' . $gitRepo . '" ' . escapeshellcmd($serverPath) . ' 2>&1';
			if (!empty($sshKey)) {
				$cloneCmd = 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o IdentitiesOnly=yes" ' . $cloneCmd;
			}
			error_log("  Clone command: " . $cloneCmd);
			
			exec($cloneCmd, $cloneOutput, $cloneReturn);
			$cloneOutputStr = implode("\n", $cloneOutput);
			error_log("  Clone return code: " . $cloneReturn);
			error_log("  Clone output:\n" . $cloneOutputStr);
			
			// 5.3: Se clone succeeded, executar sparse-checkout
			if ($cloneReturn === 0 && file_exists($serverPath)) {
				$sparseCmd = 'cd ' . escapeshellcmd($serverPath) . ' && git sparse-checkout set --verbose ' . escapeshellcmd($configPath) . ' ' . escapeshellcmd($dataPath) . ' 2>&1';
				error_log("  Sparse-checkout command: " . $sparseCmd);
				
				exec($sparseCmd, $sparseOutput, $sparseReturn);
				$sparseOutputStr = implode("\n", $sparseOutput);
				error_log("  Sparse-checkout return code: " . $sparseReturn);
				error_log("  Sparse-checkout output:\n" . $sparseOutputStr);
			}
			
			// ============================================================
			// FASE 6: Verificação de Resultado
			// ============================================================
			error_log("=== DEBUG: FASE 6 - Verificação ===");
			
			// 6.1: Verificar config.lua
			$configFullPath = $serverPath . $configPath;
			$configExists = file_exists($configFullPath);
			error_log("  Config file ({$configPath}) exists at {$serverPath}: " . ($configExists ? 'YES' : 'NO'));
			
			// 6.2: Listar arquivos baixados
			if (is_dir($serverPath)) {
				$downloadedFiles = [];
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator($serverPath, RecursiveDirectoryIterator::SKIP_DOTS),
					RecursiveIteratorIterator::SELF_FIRST
				);
				foreach ($iterator as $file) {
					if ($file->isFile()) {
						$downloadedFiles[] = $file->getPathname();
					}
				}
				error_log("  Downloaded files (" . count($downloadedFiles) . "): " . implode(', ', array_slice($downloadedFiles, 0, 20)));
			}
			
			// 6.3: Resultado final
			if (!$configExists) {
				$cloneErrors[] = "Server '{$serverName}': Clone failed. Config file not found at {$configFullPath}. Check logs for details.";
				error_log("  FINAL RESULT: FAILED - config file not found");
			} else {
				error_log("  FINAL RESULT: SUCCESS - config file found!");
			}
		}
		
		// Cleanup SSH temp files
		if (!empty($sshKey) && !empty($sshDir) && is_dir($sshDir)) {
			exec("rm -rf " . escapeshellcmd($sshDir));
			error_log("=== DEBUG: Cleanup SSH temp dir ===");
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
