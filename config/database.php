<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'user_drive_recovery');

$storagePath = getenv('DRIVE_STORAGE_PATH');
if ($storagePath === false || $storagePath === '') {
    $storagePath = DIRECTORY_SEPARATOR === '/' ? '/mnt/storage' : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage_data';
}
define('DRIVE_STORAGE_PATH', rtrim($storagePath, '/\\'));

function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        die("Error de conexión: " . $conn->connect_error);
    }
    return $conn;
}

function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isAuthenticated() {
    initSession();
    return isset($_SESSION['user_id']);
}

function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: login.php');
        exit;
    }
}

function getStorageFilePath($uuid) {
    if (!is_string($uuid) || !preg_match('/^[a-f0-9-]{36}$/', $uuid)) {
        return false;
    }
    return DRIVE_STORAGE_PATH . DIRECTORY_SEPARATOR . $uuid;
}

function ensureStorageDirectory() {
    if (!is_dir(DRIVE_STORAGE_PATH) && !mkdir(DRIVE_STORAGE_PATH, 0700, true)) {
        return false;
    }
    return is_writable(DRIVE_STORAGE_PATH);
}

function generateFileUuid() {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function streamUploadedFile($source, $destination) {
    $input = fopen($source, 'rb');
    $output = fopen($destination, 'wb');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        return false;
    }

    while (!feof($input)) {
        $chunk = fread($input, 8 * 1024 * 1024);
        if ($chunk === false || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
            fclose($input);
            fclose($output);
            @unlink($destination);
            return false;
        }
    }

    fclose($input);
    fclose($output);
    return true;
}
?>

