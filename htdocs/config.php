<?php
if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'config.credentials.php')) {
    require __DIR__ . DIRECTORY_SEPARATOR . 'config.credentials.php';
}

function config_bool($envName, $constantName, $defaultValue = false) {
    $envValue = getenv($envName);
    if ($envValue !== false && $envValue !== '') {
        return $envValue === '1' || strtolower($envValue) === 'true';
    }

    if (defined($constantName)) {
        return constant($constantName) === true;
    }

    return $defaultValue;
}

$host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : "sql312.infinityfree.com");
$user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : "if0_41346503");
$pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : "Appa2004");
$db = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : "if0_41346503_chat_app");

// Keep disabled on shared hosting unless explicitly enabled.
$autoInitEnabled = config_bool('DB_AUTO_INIT', 'DB_AUTO_INIT', false);

// App runtime flags for production/live behavior.
$clearOnLoadEnabled = config_bool('APP_CLEAR_ON_LOAD', 'APP_CLEAR_ON_LOAD', false);
$adminClearToken = getenv('ADMIN_CLEAR_TOKEN') ?: (defined('ADMIN_CLEAR_TOKEN') ? ADMIN_CLEAR_TOKEN : '');
$chatOwnerSecret = trim((string) (getenv('APP_CHAT_SECRET_OWNER') ?: (defined('APP_CHAT_SECRET_OWNER') ? APP_CHAT_SECRET_OWNER : '')));
$chatUserSecret = trim((string) (getenv('APP_CHAT_SECRET_USER') ?: (defined('APP_CHAT_SECRET_USER') ? APP_CHAT_SECRET_USER : '')));
$chatOwnerName = trim((string) (getenv('APP_CHAT_OWNER_NAME') ?: (defined('APP_CHAT_OWNER_NAME') ? APP_CHAT_OWNER_NAME : 'Owner')));
$chatUserName = trim((string) (getenv('APP_CHAT_USER_NAME') ?: (defined('APP_CHAT_USER_NAME') ? APP_CHAT_USER_NAME : 'User')));

// Backward compatibility for previous single-secret setup.
if ($chatOwnerSecret === '') {
    $chatOwnerSecret = trim((string) (getenv('APP_CHAT_SECRET') ?: (defined('APP_CHAT_SECRET') ? APP_CHAT_SECRET : '')));
}

if ($chatOwnerName === '') {
    $chatOwnerName = trim((string) (getenv('APP_CHAT_DISPLAY_NAME') ?: (defined('APP_CHAT_DISPLAY_NAME') ? APP_CHAT_DISPLAY_NAME : 'Owner')));
}

// Default for production/shared hosting: connect to an existing database only.
$conn = @new mysqli($host, $user, $pass, $db);

if ($conn->connect_error && $autoInitEnabled) {
    // Optional local-dev mode: create DB and schema automatically.
    $bootstrapConn = @new mysqli($host, $user, $pass);

    if ($bootstrapConn->connect_error) {
        error_log("DB bootstrap connection failed: " . $bootstrapConn->connect_error);
        die("Database connection failed");
    }

    if (!$bootstrapConn->query("CREATE DATABASE IF NOT EXISTS `$db`")) {
        error_log("Database initialization failed: " . $bootstrapConn->error);
        die("Database initialization failed");
    }

    if (!$bootstrapConn->select_db($db)) {
        error_log("Database selection failed: " . $bootstrapConn->error);
        die("Database selection failed");
    }

    $conn = $bootstrapConn;
}

if ($conn->connect_error) {
    error_log("DB connection failed: " . $conn->connect_error);
    $conn = null;
    return;
}

if (!$conn->set_charset('utf8mb4')) {
    error_log("Failed to set charset utf8mb4: " . $conn->error);
}

if (!$autoInitEnabled) {
    // In production, schema already exists; skip migrations/bootstrap.
    return;
}

$createTableSql = "
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
";

if (!$conn->query($createTableSql)) {
    error_log("Messages table initialization failed: " . $conn->error);
    die("Table initialization failed");
}

$photoColumnCheckSql = "
SELECT COUNT(*) AS column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = ?
  AND TABLE_NAME = 'messages'
  AND COLUMN_NAME = 'photo_path'
";

$columnCheckStmt = $conn->prepare($photoColumnCheckSql);

if (!$columnCheckStmt) {
    error_log("Column check statement preparation failed: " . $conn->error);
    die("Database migration failed");
}

$columnCheckStmt->bind_param("s", $db);
$columnCheckStmt->execute();
$columnCheckResult = $columnCheckStmt->get_result();
$columnCheckRow = $columnCheckResult->fetch_assoc();
$columnCheckStmt->close();

if ((int)$columnCheckRow['column_count'] === 0) {
    if (!$conn->query("ALTER TABLE messages ADD COLUMN photo_path VARCHAR(255) NULL AFTER message")) {
        error_log("Photo column migration failed: " . $conn->error);
        die("Database migration failed");
    }
}

$createUsersTableSql = "
CREATE TABLE IF NOT EXISTS users (
    username VARCHAR(100) PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
";

if (!$conn->query($createUsersTableSql)) {
    error_log("Users table initialization failed: " . $conn->error);
    die("Table initialization failed");
}

$createPrivateMessagesTableSql = "
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender VARCHAR(100) NOT NULL,
    receiver VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pair_time (sender, receiver, created_at)
)
";

if (!$conn->query($createPrivateMessagesTableSql)) {
    error_log("Private messages table initialization failed: " . $conn->error);
    die("Table initialization failed");
}

$createClockSettingsSql = "
CREATE TABLE IF NOT EXISTS clock_settings (
    id INT PRIMARY KEY,
    clock_time TIME NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
";

if (!$conn->query($createClockSettingsSql)) {
    error_log("Clock settings initialization failed: " . $conn->error);
    die("Table initialization failed");
}

if (!$conn->query("INSERT IGNORE INTO clock_settings(id, clock_time) VALUES(1, '12:00:00')")) {
    error_log("Clock settings seed failed: " . $conn->error);
    die("Database seed failed");
}
?>