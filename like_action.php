<?php
/**
 * like_action.php
 * Nhận: JSON { fingerprint, signals }
 * Quyết định: 1 người = 1 like, kiểm tra IP + device_hash.
 * Trả JSON: { ok, total, message, already }
 */
declare(strict_types=1);
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    json_out(['ok' => false, 'message' => 'Dữ liệu không hợp lệ'], 400);
}

// ---------- 1. Kiểm tra fingerprint ----------
$fingerprint = strtolower(trim((string)($data['fingerprint'] ?? '')));
if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
    json_out(['ok' => false, 'message' => 'Fingerprint không hợp lệ'], 400);
}

// ---------- 2. Kiểm tra signals sơ bộ (server-side sanity) ----------
$signals = $data['signals'] ?? [];
if (empty($signals['hasSrflx']) || ($signals['deviceCount'] ?? 0) < 1) {
    json_out(['ok' => false, 'message' => 'Thiết bị không đủ điều kiện'], 400);
}
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (preg_match('/HeadlessChrome|PhantomJS|Selenium|Puppeteer|Playwright|Electron\/.*Headless/i', $ua)) {
    json_out(['ok' => false, 'message' => 'Phát hiện trình duyệt tự động hóa'], 400);
}

// ---------- 3. Lấy IP & cookie ----------
$ip = get_client_ip();

// ---------- 4. Kiểm tra tồn tại (device hoặc IP) ----------
$pdo = db();

// 4a. Nếu cookie đã tồn tại → chặn ngay
if (!empty($_COOKIE[COOKIE_NAME]) && $_COOKIE[COOKIE_NAME] === $fingerprint) {
    $row = $pdo->query('SELECT total FROM like_stats WHERE id=1')->fetch();
    json_out(['ok' => false, 'already' => true, 'total' => (int)$row['total'],
              'message' => 'Bạn đã thích rồi.'], 200);
}

// 4b. Kiểm tra device_hash
$stmt = $pdo->prepare('SELECT id FROM likes WHERE device_hash = ? LIMIT 1');
$stmt->execute([$fingerprint]);
if ($stmt->fetch()) {
    $row = $pdo->query('SELECT total FROM like_stats WHERE id=1')->fetch();
    json_out(['ok' => false, 'already' => true, 'total' => (int)$row['total'],
              'message' => 'Thiết bị này đã thích rồi.'], 200);
}

// 4c. Kiểm tra IP (nếu bật)
if (ENFORCE_UNIQUE_IP) {
    $stmt = $pdo->prepare('SELECT id FROM likes WHERE ip_address = ? LIMIT 1');
    $stmt->execute([$ip]);
    if ($stmt->fetch()) {
        $row = $pdo->query('SELECT total FROM like_stats WHERE id=1')->fetch();
        json_out(['ok' => false, 'already' => true, 'total' => (int)$row['total'],
                  'message' => 'IP này đã thích rồi.'], 200);
    }
}

// ---------- 5. Ghi like (transaction để đảm bảo atomic) ----------
try {
    $pdo->beginTransaction();

    $uaShort = mb_substr($ua, 0, 250);
    $webRtcJson = json_encode([
        'deviceCount' => $signals['deviceCount'] ?? 0,
        'webglVendor' => $signals['webglVendor'] ?? '',
        'webglRenderer' => $signals['webglRenderer'] ?? '',
    ], JSON_UNESCAPED_UNICODE);

    // Insert like
    $stmt = $pdo->prepare(
        'INSERT INTO likes (device_hash, ip_address, user_agent, webrtc_data)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$fingerprint, $ip, $uaShort, $webRtcJson]);

    // Tăng tổng
    $pdo->exec('UPDATE like_stats SET total = total + 1 WHERE id = 1');

    $pdo->commit();

    // Set cookie 1 năm (dùng fingerprint làm giá trị)
    setcookie(COOKIE_NAME, $fingerprint, [
        'expires'  => time() + COOKIE_TTL,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);

    $row = $pdo->query('SELECT total FROM like_stats WHERE id=1')->fetch();
    json_out(['ok' => true, 'total' => (int)$row['total'], 'message' => 'Đã ghi nhận lượt thích.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    // Trùng unique → coi như đã like
    if ($e->getCode() === '23000') {
        $row = $pdo->query('SELECT total FROM like_stats WHERE id=1')->fetch();
        json_out(['ok' => false, 'already' => true, 'total' => (int)$row['total'],
                  'message' => 'Bạn đã thích rồi.'], 200);
    }
    json_out(['ok' => false, 'message' => 'Lỗi CSDL'], 500);
}
