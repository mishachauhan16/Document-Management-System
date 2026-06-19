<?php
// =======================================================
// api/config/db.php
// =======================================================

define('DB_HOST',  'localhost');
define('DB_NAME',  'dms_db');
define('DB_USER',  'root');
define('DB_PASS',  '');  // ← your MySQL password

define('JWT_SECRET',         'DMS_SUPER_SECRET_KEY_CHANGE_IN_PROD_2025!@#');
define('JWT_EXPIRY_HOURS',   24);

define('DEFAULT_PASSWORD',   'Welcome@123');  // all new users get this

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success'=>false,'message'=>'DB connection failed']);
            exit;
        }
    }
    return $pdo;
}
