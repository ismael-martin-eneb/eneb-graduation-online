<?php
/**
 * Librería de autenticación y autorización del panel de administración.
 *
 * Modelo:
 *   admin_roles  (id, name, description, permissions JSON, is_system)
 *   admin_users  (id, username, email, password_hash, role_id, active, ...)
 *   login_attempts (id, ip, username, created_at)  → rate limiting
 *
 * Sesión PHP (cookie HttpOnly + SameSite=Lax) + token CSRF en la cabecera
 * `X-CSRF-Token` para toda petición que modifique datos.
 *
 * Uso típico en un endpoint:
 *   require_once __DIR__ . '/auth-lib.php';
 *   apiBootstrap(['GET', 'POST']);
 *   $user = requirePermission('photos.upload');
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Tabla de graduados presenciales: valor por defecto si config.php no la define.
if (!defined('DB_TABLE_PRESENCIALES')) {
    define('DB_TABLE_PRESENCIALES', 'eneb_presenciales');
}

/**
 * Catálogo de permisos. Para añadir uno nuevo: declararlo aquí y comprobarlo
 * con requirePermission() en el endpoint y con can() en el frontend.
 */
const ADMIN_PERMISSIONS = [
    'graduates.view'   => 'Ver graduados online',
    'graduates.edit'   => 'Editar datos de graduados online',
    'graduates.delete' => 'Eliminar graduados online',
    'photos.upload'    => 'Subir fotos de graduados',
    'photos.delete'    => 'Eliminar fotos de graduados',
    'csv.online.import' => 'Importar CSV de graduados online',
    'presenciales.view'   => 'Ver graduados presenciales',
    'presenciales.import' => 'Importar CSV de presenciales',
    'presenciales.delete' => 'Eliminar presenciales',
    'presenciales.pdf'    => 'Generar PDFs de presenciales',
    'users.manage' => 'Gestionar usuarios',
    'roles.manage' => 'Gestionar roles y permisos',
];

/** Roles creados por defecto la primera vez (nombre => [descripción, permisos]). */
function defaultRoles(): array
{
    $all = array_keys(ADMIN_PERMISSIONS);
    return [
        'Superadmin' => ['Acceso total', ['*']],
        'Editor' => ['Gestiona graduados online y presenciales (sin usuarios)',
            array_values(array_diff($all, ['users.manage', 'roles.manage']))],
        'Presenciales' => ['Gestiona solo graduados presenciales',
            ['presenciales.view', 'presenciales.import', 'presenciales.delete', 'presenciales.pdf']],
        'Solo lectura' => ['Consulta sin modificar', ['graduates.view', 'presenciales.view']],
    ];
}

// ── Bootstrap de endpoints ───────────────────────────────────────────────────

/**
 * Cabeceras JSON, CORS (según ADMIN_ALLOWED_ORIGINS en config.php) y OPTIONS.
 * Por defecto no se habilita CORS: la app y la API comparten origen.
 */
function apiBootstrap(array $methods): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = defined('ADMIN_ALLOWED_ORIGINS') ? ADMIN_ALLOWED_ORIGINS : [];
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: ' . implode(', ', array_merge($methods, ['OPTIONS'])));
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        jsonError(405, 'Método no permitido');
    }
}

/** Responde con un error JSON y termina. */
function jsonError(int $code, string $message, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge(['error' => $message], $extra));
    exit;
}

/** Conexión PDO compartida. */
function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(DB_DSN, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            error_log('[auth] DB error: ' . $e->getMessage());
            jsonError(503, 'Base de datos no disponible');
        }
    }
    return $pdo;
}

/** Crea las tablas de usuarios/roles si no existen (idempotente). */
function ensureAuthSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL UNIQUE,
        description VARCHAR(255) NOT NULL DEFAULT \'\',
        permissions TEXT NOT NULL,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL UNIQUE,
        email VARCHAR(120) NOT NULL DEFAULT \'\',
        password_hash VARCHAR(255) NOT NULL,
        role_id INT NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME NULL,
        FOREIGN KEY (role_id) REFERENCES admin_roles(id)
    ) CHARACTER SET utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        username VARCHAR(60) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (ip, created_at), INDEX (username, created_at)
    ) CHARACTER SET utf8mb4');

    // Roles por defecto (solo los que falten)
    $ins = $pdo->prepare('INSERT IGNORE INTO admin_roles (name, description, permissions, is_system) VALUES (?, ?, ?, ?)');
    foreach (defaultRoles() as $name => [$desc, $perms]) {
        $ins->execute([$name, $desc, json_encode($perms), $name === 'Superadmin' ? 1 : 0]);
    }
}

// ── Sesión ───────────────────────────────────────────────────────────────────

const ADMIN_SESSION_IDLE_SECONDS = 28800; // 8 h de inactividad

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('eneb_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > ADMIN_SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

/** Token CSRF de la sesión actual. */
function csrfToken(): string
{
    startSecureSession();
    return $_SESSION['csrf'];
}

/** Rechaza peticiones que modifican datos sin token CSRF válido. */
function verifyCsrf(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        jsonError(403, 'Token CSRF inválido');
    }
}

/** ¿El rol concede el permiso? ('*' = todos). */
function permissionGranted(array $perms, string $permission): bool
{
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

/**
 * Devuelve el usuario autenticado (con role y permissions) o null.
 * Se relee de BD en cada petición: desactivar un usuario o cambiar su rol
 * surte efecto inmediato.
 */
function currentUser(): ?array
{
    startSecureSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = getDb()->prepare(
        'SELECT u.id, u.username, u.email, u.active, r.id AS role_id, r.name AS role, r.permissions
         FROM admin_users u JOIN admin_roles r ON r.id = u.role_id WHERE u.id = ?'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !(int) $u['active']) {
        $_SESSION = [];
        return null;
    }
    $perms = json_decode((string) $u['permissions'], true);
    $u['permissions'] = is_array($perms) ? $perms : [];
    unset($u['active']);
    return $u;
}

/** Exige sesión válida + CSRF. */
function requireAuth(): array
{
    $user = currentUser();
    if (!$user) {
        jsonError(401, 'No autenticado');
    }
    verifyCsrf();
    return $user;
}

/** Exige sesión y un permiso concreto. */
function requirePermission(string $permission): array
{
    $user = requireAuth();
    if (!permissionGranted($user['permissions'], $permission)) {
        jsonError(403, 'No tienes permiso para esta acción', ['required' => $permission]);
    }
    return $user;
}

/** Valida política de contraseña (mín. 10 caracteres). */
function validatePassword(string $pwd): void
{
    if (mb_strlen($pwd) < 10) {
        jsonError(422, 'La contraseña debe tener al menos 10 caracteres');
    }
}
