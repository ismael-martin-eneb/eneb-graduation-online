<?php
/**
 * API de autenticación del panel.
 *
 *   GET  /api/auth.php            → estado: {user|null, csrf, needsSetup}
 *   POST /api/auth.php?action=login            {username, password}
 *   POST /api/auth.php?action=logout
 *   POST /api/auth.php?action=change_password  {current_password, new_password}
 *   POST /api/auth.php?action=setup            {setup_key, username, email, password}
 *        → solo si no existe ningún usuario; setup_key = ADMIN_PASSWORD de config.php
 */

declare(strict_types=1);

require_once __DIR__ . '/auth-lib.php';
apiBootstrap(['GET', 'POST']);

$pdo = getDb();
try {
    ensureAuthSchema($pdo);
} catch (\PDOException $e) {
    error_log('[auth] schema: ' . $e->getMessage());
    jsonError(500, 'No se pudo preparar el esquema de usuarios');
}
startSecureSession();

$userCount  = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
$needsSetup = $userCount === 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['user' => currentUser(), 'csrf' => csrfToken(), 'needsSetup' => $needsSetup]);
    exit;
}

$input  = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$action = $_GET['action'] ?? '';
$ip     = substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);

// ── Login ────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
    $password = (string) ($input['password'] ?? '');

    // Rate limiting: 5 fallos / 15 min por IP o por usuario
    $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    $q = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE (ip = ? OR username = ?) AND created_at > (NOW() - INTERVAL 15 MINUTE)');
    $q->execute([$ip, $username]);
    if ((int) $q->fetchColumn() >= 5) {
        jsonError(429, 'Demasiados intentos. Inténtalo de nuevo en 15 minutos.');
    }

    $stmt = $pdo->prepare('SELECT id, password_hash, active FROM admin_users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    // password_verify también se ejecuta si el usuario no existe (tiempo constante)
    $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringforsalt.invalidhashinvalidhashinvalidhash';
    $ok   = password_verify($password, $hash) && $row && (int) $row['active'] === 1;

    if (!$ok) {
        $pdo->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([$ip, $username]);
        error_log('[auth] login fallido user=' . $username . ' ip=' . $ip);
        jsonError(401, 'Usuario o contraseña incorrectos');
    }

    session_regenerate_id(true); // evita fijación de sesión
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['csrf']    = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
    $pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);

    echo json_encode(['user' => currentUser(), 'csrf' => csrfToken(), 'needsSetup' => false]);
    exit;
}

// ── Setup del primer superadmin ──────────────────────────────────────────────
if ($action === 'setup') {
    if (!$needsSetup) {
        jsonError(403, 'La instalación ya está configurada');
    }
    $key = (string) ($input['setup_key'] ?? '');
    if (!defined('ADMIN_PASSWORD') || !hash_equals((string) ADMIN_PASSWORD, $key)) {
        error_log('[auth] setup con clave incorrecta ip=' . $ip);
        jsonError(401, 'Clave de instalación incorrecta');
    }
    $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
    $email    = trim((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
        jsonError(422, 'Usuario inválido (3-60 caracteres: letras, números, . _ -)');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonError(422, 'Email inválido');
    }
    validatePassword($password);

    $roleId = (int) $pdo->query("SELECT id FROM admin_roles WHERE name = 'Superadmin'")->fetchColumn();
    $pdo->prepare('INSERT INTO admin_users (username, email, password_hash, role_id) VALUES (?, ?, ?, ?)')
        ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $pdo->lastInsertId();
    $_SESSION['csrf']    = bin2hex(random_bytes(32));
    echo json_encode(['user' => currentUser(), 'csrf' => csrfToken(), 'needsSetup' => false]);
    exit;
}

// ── Logout ───────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    verifyCsrf();
    $_SESSION = [];
    session_regenerate_id(true);
    echo json_encode(['success' => true]);
    exit;
}

// ── Cambio de contraseña propio ──────────────────────────────────────────────
if ($action === 'change_password') {
    $user = requireAuth();
    $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
    $stmt->execute([$user['id']]);
    if (!password_verify((string) ($input['current_password'] ?? ''), (string) $stmt->fetchColumn())) {
        jsonError(401, 'La contraseña actual no es correcta');
    }
    $new = (string) ($input['new_password'] ?? '');
    validatePassword($new);
    $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    echo json_encode(['success' => true]);
    exit;
}

jsonError(400, 'Acción desconocida');
