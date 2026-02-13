<?php
/**
 * Server Management
 *
 * @package   MyAAC
 * @author    izziOT
 * @copyright 2024 izziOT
 * @link      https://izziot.com
 */

defined('MYAAC') or die('Direct access not allowed!');

$title = 'Server Management';

csrfProtect();

// Check if tables exist, if not create them
$tablesExist = $db->query("SHOW TABLES LIKE 'myaac_servers'")->rowCount() > 0;

if (!$tablesExist) {
    // Create tables if they don't exist
    $db->exec("
    CREATE TABLE IF NOT EXISTS `myaac_servers` (
        `id` int NOT NULL AUTO_INCREMENT,
        `name` varchar(64) NOT NULL,
        `display_name` varchar(128) NOT NULL DEFAULT '',
        `is_default` tinyint NOT NULL DEFAULT 0,
        `status` enum('online','offline','maintenance') NOT NULL DEFAULT 'online',
        `created_at` int NOT NULL DEFAULT 0,
        `updated_at` int NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_server_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4;
    ");
    
    $db->exec("
    CREATE TABLE IF NOT EXISTS `myaac_server_databases` (
        `id` int NOT NULL AUTO_INCREMENT,
        `server_id` int NOT NULL,
        `database_host` varchar(255) DEFAULT NULL,
        `database_port` int NOT NULL DEFAULT 3306,
        `database_username` varchar(128) DEFAULT NULL,
        `database_password` varchar(255) DEFAULT NULL,
        `database_name` varchar(128) NOT NULL,
        `server_path` varchar(512) DEFAULT NULL,
        `config_path` varchar(512) NOT NULL DEFAULT 'config/config.lua',
        `data_path` varchar(512) NOT NULL DEFAULT 'data/',
        `git_repo` varchar(512) DEFAULT NULL,
        `git_branch` varchar(128) NOT NULL DEFAULT 'main',
        PRIMARY KEY (`id`),
        KEY `fk_server_id` (`server_id`),
        CONSTRAINT `fk_server_id` FOREIGN KEY (`server_id`) REFERENCES `myaac_servers`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4;
    ");
}

// Handle form submissions
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $serverName = $_POST['name'] ?? '';
            $displayName = $_POST['display_name'] ?? '';
            $isDefault = isset($_POST['is_default']) ? 1 : 0;
            $status = $_POST['status'] ?? 'online';
            
            // Database fields
            $dbHost = !empty($_POST['database_host']) ? $_POST['database_host'] : null;
            $dbPort = !empty($_POST['database_port']) ? (int)$_POST['database_port'] : 3306;
            $dbUser = !empty($_POST['database_username']) ? $_POST['database_username'] : null;
            $dbPass = !empty($_POST['database_password']) ? $_POST['database_password'] : null;
            $dbName = $_POST['database_name'] ?? '';
            $serverPath = $_POST['server_path'] ?? '';
            $configPath = $_POST['config_path'] ?? 'config/config.lua';
            $dataPath = $_POST['data_path'] ?? 'data/';
            $gitRepo = $_POST['git_repo'] ?? '';
            $gitBranch = $_POST['git_branch'] ?? 'main';
            
            if (empty($serverName) || empty($dbName)) {
                $message = 'Server name and database name are required.';
                $messageType = 'danger';
            } else {
                if ($_POST['action'] === 'add') {
                    // Check if server name already exists
                    $exists = $db->query("SELECT id FROM myaac_servers WHERE name = " . $db->quote($serverName))->rowCount() > 0;
                    if ($exists) {
                        $message = 'Server with this name already exists.';
                        $messageType = 'danger';
                    } else {
                        // Insert server
                        $stmt = $db->prepare("
                            INSERT INTO myaac_servers (name, display_name, is_default, status, created_at, updated_at) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $now = time();
                        $stmt->execute([$serverName, $displayName, $isDefault, $status, $now, $now]);
                        $serverId = $db->lastInsertId();
                        
                        // Insert database config
                        $stmt = $db->prepare("
                            INSERT INTO myaac_server_databases 
                            (server_id, database_host, database_port, database_username, database_password, database_name, server_path, config_path, data_path, git_repo, git_branch)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$serverId, $dbHost, $dbPort, $dbUser, $dbPass, $dbName, $serverPath, $configPath, $dataPath, $gitRepo, $gitBranch]);
                        
                        $message = 'Server added successfully!';
                    }
                } elseif ($_POST['action'] === 'edit') {
                    $serverId = (int)$_POST['id'];
                    
                    // Update server
                    $stmt = $db->prepare("
                        UPDATE myaac_servers 
                        SET name = ?, display_name = ?, is_default = ?, status = ?, updated_at = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$serverName, $displayName, $isDefault, $status, time(), $serverId]);
                    
                    // Update database config
                    $stmt = $db->prepare("
                        UPDATE myaac_server_databases 
                        SET database_host = ?, database_port = ?, database_username = ?, database_password = ?, database_name = ?,
                            server_path = ?, config_path = ?, data_path = ?, git_repo = ?, git_branch = ?
                        WHERE server_id = ?
                    ");
                    $stmt->execute([$dbHost, $dbPort, $dbUser, $dbPass, $dbName, $serverPath, $configPath, $dataPath, $gitRepo, $gitBranch, $serverId]);
                    
                    $message = 'Server updated successfully!';
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $serverId = (int)$_POST['id'];
            $stmt = $db->prepare("DELETE FROM myaac_servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $message = 'Server deleted successfully!';
        } elseif ($_POST['action'] === 'test_connection') {
            $serverId = (int)$_POST['id'];
            
            // Get server database config
            $serverDb = $db->query("
                SELECT s.*, d.database_host, d.database_port, d.database_username, d.database_password, d.database_name
                FROM myaac_servers s
                JOIN myaac_server_databases d ON s.id = d.server_id
                WHERE s.id = $serverId
            ")->fetch();
            
            if ($serverDb) {
                try {
                    // Use default MyAAC connection if no custom database is set
                    $host = $serverDb['database_host'] ?: $config['database_host'];
                    $port = $serverDb['database_port'] ?: 3306;
                    $user = $serverDb['database_username'] ?: $config['database_username'];
                    $pass = $serverDb['database_password'] ?: $config['database_password'];
                    $name = $serverDb['database_name'];
                    
                    $testDb = new PDO("mysql:host=$host;port=$port;dbname=$name", $user, $pass);
                    $testDb = null;
                    
                    $message = 'Connection successful!';
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = 'Connection failed: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

// Get all servers
$servers = $db->query("
    SELECT s.*, d.database_host, d.database_port, d.database_username, d.database_password, d.database_name,
           d.server_path, d.config_path, d.data_path, d.git_repo, d.git_branch
    FROM myaac_servers s
    LEFT JOIN myaac_server_databases d ON s.id = d.server_id
    ORDER BY s.id ASC
")->fetchAll();

// Get default database config for new server form
$defaultHost = $config['database_host'] ?? 'localhost';
$defaultPort = $config['database_port'] ?? 3306;
$defaultUser = $config['database_username'] ?? '';
$defaultDbName = $config['database_name'] ?? '';

$twig->display('admin.servers.html.twig', [
    'servers' => $servers,
    'message' => $message,
    'messageType' => $messageType,
    'defaultHost' => $defaultHost,
    'defaultPort' => $defaultPort,
    'defaultUser' => $defaultUser,
    'defaultDbName' => $defaultDbName,
    'adminUrl' => ADMIN_URL
]);
