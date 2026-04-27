<?php
// ============================================================
//  RP AQUATECH — OTA FIRMWARE DASHBOARD
//  Simpan file ini di: /var/www/ota/index.php
// ============================================================

// ── Konfigurasi ─────────────────────────────────────────────
define('DASHBOARD_PASS',  'rpaquatech2024');        // ganti password ini
define('FIRMWARE_DIR',    '/var/www/firmware/');
define('FIRMWARE_FILE',   FIRMWARE_DIR . 'firmware.bin');
define('VERSION_FILE',    FIRMWARE_DIR . 'version.json');
define('FIRMWARE_URL',    'http://rpaquatech.online/firmware/firmware.bin');
define('MQTT_HOST',       'rpaquatech.online');
define('MQTT_PORT',       1883);
define('MQTT_USER',       'rpaquatech');
define('MQTT_PASS',       'rpaquatech280304');
define('LOG_FILE',        FIRMWARE_DIR . 'deploy.log');

session_start();

// ── Auth ─────────────────────────────────────────────────────
$authError = '';
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}
if (isset($_POST['password'])) {
    if ($_POST['password'] === DASHBOARD_PASS) {
        $_SESSION['auth'] = true;
    } else {
        $authError = 'Password salah!';
    }
}
$isAuth = !empty($_SESSION['auth']);

// ── Helper: baca version.json ────────────────────────────────
function getVersionInfo() {
    if (!file_exists(VERSION_FILE)) return ['version' => '-', 'url' => '-'];
    $json = json_decode(file_get_contents(VERSION_FILE), true);
    return $json ?? ['version' => '-', 'url' => '-'];
}

// ── Helper: tulis log ────────────────────────────────────────
function writeLog($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

// ── Helper: kirim MQTT via mosquitto_pub ─────────────────────
function sendMQTT($topic, $payload) {
    $host = escapeshellarg(MQTT_HOST);
    $port = (int) MQTT_PORT;
    $user = escapeshellarg(MQTT_USER);
    $pass = escapeshellarg(MQTT_PASS);
    $t    = escapeshellarg($topic);
    $p    = escapeshellarg($payload);
    $cmd  = "mosquitto_pub -h $host -p $port -u $user -P $pass -t $t -m $p 2>&1";
    $out  = shell_exec($cmd);
    return $out;
}

// ── Helper: baca log terakhir ─────────────────────────────────
function getRecentLogs($n = 20) {
    if (!file_exists(LOG_FILE)) return [];
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_slice(array_reverse($lines), 0, $n);
}

// ── Proses action (hanya jika sudah auth) ────────────────────
$message = '';
$msgType = '';

if ($isAuth) {

    // Upload firmware
    if (isset($_FILES['firmware']) && $_FILES['firmware']['error'] === UPLOAD_ERR_OK) {
        $version = trim($_POST['version'] ?? '');
        if (empty($version)) {
            $message = 'Versi tidak boleh kosong!';
            $msgType = 'error';
        } elseif (pathinfo($_FILES['firmware']['name'], PATHINFO_EXTENSION) !== 'bin') {
            $message = 'File harus berformat .bin!';
            $msgType = 'error';
        } else {
            if (move_uploaded_file($_FILES['firmware']['tmp_name'], FIRMWARE_FILE)) {
                $versionData = json_encode([
                    'version' => $version,
                    'url'     => FIRMWARE_URL,
                ], JSON_PRETTY_PRINT);
                file_put_contents(VERSION_FILE, $versionData);
                $size = round(filesize(FIRMWARE_FILE) / 1024, 1);
                writeLog("UPLOAD: firmware $version ({$size}KB)");
                $message = "✓ Firmware $version berhasil diupload ({$size}KB)";
                $msgType = 'success';
            } else {
                $message = '✗ Gagal menyimpan file. Cek permission folder.';
                $msgType = 'error';
            }
        }
    }

    // Trigger OTA
    if (isset($_POST['trigger_ota'])) {
        $topic = trim($_POST['mqtt_uid']) . '/' . trim($_POST['mqtt_chipid']) . '/feederota';
        $out   = sendMQTT($topic, '1');
        writeLog("TRIGGER OTA: topic=$topic");
        $message = "✓ OTA dikirim ke: $topic";
        $msgType = 'success';
        if (!empty($out)) $message .= " (mqtt: $out)";
    }

    // Hapus firmware
    if (isset($_POST['delete_firmware'])) {
        if (file_exists(FIRMWARE_FILE)) {
            unlink(FIRMWARE_FILE);
            writeLog("DELETE: firmware.bin dihapus");
            $message = '✓ Firmware lama dihapus';
            $msgType = 'success';
        }
    }

    // Clear log
    if (isset($_POST['clear_log'])) {
        file_put_contents(LOG_FILE, '');
        $message = '✓ Log dibersihkan';
        $msgType = 'success';
    }
}

$vInfo    = getVersionInfo();
$fwExists = file_exists(FIRMWARE_FILE);
$fwSize   = $fwExists ? round(filesize(FIRMWARE_FILE) / 1024, 1) . ' KB' : '-';
$fwDate   = $fwExists ? date('d M Y H:i', filemtime(FIRMWARE_FILE)) : '-';
$logs     = getRecentLogs(30);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RP Aquatech — OTA Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:       #0a0e1a;
    --surface:  #111827;
    --card:     #161d2e;
    --border:   #1e2d45;
    --accent:   #00d4ff;
    --accent2:  #0af5a0;
    --warn:     #ff6b35;
    --danger:   #ff3366;
    --text:     #e2eaf8;
    --muted:    #4a6080;
    --mono:     'JetBrains Mono', monospace;
    --sans:     'Syne', sans-serif;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--sans);
    min-height: 100vh;
    background-image:
      radial-gradient(ellipse at 10% 0%, #00d4ff08 0%, transparent 50%),
      radial-gradient(ellipse at 90% 100%, #0af5a008 0%, transparent 50%);
  }

  /* ── Login ── */
  .login-wrap {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .login-box {
    background: var(--card);
    border: 1px solid var(--border);
    border-top: 3px solid var(--accent);
    padding: 48px 40px;
    width: 360px;
    border-radius: 4px;
    box-shadow: 0 24px 64px #000a;
  }
  .login-logo {
    font-size: 11px;
    font-family: var(--mono);
    color: var(--accent);
    letter-spacing: 4px;
    text-transform: uppercase;
    margin-bottom: 8px;
  }
  .login-title {
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 32px;
    line-height: 1.1;
  }
  .login-title span { color: var(--accent); }
  .login-input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 12px 16px;
    color: var(--text);
    font-family: var(--mono);
    font-size: 14px;
    outline: none;
    transition: border-color .2s;
    margin-bottom: 16px;
  }
  .login-input:focus { border-color: var(--accent); }
  .login-error {
    background: #ff336618;
    border: 1px solid #ff336640;
    color: var(--danger);
    padding: 10px 14px;
    border-radius: 3px;
    font-size: 13px;
    margin-bottom: 16px;
    font-family: var(--mono);
  }

  /* ── Layout ── */
  .topbar {
    border-bottom: 1px solid var(--border);
    padding: 0 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 60px;
    background: var(--surface);
    position: sticky;
    top: 0;
    z-index: 100;
  }
  .topbar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .brand-dot {
    width: 8px; height: 8px;
    background: var(--accent);
    border-radius: 50%;
    box-shadow: 0 0 12px var(--accent);
    animation: pulse 2s infinite;
  }
  @keyframes pulse {
    0%,100% { opacity: 1; }
    50% { opacity: .4; }
  }
  .brand-name {
    font-size: 13px;
    font-family: var(--mono);
    letter-spacing: 2px;
    color: var(--accent);
    text-transform: uppercase;
  }
  .brand-sub {
    font-size: 11px;
    color: var(--muted);
    font-family: var(--mono);
  }

  .main { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }

  /* ── Stats row ── */
  .stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 28px;
  }
  .stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 20px 24px;
    position: relative;
    overflow: hidden;
  }
  .stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
  }
  .stat-card.cyan::before  { background: var(--accent); }
  .stat-card.green::before { background: var(--accent2); }
  .stat-card.orange::before{ background: var(--warn); }

  .stat-label {
    font-size: 10px;
    font-family: var(--mono);
    letter-spacing: 2px;
    color: var(--muted);
    text-transform: uppercase;
    margin-bottom: 8px;
  }
  .stat-value {
    font-size: 22px;
    font-weight: 800;
    font-family: var(--mono);
  }
  .stat-card.cyan  .stat-value { color: var(--accent); }
  .stat-card.green .stat-value { color: var(--accent2); }
  .stat-card.orange .stat-value { color: var(--warn); }
  .stat-sub {
    font-size: 11px;
    color: var(--muted);
    font-family: var(--mono);
    margin-top: 4px;
  }

  /* ── Grid ── */
  .grid2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
  }

  /* ── Card ── */
  .card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 4px;
    overflow: hidden;
  }
  .card-head {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .card-icon {
    width: 28px; height: 28px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 3px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
  }
  .card-title {
    font-size: 12px;
    font-family: var(--mono);
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--text);
  }
  .card-body { padding: 20px; }

  /* ── Form elements ── */
  label {
    display: block;
    font-size: 10px;
    font-family: var(--mono);
    letter-spacing: 1.5px;
    color: var(--muted);
    text-transform: uppercase;
    margin-bottom: 6px;
  }
  input[type="text"],
  input[type="file"] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 10px 14px;
    color: var(--text);
    font-family: var(--mono);
    font-size: 13px;
    outline: none;
    transition: border-color .2s;
    margin-bottom: 14px;
  }
  input[type="text"]:focus { border-color: var(--accent); }
  input[type="file"] {
    padding: 8px 12px;
    cursor: pointer;
  }
  input[type="file"]::file-selector-button {
    background: var(--border);
    color: var(--text);
    border: none;
    padding: 6px 12px;
    border-radius: 2px;
    font-family: var(--mono);
    font-size: 12px;
    cursor: pointer;
    margin-right: 10px;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: none;
    border-radius: 3px;
    font-family: var(--mono);
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 1px;
    cursor: pointer;
    transition: all .2s;
    text-transform: uppercase;
  }
  .btn-primary {
    background: var(--accent);
    color: var(--bg);
  }
  .btn-primary:hover { background: #33ddff; box-shadow: 0 0 20px #00d4ff44; }

  .btn-success {
    background: var(--accent2);
    color: var(--bg);
  }
  .btn-success:hover { background: #33ffb8; box-shadow: 0 0 20px #0af5a044; }

  .btn-danger {
    background: transparent;
    color: var(--danger);
    border: 1px solid var(--danger);
  }
  .btn-danger:hover { background: #ff336618; }

  .btn-muted {
    background: var(--border);
    color: var(--muted);
  }
  .btn-muted:hover { color: var(--text); }

  /* ── Alert ── */
  .alert {
    padding: 12px 16px;
    border-radius: 3px;
    font-family: var(--mono);
    font-size: 13px;
    margin-bottom: 24px;
    border-left: 3px solid;
  }
  .alert-success {
    background: #0af5a010;
    border-color: var(--accent2);
    color: var(--accent2);
  }
  .alert-error {
    background: #ff336610;
    border-color: var(--danger);
    color: var(--danger);
  }

  /* ── Log ── */
  .log-wrap {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 16px;
    max-height: 300px;
    overflow-y: auto;
    font-family: var(--mono);
    font-size: 12px;
    line-height: 1.8;
  }
  .log-line { color: var(--muted); }
  .log-line:hover { color: var(--text); }
  .log-line.upload { color: var(--accent2); }
  .log-line.trigger { color: var(--accent); }
  .log-line.delete  { color: var(--warn); }

  /* ── Info row ── */
  .info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
  }
  .info-row:last-child { border-bottom: none; }
  .info-key {
    font-family: var(--mono);
    color: var(--muted);
    font-size: 11px;
    letter-spacing: 1px;
    text-transform: uppercase;
  }
  .info-val {
    font-family: var(--mono);
    font-weight: 600;
  }
  .badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 2px;
    font-size: 11px;
    font-family: var(--mono);
    font-weight: 700;
    letter-spacing: 1px;
  }
  .badge-ok  { background: #0af5a020; color: var(--accent2); border: 1px solid #0af5a040; }
  .badge-err { background: #ff336620; color: var(--danger);  border: 1px solid #ff336640; }

  /* ── MQTT topic hint ── */
  .topic-hint {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 10px 14px;
    font-family: var(--mono);
    font-size: 12px;
    color: var(--accent);
    margin-bottom: 14px;
    word-break: break-all;
  }

  .row-gap { display: flex; gap: 12px; }
  .full { grid-column: 1 / -1; }
  .logout-btn {
    background: none;
    border: 1px solid var(--border);
    color: var(--muted);
    padding: 6px 14px;
    border-radius: 3px;
    font-family: var(--mono);
    font-size: 11px;
    cursor: pointer;
    transition: all .2s;
  }
  .logout-btn:hover { border-color: var(--danger); color: var(--danger); }

  @media (max-width: 700px) {
    .grid2  { grid-template-columns: 1fr; }
    .stats  { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; }
    .main   { padding: 16px; }
  }
</style>
</head>
<body>

<?php if (!$isAuth): ?>
<!-- ═══════════════ LOGIN ═══════════════ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">RP Aquatech</div>
    <div class="login-title">OTA<br><span>Dashboard</span></div>
    <?php if ($authError): ?>
      <div class="login-error">⚠ <?= htmlspecialchars($authError) ?></div>
    <?php endif; ?>
    <form method="POST">
      <label>Password</label>
      <input class="login-input" type="password" name="password"
             placeholder="••••••••" autofocus required>
      <button class="btn btn-primary" style="width:100%;justify-content:center">
        MASUK →
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════ DASHBOARD ═══════════════ -->

<!-- Topbar -->
<div class="topbar">
  <div class="topbar-brand">
    <div class="brand-dot"></div>
    <div>
      <div class="brand-name">RP Aquatech OTA</div>
      <div class="brand-sub">Firmware Management Dashboard</div>
    </div>
  </div>
  <form method="POST" style="display:inline">
    <button class="logout-btn" name="logout">LOGOUT</button>
  </form>
</div>

<div class="main">

  <!-- Alert -->
  <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>">
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats">
    <div class="stat-card cyan">
      <div class="stat-label">Versi Aktif</div>
      <div class="stat-value"><?= htmlspecialchars($vInfo['version']) ?></div>
      <div class="stat-sub">di server</div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Ukuran Firmware</div>
      <div class="stat-value"><?= $fwSize ?></div>
      <div class="stat-sub"><?= $fwDate ?></div>
    </div>
    <div class="stat-card orange">
      <div class="stat-label">Status File</div>
      <div class="stat-value"><?= $fwExists ? 'READY' : 'KOSONG' ?></div>
      <div class="stat-sub"><?= $fwExists ? 'firmware.bin tersedia' : 'belum ada firmware' ?></div>
    </div>
  </div>

  <div class="grid2">

    <!-- Upload Firmware -->
    <div class="card">
      <div class="card-head">
        <div class="card-icon">⬆</div>
        <div class="card-title">Upload Firmware</div>
      </div>
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <label>Versi Baru</label>
          <input type="text" name="version" placeholder="v5.5" required>
          <label>File .bin</label>
          <input type="file" name="firmware" accept=".bin" required>
          <button class="btn btn-primary" type="submit">
            ⬆ UPLOAD FIRMWARE
          </button>
        </form>
      </div>
    </div>

    <!-- Trigger OTA -->
    <div class="card">
      <div class="card-head">
        <div class="card-icon">📡</div>
        <div class="card-title">Trigger OTA via MQTT</div>
      </div>
      <div class="card-body">
        <form method="POST">
          <label>User ID (UID)</label>
          <input type="text" name="mqtt_uid"
                 placeholder="QdAbw85SkYRk7Gudzj5M33OUEJ33"
                 value="<?= htmlspecialchars($_POST['mqtt_uid'] ?? '') ?>">
          <label>Chip ID</label>
          <input type="text" name="mqtt_chipid"
                 placeholder="ESP_D4D7"
                 value="<?= htmlspecialchars($_POST['mqtt_chipid'] ?? '') ?>">
          <?php
            $uid    = $_POST['mqtt_uid']    ?? 'UID';
            $chipid = $_POST['mqtt_chipid'] ?? 'CHIPID';
          ?>
          <div style="margin-bottom:14px">
            <div class="stat-label" style="margin-bottom:6px">MQTT Topic</div>
            <div class="topic-hint">
              <?= htmlspecialchars($uid) ?>/<?= htmlspecialchars($chipid) ?>/feederota
            </div>
          </div>
          <button class="btn btn-success" type="submit" name="trigger_ota">
            📡 KIRIM OTA
          </button>
        </form>
      </div>
    </div>

    <!-- Info Firmware -->
    <div class="card">
      <div class="card-head">
        <div class="card-icon">ℹ</div>
        <div class="card-title">Info Firmware Server</div>
      </div>
      <div class="card-body">
        <div class="info-row">
          <span class="info-key">Versi</span>
          <span class="info-val" style="color:var(--accent)">
            <?= htmlspecialchars($vInfo['version']) ?>
          </span>
        </div>
        <div class="info-row">
          <span class="info-key">File</span>
          <span class="info-val">
            <span class="badge <?= $fwExists ? 'badge-ok' : 'badge-err' ?>">
              <?= $fwExists ? 'ADA' : 'TIDAK ADA' ?>
            </span>
          </span>
        </div>
        <div class="info-row">
          <span class="info-key">Ukuran</span>
          <span class="info-val"><?= $fwSize ?></span>
        </div>
        <div class="info-row">
          <span class="info-key">Diupload</span>
          <span class="info-val"><?= $fwDate ?></span>
        </div>
        <div class="info-row">
          <span class="info-key">URL</span>
          <span class="info-val" style="font-size:11px;color:var(--muted)">
            <?= htmlspecialchars($vInfo['url']) ?>
          </span>
        </div>
        <div style="margin-top:16px">
          <form method="POST" onsubmit="return confirm('Hapus firmware.bin?')">
            <button class="btn btn-danger" name="delete_firmware">
              🗑 HAPUS FIRMWARE
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Log -->
    <div class="card">
      <div class="card-head">
        <div class="card-icon">📋</div>
        <div class="card-title">Deploy Log</div>
      </div>
      <div class="card-body">
        <div class="log-wrap">
          <?php if (empty($logs)): ?>
            <div class="log-line" style="color:var(--muted)">-- log kosong --</div>
          <?php else: ?>
            <?php foreach ($logs as $line): ?>
              <?php
                $cls = 'log-line';
                if (str_contains($line, 'UPLOAD'))  $cls .= ' upload';
                if (str_contains($line, 'TRIGGER')) $cls .= ' trigger';
                if (str_contains($line, 'DELETE'))  $cls .= ' delete';
              ?>
              <div class="<?= $cls ?>"><?= htmlspecialchars($line) ?></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div style="margin-top:12px">
          <form method="POST" onsubmit="return confirm('Hapus semua log?')">
            <button class="btn btn-muted" name="clear_log">🗑 CLEAR LOG</button>
          </form>
        </div>
      </div>
    </div>

  </div><!-- /grid2 -->
</div><!-- /main -->

<?php endif; ?>
</body>
</html>
