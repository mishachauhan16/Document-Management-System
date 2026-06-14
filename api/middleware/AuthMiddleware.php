<?php
// =======================================================
// api/middleware/AuthMiddleware.php
// =======================================================
require_once __DIR__ . '/../config/auth.php';

function requireAuth(): array {
    $token = getBearerToken();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'No token. Please log in.']);
        exit;
    }
    $p = verifyJWT($token);
    if (!$p) {
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Session expired. Please log in again.']);
        exit;
    }
    return $p;
}

function requireAdmin(): array {
    $u = requireAuth();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Admin access required.']);
        exit;
    }
    return $u;
}
