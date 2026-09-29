<?php
/**
 * like_count.php — Trả về tổng lượt like (dùng cho polling realtime)
 */
declare(strict_types=1);
require __DIR__ . '/config.php';

$row = db()->query('SELECT total FROM like_stats WHERE id=1')->fetch();
json_out(['ok' => true, 'total' => (int)($row['total'] ?? 0)]);
