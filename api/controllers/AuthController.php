<?php
// =======================================================
// api/controllers/AuthController.php
// Routes: login, logout, register, change-password, me
// =======================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

// -------------------------------------------------------
// POST /api/auth/login
// Body: { email, password }
// -------------------------------------------------------
function login(): void {
    $d        = json_decode(file_get_contents('php://input'), true) ?? [];
    $email    = trim($d['email']    ?? '');
    $password = trim($d['password'] ?? '');

    if (!$email || !$password) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Email and password are required.']);
        return;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Log failed attempt
        if ($user) logAudit((int)$user['id'], null, 'login', getClientIP(), 'Failed login attempt');
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Invalid email or password.']);
        return;
    }

    $token = generateJWT([
        'user_id'             => (int)$user['id'],
        'email'               => $user['email'],
        'name'                => $user['name'],
        'role'                => $user['role'],
        'must_change_password'=> (bool)$user['must_change_password'],
    ]);

    logAudit((int)$user['id'], null, 'login', getClientIP(), 'Successful login');

    echo json_encode([
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'                  => (int)$user['id'],
            'name'                => $user['name'],
            'email'               => $user['email'],
            'role'                => $user['role'],
            'must_change_password'=> (bool)$user['must_change_password'],
        ]
    ]);
}

// -------------------------------------------------------
// POST /api/auth/logout
// -------------------------------------------------------
function logout(): void {
    $u = requireAuth();
    logAudit($u['user_id'], null, 'logout', getClientIP());
    echo json_encode(['success'=>true,'message'=>'Logged out.']);
}

// -------------------------------------------------------
// GET /api/auth/me — current user info
// -------------------------------------------------------
function getMe(): void {
    $u    = requireAuth();
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, email, role, must_change_password, created_at FROM users WHERE id = ?');
    $stmt->execute([$u['user_id']]);
    $data = $stmt->fetch();
    if (!$data) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'User not found']); return; }
    echo json_encode(['success'=>true,'data'=>$data]);
}

// -------------------------------------------------------
// POST /api/auth/register  (Admin only)
// Body: { name, email, role }
// Password is DEFAULT_PASSWORD — user must change on login
// -------------------------------------------------------
function register(): void {
    $admin = requireAdmin();
    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($d['name']  ?? '');
    $email = trim($d['email'] ?? '');
    $role  = in_array($d['role'] ?? '', ['admin','user']) ? $d['role'] : 'user';

    if (!$name || !$email) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Name and email are required.']);
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Invalid email format.']);
        return;
    }

    $db    = getDB();
    $check = $db->prepare('SELECT id FROM users WHERE email = ?');
    $check->execute([$email]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Email already registered.']);
        return;
    }

    // All users created by admin get DEFAULT_PASSWORD and must change it
    $hash = password_hash(DEFAULT_PASSWORD, PASSWORD_BCRYPT, ['cost'=>12]);
    $stmt = $db->prepare(
        'INSERT INTO users (name, email, password_hash, role, must_change_password, created_by)
         VALUES (?, ?, ?, ?, 1, ?)'
    );
    $stmt->execute([$name, $email, $hash, $role, $admin['user_id']]);
    $newId = (int)$db->lastInsertId();

    logAudit($admin['user_id'], null, 'create_user', getClientIP(), "Created user: $email (role: $role)");

    http_response_code(201);
    echo json_encode([
        'success'          => true,
        'message'          => "User created. Default password: ".DEFAULT_PASSWORD,
        'default_password' => DEFAULT_PASSWORD,
        'data'             => ['id'=>$newId,'name'=>$name,'email'=>$email,'role'=>$role]
    ]);
}

// -------------------------------------------------------
// POST /api/auth/change-password
// Body: { current_password, new_password, confirm_password }
// Any logged-in user can call this
// -------------------------------------------------------
function changePassword(): void {
    $u  = requireAuth();
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $current  = trim($d['current_password']  ?? '');
    $newPass  = trim($d['new_password']      ?? '');
    $confirm  = trim($d['confirm_password']  ?? '');

    if (!$current || !$newPass || !$confirm) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'All fields are required.']);
        return;
    }
    if ($newPass !== $confirm) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'New passwords do not match.']);
        return;
    }
    if (strlen($newPass) < 6) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Password must be at least 6 characters.']);
        return;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$u['user_id']]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Current password is incorrect.']);
        return;
    }

    $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost'=>12]);
    $upd  = $db->prepare(
        'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?'
    );
    $upd->execute([$hash, $u['user_id']]);

    logAudit($u['user_id'], null, 'change_password', getClientIP(), 'Password changed successfully');

    echo json_encode(['success'=>true,'message'=>'Password changed successfully.']);
}

// -------------------------------------------------------
// GET /api/users  (Admin only) — list all users
// -------------------------------------------------------
function listUsers(): void {
    requireAdmin();
    $db   = getDB();
    $stmt = $db->query(
        'SELECT u.id, u.name, u.email, u.role, u.is_active, u.must_change_password,
                u.created_at, c.name AS created_by_name
         FROM users u
         LEFT JOIN users c ON c.id = u.created_by
         ORDER BY u.created_at DESC'
    );
    echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
}

// -------------------------------------------------------
// PUT /api/users/{id}  (Admin only) — update user
// Body: { name?, role?, is_active? }
// -------------------------------------------------------
function updateUser(int $id): void {
    $admin = requireAdmin();
    if ($id === $admin['user_id']) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'You cannot edit your own account here.']);
        return;
    }

    $d        = json_decode(file_get_contents('php://input'), true) ?? [];
    $db       = getDB();
    $stmt     = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'User not found']); return; }

    $name      = trim($d['name']      ?? $existing['name']);
    $role      = in_array($d['role'] ?? '', ['admin','user']) ? $d['role'] : $existing['role'];
    $is_active = isset($d['is_active']) ? (int)(bool)$d['is_active'] : (int)$existing['is_active'];

    $upd = $db->prepare(
        'UPDATE users SET name = ?, role = ?, is_active = ?, updated_at = NOW() WHERE id = ?'
    );
    $upd->execute([$name, $role, $is_active, $id]);

    logAudit($admin['user_id'], null, 'update_user', getClientIP(),
        "Updated user ID $id: name=$name, role=$role, active=$is_active");

    echo json_encode(['success'=>true,'message'=>'User updated.']);
}

// -------------------------------------------------------
// DELETE /api/users/{id}  (Admin only)
// -------------------------------------------------------
function deleteUser(int $id): void {
    $admin = requireAdmin();
    if ($id === $admin['user_id']) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'You cannot delete your own account.']);
        return;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'User not found']); return; }

    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

    logAudit($admin['user_id'], null, 'delete_user', getClientIP(), "Deleted user: {$user['email']}");
    echo json_encode(['success'=>true,'message'=>'User deleted.']);
}

// -------------------------------------------------------
// GET /api/audit  (Admin only) — all audit logs
// GET /api/audit?user_id=X&action=login&limit=50
// -------------------------------------------------------
function getAuditLogs(): void {
    requireAdmin();
    $db      = getDB();
    $where   = ['1=1'];
    $params  = [];

    if (!empty($_GET['user_id']))  { $where[] = 'al.user_id = ?';  $params[] = (int)$_GET['user_id']; }
    if (!empty($_GET['action']))   { $where[] = 'al.action = ?';   $params[] = $_GET['action']; }
    if (!empty($_GET['doc_id']))   { $where[] = 'al.doc_id = ?';   $params[] = (int)$_GET['doc_id']; }

    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $sql    = 'SELECT al.*, u.name AS user_name, u.email AS user_email,
                      d.title AS doc_title
               FROM audit_logs al
               JOIN users u ON u.id = al.user_id
               LEFT JOIN documents d ON d.id = al.doc_id
               WHERE ' . implode(' AND ', $where) . '
               ORDER BY al.created_at DESC
               LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Total count
    $countSql  = 'SELECT COUNT(*) FROM audit_logs al WHERE ' . implode(' AND ', array_slice($where, 0));
    $countStmt = $db->prepare($countSql);
    $countStmt->execute(array_slice($params, 0, -2));

    echo json_encode([
        'success' => true,
        'total'   => (int)$countStmt->fetchColumn(),
        'data'    => $stmt->fetchAll()
    ]);
}

// -------------------------------------------------------
// Shared: write audit log row
// -------------------------------------------------------
function logAudit(int $userId, ?int $docId, string $action, string $ip, string $details = ''): void {
    try {
        $db = getDB();
        $db->prepare(
            'INSERT INTO audit_logs (user_id, doc_id, action, ip_address, details, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$userId, $docId, $action, $ip, $details]);
    } catch (Exception $e) {
        error_log('Audit failed: '.$e->getMessage());
    }
}
