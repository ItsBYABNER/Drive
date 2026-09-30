<?php
require_once 'database.php';

$conn = getConnection();

$sql = 'CREATE DATABASE IF NOT EXISTS ' . DB_NAME;
if ($conn->query($sql) === TRUE) {
    echo 'Base de datos lista.' . PHP_EOL;
} else {
    echo 'Error creando base de datos: ' . $conn->error . PHP_EOL;
}

$conn->select_db(DB_NAME);

$sql = 'CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM(\'admin\', \'user\') NOT NULL DEFAULT \'user\',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)';
$conn->query($sql);

$sql = 'CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NULL UNIQUE,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    type ENUM(\'file\', \'folder\') NOT NULL DEFAULT \'file\',
    size BIGINT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    extension VARCHAR(20) DEFAULT NULL,
    visibility ENUM(\'private\', \'public\') NOT NULL DEFAULT \'private\',
    password_hash VARCHAR(255) DEFAULT NULL,
    parent_id INT DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES files(id) ON DELETE CASCADE
)';
$conn->query($sql);

$columns = $conn->query('SHOW COLUMNS FROM files');
$existingColumns = [];
while ($column = $columns->fetch_assoc()) {
    $existingColumns[$column['Field']] = true;
}
if (!isset($existingColumns['uuid'])) {
    $conn->query('ALTER TABLE files ADD COLUMN uuid CHAR(36) NULL UNIQUE AFTER id');
}
if (!isset($existingColumns['extension'])) {
    $conn->query('ALTER TABLE files ADD COLUMN extension VARCHAR(20) DEFAULT NULL AFTER mime_type');
}
if (!isset($existingColumns['visibility'])) {
    $conn->query("ALTER TABLE files ADD COLUMN visibility ENUM('private', 'public') NOT NULL DEFAULT 'private' AFTER extension");
}
if (!isset($existingColumns['password_hash'])) {
    $conn->query('ALTER TABLE files ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER visibility');
}
if (!isset($existingColumns['deleted_at'])) {
    $conn->query('ALTER TABLE files ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER file_path');
}

$userColumns = $conn->query('SHOW COLUMNS FROM users');
$existingUserColumns = [];
while ($column = $userColumns->fetch_assoc()) {
    $existingUserColumns[$column['Field']] = true;
}
if (!isset($existingUserColumns['role'])) {
    $conn->query("ALTER TABLE users ADD COLUMN role ENUM('admin', 'user') NOT NULL DEFAULT 'user' AFTER password");
}
if (!isset($existingUserColumns['profile_image'])) {
    $conn->query('ALTER TABLE users ADD COLUMN profile_image CHAR(36) NULL AFTER role');
}

$username = 'abner';
$password = password_hash('abnerjariel', PASSWORD_DEFAULT);

$check = $conn->query("SELECT id FROM users WHERE username = '$username'");
if ($check->num_rows === 0) {
    $role = 'admin';
    $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $username, $password, $role);
    $stmt->execute();
    $stmt->close();
}

$stmt = $conn->prepare('UPDATE users SET role = \'admin\' WHERE username = ?');
$stmt->bind_param('s', $username);
$stmt->execute();
$stmt->close();

$userResult = $conn->query("SELECT id FROM users WHERE username = '$username'");
$user = $userResult->fetch_assoc();
$userId = $user['id'];

$rootCheck = $conn->query("SELECT id FROM files WHERE user_id = $userId AND name = '/' AND parent_id IS NULL");
if ($rootCheck->num_rows === 0) {
    $stmt = $conn->prepare('INSERT INTO files (user_id, name, type, parent_id) VALUES (?, \'/\', \'folder\', NULL)');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

$conn->close();
echo 'Inicializacion completada.' . PHP_EOL;
