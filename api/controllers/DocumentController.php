<?php
// =======================================================
// api/controllers/DocumentController.php
// Handles: upload, list, view, download, edit, delete,
//          restore, share, unshare
// =======================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/AuthController.php'; // for logAudit(), getClientIP()

// -------------------------------------------------------
// GET /api/documents
// Returns: documents the user owns + documents shared with them
// Admin sees ALL documents
// -------------------------------------------------------
function listDocuments(): void {
    $user = requireAuth();
    $db   = getDB();

    if ($user['role'] === 'admin') {
        $stmt = $db->prepare(
            'SELECT d.*, u.name AS owner_name
             FROM documents d
             JOIN users u ON u.id = d.owner_id
             WHERE d.deleted_at IS NULL
             ORDER BY d.created_at DESC'
        );
        $stmt->execute();
    } else {
        $stmt = $db->prepare(
            'SELECT DISTINCT d.*, u.name AS owner_name
             FROM documents d
             JOIN users u ON u.id = d.owner_id
             LEFT JOIN shares s ON s.doc_id = d.id AND s.shared_with_id = ?
             WHERE d.deleted_at IS NULL
               AND (d.owner_id = ? OR s.shared_with_id = ?)
             ORDER BY d.created_at DESC'
        );
        $stmt->execute([$user['user_id'], $user['user_id'], $user['user_id']]);
    }

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

// -------------------------------------------------------
// GET /api/documents/trash  (Admin only)
// -------------------------------------------------------
function listTrash(): void {
    $user = requireAuth();
    $db   = getDB();

    if ($user['role'] === 'admin') {
        $stmt = $db->query(
            'SELECT d.*, u.name AS owner_name
             FROM documents d
             JOIN users u ON u.id = d.owner_id
             WHERE d.deleted_at IS NOT NULL
             ORDER BY d.deleted_at DESC'
        );
        $stmt->execute();
    } else {
        $stmt = $db->prepare(
            'SELECT d.*, u.name AS owner_name
             FROM documents d
             JOIN users u ON u.id = d.owner_id
             WHERE d.deleted_at IS NOT NULL AND d.owner_id = ?
             ORDER BY d.deleted_at DESC'
        );
        $stmt->execute([$user['user_id']]);
    }

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}

// -------------------------------------------------------
// POST /api/documents/upload
// -------------------------------------------------------
function uploadDocument(): void {
    $user = requireAuth();

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
        return;
    }

    $file = $_FILES['file'];

    if ($file['size'] > MAX_FILE_SIZE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File exceeds maximum allowed size.']);
        return;
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $ext            = pathinfo($file['name'], PATHINFO_EXTENSION);
    $storedFilename = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $destination = UPLOAD_DIR . $storedFilename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save file on server.']);
        return;
    }

    $title = trim($_POST['title'] ?? '') ?: $file['name'];

    $db   = getDB();
    $stmt = $db->prepare(
        'INSERT INTO documents
            (owner_id, title, original_filename, stored_filename, file_path, file_type, file_size_bytes, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $user['user_id'], $title, $file['name'], $storedFilename,
        $destination, $mimeType, $file['size']
    ]);
    $docId = (int) $db->lastInsertId();

    logAudit($user['user_id'], $docId, 'upload', getClientIP(), "Uploaded: $title");

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully.',
        'data'    => ['id' => $docId, 'title' => $title, 'file_type' => $mimeType]
    ]);
}

// -------------------------------------------------------
// GET /api/documents/{id}
// -------------------------------------------------------
function getDocument(int $id): void {
    $user = requireAuth();

    if (!canAccessDocument($user['user_id'], $user['role'], $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have access to this document.']);
        return;
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT d.*, u.name AS owner_name FROM documents d
         JOIN users u ON u.id = d.owner_id WHERE d.id = ?'
    );
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if (!$doc) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Document not found.']); return; }

    echo json_encode(['success' => true, 'data' => $doc]);
}

// -------------------------------------------------------
// GET /api/documents/{id}/view  and  /download
// -------------------------------------------------------
function serveDocument(int $id, string $mode): void {
    $token = $_GET['token'] ?? '';
    $payload = $token ? verifyJWT($token) : null;

    if (!$payload) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or missing token.']);
        return;
    }

    if (!canAccessDocument($payload['user_id'], $payload['role'], $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        return;
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM documents WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if (!$doc || !file_exists($doc['file_path'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'File not found.']);
        return;
    }

    logAudit($payload['user_id'], $id, $mode === 'view' ? 'view' : 'download', getClientIP());

    $inlineTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $disposition = ($mode === 'view' && in_array($doc['file_type'], $inlineTypes)) ? 'inline' : 'attachment';

    header('Content-Type: ' . $doc['file_type']);
    header('Content-Disposition: ' . $disposition . '; filename="' . $doc['original_filename'] . '"');
    header('Content-Length: ' . filesize($doc['file_path']));
    readfile($doc['file_path']);
    exit;
}

// -------------------------------------------------------
// PUT /api/documents/{id}
// -------------------------------------------------------
function updateDocument(int $id): void {
    $user = requireAuth();

    if (!isDocumentOwner($user['user_id'], $user['role'], $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the owner or admin can edit this document.']);
        return;
    }

    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $title = trim($d['title'] ?? '');

    if (!$title) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Title is required.']);
        return;
    }

    $db = getDB();
    $db->prepare('UPDATE documents SET title = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$title, $id]);

    logAudit($user['user_id'], $id, 'edit', getClientIP(), "Renamed to: $title");

    echo json_encode(['success' => true, 'message' => 'Document updated.']);
}

// -------------------------------------------------------
// DELETE /api/documents/{id}  — soft delete
// -------------------------------------------------------
function deleteDocument(int $id): void {
    $user = requireAuth();

    if (!isDocumentOwner($user['user_id'], $user['role'], $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the owner or admin can delete this document.']);
        return;
    }

    $db = getDB();
    $db->prepare('UPDATE documents SET deleted_at = NOW() WHERE id = ?')->execute([$id]);

    logAudit($user['user_id'], $id, 'delete', getClientIP());

    echo json_encode(['success' => true, 'message' => 'Document moved to trash.']);
}

// -------------------------------------------------------
// POST /api/documents/{id}/restore
// -------------------------------------------------------
function restoreDocument(int $id): void {
    $user = requireAuth();

    $db   = getDB();
    $stmt = $db->prepare('SELECT owner_id FROM documents WHERE id = ?');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();

    if (!$doc) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Document not found.']); return; }
    if ($user['role'] !== 'admin' && $doc['owner_id'] != $user['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the owner or admin can restore this document.']);
        return;
    }

    $db->prepare('UPDATE documents SET deleted_at = NULL WHERE id = ?')->execute([$id]);
    logAudit($user['user_id'], $id, 'restore', getClientIP());

    echo json_encode(['success' => true, 'message' => 'Document restored.']);
}

// -------------------------------------------------------
// POST /api/documents/{id}/share
// -------------------------------------------------------
function shareDocument(int $id): void {
    $user = requireAuth();

    if (!isDocumentOwner($user['user_id'], $user['role'], $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the owner or admin can share this document.']);
        return;
    }

    $d            = json_decode(file_get_contents('php://input'), true) ?? [];
    $sharedWithId = (int) ($d['shared_with_id'] ?? 0);

    if (!$sharedWithId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'shared_with_id is required.']);
        return;
    }

    $db = getDB();

    $check = $db->prepare('SELECT id, name FROM users WHERE id = ?');
    $check->execute([$sharedWithId]);
    $target = $check->fetch();
    if (!$target) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'User not found.']); return; }

    try {
        $stmt = $db->prepare(
            'INSERT INTO shares (doc_id, shared_by_id, shared_with_id, created_at) VALUES (?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $user['user_id'], $sharedWithId]);
    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Already shared with this user.']);
        return;
    }

    logAudit($user['user_id'], $id, 'share', getClientIP(), "Shared with: {$target['name']}");

    echo json_encode(['success' => true, 'message' => 'Document shared successfully.']);
}

// -------------------------------------------------------
// DELETE /api/documents/{id}/share/{userId}
// -------------------------------------------------------
function unshareDocument(int $id, int $userId): void {
    $user = requireAuth();

    if (!isDocumentOwner($user['user_id'], $user['role'], $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the owner or admin can manage sharing.']);
        return;
    }

    $db = getDB();
    $db->prepare('DELETE FROM shares WHERE doc_id = ? AND shared_with_id = ?')->execute([$id, $userId]);

    logAudit($user['user_id'], $id, 'share', getClientIP(), 'Revoked share access');

    echo json_encode(['success' => true, 'message' => 'Share access revoked.']);
}

// -------------------------------------------------------
// GET /api/documents/{id}/shares
// -------------------------------------------------------
function getDocumentShares(int $id): void {
    $user = requireAuth();

    if (!isDocumentOwner($user['user_id'], $user['role'], $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the owner or admin can view sharing details.']);
        return;
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT s.id, s.shared_with_id, u.name, u.email, s.created_at
         FROM shares s JOIN users u ON u.id = s.shared_with_id
         WHERE s.doc_id = ? ORDER BY s.created_at DESC'
    );
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}
// -------------------------------------------------------
// GET /api/documents/users-list
// Returns basic user list for sharing (any logged-in user)
// -------------------------------------------------------
function getUsersForShare(): void {
    $user = requireAuth();
    $db   = getDB();
    $stmt = $db->prepare(
    'SELECT id, name, email FROM users 
     WHERE id != ? AND is_active = 1 AND role = "user"
     ORDER BY name ASC'
    );
    $stmt->execute([$user['user_id']]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
}