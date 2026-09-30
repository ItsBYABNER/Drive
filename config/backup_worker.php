<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/backup.php';

$lockPath = getBackupDirectory() . DIRECTORY_SEPARATOR . '.backup.lock';
try {
    $conn = getConnection();
    $conn->select_db(DB_NAME);
    createBackupArchive($conn);
    $conn->close();
} finally {
    @unlink($lockPath);
}
