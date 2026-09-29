<?php
/**
 * index.php — Giao diện nút Like + hiển thị tổng
 */
declare(strict_types=1);
require __DIR__ . '/config.php';

// Lấy tổng like ban đầu để render server-side
$row = db()->query('SELECT total FROM like_stats WHERE id=1')->fetch();
$initialTotal = (int)($row['total'] ?? 0);

// Nếu cookie đã tồn tại → có thể đã like (chỉ dùng để hiển thị UX)
$alreadyCookie = !empty($_COOKIE[COOKIE_NAME]);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nút Like — WebRTC Verified</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
  <main class="card">
    <h1>❤️ Like công khai</h1>
    <p class="sub">Mỗi người chỉ like được 1 lần. Hệ thống xác minh qua WebRTC.</p>

    <div class="counter" id="counter"><?= number_format($initialTotal) ?></div>
    <div class="counter-label">lượt thích</div>

    <button id="likeBtn" class="like-btn" <?= $alreadyCookie ? 'disabled' : '' ?>>
      <span id="btnText"><?= $alreadyCookie ? '✓ Đã thích' : '👍 Thích' ?></span>
    </button>

    <div id="status" class="status"></div>
  </main>

  <script src="webrtc_verify.js"></script>
  <script>
  (function () {
    const btn     = document.getElementById('likeBtn');
    const btnText = document.getElementById('btnText');
    const status  = document.getElementById('status');
    const counter = document.getElementById('counter');

    let busy = false;

    // ---------- Realtime counter ----------
    async function refreshCount() {
      try {
        const r = await fetch('like_count.php', { cache: 'no-store' });
        const j = await r.json();
        if (j.ok) counter.textContent = new Intl.NumberFormat('vi-VN').format(j.total);
      } catch (e) {}
    }
    setInterval(refreshCount, 4000); // cập nhật mỗi 4s
    refreshCount();

    // ---------- Kiểm tra WebRTC (chạy ngầm khi tải trang) ----------
    let verifyPromise = verifyDevice();
    verifyPromise.then(v => {
      if (!v.ok) {
        btn.disabled = true;
        btnText.textContent = '🚫 Không đủ điều kiện';
        status.textContent = 'Trình duyệt không đủ điều kiện, vui lòng dùng trình duyệt chuẩn trên thiết bị thật.';
        status.className = 'status error';
      }
    });

    // ---------- Xử lý Like ----------
    btn.addEventListener('click', async () => {
      if (busy || btn.disabled) return;
      busy = true;
      btn.disabled = true;
      status.textContent = 'Đang xác minh thiết bị...';
      status.className = 'status';

      try {
        const v = await verifyPromise;

        if (!v.ok) {
          status.textContent = 'Trình duyệt không đủ điều kiện, vui lòng dùng trình duyệt chuẩn trên thiết bị thật.';
          status.className = 'status error';
          btnText.textContent = '🚫 Không đủ điều kiện';
          return;
        }

        // Gửi lên server
        const res = await fetch('like_action.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            fingerprint: v.fingerprint,
            signals: {
              hasSrflx: v.signals.hasSrflxCandidate,
              deviceCount: v.signals.deviceCount,
              webglVendor: v.signals.webglVendor,
              webglRenderer: v.signals.webglRenderer,
            }
          })
        });

        const j = await res.json();

        if (j.ok) {
          btnText.textContent = '✓ Đã thích';
          status.textContent = 'Cảm ơn bạn đã thích!';
          status.className = 'status success';
          counter.textContent = new Intl.NumberFormat('vi-VN').format(j.total);
        } else {
          btnText.textContent = j.already ? '✓ Đã thích' : '🚫 Không thể like';
          status.textContent = j.message || 'Không thể like.';
          status.className = 'status error';
        }
      } catch (err) {
        status.textContent = 'Lỗi kết nối: ' + err.message;
        status.className = 'status error';
      } finally {
        busy = false;
      }
    });
  })();
  </script>
</body>
</html>
