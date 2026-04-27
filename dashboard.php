<?php
// ============================================================
//  RICH PANDA AQUATECH — OTA FIRMWARE DASHBOARD
//  Enhanced Edition — Engineer by Jony Dwi M.
// ============================================================

define('DASHBOARD_PASS',  'rpaquatech2024');
define('FIRMWARE_DIR',    '/var/www/firmware/');
define('FIRMWARE_FILE',   FIRMWARE_DIR . 'firmware.bin');
define('VERSION_FILE',    FIRMWARE_DIR . 'version.json');
define('FIRMWARE_URL',    'http://rpaquatech.online/firmware/firmware.bin');
define('MQTT_HOST',       'rpaquatech.online');
define('MQTT_PORT',       1883);
define('MQTT_USER',       'rpaquatech');
define('MQTT_PASS',       'rpaquatech280304');
define('LOG_FILE',        FIRMWARE_DIR . 'deploy.log');
define('DEVICES_FILE',    FIRMWARE_DIR . 'devices.json');

session_start();

if (isset($_POST['logout'])) { session_destroy(); header('Location: '.$_SERVER['PHP_SELF']); exit; }
if (isset($_POST['password'])) {
    if ($_POST['password'] === DASHBOARD_PASS) $_SESSION['auth'] = true;
    else $authError = 'Password salah!';
}
$isAuth = !empty($_SESSION['auth']);
$authError = $authError ?? '';

function getVersionInfo() {
    if (!file_exists(VERSION_FILE)) return ['version' => '-', 'url' => '-', 'changelog' => ''];
    return json_decode(file_get_contents(VERSION_FILE), true) ?? ['version' => '-', 'url' => '-', 'changelog' => ''];
}
function writeLog($msg, $level = 'INFO') {
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg . "\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}
function sendMQTT($topic, $payload) {
    $cmd = sprintf("mosquitto_pub -h %s -p %d -u %s -P %s -t %s -m %s 2>&1",
        escapeshellarg(MQTT_HOST), (int)MQTT_PORT,
        escapeshellarg(MQTT_USER), escapeshellarg(MQTT_PASS),
        escapeshellarg($topic), escapeshellarg($payload));
    return shell_exec($cmd);
}
function getRecentLogs($n = 50) {
    if (!file_exists(LOG_FILE)) return [];
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_slice(array_reverse($lines), 0, $n);
}
function getDevices() {
    if (!file_exists(DEVICES_FILE)) return [];
    return json_decode(file_get_contents(DEVICES_FILE), true) ?? [];
}
function saveDevice($uid, $chipid, $label = '') {
    $devices = getDevices();
    $key = $uid . '_' . $chipid;
    $devices[$key] = ['uid' => $uid, 'chipid' => $chipid, 'label' => $label, 'added' => date('Y-m-d H:i:s')];
    file_put_contents(DEVICES_FILE, json_encode($devices, JSON_PRETTY_PRINT));
}
function removeDevice($key) {
    $devices = getDevices();
    unset($devices[$key]);
    file_put_contents(DEVICES_FILE, json_encode($devices, JSON_PRETTY_PRINT));
}
function getServerStats() {
    return [
        'cpu'    => sys_getloadavg()[0] ?? 0,
        'uptime' => trim(shell_exec('uptime -p 2>/dev/null') ?? '-'),
        'disk'   => disk_free_space('/') ? round(disk_free_space('/') / 1024 / 1024 / 1024, 1) . ' GB free' : '-',
        'mem'    => function_exists('shell_exec') ? trim(shell_exec("free -m | awk 'NR==2{printf \"%s/%sMB\", $3,$2}'") ?? '-') : '-',
        'php'    => PHP_VERSION,
        'os'     => php_uname('s') . ' ' . php_uname('r'),
        'mqtt_check' => function_exists('shell_exec') ? (str_contains(shell_exec("mosquitto_pub --help 2>&1") ?? '', 'mosquitto') ? 'OK' : 'NOT FOUND') : 'UNKNOWN',
    ];
}

$message = ''; $msgType = '';
if ($isAuth) {
    if (isset($_FILES['firmware']) && $_FILES['firmware']['error'] === UPLOAD_ERR_OK) {
        $version   = trim($_POST['version'] ?? '');
        $changelog = trim($_POST['changelog'] ?? '');
        if (empty($version)) { $message = 'Versi tidak boleh kosong!'; $msgType = 'error'; }
        elseif (pathinfo($_FILES['firmware']['name'], PATHINFO_EXTENSION) !== 'bin') { $message = 'File harus .bin!'; $msgType = 'error'; }
        else {
            if (!is_dir(FIRMWARE_DIR)) mkdir(FIRMWARE_DIR, 0755, true);
            if (move_uploaded_file($_FILES['firmware']['tmp_name'], FIRMWARE_FILE)) {
                $size = round(filesize(FIRMWARE_FILE) / 1024, 1);
                file_put_contents(VERSION_FILE, json_encode(['version' => $version, 'url' => FIRMWARE_URL, 'changelog' => $changelog, 'uploaded_at' => date('Y-m-d H:i:s'), 'size_kb' => $size], JSON_PRETTY_PRINT));
                writeLog("UPLOAD: firmware v$version ({$size}KB) - $changelog", 'UPLOAD');
                $message = "✓ Firmware v$version berhasil diupload ({$size}KB)"; $msgType = 'success';
            } else { $message = '✗ Gagal simpan file. Cek permission.'; $msgType = 'error'; }
        }
    }
    if (isset($_POST['trigger_ota'])) {
        $uid = trim($_POST['mqtt_uid']); $chipid = trim($_POST['mqtt_chipid']);
        $topic = "$uid/$chipid/feederota";
        $out = sendMQTT($topic, '1');
        writeLog("OTA TRIGGER: $topic", 'TRIGGER');
        $message = "✓ OTA dikirim → $topic"; $msgType = 'success';
        if (!empty($out)) $message .= " | mqtt: $out";
    }
    if (isset($_POST['trigger_ota_broadcast'])) {
        $devices = getDevices();
        $count = 0;
        foreach ($devices as $d) {
            $topic = "{$d['uid']}/{$d['chipid']}/feederota";
            sendMQTT($topic, '1');
            writeLog("BROADCAST OTA: $topic", 'BROADCAST'); $count++;
        }
        $message = "✓ OTA Broadcast ke $count device"; $msgType = 'success';
    }
    if (isset($_POST['add_device'])) {
        $uid = trim($_POST['dev_uid']); $chipid = trim($_POST['dev_chipid']); $label = trim($_POST['dev_label'] ?? '');
        if ($uid && $chipid) { saveDevice($uid, $chipid, $label); writeLog("DEVICE ADDED: $uid/$chipid ($label)", 'DEVICE'); $message = "✓ Device $label ($chipid) ditambahkan"; $msgType = 'success'; }
        else { $message = 'UID dan Chip ID wajib diisi'; $msgType = 'error'; }
    }
    if (isset($_POST['remove_device'])) {
        $key = $_POST['device_key'];
        removeDevice($key); writeLog("DEVICE REMOVED: $key", 'DEVICE');
        $message = "✓ Device dihapus"; $msgType = 'success';
    }
    if (isset($_POST['delete_firmware'])) {
        if (file_exists(FIRMWARE_FILE)) { unlink(FIRMWARE_FILE); writeLog("DELETE: firmware.bin", 'DELETE'); }
        $message = '✓ Firmware dihapus'; $msgType = 'success';
    }
    if (isset($_POST['clear_log'])) { file_put_contents(LOG_FILE, ''); $message = '✓ Log dibersihkan'; $msgType = 'success'; }
    if (isset($_POST['publish_custom'])) {
        $ct = trim($_POST['custom_topic']); $cp = trim($_POST['custom_payload']);
        if ($ct && $cp) { $out = sendMQTT($ct, $cp); writeLog("PUBLISH: $ct → $cp", 'MQTT'); $message = "✓ Pesan dikirim ke $ct"; $msgType = 'success'; }
        else { $message = 'Topic dan payload wajib diisi'; $msgType = 'error'; }
    }
}

$vInfo   = getVersionInfo();
$fwExists = file_exists(FIRMWARE_FILE);
$fwSize  = $fwExists ? round(filesize(FIRMWARE_FILE) / 1024, 1) . ' KB' : '-';
$fwDate  = $fwExists ? date('d M Y H:i', filemtime(FIRMWARE_FILE)) : '-';
$logs    = getRecentLogs(50);
$devices = getDevices();
$stats   = getServerStats();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rich Panda Aquatech — OTA Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
  --bg:        #050c18;
  --bg2:       #080f1e;
  --surface:   #0c1628;
  --card:      #0e1c30;
  --card2:     #112038;
  --border:    #1a3050;
  --border2:   #1e3a5f;
  --cyan:      #00e5ff;
  --cyan2:     #33ecff;
  --green:     #00ff9d;
  --amber:     #ffb700;
  --red:       #ff3d6b;
  --purple:    #a855f7;
  --blue:      #3b82f6;
  --text:      #ddeeff;
  --text2:     #7fa8cc;
  --text3:     #3d6080;
  --mono:      'Space Mono', monospace;
  --sans:      'Outfit', sans-serif;
  --glow-c:    0 0 30px #00e5ff33;
  --glow-g:    0 0 30px #00ff9d33;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  background:var(--bg);
  color:var(--text);
  font-family:var(--sans);
  min-height:100vh;
  overflow-x:hidden;
}
body::before{
  content:'';
  position:fixed;top:0;left:0;right:0;bottom:0;
  background:
    radial-gradient(ellipse 80% 40% at 20% -10%, #00e5ff0a 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 80% 110%, #00ff9d07 0%, transparent 60%),
    radial-gradient(ellipse 40% 30% at 50% 50%, #3b82f603 0%, transparent 70%);
  pointer-events:none;z-index:0;
}

/* ──── SCROLLBAR ──── */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--cyan)}

/* ──── LOGIN ──── */
.login-wrap{
  min-height:100vh;display:flex;align-items:center;justify-content:center;
  padding:20px;position:relative;z-index:1;
}
.login-box{
  width:420px;
  background:var(--card);
  border:1px solid var(--border2);
  border-radius:16px;
  overflow:hidden;
  box-shadow:0 40px 100px #000c, 0 0 0 1px #00e5ff15;
}
.login-header{
  background:linear-gradient(135deg, #0a1f3a, #0e2850);
  padding:40px 40px 32px;
  border-bottom:1px solid var(--border2);
  position:relative;overflow:hidden;
}
.login-header::before{
  content:'';position:absolute;top:-40px;right:-40px;
  width:200px;height:200px;
  background:radial-gradient(circle, #00e5ff18, transparent 70%);
  border-radius:50%;
}
.login-logo{
  display:flex;align-items:center;gap:14px;margin-bottom:20px;
}
.logo-mark{
  width:44px;height:44px;
  background:linear-gradient(135deg, #00e5ff, #00ff9d);
  border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:20px;font-weight:900;color:#050c18;
  font-family:var(--sans);
  box-shadow:0 0 20px #00e5ff44;
}
.logo-text .company{font-size:16px;font-weight:800;color:#fff;letter-spacing:.5px}
.logo-text .sub{font-size:11px;color:var(--text2);font-family:var(--mono);letter-spacing:1px}
.login-title{font-size:26px;font-weight:800;line-height:1.1}
.login-title span{
  background:linear-gradient(90deg, var(--cyan), var(--green));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.login-body{padding:32px 40px 40px}
.login-error{
  background:#ff3d6b12;border:1px solid #ff3d6b40;
  color:var(--red);padding:12px 16px;border-radius:8px;
  font-family:var(--mono);font-size:12px;margin-bottom:20px;
}
.fld{margin-bottom:16px}
.fld label{
  display:block;font-size:11px;font-family:var(--mono);
  letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;margin-bottom:8px;
}
.fld input{
  width:100%;background:var(--bg2);
  border:1px solid var(--border2);border-radius:8px;
  padding:12px 16px;color:var(--text);
  font-family:var(--mono);font-size:14px;outline:none;
  transition:all .2s;
}
.fld input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px #00e5ff15}
.btn{
  display:inline-flex;align-items:center;gap:8px;
  padding:11px 22px;border:none;border-radius:8px;
  font-family:var(--sans);font-size:13px;font-weight:700;
  cursor:pointer;transition:all .2s;letter-spacing:.3px;
}
.btn-grad{
  background:linear-gradient(135deg, var(--cyan), #0099bb);
  color:#050c18;width:100%;justify-content:center;
  box-shadow:0 4px 20px #00e5ff30;
}
.btn-grad:hover{transform:translateY(-1px);box-shadow:0 8px 30px #00e5ff50}
.btn-green{background:var(--green);color:#050c18;box-shadow:0 4px 15px #00ff9d30}
.btn-green:hover{background:#33ffb0;box-shadow:0 6px 25px #00ff9d50;transform:translateY(-1px)}
.btn-outline{background:transparent;border:1px solid var(--border2);color:var(--text2)}
.btn-outline:hover{border-color:var(--cyan);color:var(--cyan)}
.btn-red{background:transparent;border:1px solid var(--red);color:var(--red)}
.btn-red:hover{background:#ff3d6b15}
.btn-amber{background:var(--amber);color:#050c18}
.btn-amber:hover{background:#ffca33}
.btn-sm{padding:7px 14px;font-size:12px}
.btn-purple{background:var(--purple);color:#fff}
.btn-purple:hover{background:#bf7aff}
.btn-blue{background:var(--blue);color:#fff}
.btn-blue:hover{background:#60a5fa}

/* ──── TOPBAR ──── */
.topbar{
  position:sticky;top:0;z-index:200;
  background:rgba(8,15,30,.92);
  backdrop-filter:blur(20px);
  border-bottom:1px solid var(--border);
  padding:0 28px;
  display:flex;align-items:center;justify-content:space-between;
  height:64px;
}
.tb-left{display:flex;align-items:center;gap:16px}
.tb-logo{
  width:36px;height:36px;
  background:linear-gradient(135deg, var(--cyan), var(--green));
  border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-weight:900;font-size:14px;color:#050c18;
  box-shadow:0 0 15px #00e5ff33;
}
.tb-brand .name{font-size:15px;font-weight:800;color:#fff}
.tb-brand .sub{font-size:10px;font-family:var(--mono);color:var(--text3);letter-spacing:1.5px;text-transform:uppercase}
.tb-status{
  display:flex;align-items:center;gap:8px;
  background:var(--card2);border:1px solid var(--border);
  border-radius:20px;padding:6px 14px;
}
.dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.dot-green{background:var(--green);box-shadow:0 0 8px var(--green);animation:blink 2s infinite}
.dot-red{background:var(--red);box-shadow:0 0 8px var(--red)}
.dot-amber{background:var(--amber);box-shadow:0 0 8px var(--amber);animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
.tb-right{display:flex;align-items:center;gap:12px}
.tb-time{font-family:var(--mono);font-size:12px;color:var(--text2)}
.logout-btn{
  background:transparent;border:1px solid var(--border2);
  color:var(--text3);padding:7px 16px;border-radius:6px;
  font-family:var(--mono);font-size:11px;cursor:pointer;
  transition:all .2s;letter-spacing:1px;text-transform:uppercase;
}
.logout-btn:hover{border-color:var(--red);color:var(--red)}

/* ──── LAYOUT ──── */
.wrap{position:relative;z-index:1}
.page-head{
  padding:28px 28px 0;
  display:flex;align-items:flex-end;justify-content:space-between;
  flex-wrap:wrap;gap:16px;
}
.page-title{font-size:28px;font-weight:900;letter-spacing:-.5px}
.page-title span{
  background:linear-gradient(90deg, var(--cyan), var(--green));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}
.engineer-tag{
  font-family:var(--mono);font-size:10px;color:var(--text3);
  letter-spacing:1.5px;text-transform:uppercase;margin-top:4px;
}
.engineer-tag span{color:var(--cyan)}

.main{padding:20px 28px 40px}

/* ──── ALERT ──── */
.alert{
  padding:14px 18px;border-radius:10px;
  font-family:var(--mono);font-size:12px;
  margin-bottom:20px;border-left:3px solid;
  display:flex;align-items:center;gap:10px;
}
.alert-success{background:#00ff9d0f;border-color:var(--green);color:var(--green)}
.alert-error{background:#ff3d6b0f;border-color:var(--red);color:var(--red)}

/* ──── STATS ROW ──── */
.stats-row{
  display:grid;
  grid-template-columns:repeat(5, 1fr);
  gap:14px;margin-bottom:20px;
}
.stat{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:12px;
  padding:18px 20px;
  position:relative;overflow:hidden;
  transition:border-color .2s, transform .2s;
}
.stat:hover{border-color:var(--border2);transform:translateY(-2px)}
.stat::after{
  content:'';position:absolute;
  inset:0;border-radius:12px;opacity:0;
  transition:opacity .3s;
}
.stat:hover::after{opacity:1}
.stat-ic{font-size:22px;margin-bottom:10px;display:block}
.stat-lbl{font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text3);text-transform:uppercase;margin-bottom:6px}
.stat-val{font-size:18px;font-weight:800;font-family:var(--mono);line-height:1}
.stat-meta{font-size:11px;color:var(--text3);margin-top:5px;font-family:var(--mono)}
.s-cyan .stat-val{color:var(--cyan)}
.s-green .stat-val{color:var(--green)}
.s-amber .stat-val{color:var(--amber)}
.s-purple .stat-val{color:var(--purple)}
.s-blue .stat-val{color:var(--blue)}
.s-cyan{border-top:2px solid var(--cyan)}
.s-green{border-top:2px solid var(--green)}
.s-amber{border-top:2px solid var(--amber)}
.s-purple{border-top:2px solid var(--purple)}
.s-blue{border-top:2px solid var(--blue)}

/* ──── TABS ──── */
.tabs{
  display:flex;gap:4px;
  background:var(--card);border:1px solid var(--border);
  border-radius:12px;padding:5px;
  margin-bottom:20px;flex-wrap:wrap;
}
.tab-btn{
  padding:9px 18px;border:none;border-radius:8px;
  background:transparent;color:var(--text2);
  font-family:var(--sans);font-size:13px;font-weight:600;
  cursor:pointer;transition:all .2s;letter-spacing:.2px;
  display:flex;align-items:center;gap:7px;
}
.tab-btn:hover{background:var(--card2);color:var(--text)}
.tab-btn.active{background:var(--cyan);color:#050c18}
.tab-pane{display:none}
.tab-pane.active{display:block}

/* ──── GRID ──── */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.full{grid-column:1/-1}

/* ──── CARD ──── */
.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:12px;overflow:hidden;
}
.card-h{
  padding:14px 20px;
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  background:var(--card2);
}
.card-h-left{display:flex;align-items:center;gap:10px}
.card-ic{
  width:32px;height:32px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-size:15px;
}
.ic-cyan{background:#00e5ff18;border:1px solid #00e5ff30}
.ic-green{background:#00ff9d18;border:1px solid #00ff9d30}
.ic-amber{background:#ffb70018;border:1px solid #ffb70030}
.ic-red{background:#ff3d6b18;border:1px solid #ff3d6b30}
.ic-purple{background:#a855f718;border:1px solid #a855f730}
.ic-blue{background:#3b82f618;border:1px solid #3b82f630}
.card-title{font-size:12px;font-family:var(--mono);letter-spacing:2px;text-transform:uppercase;color:var(--text2)}
.card-b{padding:20px}

/* ──── FORM ELEMENTS ──── */
.fld label{
  display:block;font-size:10px;font-family:var(--mono);
  letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;margin-bottom:6px;
}
.inp{
  width:100%;background:var(--bg2);
  border:1px solid var(--border);border-radius:8px;
  padding:10px 14px;color:var(--text);
  font-family:var(--mono);font-size:13px;outline:none;
  transition:all .2s;
}
.inp:focus{border-color:var(--cyan);box-shadow:0 0 0 3px #00e5ff12}
.inp-file{
  width:100%;background:var(--bg2);
  border:1px solid var(--border);border-radius:8px;
  padding:9px 12px;color:var(--text);
  font-family:var(--mono);font-size:12px;cursor:pointer;
  transition:border-color .2s;
}
.inp-file::file-selector-button{
  background:var(--border2);color:var(--text);
  border:none;padding:5px 12px;border-radius:5px;
  font-family:var(--mono);font-size:11px;cursor:pointer;margin-right:10px;
}
.inp:focus+.inp-file{border-color:var(--cyan)}
textarea.inp{resize:vertical;min-height:80px}
.mb{margin-bottom:12px}
.row-btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px}

/* ──── TOPIC PREVIEW ──── */
.topic-box{
  background:var(--bg2);border:1px solid #00e5ff25;border-radius:8px;
  padding:10px 16px;font-family:var(--mono);font-size:12px;
  color:var(--cyan);margin-bottom:14px;word-break:break-all;
  display:flex;align-items:center;gap:8px;
}
.topic-box::before{content:'📡';font-size:14px}

/* ──── DEVICES TABLE ──── */
.device-table{width:100%;border-collapse:collapse;font-size:13px}
.device-table th{
  font-family:var(--mono);font-size:10px;letter-spacing:1.5px;
  text-transform:uppercase;color:var(--text3);
  padding:10px 14px;border-bottom:1px solid var(--border);
  text-align:left;
}
.device-table td{
  padding:10px 14px;border-bottom:1px solid var(--border);
  font-family:var(--mono);font-size:12px;
}
.device-table tr:last-child td{border-bottom:none}
.device-table tr:hover td{background:var(--card2)}
.chip-badge{
  background:#00e5ff15;border:1px solid #00e5ff30;
  color:var(--cyan);padding:2px 8px;border-radius:4px;
  font-size:11px;
}
.empty-state{
  text-align:center;padding:40px 20px;
  color:var(--text3);font-family:var(--mono);font-size:12px;
}
.empty-icon{font-size:32px;margin-bottom:12px}

/* ──── LOG ──── */
.log-wrap{
  background:var(--bg2);border:1px solid var(--border);
  border-radius:8px;padding:14px;
  max-height:320px;overflow-y:auto;
  font-family:var(--mono);font-size:11px;line-height:2;
}
.ll{color:var(--text3);transition:color .2s}
.ll:hover{color:var(--text)}
.ll-upload{color:var(--green)}
.ll-trigger,.ll-broadcast{color:var(--cyan)}
.ll-delete{color:var(--amber)}
.ll-mqtt{color:var(--purple)}
.ll-device{color:var(--blue)}

/* ──── MQTT MONITOR ──── */
.mqtt-monitor{
  background:var(--bg2);border:1px solid var(--border);
  border-radius:8px;padding:14px;
  font-family:var(--mono);font-size:11px;line-height:1.8;
  height:320px;overflow-y:auto;position:relative;
}
.mqtt-msg{
  display:flex;gap:10px;padding:4px 0;
  border-bottom:1px solid var(--border);
}
.mqtt-msg:last-child{border:none}
.mqtt-ts{color:var(--text3);flex-shrink:0;font-size:10px}
.mqtt-topic{color:var(--cyan);flex-shrink:0}
.mqtt-payload{color:var(--green)}
.mqtt-empty{color:var(--text3);text-align:center;padding:40px;font-size:12px}
.mqtt-badge{
  display:inline-flex;align-items:center;gap:5px;
  background:#00e5ff18;border:1px solid #00e5ff30;
  color:var(--cyan);padding:3px 10px;border-radius:4px;font-size:11px;
}
.mqtt-connecting{
  display:flex;align-items:center;gap:8px;
  color:var(--amber);font-size:12px;
  padding:10px;
}

/* ──── INFO ROWS ──── */
.inf-row{
  display:flex;justify-content:space-between;align-items:center;
  padding:10px 0;border-bottom:1px solid var(--border);
  font-size:13px;
}
.inf-row:last-child{border:none}
.inf-k{font-family:var(--mono);color:var(--text3);font-size:10px;letter-spacing:1px;text-transform:uppercase}
.inf-v{font-family:var(--mono);font-weight:700;font-size:12px}
.badge{display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-family:var(--mono);font-weight:700;letter-spacing:.5px}
.b-ok{background:#00ff9d18;color:var(--green);border:1px solid #00ff9d30}
.b-err{background:#ff3d6b18;color:var(--red);border:1px solid #ff3d6b30}
.b-amber{background:#ffb70018;color:var(--amber);border:1px solid #ffb70030}

/* ──── SERVER MONITOR ──── */
.srv-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.srv-item{
  background:var(--bg2);border:1px solid var(--border);
  border-radius:8px;padding:14px;
}
.srv-lbl{font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text3);text-transform:uppercase;margin-bottom:6px}
.srv-val{font-size:14px;font-family:var(--mono);font-weight:700;color:var(--text)}
.progress-bar{background:var(--border);border-radius:4px;height:6px;overflow:hidden;margin-top:8px}
.progress-fill{height:100%;border-radius:4px;transition:width 1s}

/* ──── FOOTER ──── */
.footer{
  border-top:1px solid var(--border);
  padding:20px 28px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:10px;
}
.footer-brand{font-size:13px;font-weight:800;color:var(--text2)}
.footer-brand span{color:var(--cyan)}
.footer-eng{font-family:var(--mono);font-size:11px;color:var(--text3)}
.footer-eng b{color:var(--green)}

@media(max-width:900px){
  .stats-row{grid-template-columns:repeat(3,1fr)}
  .g2,.g3{grid-template-columns:1fr}
  .topbar{padding:0 16px}
  .main,.page-head{padding-left:16px;padding-right:16px}
}
@media(max-width:600px){
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .tabs{overflow-x:auto}
}
</style>
</head>
<body>

<?php if (!$isAuth): ?>
<!-- ═══════════════════ LOGIN ═══════════════════ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-header">
      <div class="login-logo">
        <div class="logo-mark">RP</div>
        <div class="logo-text">
          <div class="company">Rich Panda Aquatech</div>
          <div class="sub">OTA // Firmware Management</div>
        </div>
      </div>
      <div class="login-title">Selamat<br><span>Datang Kembali</span></div>
    </div>
    <div class="login-body">
      <?php if ($authError): ?>
        <div class="login-error">⚠ <?= htmlspecialchars($authError) ?></div>
      <?php endif; ?>
      <form method="POST">
        <div class="fld"><label>Password Akses</label>
          <input class="fld" style="width:100%;background:var(--bg2);border:1px solid var(--border2);border-radius:8px;padding:12px 16px;color:var(--text);font-family:var(--mono);font-size:14px;outline:none;transition:all .2s;margin-bottom:20px;display:block" type="password" name="password" placeholder="••••••••••••" autofocus required>
        </div>
        <button class="btn btn-grad" type="submit">MASUK KE DASHBOARD →</button>
      </form>
      <div style="margin-top:24px;text-align:center;font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px">
        ENGINEER BY <span style="color:var(--green)">JONY DWI M.</span>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════ DASHBOARD ═══════════════════ -->
<div class="wrap">

<!-- TOPBAR -->
<div class="topbar">
  <div class="tb-left">
    <div class="tb-logo">RP</div>
    <div class="tb-brand">
      <div class="name">Rich Panda Aquatech</div>
      <div class="sub">OTA Dashboard System</div>
    </div>
    <div class="tb-status" id="mqttStatusBar">
      <div class="dot dot-amber" id="mqttDot"></div>
      <span style="font-size:11px;font-family:var(--mono);color:var(--text2)" id="mqttStatusTxt">Menghubungkan MQTT...</span>
    </div>
  </div>
  <div class="tb-right">
    <div class="tb-time" id="clockEl"></div>
    <form method="POST" style="display:inline">
      <button class="logout-btn" name="logout">⏻ LOGOUT</button>
    </form>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="page-head">
  <div>
    <div class="page-title">OTA <span>Control Center</span></div>
    <div class="engineer-tag">Rich Panda Aquatech · Engineer by <span>Jony Dwi M.</span></div>
  </div>
  <div style="font-family:var(--mono);font-size:11px;color:var(--text3);text-align:right">
    <?= date('l, d F Y') ?><br>
    <span style="color:var(--cyan)"><?= MQTT_HOST ?></span>
  </div>
</div>

<div class="main">

  <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>">
      <?= $msgType === 'success' ? '✓' : '✗' ?> <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="stats-row">
    <div class="stat s-cyan">
      <span class="stat-ic">🚀</span>
      <div class="stat-lbl">Versi Aktif</div>
      <div class="stat-val"><?= htmlspecialchars($vInfo['version']) ?></div>
      <div class="stat-meta">di server</div>
    </div>
    <div class="stat s-green">
      <span class="stat-ic">💾</span>
      <div class="stat-lbl">Ukuran</div>
      <div class="stat-val"><?= $fwSize ?></div>
      <div class="stat-meta"><?= $fwDate ?></div>
    </div>
    <div class="stat s-amber">
      <span class="stat-ic">📦</span>
      <div class="stat-lbl">Status</div>
      <div class="stat-val"><?= $fwExists ? 'READY' : 'KOSONG' ?></div>
      <div class="stat-meta"><?= $fwExists ? 'firmware.bin ada' : 'belum ada' ?></div>
    </div>
    <div class="stat s-purple">
      <span class="stat-ic">📡</span>
      <div class="stat-lbl">Devices</div>
      <div class="stat-val"><?= count($devices) ?></div>
      <div class="stat-meta">terdaftar</div>
    </div>
    <div class="stat s-blue">
      <span class="stat-ic">🖥</span>
      <div class="stat-lbl">CPU Load</div>
      <div class="stat-val"><?= number_format($stats['cpu'], 2) ?></div>
      <div class="stat-meta">load avg</div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('ota')">🚀 OTA Firmware</button>
    <button class="tab-btn" onclick="switchTab('mqtt')">📡 MQTT Monitor</button>
    <button class="tab-btn" onclick="switchTab('devices')">🔌 Device Manager</button>
    <button class="tab-btn" onclick="switchTab('server')">🖥 Server Status</button>
    <button class="tab-btn" onclick="switchTab('logs')">📋 Deploy Log</button>
  </div>

  <!-- ── TAB: OTA ── -->
  <div class="tab-pane active" id="tab-ota">
    <div class="g2">

      <!-- Upload -->
      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-cyan">⬆</div>
            <div class="card-title">Upload Firmware</div>
          </div>
        </div>
        <div class="card-b">
          <form method="POST" enctype="multipart/form-data">
            <div class="mb"><label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">Nomor Versi</label>
              <input class="inp" type="text" name="version" placeholder="v5.5" required></div>
            <div class="mb"><label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">Changelog / Catatan</label>
              <input class="inp" type="text" name="changelog" placeholder="Perbaikan sensor pH, tambah fitur auto-feeding"></div>
            <div class="mb"><label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">File Firmware (.bin)</label>
              <input class="inp-file" type="file" name="firmware" accept=".bin" required></div>
            <button class="btn btn-grad" type="submit">⬆ UPLOAD FIRMWARE</button>
          </form>
        </div>
      </div>

      <!-- Info Firmware -->
      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-green">ℹ</div>
            <div class="card-title">Info Firmware Server</div>
          </div>
          <span class="badge <?= $fwExists ? 'b-ok' : 'b-err' ?>"><?= $fwExists ? 'READY' : 'KOSONG' ?></span>
        </div>
        <div class="card-b">
          <div class="inf-row"><span class="inf-k">Versi</span><span class="inf-v" style="color:var(--cyan)"><?= htmlspecialchars($vInfo['version']) ?></span></div>
          <div class="inf-row"><span class="inf-k">Ukuran</span><span class="inf-v"><?= $fwSize ?></span></div>
          <div class="inf-row"><span class="inf-k">Diupload</span><span class="inf-v"><?= $fwDate ?></span></div>
          <div class="inf-row"><span class="inf-k">Changelog</span><span class="inf-v" style="font-size:11px;color:var(--text2)"><?= htmlspecialchars($vInfo['changelog'] ?? '-') ?></span></div>
          <div class="inf-row" style="flex-direction:column;align-items:flex-start;gap:4px">
            <span class="inf-k">URL Download</span>
            <span style="font-family:var(--mono);font-size:10px;color:var(--text3);word-break:break-all"><?= htmlspecialchars($vInfo['url']) ?></span>
          </div>
          <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
            <?php if ($fwExists): ?>
            <a href="<?= htmlspecialchars(FIRMWARE_URL) ?>" target="_blank" class="btn btn-outline btn-sm">↓ DOWNLOAD</a>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Hapus firmware.bin?')">
              <button class="btn btn-red btn-sm" name="delete_firmware">🗑 HAPUS</button>
            </form>
          </div>
        </div>
      </div>

      <!-- Trigger OTA -->
      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-amber">📡</div>
            <div class="card-title">Trigger OTA — Single Device</div>
          </div>
        </div>
        <div class="card-b">
          <form method="POST">
            <div class="mb">
              <label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">User ID (UID Firebase)</label>
              <input class="inp" type="text" name="mqtt_uid" placeholder="QdAbw85SkYRk7Gudzj5M33OUEJ33" value="<?= htmlspecialchars($_POST['mqtt_uid'] ?? '') ?>" id="otaUid" oninput="updateTopic()">
            </div>
            <div class="mb">
              <label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">Chip ID</label>
              <input class="inp" type="text" name="mqtt_chipid" placeholder="ESP_D4D7" value="<?= htmlspecialchars($_POST['mqtt_chipid'] ?? '') ?>" id="otaChip" oninput="updateTopic()">
            </div>
            <div class="mb">
              <div style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;margin-bottom:6px">Preview MQTT Topic</div>
              <div class="topic-box" id="topicPreview">
                <?= htmlspecialchars(($_POST['mqtt_uid'] ?? 'UID') . '/' . ($_POST['mqtt_chipid'] ?? 'CHIPID') . '/feederota') ?>
              </div>
            </div>
            <button class="btn btn-amber" type="submit" name="trigger_ota">📡 KIRIM OTA TRIGGER</button>
          </form>
        </div>
      </div>

      <!-- Broadcast OTA -->
      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-purple">📢</div>
            <div class="card-title">Broadcast OTA — Semua Device</div>
          </div>
          <span class="badge b-amber"><?= count($devices) ?> device</span>
        </div>
        <div class="card-b">
          <?php if (empty($devices)): ?>
            <div class="empty-state">
              <div class="empty-icon">📭</div>
              <div>Belum ada device terdaftar.<br>Tambahkan di tab Device Manager.</div>
            </div>
          <?php else: ?>
            <div style="margin-bottom:16px">
              <?php foreach(array_slice($devices, 0, 4) as $d): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)">
                  <span style="font-family:var(--mono);font-size:12px;color:var(--text2)"><?= htmlspecialchars($d['label'] ?: $d['chipid']) ?></span>
                  <span class="chip-badge"><?= htmlspecialchars($d['chipid']) ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (count($devices) > 4): ?>
                <div style="font-family:var(--mono);font-size:11px;color:var(--text3);padding:8px 0">+<?= count($devices)-4 ?> device lainnya...</div>
              <?php endif; ?>
            </div>
            <form method="POST" onsubmit="return confirm('Kirim OTA ke SEMUA <?= count($devices) ?> device?')">
              <button class="btn btn-purple" type="submit" name="trigger_ota_broadcast">
                📢 BROADCAST KE <?= count($devices) ?> DEVICE
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /g2 -->
  </div>

  <!-- ── TAB: MQTT MONITOR ── -->
  <div class="tab-pane" id="tab-mqtt">
    <div class="g2">

      <div class="card full">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-cyan">📡</div>
            <div class="card-title">MQTT Live Monitor</div>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            <span class="mqtt-badge"><span class="dot dot-amber" id="mqttLiveDot" style="width:6px;height:6px"></span> <span id="mqttLiveStatus">Menghubungkan...</span></span>
            <button class="btn btn-outline btn-sm" onclick="clearMqttLog()">🗑 Clear</button>
          </div>
        </div>
        <div class="card-b">
          <div style="margin-bottom:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <div style="font-family:var(--mono);font-size:11px;color:var(--text3)">
              Broker: <span style="color:var(--cyan)"><?= MQTT_HOST ?>:<?= MQTT_PORT ?></span> ·
              User: <span style="color:var(--green)"><?= MQTT_USER ?></span> ·
              Subscribe: <span style="color:var(--amber)">#</span>
            </div>
          </div>
          <div class="mqtt-monitor" id="mqttLog">
            <div class="mqtt-connecting">
              <div class="dot dot-amber"></div>
              Menghubungkan ke broker MQTT...
            </div>
          </div>
          <div style="margin-top:10px;font-family:var(--mono);font-size:10px;color:var(--text3)">
            ⓘ Pesan masuk ditampilkan secara real-time via WebSocket. Koneksi ke <b style="color:var(--cyan)"><?= MQTT_HOST ?>:9001</b>
          </div>
        </div>
      </div>

      <!-- Publish Manual -->
      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-green">✉</div>
            <div class="card-title">Publish Pesan Manual</div>
          </div>
        </div>
        <div class="card-b">
          <form method="POST">
            <div class="mb">
              <label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">Topic</label>
              <input class="inp" type="text" name="custom_topic" placeholder="uid/chipid/command" value="<?= htmlspecialchars($_POST['custom_topic'] ?? '') ?>">
            </div>
            <div class="mb">
              <label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">Payload</label>
              <input class="inp" type="text" name="custom_payload" placeholder='{"cmd":"restart"}' value="<?= htmlspecialchars($_POST['custom_payload'] ?? '') ?>">
            </div>
            <button class="btn btn-green" type="submit" name="publish_custom">✉ PUBLISH</button>
          </form>
        </div>
      </div>

      <!-- MQTT Info -->
      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-blue">🔧</div>
            <div class="card-title">Info Broker MQTT</div>
          </div>
        </div>
        <div class="card-b">
          <div class="inf-row"><span class="inf-k">Host</span><span class="inf-v" style="color:var(--cyan)"><?= MQTT_HOST ?></span></div>
          <div class="inf-row"><span class="inf-k">Port TCP</span><span class="inf-v"><?= MQTT_PORT ?></span></div>
          <div class="inf-row"><span class="inf-k">Port WS</span><span class="inf-v">9001</span></div>
          <div class="inf-row"><span class="inf-k">Username</span><span class="inf-v"><?= MQTT_USER ?></span></div>
          <div class="inf-row"><span class="inf-k">Password</span><span class="inf-v" style="letter-spacing:3px;color:var(--text3)" id="passBlur" onclick="this.style.filter='none'" style="filter:blur(4px);cursor:pointer"><?= MQTT_PASS ?></span></div>
          <div class="inf-row"><span class="inf-k">Subscribe</span><span class="inf-v" style="color:var(--amber)"># (semua topic)</span></div>
          <div class="inf-row"><span class="inf-k">Status mosquitto_pub</span>
            <span class="badge <?= $stats['mqtt_check'] === 'OK' ? 'b-ok' : 'b-err' ?>"><?= $stats['mqtt_check'] ?></span>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── TAB: DEVICES ── -->
  <div class="tab-pane" id="tab-devices">
    <div class="g2">

      <!-- Add Device -->
      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-cyan">➕</div>
            <div class="card-title">Tambah Device</div>
          </div>
        </div>
        <div class="card-b">
          <form method="POST">
            <div class="mb"><label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">Label / Nama Device</label>
              <input class="inp" type="text" name="dev_label" placeholder="Kolam Ikan Mas Blok A"></div>
            <div class="mb"><label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">User ID (UID)</label>
              <input class="inp" type="text" name="dev_uid" placeholder="QdAbw85SkYRk7Gudzj5M33OUEJ33" required></div>
            <div class="mb"><label style="font-size:10px;font-family:var(--mono);letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;display:block;margin-bottom:6px">Chip ID</label>
              <input class="inp" type="text" name="dev_chipid" placeholder="ESP_D4D7" required></div>
            <button class="btn btn-grad" type="submit" name="add_device">➕ DAFTARKAN DEVICE</button>
          </form>
        </div>
      </div>

      <!-- Device List -->
      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-purple">🔌</div>
            <div class="card-title">Daftar Device Terdaftar</div>
          </div>
          <span class="badge b-ok"><?= count($devices) ?> device</span>
        </div>
        <div class="card-b" style="padding:0">
          <?php if (empty($devices)): ?>
            <div class="empty-state"><div class="empty-icon">🔌</div><div>Belum ada device.<br>Tambahkan di form kiri.</div></div>
          <?php else: ?>
            <table class="device-table">
              <thead>
                <tr>
                  <th>Label</th>
                  <th>Chip ID</th>
                  <th>Ditambahkan</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($devices as $key => $d): ?>
                  <tr>
                    <td style="color:var(--text)"><?= htmlspecialchars($d['label'] ?: '-') ?></td>
                    <td><span class="chip-badge"><?= htmlspecialchars($d['chipid']) ?></span></td>
                    <td style="color:var(--text3)"><?= htmlspecialchars(substr($d['added'] ?? '-', 0, 10)) ?></td>
                    <td>
                      <form method="POST" onsubmit="return confirm('Hapus device ini?')">
                        <input type="hidden" name="device_key" value="<?= htmlspecialchars($key) ?>">
                        <button class="btn btn-red btn-sm" name="remove_device">🗑</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

  <!-- ── TAB: SERVER ── -->
  <div class="tab-pane" id="tab-server">
    <div class="g2">

      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-blue">🖥</div>
            <div class="card-title">Server Resources</div>
          </div>
          <button class="btn btn-outline btn-sm" onclick="refreshServer()">↻ Refresh</button>
        </div>
        <div class="card-b">
          <div class="srv-grid">
            <div class="srv-item">
              <div class="srv-lbl">CPU Load Avg</div>
              <div class="srv-val" style="color:var(--cyan)"><?= number_format($stats['cpu'], 2) ?></div>
              <div class="progress-bar"><div class="progress-fill" style="width:<?= min(100, $stats['cpu']*100) ?>%;background:var(--cyan)"></div></div>
            </div>
            <div class="srv-item">
              <div class="srv-lbl">Memory Usage</div>
              <div class="srv-val" style="color:var(--green)"><?= $stats['mem'] ?></div>
            </div>
            <div class="srv-item">
              <div class="srv-lbl">Disk Free</div>
              <div class="srv-val" style="color:var(--amber)"><?= $stats['disk'] ?></div>
            </div>
            <div class="srv-item">
              <div class="srv-lbl">Uptime</div>
              <div class="srv-val" style="color:var(--purple);font-size:12px"><?= $stats['uptime'] ?></div>
            </div>
            <div class="srv-item">
              <div class="srv-lbl">PHP Version</div>
              <div class="srv-val"><?= $stats['php'] ?></div>
            </div>
            <div class="srv-item">
              <div class="srv-lbl">OS</div>
              <div class="srv-val" style="font-size:11px"><?= htmlspecialchars($stats['os']) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-h">
          <div class="card-h-left">
            <div class="card-ic ic-green">⚙</div>
            <div class="card-title">System Info</div>
          </div>
        </div>
        <div class="card-b">
          <div class="inf-row"><span class="inf-k">Hostname</span><span class="inf-v" style="color:var(--cyan)"><?= htmlspecialchars(gethostname() ?: '-') ?></span></div>
          <div class="inf-row"><span class="inf-k">Server IP</span><span class="inf-v"><?= htmlspecialchars($_SERVER['SERVER_ADDR'] ?? '-') ?></span></div>
          <div class="inf-row"><span class="inf-k">Client IP</span><span class="inf-v"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '-') ?></span></div>
          <div class="inf-row"><span class="inf-k">Firmware Dir</span><span class="inf-v" style="font-size:11px;color:var(--text2)"><?= FIRMWARE_DIR ?></span></div>
          <div class="inf-row"><span class="inf-k">Firmware URL Base</span><span class="inf-v" style="font-size:10px;color:var(--text3)"><?= FIRMWARE_URL ?></span></div>
          <div class="inf-row"><span class="inf-k">mosquitto_pub</span>
            <span class="badge <?= $stats['mqtt_check'] === 'OK' ? 'b-ok' : 'b-err' ?>"><?= $stats['mqtt_check'] ?></span>
          </div>
          <div class="inf-row"><span class="inf-k">Log File</span>
            <span class="badge <?= file_exists(LOG_FILE) ? 'b-ok' : 'b-amber' ?>"><?= file_exists(LOG_FILE) ? 'ADA' : 'BELUM DIBUAT' ?></span>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- ── TAB: LOGS ── -->
  <div class="tab-pane" id="tab-logs">
    <div class="card">
      <div class="card-h">
        <div class="card-h-left">
          <div class="card-ic ic-amber">📋</div>
          <div class="card-title">Deploy Log</div>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
          <span style="font-family:var(--mono);font-size:11px;color:var(--text3)"><?= count($logs) ?> entri terbaru</span>
          <form method="POST" onsubmit="return confirm('Hapus semua log?')">
            <button class="btn btn-red btn-sm" name="clear_log">🗑 CLEAR LOG</button>
          </form>
        </div>
      </div>
      <div class="card-b">
        <div class="log-wrap">
          <?php if (empty($logs)): ?>
            <div style="color:var(--text3);text-align:center;padding:30px;font-family:var(--mono);font-size:12px">-- log kosong --</div>
          <?php else: ?>
            <?php foreach ($logs as $line): ?>
              <?php
                $cls = 'll';
                if (str_contains($line, 'UPLOAD'))    $cls .= ' ll-upload';
                elseif (str_contains($line, 'TRIGGER') || str_contains($line, 'BROADCAST')) $cls .= ' ll-trigger';
                elseif (str_contains($line, 'DELETE')) $cls .= ' ll-delete';
                elseif (str_contains($line, 'MQTT') || str_contains($line, 'PUBLISH')) $cls .= ' ll-mqtt';
                elseif (str_contains($line, 'DEVICE')) $cls .= ' ll-device';
              ?>
              <div class="<?= $cls ?>"><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div><!-- /main -->

<!-- FOOTER -->
<div class="footer">
  <div>
    <div class="footer-brand"><span>Rich Panda</span> Aquatech</div>
    <div style="font-size:11px;color:var(--text3);font-family:var(--mono);margin-top:3px">OTA Firmware Dashboard · <?= date('Y') ?></div>
  </div>
  <div class="footer-eng">Engineer by <b>Jony Dwi M.</b> · <?= MQTT_HOST ?></div>
</div>

</div><!-- /wrap -->

<script>
// ── Clock ──
function updateClock() {
  const d = new Date();
  document.getElementById('clockEl').textContent =
    d.getHours().toString().padStart(2,'0') + ':' +
    d.getMinutes().toString().padStart(2,'0') + ':' +
    d.getSeconds().toString().padStart(2,'0');
}
setInterval(updateClock, 1000); updateClock();

// ── Tabs ──
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach((b,i) => b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  event.currentTarget.classList.add('active');
  document.getElementById('tab-' + name).classList.add('active');
}

// ── Topic preview ──
function updateTopic() {
  const uid   = document.getElementById('otaUid').value || 'UID';
  const chip  = document.getElementById('otaChip').value || 'CHIPID';
  document.getElementById('topicPreview').textContent = uid + '/' + chip + '/feederota';
}

// ── MQTT WebSocket ──
let mqttClient = null;
let msgCount = 0;
const MQTT_MSGS = [];

function connectMQTT() {
  try {
    const clientId = 'dashboard_' + Math.random().toString(16).substr(2,8);
    mqttClient = new Paho.Client('<?= MQTT_HOST ?>', 9001, '/mqtt', clientId);

    mqttClient.onConnectionLost = function(res) {
      setMqttStatus('Terputus · ' + res.errorMessage, false);
      setTimeout(connectMQTT, 5000);
    };

    mqttClient.onMessageArrived = function(msg) {
      msgCount++;
      const now = new Date();
      const ts = now.getHours().toString().padStart(2,'0') + ':' +
                 now.getMinutes().toString().padStart(2,'0') + ':' +
                 now.getSeconds().toString().padStart(2,'0');
      appendMqttMsg(ts, msg.destinationName, msg.payloadString);
    };

    mqttClient.connect({
      userName: '<?= MQTT_USER ?>',
      password: '<?= MQTT_PASS ?>',
      onSuccess: function() {
        setMqttStatus('Terhubung · ' + '<?= MQTT_HOST ?>', true);
        mqttClient.subscribe('#');
      },
      onFailure: function(err) {
        setMqttStatus('Gagal: ' + err.errorMessage, false);
        setTimeout(connectMQTT, 5000);
      },
      keepAliveInterval: 30,
      useSSL: false,
      timeout: 10
    });
  } catch(e) {
    setMqttStatus('Error: ' + e.message, false);
  }
}

function setMqttStatus(txt, ok) {
  const dot = document.getElementById('mqttDot');
  const dotLive = document.getElementById('mqttLiveDot');
  const statusBar = document.getElementById('mqttStatusTxt');
  const statusLive = document.getElementById('mqttLiveStatus');
  if (dot) {
    dot.className = 'dot ' + (ok ? 'dot-green' : 'dot-red');
  }
  if (dotLive) dotLive.className = 'dot ' + (ok ? 'dot-green' : 'dot-red');
  if (statusBar) statusBar.textContent = ok ? 'MQTT Terhubung' : 'MQTT Terputus';
  if (statusLive) statusLive.textContent = txt;
  if (ok) {
    const log = document.getElementById('mqttLog');
    if (log.querySelector('.mqtt-connecting')) log.innerHTML = '';
  }
}

function appendMqttMsg(ts, topic, payload) {
  const log = document.getElementById('mqttLog');
  const el = document.createElement('div');
  el.className = 'mqtt-msg';
  el.innerHTML =
    '<span class="mqtt-ts">' + ts + '</span>' +
    '<span class="mqtt-topic">' + escHtml(topic) + '</span>' +
    '<span class="mqtt-payload">' + escHtml(payload) + '</span>';
  log.appendChild(el);
  log.scrollTop = log.scrollHeight;
  // keep last 200
  while (log.children.length > 200) log.removeChild(log.firstChild);
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function clearMqttLog() {
  document.getElementById('mqttLog').innerHTML = '';
}

function refreshServer() { location.reload(); }

// Load Paho MQTT then connect
(function loadPaho() {
  const s = document.createElement('script');
  s.src = 'https://cdnjs.cloudflare.com/ajax/libs/paho-mqtt/1.0.2/mqttws31.min.js';
  s.onload = connectMQTT;
  s.onerror = function() {
    setMqttStatus('Library MQTT gagal dimuat', false);
  };
  document.head.appendChild(s);
})();
</script>

<?php endif; ?>
</body>
</html>
