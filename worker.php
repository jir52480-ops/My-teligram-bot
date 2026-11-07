<?php
define('BOT_LIB_ONLY', true);
require __DIR__ . '/bot.php';

ignore_user_abort(true);
set_time_limit(0);

$payload = ''; $tokenIn = '';
if (php_sapi_name() === 'cli') {
    $payload = base64_decode($argv[1] ?? '');
    $tokenIn = $argv[2] ?? '';
} else {
    $payload = file_get_contents('php://input');
    $tokenIn = $_SERVER['HTTP_X_WORKER_TOKEN'] ?? '';
    header('Content-Type: application/json');
    echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level()) ob_end_flush(); flush(); }
}
if (!$payload) exit;
$expected = hash_hmac('sha256', $payload, WORKER_SECRET);
if (!hash_equals($expected, (string)$tokenIn)) exit;

$data = json_decode($payload, true);
if (!$data || empty($data['uid'])) exit;

$uid = (int)$data['uid'];
$chat_id = (int)$data['chat_id'];
$urls = $data['urls'] ?? [];
$scan_msg_id = $data['scan_msg_id'] ?? null;

if (empty($urls)) exit;

$lock = DATA_DIR . '/worker_' . $uid . '.lock';
$lfp = @fopen($lock, 'c');
if ($lfp && !flock($lfp, LOCK_EX | LOCK_NB)) { fclose($lfp); exit; }

try { runAutoFlow($uid, $chat_id, $urls, $scan_msg_id); }
catch (Throwable $e) { error_log("Worker [$uid]: " . $e->getMessage()); }

if ($lfp) { flock($lfp, LOCK_UN); fclose($lfp); }
@unlink($lock);