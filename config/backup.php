<?php

function getBackupDirectory() {
    return DRIVE_STORAGE_PATH . DIRECTORY_SEPARATOR . 'backups';
}

function ensureBackupDirectory() {
    $directory = getBackupDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        return false;
    }
    return is_writable($directory);
}

function isBackupInProgress() {
    $lockPath = getBackupDirectory() . DIRECTORY_SEPARATOR . '.backup.lock';
    if (!is_file($lockPath)) {
        return false;
    }
    $lockTime = filemtime($lockPath);
    if ($lockTime !== false && $lockTime < time() - 86400) {
        @unlink($lockPath);
        return false;
    }
    return true;
}

function createDatabaseDump($conn, $path) {
    $dump = "-- Copia de seguridad A.R.C.A\n-- Generada: " . date('c') . "\n\n";
    $tables = $conn->query('SHOW TABLES');
    if (!$tables) {
        return false;
    }
    while ($tableRow = $tables->fetch_row()) {
        $tableName = $tableRow[0];
        $escapedTable = str_replace('`', '``', $tableName);
        $createResult = $conn->query('SHOW CREATE TABLE `' . $escapedTable . '`');
        $createRow = $createResult ? $createResult->fetch_assoc() : null;
        if (!$createRow) {
            return false;
        }
        $createSql = $createRow['Create Table'] ?? reset($createRow);
        $dump .= "DROP TABLE IF EXISTS `" . $escapedTable . "`;\n" . $createSql . ";\n";

        $dataResult = $conn->query('SELECT * FROM `' . $escapedTable . '`');
        if ($dataResult && $dataResult->num_rows > 0) {
            $fields = [];
            foreach ($dataResult->fetch_fields() as $field) {
                $fields[] = '`' . str_replace('`', '``', $field->name) . '`';
            }
            $dump .= 'INSERT INTO `' . $escapedTable . '` (' . implode(', ', $fields) . ") VALUES\n";
            $rows = [];
            while ($dataRow = $dataResult->fetch_assoc()) {
                $values = [];
                foreach ($dataRow as $value) {
                    $values[] = $value === null ? 'NULL' : "'" . $conn->real_escape_string((string) $value) . "'";
                }
                $rows[] = '(' . implode(', ', $values) . ')';
            }
            $dump .= implode(",\n", $rows) . ";\n";
        }
        $dump .= "\n";
    }
    return file_put_contents($path, $dump, LOCK_EX) !== false;
}

function createBackupArchive($conn) {
    if (!class_exists('PharData') || !ensureBackupDirectory()) {
        return false;
    }
    $backupName = 'backup_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '.tar';
    $backupPath = getBackupDirectory() . DIRECTORY_SEPARATOR . $backupName;
    $dumpPath = tempnam(sys_get_temp_dir(), 'arca_dump_');
    if ($dumpPath === false || !createDatabaseDump($conn, $dumpPath)) {
        if ($dumpPath !== false) {
            @unlink($dumpPath);
        }
        return false;
    }

    try {
        $archive = new PharData($backupPath);
        $archive->addFile($dumpPath, 'database.sql');
        $storageRoot = realpath(DRIVE_STORAGE_PATH);
        if ($storageRoot !== false) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($storageRoot, FilesystemIterator::SKIP_DOTS)
            );
            $backupRoot = realpath(getBackupDirectory());
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $filePath = $fileInfo->getPathname();
                if ($backupRoot !== false && strpos($filePath, $backupRoot . DIRECTORY_SEPARATOR) === 0) {
                    continue;
                }
                $relativePath = substr($filePath, strlen($storageRoot) + 1);
                $archive->addFile($filePath, 'storage/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
            }
        }
        unset($archive);
    } catch (Throwable $exception) {
        @unlink($backupPath);
        @unlink($dumpPath);
        return false;
    }
    @unlink($dumpPath);
    return $backupName;
}

function startBackupProcess() {
    if (!ensureBackupDirectory() || isBackupInProgress()) {
        return false;
    }
    $lockPath = getBackupDirectory() . DIRECTORY_SEPARATOR . '.backup.lock';
    $lockHandle = @fopen($lockPath, 'x');
    if ($lockHandle === false) {
        return false;
    }
    fclose($lockHandle);

    $workerPath = __DIR__ . DIRECTORY_SEPARATOR . 'backup_worker.php';
    if (DIRECTORY_SEPARATOR === '\\') {
        $command = 'start /B "" ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath) . ' > NUL 2>&1';
    } else {
        $command = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath) . ' >/dev/null 2>&1 &';
    }
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        @unlink($lockPath);
        return false;
    }
    return true;
}

function getAvailableBackups() {
    if (!is_dir(getBackupDirectory())) {
        return [];
    }
    $backups = [];
    foreach (glob(getBackupDirectory() . DIRECTORY_SEPARATOR . 'backup_*.tar') ?: [] as $path) {
        if (is_file($path)) {
            $backups[] = ['name' => basename($path), 'size' => filesize($path), 'created_at' => filemtime($path)];
        }
    }
    usort($backups, function ($left, $right) {
        return $right['created_at'] <=> $left['created_at'];
    });
    return $backups;
}
