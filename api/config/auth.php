<?php
// =======================================================
// api/config/auth.php — JWT helpers
// =======================================================
require_once __DIR__ . '/db.php';

function base64url_encode(string $d): string {
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}
function base64url_decode(string $d): string {
    return base64_decode(str_pad(strtr($d, '-_', '+/'), strlen($d) % 4, '=', STR_PAD_RIGHT));
}

function generateJWT(array $payload): string {
    $h = base64url_encode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + (JWT_EXPIRY_HOURS * 3600);
    $b = base64url_encode(json_encode($payload));
    $s = base64url_encode(hash_hmac('sha256', "$h.$b", JWT_SECRET, true));
    return "$h.$b.$s";
}

function verifyJWT(string $token): ?array {
    $p = explode('.', $token);
    if (count($p) !== 3) return null;
    [$h, $b, $s] = $p;
    $expected = base64url_encode(hash_hmac('sha256', "$h.$b", JWT_SECRET, true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(base64url_decode($b), true);
    if (!$payload || $payload['exp'] < time()) return null;
    return $payload;
}

function getBearerToken(): ?string {
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'authorization') {
            if (preg_match('/Bearer\s+(\S+)/i', $v, $m)) return $m[1];
        }
    }
    return null;
}

function getClientIP(): string {
    foreach (['HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return '0.0.0.0';
}
