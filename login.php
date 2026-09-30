<?php
require_once 'config/database.php';
initSession();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (isAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Ingresa tu usuario y contrasena.';
    } else {
        $conn = getConnection();
        $conn->select_db(DB_NAME);

        $stmt = $conn->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                header('Location: dashboard.php');
                exit;
            }
            $error = 'Contraseña incorrecta.';
        } else {
            $error = 'El usuario no existe.';
        }

        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar sesión</title>
    <link rel="icon" type="image/jpeg" href="logo/A.R.C.A.jpeg">
    <link rel="stylesheet" href="recursos/estilos/login.css?v=<?php echo urlencode((string) filemtime(__DIR__ . '/recursos/estilos/login.css')); ?>">
</head>

<body>
    <main class="card">
        <div class="brand">
            <img src="logo/A.R.C.A.jpeg" alt="A.R.C.A">
            <span>A.R.C.A</span>
        </div>
        <p class="subtitle">Espacio privado para guardar y organizar archivos.</p>
        <?php if ($error !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="field">
                <label for="username">Usuario</label>
                <input id="username" name="username" type="text" required autofocus>
            </div>
            <div class="field">
                <label for="password">Contraseña</label>
                <input id="password" name="password" type="password" required>
            </div>
            <button class="btn" type="submit">Entrar</button>
        </form>
    </main>
    <script src="recursos/scripts/password-toggle.js?v=<?php echo urlencode((string) filemtime(__DIR__ . '/recursos/scripts/password-toggle.js')); ?>"></script>
</body>

</html>