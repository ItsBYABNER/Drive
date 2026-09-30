<?php
require_once 'config/database.php';
require_once 'config/backup.php';
requireAuth();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$conn = getConnection();
$conn->select_db(DB_NAME);

$userId = (int) $_SESSION['user_id'];
$roleStmt = $conn->prepare('SELECT username, role, profile_image FROM users WHERE id = ?');
$roleStmt->bind_param('i', $userId);
$roleStmt->execute();
$currentUser = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();
$_SESSION['role'] = $currentUser['role'] ?? 'user';
$_SESSION['username'] = $currentUser['username'] ?? $_SESSION['username'];
$isAdmin = $_SESSION['role'] === 'admin';
$trashView = $isAdmin && isset($_GET['trash']) && $_GET['trash'] === '1';
$currentFolder = isset($_GET['folder']) ? (int) $_GET['folder'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$action = $_GET['action'] ?? '';
$message = '';
$error = '';
$folderOwnerId = $userId;
$lockedFolderId = null;

function ensureDirectory($path)
{
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

function formatSize($bytes)
{
    if ($bytes === 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[(int) $i];
}

function formatDate($date)
{
    return date('d/m/Y H:i', strtotime($date));
}

function getMimeTypeForPath($path)
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo && is_file($path) ? $finfo->file($path) : false;
    return is_string($mimeType) && $mimeType !== '' ? strtolower($mimeType) : 'application/octet-stream';
}

function normalizeUploadedFile($sourcePath, $originalName, $mimeType = '')
{
    $mimeType = strtolower($mimeType !== '' ? $mimeType : getMimeTypeForPath($sourcePath));
    $safeName = basename($originalName);
    $baseName = pathinfo($safeName, PATHINFO_FILENAME);
    $targetExtension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
    $targetMime = $mimeType;

    if (strpos($mimeType, 'video/') === 0) {
        $targetMime = 'video/mp4';
        $targetExtension = 'mp4';
    } elseif (strpos($mimeType, 'audio/') === 0) {
        $targetMime = 'audio/mpeg';
        $targetExtension = 'mp3';
    } elseif (strpos($mimeType, 'image/') === 0) {
        $targetMime = 'image/png';
        $targetExtension = 'png';
    }

    $convertedPath = $sourcePath;

    if (strpos($targetMime, 'image/png') === 0) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
            return [
                'path' => $sourcePath,
                'name' => $baseName !== '' ? $baseName . '.png' : $safeName,
                'mime_type' => 'image/png',
                'extension' => 'png',
            ];
        }

        $rawData = @file_get_contents($sourcePath);
        if ($rawData === false || $rawData === '') {
            return [
                'path' => $sourcePath,
                'name' => $baseName !== '' ? $baseName . '.png' : $safeName,
                'mime_type' => 'image/png',
                'extension' => 'png',
            ];
        }

        $image = @imagecreatefromstring($rawData);
        if ($image !== false) {
            $convertedPath = $sourcePath . '.converted.png';
            $result = @imagepng($image, $convertedPath, 9);
            @imagedestroy($image);
            if ($result !== true || !is_file($convertedPath) || filesize($convertedPath) === 0) {
                @unlink($convertedPath);
                $convertedPath = $sourcePath;
            }
        }
    } elseif (strpos($targetMime, 'video/mp4') === 0 || strpos($targetMime, 'audio/mpeg') === 0) {
        $convertedPath = $sourcePath . '.' . $targetExtension;
        $ffmpegPath = '';
        if (DIRECTORY_SEPARATOR === '\\') {
            exec('where ffmpeg 2>nul', $ffmpegOutput, $ffmpegCode);
            if ($ffmpegCode === 0 && !empty($ffmpegOutput)) {
                $ffmpegPath = trim($ffmpegOutput[0]);
            }
        } else {
            exec('command -v ffmpeg 2>/dev/null', $ffmpegOutput, $ffmpegCode);
            if ($ffmpegCode === 0 && !empty($ffmpegOutput)) {
                $ffmpegPath = trim($ffmpegOutput[0]);
            }
        }

        if ($ffmpegPath !== '') {
            if (strpos($targetMime, 'video/mp4') === 0) {
                $command = escapeshellarg($ffmpegPath) . ' -y -i ' . escapeshellarg($sourcePath) . ' -c:v libx264 -pix_fmt yuv420p -c:a aac -movflags +faststart ' . escapeshellarg($convertedPath) . ' 2>/dev/null';
            } else {
                $command = escapeshellarg($ffmpegPath) . ' -y -i ' . escapeshellarg($sourcePath) . ' -vn -ar 44100 -ac 2 -c:a libmp3lame ' . escapeshellarg($convertedPath) . ' 2>/dev/null';
            }
            exec($command . ' >/dev/null 2>&1', $output, $exitCode);
            if ($exitCode !== 0 || !is_file($convertedPath) || filesize($convertedPath) === 0) {
                @unlink($convertedPath);
                $convertedPath = $sourcePath;
            }
        }
    }

    $finalName = $baseName !== '' ? $baseName . '.' . $targetExtension : $safeName;
    return [
        'path' => $convertedPath,
        'name' => $finalName,
        'mime_type' => $targetMime,
        'extension' => $targetExtension,
    ];
}

function getFolderRelativePath($conn, $folderId, $userId)
{
    $path = [];
    $currentId = $folderId;

    while ($currentId) {
        $stmt = $conn->prepare('SELECT id, name, parent_id FROM files WHERE id = ?');
        $stmt->bind_param('i', $currentId);
        $stmt->execute();
        $folder = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$folder) {
            return false;
        }
        if ($folder['name'] === '/') {
            break;
        }
        array_unshift($path, $folder['name']);
        $currentId = $folder['parent_id'];
    }

    return implode('/', $path);
}

function getFolderPath($conn, $folderId, $userId)
{
    $basePath = __DIR__ . '/storage/' . $userId;
    $relativePath = getFolderRelativePath($conn, $folderId, $userId);
    if ($relativePath === false) {
        return false;
    }
    return $relativePath === '' ? $basePath : $basePath . '/' . $relativePath;
}

function getFolderPathById($conn, $folderId, $userId)
{
    return getFolderPath($conn, $folderId, $userId);
}

function deleteDirectory($dir)
{
    if (!file_exists($dir)) {
        return true;
    }
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    return rmdir($dir);
}

function deleteStoredFilesInTree($conn, $itemId)
{
    $pendingIds = [$itemId];
    while ($pendingIds) {
        $currentId = array_pop($pendingIds);
        $stmt = $conn->prepare('SELECT id, type, uuid FROM files WHERE id = ?');
        $stmt->bind_param('i', $currentId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) {
            continue;
        }
        if ($item['type'] === 'file' && $item['uuid']) {
            $filePath = getStorageFilePath($item['uuid']);
            if (is_string($filePath) && file_exists($filePath)) {
                unlink($filePath);
            }
        }
        $childStmt = $conn->prepare('SELECT id FROM files WHERE parent_id = ?');
        $childStmt->bind_param('i', $currentId);
        $childStmt->execute();
        $children = $childStmt->get_result();
        while ($child = $children->fetch_assoc()) {
            $pendingIds[] = (int) $child['id'];
        }
        $childStmt->close();
    }
}

function collectFileTreeIds($conn, $itemId)
{
    $ids = [];
    $pendingIds = [$itemId];
    while ($pendingIds) {
        $currentId = array_pop($pendingIds);
        $ids[] = $currentId;
        $childStmt = $conn->prepare('SELECT id FROM files WHERE parent_id = ?');
        $childStmt->bind_param('i', $currentId);
        $childStmt->execute();
        $children = $childStmt->get_result();
        while ($child = $children->fetch_assoc()) {
            $pendingIds[] = (int) $child['id'];
        }
        $childStmt->close();
    }
    return $ids;
}

function markFileTreeDeleted($conn, $itemId)
{
    $ids = collectFileTreeIds($conn, $itemId);
    $stmt = $conn->prepare('UPDATE files SET deleted_at = NOW() WHERE id = ?');
    foreach ($ids as $id) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
    }
    $stmt->close();
}

function permanentlyDeleteFileTree($conn, $itemId)
{
    deleteStoredFilesInTree($conn, $itemId);
    $stmt = $conn->prepare('DELETE FROM files WHERE id = ?');
    $stmt->bind_param('i', $itemId);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function canAccessFile($conn, $parentId)
{
    while ($parentId) {
        $stmt = $conn->prepare('SELECT id, name, parent_id, visibility, deleted_at FROM files WHERE id = ? AND type = \'folder\'');
        $stmt->bind_param('i', $parentId);
        $stmt->execute();
        $folder = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$folder || $folder['deleted_at'] !== null) {
            return false;
        }
        $isRootFolder = $folder['name'] === '/' && $folder['parent_id'] === null;
        $isUnlocked = isset($_SESSION['unlocked_folders'][$folder['id']]);
        if (!$isRootFolder && $folder['visibility'] === 'private' && !$isUnlocked) {
            return false;
        }
        $parentId = (int) $folder['parent_id'];
    }
    return true;
}

function streamFileResponse($path, $mimeType, $fileName, $disposition = 'inline')
{
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    clearstatcache(true, $path);
    $fileSize = filesize($path);
    $start = 0;
    $end = $fileSize - 1;
    $status = 200;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $range)) {
        if ($range[1] === '' && $range[2] !== '') {
            $start = max(0, $fileSize - (int) $range[2]);
        } else {
            $start = (int) $range[1];
            if ($range[2] !== '') {
                $end = (int) $range[2];
            }
        }
        if ($start > $end || $start >= $fileSize) {
            header('Content-Range: bytes */' . $fileSize);
            http_response_code(416);
            exit;
        }
        $end = min($end, $fileSize - 1);
        $status = 206;
    }

    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '\\"', $fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    header('Content-Transfer-Encoding: binary');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }
    header('X-Content-Type-Options: nosniff');

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        http_response_code(500);
        exit;
    }

    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8 * 1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}

function getBreadcrumb($conn, $folderId, $userId)
{
    $crumbs = [];
    $currentId = $folderId;

    while ($currentId) {
        $stmt = $conn->prepare('SELECT id, name, parent_id FROM files WHERE id = ?');
        $stmt->bind_param('i', $currentId);
        $stmt->execute();
        $folder = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$folder) {
            break;
        }
        array_unshift($crumbs, $folder);
        $currentId = $folder['parent_id'];
    }

    return $crumbs;
}

function renderIcon($type, $mimeType = '')
{
    if ($type === 'folder') {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h6l2 2h8a1 1 0 0 1 1 1v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"></path></svg>';
    }

    if (strpos($mimeType, 'image/') === 0) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="M20 16l-4-4-4 4"></path></svg>';
    }
    if (strpos($mimeType, 'pdf') !== false) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v4h4"></path><path d="M9 13h6"></path><path d="M9 17h4"></path></svg>';
    }
    if (strpos($mimeType, 'text/') === 0 || strpos($mimeType, 'json') !== false) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v4h4"></path><path d="M9 10h6"></path><path d="M9 14h4"></path></svg>';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 3h7l4 4v14H7z"></path><path d="M14 3v4h4"></path><path d="M9 10h6"></path><path d="M9 14h4"></path></svg>';
}

if ($currentFolder === null) {
    $stmt = $conn->prepare('SELECT id FROM files WHERE user_id = ? AND name = \'/\' AND parent_id IS NULL AND deleted_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rootRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($rootRow) {
        $currentFolder = (int) $rootRow['id'];
    } else {
        $stmt = $conn->prepare('INSERT INTO files (user_id, name, type, parent_id, deleted_at) VALUES (?, \'/\', \'folder\', NULL, NULL)');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $currentFolder = $stmt->insert_id;
        $stmt->close();
    }
}

if (isset($_SESSION['unlocked_folders'])) {
    foreach (array_keys($_SESSION['unlocked_folders']) as $unlockedFolderId) {
        if ((int) $unlockedFolderId !== $currentFolder) {
            unset($_SESSION['unlocked_folders'][$unlockedFolderId]);
        }
    }
}

if ($action === 'unlock_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $unlockId = (int) ($_POST['folder_id'] ?? 0);
    $unlockPassword = $_POST['folder_password'] ?? '';
    $stmt = $conn->prepare('SELECT id, user_id, password_hash FROM files WHERE id = ? AND type = \'folder\' AND visibility = \'private\'');
    $stmt->bind_param('i', $unlockId);
    $stmt->execute();
    $lockedFolder = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($lockedFolder && password_verify($unlockPassword, (string) $lockedFolder['password_hash'])) {
        $_SESSION['unlocked_folders'][$unlockId] = time();
        header('Location: dashboard.php?folder=' . $unlockId);
        exit;
    }
    $error = 'La contraseña de la carpeta no es correcta.';
}

if ($action === 'profile_image' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $imageStmt = $conn->prepare('SELECT profile_image FROM users WHERE id = ?');
    $imageStmt->bind_param('i', $userId);
    $imageStmt->execute();
    $profile = $imageStmt->get_result()->fetch_assoc();
    $imageStmt->close();
    $imagePath = $profile && $profile['profile_image'] ? getStorageFilePath($profile['profile_image']) : false;
    if (is_string($imagePath) && is_file($imagePath)) {
        $imageType = (new finfo(FILEINFO_MIME_TYPE))->file($imagePath) ?: 'image/jpeg';
        streamFileResponse($imagePath, $imageType, 'profile-image', 'inline');
    }
    http_response_code(404);
    exit;
}

if ($action === 'update_profile_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $image = $_FILES['profile_image'] ?? null;
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!$image || $image['error'] !== UPLOAD_ERR_OK) {
        $error = 'Selecciona una imagen válida.';
    } elseif ($image['size'] > 5 * 1024 * 1024) {
        $error = 'La imagen no puede superar 5 MB.';
    } else {
        $imageType = (new finfo(FILEINFO_MIME_TYPE))->file($image['tmp_name']);
        if (!in_array($imageType, $allowedTypes, true)) {
            $error = 'Solo se permiten imágenes JPG, PNG o WEBP.';
        } else {
            $imageUuid = generateFileUuid();
            $imagePath = getStorageFilePath($imageUuid);
            if (ensureStorageDirectory() && streamUploadedFile($image['tmp_name'], $imagePath)) {
                $oldImage = $currentUser['profile_image'] ?? null;
                $imageStmt = $conn->prepare('UPDATE users SET profile_image = ? WHERE id = ?');
                $imageStmt->bind_param('si', $imageUuid, $userId);
                if ($imageStmt->execute()) {
                    if ($oldImage) {
                        $oldImagePath = getStorageFilePath($oldImage);
                        if (is_string($oldImagePath) && is_file($oldImagePath)) {
                            unlink($oldImagePath);
                        }
                    }
                    $currentUser['profile_image'] = $imageUuid;
                    $message = 'Imagen de perfil actualizada.';
                } else {
                    unlink($imagePath);
                    $error = 'No se pudo actualizar la imagen.';
                }
                $imageStmt->close();
            } else {
                $error = 'No se pudo guardar la imagen.';
            }
        }
    }
}

if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $passwordStmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
    $passwordStmt->bind_param('i', $userId);
    $passwordStmt->execute();
    $passwordUser = $passwordStmt->get_result()->fetch_assoc();
    $passwordStmt->close();
    if (!password_verify($currentPassword, (string) ($passwordUser['password'] ?? ''))) {
        $error = 'La contraseña actual no es correcta.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Las contraseñas nuevas no coinciden.';
    } elseif ($newPassword === $currentPassword) {
        $error = 'La nueva contraseña debe ser diferente.';
    } else {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $passwordStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $passwordStmt->bind_param('si', $passwordHash, $userId);
        $message = $passwordStmt->execute() ? 'Contraseña actualizada.' : 'No se pudo actualizar la contraseña.';
        $passwordStmt->close();
    }
}

if ($action === 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $error = 'No tienes permisos para administrar usuarios.';
    } else {
        $newUsername = trim($_POST['username'] ?? '');
        $newPassword = trim($_POST['password'] ?? '');
        $newRole = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($newUsername === '' || $newPassword === '') {
            $error = 'El usuario y la contraseña son obligatorios.';
        } else {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $newUsername, $passwordHash, $newRole);
            if ($stmt->execute()) {
                $message = 'Usuario creado.';
            } else {
                $error = $stmt->errno === 1062 ? 'Ese usuario ya existe.' : 'No se pudo crear el usuario.';
            }
            $stmt->close();
        }
    }
}

if ($action === 'update_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $error = 'No tienes permisos para administrar usuarios.';
    } else {
        $editUserId = (int) ($_POST['user_id'] ?? 0);
        $editUsername = trim($_POST['username'] ?? '');
        $editPassword = trim($_POST['password'] ?? '');
        $editRole = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        if ($editUserId <= 0 || $editUsername === '') {
            $error = 'El usuario y el nombre son obligatorios.';
        } elseif ($editUserId === $userId && $editRole !== 'admin') {
            $error = 'No puedes quitarte el rol de administrador.';
        } else {
            $stmt = $conn->prepare('SELECT role FROM users WHERE id = ?');
            $stmt->bind_param('i', $editUserId);
            $stmt->execute();
            $editedUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $wouldRemoveAdmin = $editedUser && $editedUser['role'] === 'admin' && $editRole !== 'admin';
            $adminCount = (int) $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")->fetch_assoc()['total'];
            if ($wouldRemoveAdmin && $adminCount <= 1) {
                $error = 'Debe existir al menos un administrador.';
            } else {
                if ($editPassword !== '') {
                    $passwordHash = password_hash($editPassword, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('UPDATE users SET username = ?, password = ?, role = ? WHERE id = ?');
                    $stmt->bind_param('sssi', $editUsername, $passwordHash, $editRole, $editUserId);
                } else {
                    $stmt = $conn->prepare('UPDATE users SET username = ?, role = ? WHERE id = ?');
                    $stmt->bind_param('ssi', $editUsername, $editRole, $editUserId);
                }
                if ($stmt->execute()) {
                    $message = 'Usuario actualizado.';
                    if ($editUserId === $userId) {
                        $_SESSION['username'] = $editUsername;
                    }
                } else {
                    $error = $stmt->errno === 1062 ? 'Ese usuario ya existe.' : 'No se pudo actualizar el usuario.';
                }
                $stmt->close();
            }
        }
    }
}

if ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $error = 'No tienes permisos para administrar usuarios.';
    } else {
        $deleteUserId = (int) ($_POST['user_id'] ?? 0);
        if ($deleteUserId === $userId) {
            $error = 'No puedes eliminar tu propio usuario.';
        } else {
            $stmt = $conn->prepare('SELECT role FROM users WHERE id = ?');
            $stmt->bind_param('i', $deleteUserId);
            $stmt->execute();
            $deletedUser = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $adminCount = (int) $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")->fetch_assoc()['total'];
            if (!$deletedUser) {
                $error = 'El usuario no existe.';
            } elseif ($deletedUser['role'] === 'admin' && $adminCount <= 1) {
                $error = 'Debe existir al menos un administrador.';
            } else {
                $fileStmt = $conn->prepare('SELECT uuid FROM files WHERE user_id = ? AND uuid IS NOT NULL');
                $fileStmt->bind_param('i', $deleteUserId);
                $fileStmt->execute();
                $fileResult = $fileStmt->get_result();
                while ($ownedFile = $fileResult->fetch_assoc()) {
                    $ownedPath = getStorageFilePath($ownedFile['uuid']);
                    if (is_string($ownedPath) && file_exists($ownedPath)) {
                        unlink($ownedPath);
                    }
                }
                $fileStmt->close();
                $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
                $stmt->bind_param('i', $deleteUserId);
                $message = $stmt->execute() ? 'Usuario eliminado.' : 'No se pudo eliminar el usuario.';
                $stmt->close();
            }
        }
    }
}

if (($action === 'trash_restore' || $action === 'trash_delete') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $error = 'No tienes permisos para administrar la papelera.';
    } else {
        $trashId = (int) ($_POST['item_id'] ?? 0);
        $stmt = $conn->prepare('SELECT id, name, deleted_at FROM files WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->bind_param('i', $trashId);
        $stmt->execute();
        $trashItem = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$trashItem) {
            $error = 'El elemento no está en la papelera.';
        } elseif ($action === 'trash_restore') {
            $stmt = $conn->prepare('UPDATE files SET deleted_at = NULL WHERE id = ?');
            $stmt->bind_param('i', $trashId);
            $message = $stmt->execute() ? 'Elemento restaurado.' : 'No se pudo restaurar el elemento.';
            $stmt->close();
            if ($message === 'Elemento restaurado.') {
                foreach (collectFileTreeIds($conn, $trashId) as $treeId) {
                    $restoreStmt = $conn->prepare('UPDATE files SET deleted_at = NULL WHERE id = ?');
                    $restoreStmt->bind_param('i', $treeId);
                    $restoreStmt->execute();
                    $restoreStmt->close();
                }
            }
        } elseif (permanentlyDeleteFileTree($conn, $trashId)) {
            $message = 'Elemento eliminado definitivamente.';
        } else {
            $error = 'No se pudo eliminar definitivamente.';
        }
    }
}

if ($action === 'create_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $error = 'No tienes permisos para crear copias de seguridad.';
    } else {
        $message = startBackupProcess()
            ? 'La copia de seguridad se está generando en segundo plano.'
            : 'Ya hay una copia en proceso o no se pudo iniciar.';
    }
}

if ($action === 'upload_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        $error = 'No tienes permisos para subir copias de seguridad.';
    } else {
        $uploadedBackup = $_FILES['backup_file'] ?? null;
        $originalName = is_array($uploadedBackup) ? (string) ($uploadedBackup['name'] ?? '') : '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!$uploadedBackup || $uploadedBackup['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($uploadedBackup['tmp_name'])) {
            $error = 'Selecciona una copia de seguridad válida.';
        } elseif ($extension !== 'tar') {
            $error = 'Solo se pueden subir copias en formato TAR.';
        } elseif (!ensureBackupDirectory()) {
            $error = 'No se puede acceder a la carpeta de copias de seguridad.';
        } else {
            $uploadedName = 'backup_uploaded_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '.tar';
            $uploadedPath = getBackupDirectory() . DIRECTORY_SEPARATOR . $uploadedName;
            if (move_uploaded_file($uploadedBackup['tmp_name'], $uploadedPath)) {
                @chmod($uploadedPath, 0600);
                $message = 'Copia de seguridad subida.';
            } else {
                $error = 'No se pudo guardar la copia de seguridad.';
            }
        }
    }
}

if ($action === 'download_backup' && isset($_GET['name'])) {
    if (!$isAdmin) {
        http_response_code(403);
        exit;
    }
    $backupName = basename($_GET['name']);
    $backupPath = getBackupDirectory() . DIRECTORY_SEPARATOR . $backupName;
    if (!preg_match('/^backup_[a-zA-Z0-9_-]+\.tar$/', $backupName) || !is_file($backupPath)) {
        http_response_code(404);
        exit;
    }
    streamFileResponse($backupPath, 'application/x-tar', $backupName, 'attachment');
}

$folderInfo = null;
if ($currentFolder) {
    $stmt = $conn->prepare('SELECT id, name, parent_id, user_id, visibility, password_hash FROM files WHERE id = ? AND type = \'folder\' AND deleted_at IS NULL');
    $stmt->bind_param('i', $currentFolder);
    $stmt->execute();
    $folderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$folderInfo && !$isAdmin) {
        $rootStmt = $conn->prepare('SELECT id, name, parent_id, user_id, visibility, password_hash FROM files WHERE user_id = ? AND name = \'/\' AND parent_id IS NULL AND type = \'folder\' AND deleted_at IS NULL');
        $rootStmt->bind_param('i', $userId);
        $rootStmt->execute();
        $folderInfo = $rootStmt->get_result()->fetch_assoc();
        $rootStmt->close();
        $currentFolder = $folderInfo ? (int) $folderInfo['id'] : null;
    }

    if ($folderInfo) {
        $folderOwnerId = (int) $folderInfo['user_id'];
        $unlockedAt = $_SESSION['unlocked_folders'][$currentFolder] ?? 0;
        $isRootFolder = $folderInfo['name'] === '/' && $folderInfo['parent_id'] === null;
        $hasUnlockedAccess = $unlockedAt > 0;
        $hasAccess = $isRootFolder || $folderInfo['visibility'] === 'public' || $hasUnlockedAccess;
        if (!$hasAccess) {
            $lockedFolderId = $currentFolder;
            $error = 'Esta carpeta está protegida. Introduce su contraseña para abrirla.';
            $rootStmt = $conn->prepare('SELECT id FROM files WHERE user_id = ? AND name = \'/\' AND parent_id IS NULL AND deleted_at IS NULL');
            $rootStmt->bind_param('i', $userId);
            $rootStmt->execute();
            $root = $rootStmt->get_result()->fetch_assoc();
            $rootStmt->close();
            $currentFolder = $root ? (int) $root['id'] : null;
            $folderInfo = null;
            $folderOwnerId = $userId;
        }
    }
}

$breadcrumb = [];
if ($currentFolder) {
    $breadcrumb = getBreadcrumb($conn, $currentFolder, $folderOwnerId);
}

if ($action === 'create_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folderName = trim($_POST['folder_name'] ?? '');
    $folderName = preg_replace('~[\\/:*?"<>|]~', '_', $folderName);
    if ($folderName !== '') {
        $stmt = $conn->prepare('SELECT id FROM files WHERE parent_id = ? AND name = ? AND type = \'folder\' AND deleted_at IS NULL');
        $stmt->bind_param('is', $currentFolder, $folderName);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            $visibility = ($_POST['visibility'] ?? 'private') === 'public' ? 'public' : 'private';
            $folderPassword = trim($_POST['folder_password'] ?? '');
            if ($visibility === 'private' && $folderPassword === '') {
                $error = 'Las carpetas privadas necesitan una contraseña.';
            } else {
                $passwordHash = $visibility === 'private' ? password_hash($folderPassword, PASSWORD_DEFAULT) : null;
                $insertStmt = $conn->prepare('INSERT INTO files (user_id, name, type, visibility, password_hash, parent_id) VALUES (?, ?, \'folder\', ?, ?, ?)');
                $insertStmt->bind_param('isssi', $userId, $folderName, $visibility, $passwordHash, $currentFolder);
                if ($insertStmt->execute()) {
                    $message = 'Carpeta creada.';
                } else {
                    $error = 'No se pudo crear la carpeta.';
                }
                $insertStmt->close();
            }
        } else {
            $error = 'Ya existe una carpeta con ese nombre.';
            $stmt->close();
        }
    }
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['files']) && !isset($_FILES['folder_files'])) {
    $error = 'El archivo supera el límite permitido o no pudo recibirse. El máximo configurado es 50 GB.';
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_FILES['files']) || isset($_FILES['folder_files']))) {
    $uploads = [];
    $folderPaths = isset($_POST['folder_paths']) && is_array($_POST['folder_paths']) ? $_POST['folder_paths'] : [];
    foreach (['files', 'folder_files'] as $uploadKey) {
        if (!isset($_FILES[$uploadKey]) || !is_array($_FILES[$uploadKey]['name'])) {
            continue;
        }
        foreach (array_keys($_FILES[$uploadKey]['name']) as $uploadIndex) {
            $fullPath = $_FILES[$uploadKey]['full_path'][$uploadIndex] ?? '';
            if ($fullPath === '' && $uploadKey === 'folder_files') {
                $fullPath = $folderPaths[$uploadIndex] ?? '';
            }
            $uploads[] = [
                'name' => $_FILES[$uploadKey]['name'][$uploadIndex],
                'full_path' => $fullPath,
                'type' => $_FILES[$uploadKey]['type'][$uploadIndex] ?? '',
                'tmp_name' => $_FILES[$uploadKey]['tmp_name'][$uploadIndex],
                'error' => $_FILES[$uploadKey]['error'][$uploadIndex],
                'size' => $_FILES[$uploadKey]['size'][$uploadIndex]
            ];
        }
    }
    $fileCount = count($uploads);
    $uploadedCount = 0;
    $duplicateAction = ($_POST['duplicate_action'] ?? 'rename') === 'replace' ? 'replace' : 'rename';
    $relativeBase = getFolderRelativePath($conn, $currentFolder, $folderOwnerId);
    $folderCache = ['' => $currentFolder];

    for ($index = 0; $index < $fileCount; $index++) {
        $file = $uploads[$index];
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            continue;
        }
        $rawPath = str_replace('\\', '/', $file['full_path'] ?: $file['name']);
        $pathParts = array_values(array_filter(explode('/', $rawPath), function ($part) {
            return $part !== '' && $part !== '.' && $part !== '..';
        }));
        if (!$pathParts) {
            continue;
        }
        $safeParts = array_map(function ($part) {
            return trim(preg_replace('~[\\/:*?"<>|]~', '_', $part));
        }, $pathParts);
        $fileName = basename(array_pop($safeParts));
        if ($fileName === '') {
            continue;
        }
        $normalizedFile = normalizeUploadedFile($file['tmp_name'], $fileName, $file['type']);
        $fileName = $normalizedFile['name'];
        $mimeType = $normalizedFile['mime_type'];
        $extension = $normalizedFile['extension'];
        $file['tmp_name'] = $normalizedFile['path'];
        $folderPath = implode('/', $safeParts);
        if (!isset($folderCache[$folderPath])) {
            $parentId = $currentFolder;
            $builtPath = [];
            foreach ($safeParts as $folderName) {
                $builtPath[] = $folderName;
                $builtPathKey = implode('/', $builtPath);
                if (isset($folderCache[$builtPathKey])) {
                    $parentId = $folderCache[$builtPathKey];
                    continue;
                }
                $folderStmt = $conn->prepare('SELECT id FROM files WHERE parent_id = ? AND name = ? AND type = \'folder\' AND deleted_at IS NULL');
                $folderStmt->bind_param('is', $parentId, $folderName);
                $folderStmt->execute();
                $existingFolder = $folderStmt->get_result()->fetch_assoc();
                $folderStmt->close();
                if ($existingFolder) {
                    $parentId = (int) $existingFolder['id'];
                    if ($duplicateAction === 'rename') {
                        $baseFolderName = $folderName;
                        $suffix = 1;
                        do {
                            $folderName = $baseFolderName . ' (' . $suffix . ')';
                            $folderStmt = $conn->prepare('SELECT id FROM files WHERE parent_id = ? AND name = ? AND type = \'folder\' AND deleted_at IS NULL');
                            $folderStmt->bind_param('is', $parentId, $folderName);
                            $folderStmt->execute();
                            $duplicateFolder = $folderStmt->get_result()->fetch_assoc();
                            $folderStmt->close();
                            $suffix++;
                        } while ($duplicateFolder);
                        $folderStmt = $conn->prepare('INSERT INTO files (user_id, name, type, visibility, password_hash, parent_id) VALUES (?, ?, \'folder\', \'public\', NULL, ?)');
                        $folderStmt->bind_param('isi', $userId, $folderName, $parentId);
                        $folderStmt->execute();
                        $parentId = $folderStmt->insert_id;
                        $folderStmt->close();
                    }
                } else {
                    $visibility = 'public';
                    $passwordHash = null;
                    $folderStmt = $conn->prepare('INSERT INTO files (user_id, name, type, visibility, password_hash, parent_id) VALUES (?, ?, \'folder\', ?, ?, ?)');
                    $folderStmt->bind_param('isssi', $userId, $folderName, $visibility, $passwordHash, $parentId);
                    $folderStmt->execute();
                    $parentId = $folderStmt->insert_id;
                    $folderStmt->close();
                }
                $folderCache[$builtPathKey] = $parentId;
            }
            $folderCache[$folderPath] = $parentId;
        }
        $parentId = $folderCache[$folderPath];
        $uuid = generateFileUuid();
        $destPath = getStorageFilePath($uuid);
        $fileStmt = $conn->prepare('SELECT id, uuid FROM files WHERE parent_id = ? AND name = ? AND type = \'file\' AND deleted_at IS NULL');
        $fileStmt->bind_param('is', $parentId, $fileName);
        $fileStmt->execute();
        $existingFile = $fileStmt->get_result()->fetch_assoc();
        $fileStmt->close();
        if ($existingFile && $duplicateAction === 'rename') {
            $baseFileName = pathinfo($fileName, PATHINFO_FILENAME);
            $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
            $suffix = 1;
            do {
                $candidateName = $baseFileName . ' (' . $suffix . ')' . ($fileExtension !== '' ? '.' . $fileExtension : '');
                $fileStmt = $conn->prepare('SELECT id FROM files WHERE parent_id = ? AND name = ? AND deleted_at IS NULL');
                $fileStmt->bind_param('is', $parentId, $candidateName);
                $fileStmt->execute();
                $duplicateName = $fileStmt->get_result()->fetch_assoc();
                $fileStmt->close();
                $suffix++;
            } while ($duplicateName);
            $fileName = $candidateName;
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $existingFile = null;
        }
        if (!ensureStorageDirectory() || !streamUploadedFile($file['tmp_name'], $destPath)) {
            continue;
        }
        $logicalPath = $relativeBase === false ? '/' : ($relativeBase === '' ? '/' : '/' . $relativeBase);
        if ($folderPath !== '') {
            $logicalPath .= ($logicalPath === '/' ? '' : '/') . $folderPath;
        }
        if ($existingFile && $duplicateAction === 'replace') {
            $oldPath = getStorageFilePath($existingFile['uuid']);
            if (is_string($oldPath) && is_file($oldPath)) {
                unlink($oldPath);
            }
            $stmt = $conn->prepare('UPDATE files SET uuid = ?, user_id = ?, size = ?, mime_type = ?, extension = ?, file_path = ? WHERE id = ?');
            $stmt->bind_param('sissssi', $uuid, $userId, $file['size'], $mimeType, $extension, $logicalPath, $existingFile['id']);
        } else {
            $stmt = $conn->prepare('INSERT INTO files (uuid, user_id, name, type, size, mime_type, extension, parent_id, file_path) VALUES (?, ?, ?, \'file\', ?, ?, ?, ?, ?)');
            $stmt->bind_param('sissssis', $uuid, $userId, $fileName, $file['size'], $mimeType, $extension, $parentId, $logicalPath);
        }
        if ($stmt->execute()) {
            $uploadedCount++;
        } else {
            unlink($destPath);
        }
        $stmt->close();
    }
    if ($uploadedCount > 0) {
        $message = $uploadedCount === 1 ? 'Archivo subido.' : $uploadedCount . ' archivos subidos.';
    } else {
        $error = 'No se pudo subir el contenido seleccionado.';
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $deleteId = (int) $_GET['id'];
    $deletePassword = $_POST['delete_password'] ?? '';
    $stmt = $conn->prepare('SELECT id, name, type, uuid, user_id, visibility, password_hash FROM files WHERE id = ? AND deleted_at IS NULL' . ($isAdmin ? '' : ' AND user_id = ?'));
    if ($isAdmin) {
        $stmt->bind_param('i', $deleteId);
    } else {
        $stmt->bind_param('ii', $deleteId, $userId);
    }
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($item) {
        if ($item['type'] === 'folder' && $item['visibility'] === 'private' && !password_verify($deletePassword, (string) $item['password_hash'])) {
            $error = 'La contraseña de la carpeta no es correcta.';
        } else {
            markFileTreeDeleted($conn, $deleteId);
            $message = 'Elemento movido a la papelera.';
        }
    }
}

if ($action === 'rename' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $newName = trim($_POST['new_name'] ?? '');
    $newName = preg_replace('~[\\/:*?"<>|]~', '_', $newName);

    if ($itemId && $newName !== '') {
        $stmt = $conn->prepare('SELECT id, name, type, uuid FROM files WHERE id = ? AND deleted_at IS NULL' . ($isAdmin ? '' : ' AND user_id = ?'));
        if ($isAdmin) {
            $stmt->bind_param('i', $itemId);
        } else {
            $stmt->bind_param('ii', $itemId, $userId);
        }
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($item) {
            $stmt = $conn->prepare('SELECT id FROM files WHERE parent_id = (SELECT parent_id FROM files WHERE id = ?) AND name = ? AND id != ? AND deleted_at IS NULL');
            $stmt->bind_param('isi', $itemId, $newName, $itemId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                if ($item['type'] === 'file' && $item['uuid']) {
                    $newExtension = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
                    $stmt2 = $conn->prepare('UPDATE files SET name = ?, extension = ? WHERE id = ?');
                    $stmt2->bind_param('ssi', $newName, $newExtension, $itemId);
                    $stmt2->execute();
                    $stmt2->close();
                } else {
                    $stmt2 = $conn->prepare('UPDATE files SET name = ? WHERE id = ?');
                    $stmt2->bind_param('si', $newName, $itemId);
                    $stmt2->execute();
                    $stmt2->close();
                }
                if ($item['type'] === 'folder') {
                    unset($_SESSION['unlocked_folders'][$itemId]);
                }
                $message = 'Elemento renombrado.';
            } else {
                $error = 'Ya existe un elemento con ese nombre.';
            }
            $stmt->close();
        }
    }
}

if ($action === 'preview' && isset($_GET['id'])) {
    $previewId = (int) $_GET['id'];
    $stmt = $conn->prepare('SELECT name, uuid, mime_type, extension, parent_id FROM files WHERE id = ? AND type = \'file\' AND deleted_at IS NULL');
    $stmt->bind_param('i', $previewId);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($file && canAccessFile($conn, (int) $file['parent_id'])) {
        $fullPath = getStorageFilePath($file['uuid']);
        $mimeType = $file['mime_type'] ?: 'application/octet-stream';
        if (is_string($fullPath) && is_file($fullPath)) {
            streamFileResponse($fullPath, $mimeType, $file['name']);
        }
        http_response_code(404);
        exit;
    }
    http_response_code($file ? 403 : 404);
    exit;
}

if ($action === 'download' && isset($_GET['id'])) {
    $downloadId = (int) $_GET['id'];
    $stmt = $conn->prepare('SELECT name, uuid, mime_type, parent_id FROM files WHERE id = ? AND type = \'file\' AND deleted_at IS NULL');
    $stmt->bind_param('i', $downloadId);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($file && canAccessFile($conn, (int) $file['parent_id'])) {
        $fullPath = getStorageFilePath($file['uuid']);
        if (is_string($fullPath) && file_exists($fullPath)) {
            streamFileResponse($fullPath, $file['mime_type'], $file['name'], 'attachment');
        }
    }
    http_response_code($file ? 403 : 404);
    exit;
}

if ($trashView) {
    $stmt = $conn->prepare('SELECT files.id, files.name, files.type, files.size, files.mime_type, files.extension, files.visibility, files.user_id, files.created_at FROM files LEFT JOIN files AS parent ON parent.id = files.parent_id WHERE files.deleted_at IS NOT NULL AND (files.parent_id IS NULL OR parent.deleted_at IS NULL) ORDER BY files.deleted_at DESC, files.name ASC');
} elseif ($search !== '') {
    $stmt = $conn->prepare('SELECT id, name, type, size, mime_type, extension, visibility, user_id, created_at FROM files WHERE user_id = ? AND name LIKE ? AND id != ? AND deleted_at IS NULL ORDER BY type DESC, name ASC');
    $searchParam = '%' . $search . '%';
    $stmt->bind_param('isi', $userId, $searchParam, $currentFolder);
} else {
    $stmt = $conn->prepare('SELECT id, name, type, size, mime_type, extension, visibility, user_id, created_at FROM files WHERE parent_id = ? AND deleted_at IS NULL ORDER BY type DESC, name ASC');
    $stmt->bind_param('i', $currentFolder);
}
$stmt->execute();
$items = $stmt->get_result();
$stmt->close();

$sidebarFolders = [];
$sidebarStmt = $conn->prepare('SELECT files.id, files.name, files.visibility, users.username FROM files INNER JOIN users ON users.id = files.user_id WHERE files.parent_id IN (SELECT id FROM files WHERE name = \'/\' AND parent_id IS NULL AND type = \'folder\' AND deleted_at IS NULL) AND files.type = \'folder\' AND files.deleted_at IS NULL ORDER BY users.username ASC, files.name ASC');
$sidebarStmt->execute();
$sidebarResult = $sidebarStmt->get_result();
while ($sidebarFolder = $sidebarResult->fetch_assoc()) {
    $sidebarFolders[] = $sidebarFolder;
}
$sidebarStmt->close();

$managedUsers = [];
$availableBackups = [];
$backupInProgress = false;
if ($isAdmin) {
    $usersResult = $conn->query('SELECT id, username, role FROM users ORDER BY username ASC');
    while ($managedUser = $usersResult->fetch_assoc()) {
        $managedUsers[] = $managedUser;
    }
    $availableBackups = getAvailableBackups();
    $backupInProgress = isBackupInProgress();
}

$assetVersion = substr(hash('sha256', file_get_contents(__DIR__ . '/recursos/estilos/dashboard.css') . file_get_contents(__DIR__ . '/recursos/scripts/dashboard.js') . file_get_contents(__DIR__ . '/recursos/scripts/password-toggle.js')), 0, 12);
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A.R.C.A</title>
    <link rel="icon" type="image/jpeg" href="logo/A.R.C.A.jpeg">
    <link rel="stylesheet" href="recursos/estilos/dashboard.css?v=<?php echo urlencode($assetVersion); ?>">
</head>

<body data-current-folder="<?php echo htmlspecialchars((string) $currentFolder); ?>">
    <header class="topbar">
        <div class="brand">
            <img src="logo/A.R.C.A.jpeg" alt="A.R.C.A">
            <span>A.R.C.A</span>
        </div>
        <div class="topbar-right">
            <form class="search" method="get" action="dashboard.php">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Buscar archivos">
                <button type="submit" aria-label="Buscar">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="11" cy="11" r="6"></circle>
                        <path d="M20 20l-4.2-4.2"></path>
                    </svg>
                </button>
            </form>
            <button class="user-pill" type="button" onclick="openModal('profile')" aria-label="Abrir perfil">
                <?php if (!empty($currentUser['profile_image'])): ?>
                    <img class="avatar avatar-image" src="dashboard.php?action=profile_image&v=<?php echo urlencode($currentUser['profile_image']); ?>" alt="Perfil de <?php echo htmlspecialchars($_SESSION['username']); ?>">
                <?php else: ?>
                    <span class="avatar"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></span>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            </button>
            <?php if ($isAdmin): ?>
                <button class="admin-toggle" type="button" onclick="openModal('admin')" aria-label="Administrar usuarios">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M12 3l2.2 2.2 3.1-.4.9 3 2.7 1.6-1.5 2.8 1.5 2.8-2.7 1.6-.9 3-3.1-.4L12 21l-2.2-2.2-3.1.4-.9-3-2.7-1.6 1.5-2.8-1.5-2.8 2.7-1.6.9-3 3.1.4L12 3z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    <span>Administrar</span>
                </button>
                <button class="admin-toggle backup-toggle" type="button" onclick="openModal('backups')" aria-label="Gestionar copias de seguridad">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M4 4h16v16H4z"></path>
                        <path d="M8 4v5h8V4"></path>
                        <path d="M8 15h8"></path>
                        <path d="M8 18h5"></path>
                    </svg>
                    <span>Copias</span>
                </button>
            <?php endif; ?>
            <a class="logout" href="logout.php">Salir</a>
        </div>
    </header>
    <div class="layout">
        <aside class="sidebar">
            <button class="btn primary" type="button" onclick="openModal('upload')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 4v12"></path>
                    <path d="M7 9l5-5 5 5"></path>
                    <path d="M5 19h14"></path>
                </svg>
                Subir archivo
            </button>
            <button class="btn secondary" type="button" onclick="openModal('folder')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M4 7h6l2 2h8a1 1 0 0 1 1 1v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"></path>
                </svg>
                Nueva carpeta
            </button>
            <div class="small">Almacenamiento</div>
            <a class="btn secondary" href="dashboard.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M3 10.5L12 3l9 7.5"></path>
                    <path d="M5 9.5V20h14V9.5"></path>
                </svg>
                Inicio
            </a>
            <?php if ($isAdmin): ?>
                <a class="btn secondary" href="dashboard.php?trash=1">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M4 7h16"></path>
                        <path d="M9 7V4h6v3"></path>
                        <path d="M7 7l1 13h8l1-13"></path>
                    </svg>
                    Papelera
                </a>
            <?php endif; ?>
            <nav class="folder-list" aria-label="Carpetas">
                <?php foreach ($sidebarFolders as $sidebarFolder): ?>
                    <a class="folder-link" href="dashboard.php?folder=<?php echo (int) $sidebarFolder['id']; ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M4 7h6l2 2h8a1 1 0 0 1 1 1v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"></path>
                        </svg>
                        <span><?php echo htmlspecialchars($sidebarFolder['name']); ?><?php echo ' (' . htmlspecialchars($sidebarFolder['username']) . ')'; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="storage-card">
                <?php
                $storageRoot = is_dir(DRIVE_STORAGE_PATH) ? DRIVE_STORAGE_PATH : __DIR__;
                $diskTotal = @disk_total_space($storageRoot);
                $diskFree = @disk_free_space($storageRoot);
                $hasDiskSpace = $diskTotal !== false && $diskTotal > 0 && $diskFree !== false;
                $diskTotal = $hasDiskSpace ? (int) $diskTotal : 0;
                $diskFree = $hasDiskSpace ? min($diskTotal, max(0, (int) $diskFree)) : 0;
                $percent = $hasDiskSpace ? (int) round(($diskTotal - $diskFree) / $diskTotal * 100) : 0;
                ?>
                <div class="storage-bar">
                    <div style="width: <?php echo $percent; ?>%"></div>
                </div>
                <div style="font-size:0.85rem;color:#64748b"><?php echo $hasDiskSpace ? formatSize($diskFree) . ' disponibles de ' . formatSize($diskTotal) : 'Espacio disponible: No disponible'; ?></div>
            </div>
        </aside>
        <main class="content">
            <?php if ($message !== ''): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <div class="breadcrumb">
                <a href="dashboard.php">A.R.C.A</a>
                <?php if ($trashView): ?>
                    <span>/</span><span>Papelera</span>
                <?php elseif ($search !== ''): ?>
                    <span>/</span><span>Resultados para <?php echo htmlspecialchars($search); ?></span>
                <?php else: ?>
                    <?php foreach ($breadcrumb as $index => $crumb): ?>
                        <?php if ($crumb['name'] !== '/'): ?>
                            <span>/</span>
                            <?php if ($index === count($breadcrumb) - 1): ?>
                                <span><?php echo htmlspecialchars($crumb['name']); ?></span>
                            <?php else: ?>
                                <a href="dashboard.php?folder=<?php echo $crumb['id']; ?>"><?php echo htmlspecialchars($crumb['name']); ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="toolbar">
                <?php if ($trashView): ?>
                    <div>
                        <h2 class="trash-title">Papelera</h2>
                    </div>
                <?php else: ?>
                    <div class="actions">
                        <button class="btn secondary" type="button" onclick="openModal('folder')">Nueva carpeta</button>
                        <button class="btn primary" type="button" onclick="openModal('upload')">Subir</button>
                    </div>
                <?php endif; ?>
                <div style="color:#64748b;font-size:0.9rem"><?php echo $items->num_rows; ?> elemento(s)</div>
            </div>
            <?php if ($trashView && $items->num_rows > 0): ?>
                <div class="grid trash-grid">
                    <?php while ($item = $items->fetch_assoc()): ?>
                        <div class="item trash-item">
                            <div>
                                <div class="icon-wrap"><?php echo renderIcon($item['type'], $item['mime_type'] ?? ''); ?></div>
                                <div class="name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="meta"><?php echo $item['type'] === 'file' ? formatSize((int) $item['size']) : 'Carpeta'; ?> &middot; <?php echo formatDate($item['created_at']); ?></div>
                            </div>
                            <div class="actions">
                                <form method="post" action="dashboard.php?action=trash_restore&trash=1">
                                    <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                    <button type="submit" title="Restaurar">Restaurar</button>
                                </form>
                                <form method="post" action="dashboard.php?action=trash_delete&trash=1" onsubmit="return confirm('¿Eliminar definitivamente este elemento?');">
                                    <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                    <button class="danger" type="submit" title="Eliminar definitivamente">Eliminar</button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php elseif (!$trashView && $items->num_rows > 0): ?>
                <div class="grid">
                    <?php while ($item = $items->fetch_assoc()): ?>
                        <div class="item" data-id="<?php echo $item['id']; ?>" data-type="<?php echo $item['type']; ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>" ondblclick="openFolder(<?php echo (int) $item['id']; ?>, '<?php echo $item['type']; ?>', <?php echo htmlspecialchars(json_encode($item['mime_type'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($item['name'])); ?>)" oncontextmenu="showContextMenu(event, <?php echo $item['id']; ?>, '<?php echo $item['type']; ?>', '<?php echo htmlspecialchars(addslashes($item['name'])); ?>', <?php echo htmlspecialchars(json_encode($item['mime_type'] ?? '')); ?>, '<?php echo $item['visibility'] ?? 'private'; ?>')">
                            <div>
                                <div class="icon-wrap"><?php echo renderIcon($item['type'], $item['mime_type'] ?? ''); ?></div>
                                <div class="name">
                                    <?php if ($item['type'] === 'folder'): ?>
                                        <span class="folder-name" title="Doble clic para abrir"><?php echo htmlspecialchars($item['name']); ?></span>
                                    <?php else: ?>
                                        <a href="dashboard.php?action=download&id=<?php echo $item['id']; ?>" onclick="return openFilePreview(event, <?php echo $item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['mime_type'] ?? '')); ?>, <?php echo htmlspecialchars(json_encode($item['name'])); ?>);" title="Abrir vista previa" download><?php echo htmlspecialchars($item['name']); ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="meta">
                                    <?php if ($item['type'] === 'file'): ?>
                                        <?php echo formatSize((int) $item['size']); ?> &middot; <?php echo formatDate($item['created_at']); ?>
                                    <?php else: ?>
                                        Carpeta <?php echo ($item['visibility'] ?? 'private') === 'public' ? '(Pública)' : '(Privada)'; ?> &middot; <?php echo formatDate($item['created_at']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="actions">
                                <?php if ($item['type'] === 'file'): ?>
                                    <a href="dashboard.php?action=download&id=<?php echo $item['id']; ?>" title="Descargar" download>
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path d="M12 4v10"></path>
                                            <path d="M7 11l5 5 5-5"></path>
                                            <path d="M5 19h14"></path>
                                        </svg>
                                    </a>
                                <?php endif; ?>
                                <?php if ($isAdmin || (int) $item['user_id'] === $userId): ?>
                                    <button type="button" onclick="openRenameModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')" title="Renombrar">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path d="M4 20h4l10-10-4-4L4 16z"></path>
                                            <path d="M13 6l5 5"></path>
                                        </svg>
                                    </button>
                                    <button type="button" onclick="confirmDelete(<?php echo $item['id']; ?>, '<?php echo $item['type']; ?>', '<?php echo $item['visibility'] ?? 'private'; ?>')" title="Eliminar">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                            <path d="M4 7h16"></path>
                                            <path d="M9 7V4h6v3"></path>
                                            <path d="M8 7l1 12h6l1-12"></path>
                                        </svg>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty">
                    <h3 style="margin-bottom:8px;color:#0f172a"><?php echo $trashView ? 'La papelera está vacía' : 'Sin elementos'; ?></h3>
                    <p><?php echo $trashView ? 'Los elementos eliminados aparecerán aquí.' : 'Sube archivos o crea una carpeta para empezar.'; ?></p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div class="modal" id="folder-modal">
        <div class="modal-card">
            <h3>Nueva carpeta</h3>
            <form method="post" action="dashboard.php?action=create_folder&folder=<?php echo $currentFolder; ?>">
                <input type="text" name="folder_name" placeholder="Nombre de la carpeta" required>
                <label class="choice"><input type="radio" name="visibility" value="private" checked onchange="toggleFolderPassword(this.form)"> Privada</label>
                <label class="choice"><input type="radio" name="visibility" value="public" onchange="toggleFolderPassword(this.form)"> Pública</label>
                <div class="folder-password-field">
                    <input type="password" name="folder_password" placeholder="Contraseña requerida" autocomplete="new-password" required>
                </div>
                <small class="field-help">Las carpetas públicas no requieren contraseña. Las privadas sí.</small>
                <div class="row">
                    <button class="cancel" type="button" onclick="closeModal('folder')">Cancelar</button>
                    <button class="confirm" type="submit">Crear</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($lockedFolderId): ?>
        <div class="modal show" id="unlock-folder-modal">
            <div class="modal-card">
                <h3>Carpeta protegida</h3>
                <p class="field-help">Introduce la contraseña para abrir esta carpeta.</p>
                <form method="post" action="dashboard.php?folder=<?php echo $lockedFolderId; ?>&action=unlock_folder">
                    <input type="hidden" name="folder_id" value="<?php echo $lockedFolderId; ?>">
                    <input type="password" name="folder_password" placeholder="Contraseña" required autofocus>
                    <div class="row">
                        <a class="cancel" href="dashboard.php">Cancelar</a>
                        <button class="confirm" type="submit">Abrir carpeta</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <div class="modal admin-modal" id="admin-modal">
            <div class="modal-card admin-card">
                <div class="admin-header">
                    <div>
                        <span class="admin-kicker">A.R.C.A / CONTROL</span>
                        <h3>Administrar usuarios</h3>
                    </div>
                    <button class="preview-close" type="button" onclick="closeModal('admin')" aria-label="Cerrar administración">&times;</button>
                </div>
                <div class="admin-stats">
                    <div><strong><?php echo count($managedUsers); ?></strong><span>Total</span></div>
                    <div><strong><?php echo count(array_filter($managedUsers, function ($managedUser) {
                                        return $managedUser['role'] === 'admin';
                                    })); ?></strong><span>Administradores</span></div>
                    <div><strong><?php echo count(array_filter($managedUsers, function ($managedUser) {
                                        return $managedUser['role'] === 'user';
                                    })); ?></strong><span>Usuarios</span></div>
                </div>
                <form class="admin-create" method="post" action="dashboard.php?action=create_user&folder=<?php echo $currentFolder; ?>">
                    <div class="admin-section-heading">
                        <div>
                            <div class="admin-section-title">Nuevo usuario</div>
                            <p>Crea una cuenta y define su nivel de acceso.</p>
                        </div>
                        <span class="section-mark">+</span>
                    </div>
                    <div class="admin-form-grid">
                        <label><span>Usuario</span><input type="text" name="username" placeholder="Nombre de usuario" required></label>
                        <label><span>Contraseña</span><input type="password" name="password" placeholder="Contraseña" required></label>
                        <label><span>Rol</span><select name="role">
                                <option value="user">Usuario</option>
                                <option value="admin">Administrador</option>
                            </select></label>
                        <button class="confirm" type="submit">Crear usuario</button>
                    </div>
                </form>
                <div class="admin-section-heading accounts-heading">
                    <div>
                        <div class="admin-section-title">Cuentas registradas</div>
                        <p>Administra nombres, roles y contraseñas.</p>
                    </div>
                </div>
                <div class="user-admin-list">
                    <?php foreach ($managedUsers as $managedUser): ?>
                        <form class="managed-user" method="post" action="dashboard.php?action=update_user&folder=<?php echo $currentFolder; ?>">
                            <input type="hidden" name="user_id" value="<?php echo (int) $managedUser['id']; ?>">
                            <div class="managed-user-identity">
                                <span class="user-dot <?php echo $managedUser['role'] === 'admin' ? 'is-admin' : ''; ?>"></span>
                                <div><strong><?php echo htmlspecialchars($managedUser['username']); ?></strong><span class="role-badge <?php echo $managedUser['role'] === 'admin' ? 'is-admin' : ''; ?>"><?php echo $managedUser['role'] === 'admin' ? 'Administrador' : 'Usuario'; ?></span></div>
                            </div>
                            <label><span>Nombre</span><input type="text" name="username" value="<?php echo htmlspecialchars($managedUser['username']); ?>" required aria-label="Nombre de usuario"></label>
                            <label><span>Nueva contraseña</span><input type="password" name="password" placeholder="Sin cambios" aria-label="Nueva contraseña"></label>
                            <label><span>Rol</span><select name="role" aria-label="Rol">
                                    <option value="user" <?php echo $managedUser['role'] === 'user' ? 'selected' : ''; ?>>Usuario</option>
                                    <option value="admin" <?php echo $managedUser['role'] === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                                </select></label>
                            <div class="managed-user-actions"><button class="confirm" type="submit">Guardar</button>
                                <?php if ((int) $managedUser['id'] !== $userId): ?>
                                    <button class="danger" type="submit" formaction="dashboard.php?action=delete_user&folder=<?php echo $currentFolder; ?>">Eliminar</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <div class="modal admin-modal" id="backups-modal">
            <div class="modal-card admin-card backup-card">
                <div class="admin-header">
                    <div>
                        <span class="admin-kicker">A.R.C.A / RESPALDOS</span>
                        <h3>Copias de seguridad</h3>
                    </div>
                    <button class="preview-close" type="button" onclick="closeModal('backups')" aria-label="Cerrar copias de seguridad">&times;</button>
                </div>
                <form class="backup-create" method="post" action="dashboard.php?action=create_backup">
                    <div>
                        <div class="admin-section-title">Crear copia completa</div>
                        <p>Incluye la base de datos y los archivos almacenados.</p>
                    </div>
                    <button class="confirm" type="submit">Crear copia</button>
                </form>
                <form class="backup-upload" method="post" action="dashboard.php?action=upload_backup" enctype="multipart/form-data">
                    <label for="backup-file">Subir una copia existente</label>
                    <input id="backup-file" type="file" name="backup_file" accept=".tar,application/x-tar" required>
                    <button class="confirm" type="submit">Subir copia</button>
                </form>
                <div class="admin-section-heading accounts-heading">
                    <div>
                        <div class="admin-section-title">Copias disponibles</div>
                        <p><?php echo count($availableBackups); ?> copia(s) guardada(s).<?php echo $backupInProgress ? ' Hay una copia en proceso.' : ''; ?></p>
                    </div>
                </div>
                <?php if ($availableBackups): ?>
                    <div class="backup-list">
                        <?php foreach ($availableBackups as $backup): ?>
                            <div class="backup-row">
                                <div>
                                    <strong><?php echo htmlspecialchars($backup['name']); ?></strong>
                                    <span><?php echo formatSize((int) $backup['size']); ?> &middot; <?php echo date('d/m/Y H:i', (int) $backup['created_at']); ?></span>
                                </div>
                                <a class="confirm" href="dashboard.php?action=download_backup&amp;name=<?php echo rawurlencode($backup['name']); ?>">Descargar</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty backup-empty">Todavía no hay copias de seguridad.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal profile-modal" id="profile-modal">
        <div class="modal-card profile-card">
            <div class="profile-header">
                <div class="profile-avatar-wrap">
                    <?php if (!empty($currentUser['profile_image'])): ?>
                        <img class="profile-avatar" src="dashboard.php?action=profile_image&v=<?php echo urlencode($currentUser['profile_image']); ?>" alt="Imagen de perfil">
                    <?php else: ?>
                        <span class="profile-avatar profile-initial"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="admin-kicker">MI CUENTA</span>
                    <h3><?php echo htmlspecialchars($_SESSION['username']); ?></h3>
                    <span class="profile-role"><?php echo $isAdmin ? 'Administrador' : 'Usuario'; ?></span>
                </div>
                <button class="preview-close" type="button" onclick="closeModal('profile')" aria-label="Cerrar perfil">&times;</button>
            </div>
            <section class="profile-section">
                <div class="admin-section-title">Imagen de perfil</div>
                <form class="profile-image-form" method="post" action="dashboard.php?action=update_profile_image&folder=<?php echo $currentFolder; ?>" enctype="multipart/form-data">
                    <input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp" required>
                    <button class="confirm" type="submit">Actualizar imagen</button>
                </form>
            </section>
            <section class="profile-section">
                <div class="admin-section-title">Cambiar contraseña</div>
                <form class="password-form" method="post" action="dashboard.php?action=change_password&folder=<?php echo $currentFolder; ?>">
                    <input type="password" name="current_password" placeholder="Contraseña actual" required>
                    <input type="password" name="new_password" id="new-password" placeholder="Nueva contraseña" minlength="8" required oninput="updatePasswordStrength(this.value)">
                    <div class="password-meter" aria-live="polite"><span id="password-meter-fill"></span></div>
                    <div class="password-strength" id="password-strength">Usa 8 caracteres o más</div>
                    <input type="password" name="confirm_password" placeholder="Repite la nueva contraseña" minlength="8" required>
                    <button class="confirm" type="submit">Cambiar contraseña</button>
                </form>
            </section>
        </div>
    </div>

    <div class="modal" id="upload-modal">
        <div class="modal-card">
            <h3>Subir archivo</h3>
            <form id="upload-form" method="post" action="dashboard.php?action=upload&folder=<?php echo $currentFolder; ?>" enctype="multipart/form-data" onsubmit="return confirmUploadDuplicates(event)">
                <label class="upload-dropzone" id="upload-dropzone">
                    <input type="file" id="upload-files" name="files[]" accept="*/*" multiple>
                    <span class="upload-dropzone-title">Arrastra archivos o carpetas aquí o haz clic para seleccionarlos</span>
                    <span class="upload-dropzone-status" id="upload-dropzone-status" aria-live="polite">Se pueden subir varios archivos a la vez</span>
                </label>
                <label class="upload-choice">Carpeta completa
                    <input type="file" name="folder_files[]" accept="*/*" webkitdirectory directory multiple>
                </label>
                <input type="hidden" name="duplicate_action" id="duplicate-action" value="rename">
                <small class="field-help">Se aceptan todos los formatos, incluidos .py, .php, .js y .html. Puedes seleccionar archivos o una carpeta completa.</small>
                <div class="row">
                    <button class="cancel" type="button" onclick="closeModal('upload')">Cancelar</button>
                    <button class="confirm" type="submit">Subir</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="duplicate-upload-modal">
        <div class="modal-card duplicate-card">
            <span class="admin-kicker">ARCHIVOS EXISTENTES</span>
            <h3>¿Qué deseas hacer?</h3>
            <p class="field-help">Si algún archivo o carpeta ya existe en el destino, elige cómo conservar la nueva versión.</p>
            <div class="duplicate-actions">
                <button class="duplicate-option replace" type="button" onclick="submitUploadWithDuplicateAction('replace')">
                    <strong>Reemplazar</strong>
                    <span>Usar el nuevo contenido y conservar el nombre.</span>
                </button>
                <button class="duplicate-option rename" type="button" onclick="submitUploadWithDuplicateAction('rename')">
                    <strong>Añadir con sufijo</strong>
                    <span>Crear una copia como archivo (1), (2), etc.</span>
                </button>
            </div>
            <button class="cancel duplicate-cancel" type="button" onclick="closeModal('duplicate-upload')">Cancelar</button>
        </div>
    </div>

    <div class="modal" id="rename-modal">
        <div class="modal-card">
            <h3>Renombrar</h3>
            <form method="post" action="dashboard.php?action=rename&folder=<?php echo $currentFolder; ?>">
                <input type="hidden" name="item_id" id="rename-id">
                <input type="text" name="new_name" id="rename-name" required>
                <div class="row">
                    <button class="cancel" type="button" onclick="closeModal('rename')">Cancelar</button>
                    <button class="confirm" type="submit">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="delete-modal">
        <div class="modal-card">
            <h3>Eliminar</h3>
            <p style="margin-bottom:14px;color:#64748b">&iquest;Deseas eliminar este elemento?</p>
            <form method="post" id="delete-form">
                <input type="hidden" name="delete_password" id="delete-password" disabled>
                <div class="row">
                    <button class="cancel" type="button" onclick="closeModal('delete')">Cancelar</button>
                    <button class="danger" type="submit">Eliminar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal preview-modal" id="preview-modal">
        <div class="modal-card preview-card">
            <div class="preview-header">
                <h3 id="preview-title">Vista previa</h3>
                <button class="preview-close" type="button" onclick="closePreview()" aria-label="Cerrar vista previa">&times;</button>
            </div>
            <div class="preview-content" id="preview-content"></div>
            <div class="preview-footer">
                <a class="confirm" id="preview-download" href="#">Descargar</a>
                <button class="cancel" type="button" onclick="closePreview()">Cerrar</button>
            </div>
        </div>
    </div>

    <div class="context-menu" id="context-menu">
        <button type="button" onclick="contextAction('open')">Abrir</button>
        <button type="button" onclick="contextAction('download')">Descargar</button>
        <button type="button" onclick="contextAction('rename')">Renombrar</button>
        <button type="button" onclick="contextAction('delete')">Eliminar</button>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.8.0/mammoth.browser.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="recursos/scripts/password-toggle.js?v=<?php echo urlencode($assetVersion); ?>"></script>
    <script src="recursos/scripts/dashboard.js?v=<?php echo urlencode($assetVersion); ?>"></script>
</body>

</html>