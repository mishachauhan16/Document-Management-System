<?php
// =======================================================
// api/index.php — Router entry point
// =======================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/DocumentController.php';

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = preg_replace('#^/dms/api#', '', $uri);
$parts  = explode('/', trim($uri, '/'));   // e.g. ['auth','login']
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;

// ---- AUTH ----
if ($parts[0] === 'auth') {
    match(true) {
        $method==='POST' && $parts[1]==='login'           => login(),
        $method==='POST' && $parts[1]==='logout'          => logout(),
        $method==='POST' && $parts[1]==='register'        => register(),
        $method==='POST' && $parts[1]==='change-password' => changePassword(),
        $method==='GET'  && $parts[1]==='me'              => getMe(),
        default => notFound()
    };
    exit;
}

// ---- USERS (admin) ----
if ($parts[0] === 'users') {
    match(true) {
        $method==='GET'    && !$id                => listUsers(),
        $method==='PUT'    && $id                 => updateUser($id),
        $method==='DELETE' && $id                 => deleteUser($id),
        default => notFound()
    };
    exit;
}
// ---- DOCUMENTS ----
if ($parts[0] === 'documents') {
    // /documents/upload
    if ($method === 'POST' && ($parts[1] ?? '') === 'upload') { uploadDocument(); exit; }
    if ($method === 'GET' && ($parts[1] ?? '') === 'users-list') { getUsersForShare(); exit; }
    // /documents/trash
    if ($method === 'GET' && ($parts[1] ?? '') === 'trash') { listTrash(); exit; }
    // /documents/{id}/view
    if ($method === 'GET' && $id && ($parts[2] ?? '') === 'view') { serveDocument($id, 'view'); exit; }
    // /documents/{id}/download
    if ($method === 'GET' && $id && ($parts[2] ?? '') === 'download') { serveDocument($id, 'download'); exit; }
    // /documents/{id}/restore
    if ($method === 'POST' && $id && ($parts[2] ?? '') === 'restore') { restoreDocument($id); exit; }
    // /documents/{id}/share/{userId}
    if ($method === 'DELETE' && $id && ($parts[2] ?? '') === 'share' && isset($parts[3])) {
        unshareDocument($id, (int)$parts[3]); exit;
    }
    // /documents/{id}/share
    if ($method === 'POST' && $id && ($parts[2] ?? '') === 'share') { shareDocument($id); exit; }
    if ($method === 'GET'  && $id && ($parts[2] ?? '') === 'share') { getDocumentShares($id); exit; }
    // /documents/{id}
    if ($method === 'GET'    && $id) { getDocument($id); exit; }
    if ($method === 'PUT'    && $id) { updateDocument($id); exit; }
    if ($method === 'DELETE' && $id) { deleteDocument($id); exit; }
    // /documents
    if ($method === 'GET' && !$id) { listDocuments(); exit; }

    notFound(); exit;
}

// ---- AUDIT ----
if ($parts[0] === 'audit' && $method === 'GET') { getAuditLogs(); exit; }
// ---- AUDIT ----
if ($parts[0] === 'audit' && $method === 'GET') { getAuditLogs(); exit; }

// ---- 404 ----
notFound();

function notFound(): void {
    http_response_code(404);
    echo json_encode(['success'=>false,'message'=>'Route not found']);
}
