<?php
/**
 * Gestión de usuarios y roles (requiere users.manage / roles.manage).
 *
 *   GET    /api/users.php                       → {users, roles, permissions}
 *   POST   /api/users.php                       {username,email,password,role_id}
 *   PUT    /api/users.php?id=N                  {email?,role_id?,active?,password?}
 *   DELETE /api/users.php?id=N
 *   POST   /api/users.php?resource=roles        {name,description,permissions[]}
 *   PUT    /api/users.php?resource=roles&id=N
 *   DELETE /api/users.php?resource=roles&id=N
 */

declare(strict_types=1);

require_once __DIR__ . '/auth-lib.php';
apiBootstrap(['GET', 'POST', 'PUT', 'DELETE']);

$pdo   = getDb();
$input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$id    = (int) ($_GET['id'] ?? 0);
$isRoles = ($_GET['resource'] ?? '') === 'roles';
$method  = $_SERVER['REQUEST_METHOD'];

/** Lista de permisos válidos desde la entrada (descarta desconocidos). */
function cleanPermissions($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    if (in_array('*', $raw, true)) {
        return ['*'];
    }
    return array_values(array_intersect(array_keys(ADMIN_PERMISSIONS), $raw));
}

/** Nº de superadmins activos (para no dejar el sistema sin ninguno). */
function activeSuperadmins(PDO $pdo, int $excludeUserId = 0): int
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM admin_users u JOIN admin_roles r ON r.id = u.role_id
        WHERE u.active = 1 AND r.permissions LIKE '%\"*\"%' AND u.id <> ?");
    $s->execute([$excludeUserId]);
    return (int) $s->fetchColumn();
}

// ── GET: listado ─────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $me = requireAuth();
    if (!permissionGranted($me['permissions'], 'users.manage') && !permissionGranted($me['permissions'], 'roles.manage')) {
        jsonError(403, 'No tienes permiso para esta acción');
    }
    $users = $pdo->query('SELECT u.id, u.username, u.email, u.active, u.created_at, u.last_login, u.role_id, r.name AS role
        FROM admin_users u JOIN admin_roles r ON r.id = u.role_id ORDER BY u.username')->fetchAll();
    $roles = $pdo->query('SELECT id, name, description, permissions, is_system FROM admin_roles ORDER BY id')->fetchAll();
    foreach ($roles as &$r) {
        $r['permissions'] = json_decode((string) $r['permissions'], true) ?: [];
        $r['is_system']   = (int) $r['is_system'];
    }
    echo json_encode(['users' => $users, 'roles' => $roles, 'permissions' => ADMIN_PERMISSIONS]);
    exit;
}

$me = requirePermission($isRoles ? 'roles.manage' : 'users.manage');

try {
    // ── Roles ────────────────────────────────────────────────────────────────
    if ($isRoles) {
        if ($method === 'DELETE') {
            $row = $pdo->prepare('SELECT is_system FROM admin_roles WHERE id = ?');
            $row->execute([$id]);
            $role = $row->fetch();
            if (!$role) jsonError(404, 'Rol no encontrado');
            if ((int) $role['is_system']) jsonError(409, 'El rol de sistema no se puede eliminar');
            $c = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE role_id = ?');
            $c->execute([$id]);
            if ((int) $c->fetchColumn() > 0) jsonError(409, 'Hay usuarios con este rol; reasígnalos primero');
            $pdo->prepare('DELETE FROM admin_roles WHERE id = ?')->execute([$id]);
            echo json_encode(['success' => true]);
            exit;
        }
        $name  = trim((string) ($input['name'] ?? ''));
        $desc  = mb_substr(trim((string) ($input['description'] ?? '')), 0, 255);
        $perms = cleanPermissions($input['permissions'] ?? []);
        if ($method === 'POST') {
            if ($name === '' || mb_strlen($name) > 60) jsonError(422, 'Nombre de rol inválido');
            // Solo un superadmin puede crear roles con comodín total
            if ($perms === ['*'] && !permissionGranted($me['permissions'], '*')) jsonError(403, 'Solo un superadmin puede conceder acceso total');
            $pdo->prepare('INSERT INTO admin_roles (name, description, permissions) VALUES (?,?,?)')
                ->execute([$name, $desc, json_encode($perms)]);
            echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
            exit;
        }
        if ($method === 'PUT') {
            $row = $pdo->prepare('SELECT is_system FROM admin_roles WHERE id = ?');
            $row->execute([$id]);
            $role = $row->fetch();
            if (!$role) jsonError(404, 'Rol no encontrado');
            if ((int) $role['is_system']) jsonError(409, 'El rol de sistema no se puede modificar');
            if ($name === '' || mb_strlen($name) > 60) jsonError(422, 'Nombre de rol inválido');
            if ($perms === ['*'] && !permissionGranted($me['permissions'], '*')) jsonError(403, 'Solo un superadmin puede conceder acceso total');
            $pdo->prepare('UPDATE admin_roles SET name=?, description=?, permissions=? WHERE id=?')
                ->execute([$name, $desc, json_encode($perms), $id]);
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // ── Usuarios ─────────────────────────────────────────────────────────────
    if ($method === 'POST') {
        $username = mb_strtolower(trim((string) ($input['username'] ?? '')));
        $email    = trim((string) ($input['email'] ?? ''));
        $roleId   = (int) ($input['role_id'] ?? 0);
        $password = (string) ($input['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) jsonError(422, 'Usuario inválido (3-60 caracteres: letras, números, . _ -)');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError(422, 'Email inválido');
        validatePassword($password);
        assertCanAssignRole($pdo, $me, $roleId);
        $pdo->prepare('INSERT INTO admin_users (username, email, password_hash, role_id) VALUES (?,?,?,?)')
            ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $roleId]);
        echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
        exit;
    }

    $target = $pdo->prepare('SELECT id, role_id, active FROM admin_users WHERE id = ?');
    $target->execute([$id]);
    $t = $target->fetch();
    if (!$t) jsonError(404, 'Usuario no encontrado');

    if ($method === 'DELETE') {
        if ($id === (int) $me['id']) jsonError(409, 'No puedes eliminar tu propio usuario');
        if (activeSuperadmins($pdo, $id) === 0) jsonError(409, 'Debe quedar al menos un superadmin activo');
        $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'PUT') {
        $sets = []; $vals = [];
        if (isset($input['email'])) {
            $email = trim((string) $input['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError(422, 'Email inválido');
            $sets[] = 'email = ?'; $vals[] = $email;
        }
        if (isset($input['role_id'])) {
            assertCanAssignRole($pdo, $me, (int) $input['role_id']);
            $sets[] = 'role_id = ?'; $vals[] = (int) $input['role_id'];
        }
        if (isset($input['active'])) {
            $sets[] = 'active = ?'; $vals[] = $input['active'] ? 1 : 0;
        }
        if (!empty($input['password'])) {
            validatePassword((string) $input['password']);
            $sets[] = 'password_hash = ?'; $vals[] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        }
        if (!$sets) jsonError(422, 'Nada que actualizar');

        // No permitir degradar/desactivar al último superadmin ni a uno mismo
        $demoting = (isset($input['active']) && !$input['active']) || isset($input['role_id']);
        if ($demoting && activeSuperadmins($pdo, $id) === 0) {
            $newRoleIsSuper = isset($input['role_id']) && roleIsSuper($pdo, (int) $input['role_id'])
                && !(isset($input['active']) && !$input['active']);
            if (!$newRoleIsSuper) jsonError(409, 'Debe quedar al menos un superadmin activo');
        }
        $vals[] = $id;
        $pdo->prepare('UPDATE admin_users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        echo json_encode(['success' => true]);
        exit;
    }
} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        jsonError(409, 'Ya existe un registro con ese nombre');
    }
    error_log('[users] ' . $e->getMessage());
    jsonError(500, 'Error en base de datos');
}

jsonError(400, 'Petición no válida');

/** ¿El rol tiene acceso total? */
function roleIsSuper(PDO $pdo, int $roleId): bool
{
    $s = $pdo->prepare('SELECT permissions FROM admin_roles WHERE id = ?');
    $s->execute([$roleId]);
    $p = json_decode((string) $s->fetchColumn(), true);
    return is_array($p) && in_array('*', $p, true);
}

/** El rol debe existir; asignar un rol superadmin requiere serlo (anti-escalada). */
function assertCanAssignRole(PDO $pdo, array $me, int $roleId): void
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM admin_roles WHERE id = ?');
    $s->execute([$roleId]);
    if (!(int) $s->fetchColumn()) {
        jsonError(422, 'Rol inválido');
    }
    if (roleIsSuper($pdo, $roleId) && !permissionGranted($me['permissions'], '*')) {
        jsonError(403, 'Solo un superadmin puede asignar el rol Superadmin');
    }
}
