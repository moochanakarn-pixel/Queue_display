<?php
session_name('qdisplay_cfg');
session_start();
ob_start();
require_once __DIR__ . '/config.php';

// ── helpers ──────────────────────────────────────────────────────────────────
function sv($local, $key, $default) {
    return array_key_exists($key, $local) ? $local[$key] : $default;
}
function safeColor($v, $default) {
    $v = trim((string)$v);
    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)) return $v;
    if (preg_match('/^[a-zA-Z]{1,20}$/', $v)) return $v;
    return $default;
}
function settingsFilePath() {
    return __DIR__ . DIRECTORY_SEPARATOR . 'settings.local.php';
}

// ── load current settings ────────────────────────────────────────────────────
$local = getLocalSettings();
$configuredPin = (string)sv($local, 'settings_pin', '1234');

$msg   = '';
$isErr = false;

// ── handle POST ───────────────────────────────────────────────────────────────
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';

// List computers where ComputerType=4 (AJAX — returns JSON)
if ($action === 'list_computers') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $h = trim((string)($_POST['db_host'] ?? ''));
        $p = max(1, (int)($_POST['db_port'] ?? 3307));
        $n = trim((string)($_POST['db_name'] ?? ''));
        $u = trim((string)($_POST['db_user'] ?? ''));
        $w = (string)($_POST['db_pass'] ?? '');
        if ($h === '' || $n === '' || $u === '') throw new Exception('กรุณากรอก Host / DB Name / User ก่อน');
        $conn = new mysqli($h, $u, $w, $n, $p);
        if ($conn->connect_error) throw new Exception($conn->connect_error);
        $conn->set_charset('utf8');
        $res = $conn->query(
            "SELECT ComputerID, ComputerName FROM computername WHERE ComputerType = 4 ORDER BY ComputerName"
        );
        $list = array();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = array('id' => (int)$row['ComputerID'], 'name' => (string)$row['ComputerName']);
            }
        }
        $conn->close();
        echo json_encode(array('success' => true, 'computers' => $list));
    } catch (Exception $e) {
        echo json_encode(array('success' => false, 'message' => $e->getMessage()));
    }
    exit;
}

// Test DB connection (AJAX — returns JSON)
if ($action === 'test_db') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $h = trim((string)($_POST['db_host'] ?? ''));
        $p = max(1, (int)($_POST['db_port'] ?? 3307));
        $n = trim((string)($_POST['db_name'] ?? ''));
        $u = trim((string)($_POST['db_user'] ?? ''));
        $w = (string)($_POST['db_pass'] ?? '');
        if ($h === '' || $n === '' || $u === '') throw new Exception('กรุณากรอก Host / DB Name / User');
        $conn = new mysqli($h, $u, $w, $n, $p);
        if ($conn->connect_error) throw new Exception($conn->connect_error);
        $conn->close();
        echo json_encode(['success' => true,  'message' => 'เชื่อมต่อสำเร็จ']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Verify PIN
if ($action === 'pin') {
    $pin = (string)($_POST['pin'] ?? '');
    if ($pin === $configuredPin) {
        session_regenerate_id(true);
        $_SESSION['qdisplay_auth'] = true;
    } else {
        $msg = 'PIN ไม่ถูกต้อง';
        $isErr = true;
    }
}

// Logout
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: settings.php');
    exit;
}

// Save settings
if ($action === 'save' && !empty($_SESSION['qdisplay_auth'])) {
    $pin = isset($_POST['pin']) ? (string)$_POST['pin'] : $configuredPin;
    if ($pin !== $configuredPin && $pin !== '') {
        $msg = 'PIN ไม่ถูกต้อง';
        $isErr = true;
    } else {
        $newPin = trim((string)($_POST['settings_pin'] ?? $configuredPin));
        if ($newPin === '') $newPin = $configuredPin;
        $new = [
            'db_host'               => trim((string)($_POST['db_host']               ?? '')),
            'db_port'               => max(1, (int)($_POST['db_port']                ?? 3307)),
            'db_name'               => trim((string)($_POST['db_name']               ?? '')),
            'db_user'               => trim((string)($_POST['db_user']               ?? '')),
            'db_pass'               => (($_POST['db_pass'] ?? '') !== '') ? (string)$_POST['db_pass'] : (string)sv($local, 'db_pass', ''),
            'settings_pin'          => $newPin,
            'computer_id'           => max(0, (int)($_POST['computer_id']            ?? 0)),
            'product_level_id'      => max(0, (int)($_POST['product_level_id']       ?? 0)),
            'queue_refresh_ms'      => max(2000, (int)($_POST['queue_refresh_ms']    ?? 5000)),
            'ready_limit'           => max(1, (int)($_POST['ready_limit']            ?? 30)),
            'preparing_limit'       => max(1, (int)($_POST['preparing_limit']        ?? 30)),
            'ready_display_minutes' => max(0, (int)($_POST['ready_display_minutes']  ?? 40)),
            'grid_columns'          => max(1, min(8, (int)($_POST['grid_columns']    ?? 2))),
            'bg_image'              => (function ($v) {
                $v = trim((string)$v);
                if ($v === '') return '';
                if (preg_match('/:\/\/|^\\/|\.\./', $v)) return '';
                if (!preg_match('/^[a-zA-Z0-9\/_\-\.]+$/', $v)) return '';
                return $v;
            })(($_POST['bg_image'] ?? '')),
            'color_header_bg'       => safeColor($_POST['color_header_bg']   ?? '#1a1a2e', '#1a1a2e'),
            'color_header_text'     => safeColor($_POST['color_header_text'] ?? '#ffffff', '#ffffff'),
            'color_queue_text'      => safeColor($_POST['color_queue_text']  ?? '#1a1a2e', '#1a1a2e'),
            'color_app_bg'          => safeColor($_POST['color_app_bg']      ?? '#ffffff', '#ffffff'),
            'sound_enabled'         => isset($_POST['sound_enabled'])         ? 1 : 0,
            'sound_volume'          => max(0, min(100, (int)($_POST['sound_volume']  ?? 70))),
            'sound_type'      => in_array(($_POST['sound_type'] ?? ''), ['beep', 'tts', 'file'], true) ? (string)$_POST['sound_type'] : 'beep',
            'sound_beep_tone' => in_array(($_POST['sound_beep_tone'] ?? ''), ['ding', 'double', 'low'], true) ? (string)$_POST['sound_beep_tone'] : 'ding',
            'sound_file'      => preg_match('/^sounds\/custom\.(mp3|wav|ogg|m4a|aac)$/', trim((string)($_POST['sound_file'] ?? '')))
                                    ? trim((string)$_POST['sound_file'])
                                    : (string)sv($local, 'sound_file', ''),
            'show_computer_name'    => isset($_POST['show_computer_name'])    ? 1 : 0,
        ];
        $content = "<?php return " . var_export($new, true) . ";\n";
        $dest = settingsFilePath();
        $tmp  = $dest . '.tmp';
        $ok   = file_put_contents($tmp, $content) !== false && rename($tmp, $dest);
        if (!$ok) @unlink($tmp);
        if ($ok) {
            $local = $new;
            $configuredPin = $newPin;
            $msg = 'บันทึกการตั้งค่าเรียบร้อยแล้ว';
        } else {
            $msg = 'บันทึกไม่ได้ — ตรวจสอบสิทธิ์ write ของโฟลเดอร์';
            $isErr = true;
        }
    }
}

// Upload custom sound file
if ($action === 'upload_sound') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['qdisplay_auth'])) {
        echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']);
        exit;
    }
    try {
        if (!isset($_FILES['sound_file']) || $_FILES['sound_file']['error'] !== UPLOAD_ERR_OK) {
            $errMap = [1 => 'ไฟล์ใหญ่เกิน', 2 => 'ไฟล์ใหญ่เกิน', 3 => 'อัปโหลดไม่สมบูรณ์', 4 => 'ไม่มีไฟล์'];
            $errCode = isset($_FILES['sound_file']['error']) ? (int)$_FILES['sound_file']['error'] : 4;
            throw new Exception(isset($errMap[$errCode]) ? $errMap[$errCode] : 'ข้อผิดพลาด code ' . $errCode);
        }
        $file = $_FILES['sound_file'];
        if ($file['size'] > 5 * 1024 * 1024) throw new Exception('ไฟล์ใหญ่เกิน 5MB');
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac'], true)) {
            throw new Exception('รองรับเฉพาะ MP3, WAV, OGG, M4A, AAC');
        }
        $allowedMimes = ['audio/mpeg', 'audio/wav', 'audio/wave', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/aac', 'audio/x-aac', 'audio/x-m4a', 'video/mp4'];
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMimes, true)) {
                throw new Exception('ประเภทไฟล์ไม่ถูกต้อง (' . $mime . ')');
            }
        }
        $soundsDir = __DIR__ . DIRECTORY_SEPARATOR . 'sounds';
        if (!is_dir($soundsDir) && !mkdir($soundsDir, 0755, true)) {
            throw new Exception('ไม่สามารถสร้างโฟลเดอร์ sounds/ ได้');
        }
        $destPath = $soundsDir . DIRECTORY_SEPARATOR . 'custom.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception('ไม่สามารถบันทึกไฟล์ได้');
        }
        foreach (glob($soundsDir . DIRECTORY_SEPARATOR . 'custom.*') ?: [] as $old) {
            if ($old !== $destPath) @unlink($old);
        }
        echo json_encode(['success' => true, 'path' => 'sounds/custom.' . $ext, 'name' => $file['name']]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$auth = !empty($_SESSION['qdisplay_auth']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Queue Display — ตั้งค่า</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI','Helvetica Neue',Arial,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;padding:0}

/* ── Top bar ── */
.topbar{display:flex;align-items:center;justify-content:space-between;background:#1e293b;padding:14px 20px;border-bottom:1px solid #334155;position:sticky;top:0;z-index:10}
.topbar-title{font-size:18px;font-weight:700;color:#f1f5f9}
.btn-back{display:inline-flex;align-items:center;gap:6px;background:transparent;border:1px solid #475569;color:#94a3b8;padding:7px 14px;border-radius:8px;font-size:14px;cursor:pointer;text-decoration:none;transition:.15s}
.btn-back:hover{background:#1e293b;color:#f1f5f9;border-color:#94a3b8}
.btn-logout{background:transparent;border:1px solid #ef4444;color:#ef4444;padding:7px 14px;border-radius:8px;font-size:14px;cursor:pointer;transition:.15s}
.btn-logout:hover{background:#ef4444;color:#fff}

/* ── Content ── */
.content{max-width:620px;margin:0 auto;padding:24px 16px 60px}

/* ── PIN screen ── */
.pin-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:80vh;gap:24px}
.pin-title{font-size:22px;font-weight:700;color:#f1f5f9}
.pin-sub{font-size:14px;color:#64748b}
.pin-dots{display:flex;gap:14px;margin:8px 0}
.pin-dot{width:18px;height:18px;border-radius:50%;background:#334155;transition:.15s}
.pin-dot.filled{background:#3b82f6}
.pin-input{background:#1e293b;border:2px solid #334155;color:#f1f5f9;font-size:36px;font-weight:700;text-align:center;width:220px;padding:12px;border-radius:14px;letter-spacing:8px;outline:none}
.pin-input:focus{border-color:#3b82f6}
.btn-pin{background:#3b82f6;color:#fff;border:none;padding:14px 40px;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;transition:.15s;width:220px}
.btn-pin:hover{background:#2563eb}

/* ── Sections ── */
.section{background:#1e293b;border-radius:14px;padding:20px;margin-bottom:20px}
.section-title{font-size:13px;font-weight:700;letter-spacing:2px;color:#64748b;text-transform:uppercase;margin-bottom:16px}

/* ── Fields ── */
.field{margin-bottom:14px}
.field label:not(.toggle-wrap){display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:5px}
.field input[type=text],
.field input[type=password],
.field input[type=number]{width:100%;background:#0f172a;border:1px solid #334155;color:#f1f5f9;padding:10px 12px;border-radius:8px;font-size:15px;outline:none;transition:.15s}
.field input:focus{border-color:#3b82f6}
.field input[type=color]{width:52px;height:36px;border:1px solid #334155;border-radius:8px;cursor:pointer;background:#0f172a;padding:2px}
.color-row{display:flex;align-items:center;gap:10px}
.color-row input[type=text]{flex:1}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* ── Alert ── */
.alert{padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:18px;font-weight:600}
.alert-ok {background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.alert-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171}

/* ── Action buttons ── */
.actions{display:flex;gap:10px;margin-top:8px}
.btn-save{flex:1;background:#3b82f6;color:#fff;border:none;padding:14px;border-radius:10px;font-size:16px;font-weight:700;cursor:pointer;transition:.15s}
.btn-save:hover{background:#2563eb}
.btn-test{background:#0f172a;color:#94a3b8;border:1px solid #334155;padding:14px 20px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;transition:.15s}
.btn-test:hover{border-color:#94a3b8;color:#f1f5f9}
.test-result{margin-top:8px;font-size:13px;font-weight:600}
.test-result.ok {color:#4ade80}
.test-result.err{color:#f87171}

/* ── Toggle switch ── */
.toggle-wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;user-select:none}
.toggle-text{font-size:14px;color:#e2e8f0;flex:1}
.toggle-switch{position:relative;width:46px;height:26px;flex-shrink:0}
.toggle-switch input{display:none}
.toggle-slider{position:absolute;inset:0;background:#334155;border-radius:13px;transition:.2s}
.toggle-slider::before{content:'';position:absolute;width:20px;height:20px;border-radius:50%;background:#94a3b8;left:3px;top:3px;transition:.2s}
.toggle-switch input:checked+.toggle-slider{background:#3b82f6}
.toggle-switch input:checked+.toggle-slider::before{transform:translateX(20px);background:#fff}
.sound-type-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.sound-type-opt{display:flex;flex-direction:column;align-items:center;gap:3px;padding:12px 10px;border:2px solid #334155;border-radius:10px;cursor:pointer;transition:.15s;flex:1;min-width:90px;user-select:none}
.sound-type-opt input[type=radio]{display:none}
.sound-type-opt .st-icon{font-size:22px}
.sound-type-opt .st-label{font-size:13px;font-weight:700;color:#e2e8f0}
.sound-type-opt .st-sub{font-size:11px;color:#64748b}
.sound-type-opt:has(input:checked){border-color:#3b82f6;background:rgba(59,130,246,.12)}
.sound-type-opt:hover{border-color:#94a3b8}
.sound-sub-box{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:12px;font-size:13px;color:#94a3b8;line-height:1.7}
.upload-zone{border:2px dashed #334155;border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:.15s;margin-top:4px}
.upload-zone:hover{border-color:#3b82f6;background:rgba(59,130,246,.04)}
select.field-select{width:100%;background:#0f172a;border:1px solid #334155;color:#f1f5f9;padding:10px 12px;border-radius:8px;font-size:15px;outline:none;transition:.15s}
select.field-select:focus{border-color:#3b82f6}
</style>
</head>
<body>

<div class="topbar">
    <a href="index.php" class="btn-back">&#8592; กลับหน้าแสดงผล</a>
    <span class="topbar-title">ตั้งค่า Queue Display</span>
    <?php if ($auth): ?>
    <form method="post" style="margin:0">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn-logout">ออก</button>
    </form>
    <?php else: ?>
    <span></span>
    <?php endif; ?>
</div>

<?php if (!$auth): ?>
<!-- ── PIN Screen ── -->
<div class="content">
    <div class="pin-wrap">
        <div class="pin-title">ใส่ PIN เพื่อเข้าตั้งค่า</div>
        <div class="pin-sub">Queue Display Settings</div>
        <div class="pin-dots">
            <div class="pin-dot" id="d0"></div>
            <div class="pin-dot" id="d1"></div>
            <div class="pin-dot" id="d2"></div>
            <div class="pin-dot" id="d3"></div>
        </div>
        <?php if ($msg): ?>
        <div class="alert alert-err"><?= h($msg) ?></div>
        <?php endif; ?>
        <form method="post" id="pinForm">
            <input type="hidden" name="action" value="pin">
            <input type="password" name="pin" id="pinInput" class="pin-input"
                   maxlength="8" inputmode="numeric" pattern="[0-9]*"
                   autocomplete="off" autofocus placeholder="••••">
            <br><br>
            <button type="submit" class="btn-pin">ยืนยัน</button>
        </form>
    </div>
</div>
<script>
(function(){
    var inp     = document.getElementById('pinInput');
    var pinLen  = <?php echo (int)min(8, max(4, strlen($configuredPin))); ?>;
    inp.setAttribute('maxlength', pinLen);
    inp.addEventListener('input', function(){
        var v = this.value.replace(/\D/g,'').slice(0, pinLen);
        this.value = v;
        for(var i=0;i<4;i++){
            var frac = Math.round((i + 1) / 4 * pinLen);
            document.getElementById('d'+i).classList.toggle('filled', v.length >= frac);
        }
        if(v.length === pinLen){ document.getElementById('pinForm').submit(); }
    });
}());
</script>

<?php else: ?>
<!-- ── Settings Form ── -->
<div class="content">
    <?php if ($msg): ?>
    <div class="alert <?= $isErr ? 'alert-err' : 'alert-ok' ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="save">

        <!-- Database -->
        <div class="section">
            <div class="section-title">ฐานข้อมูล</div>
            <div class="row2">
                <div class="field">
                    <label>Host / IP</label>
                    <input type="text" name="db_host" value="<?= h(sv($local,'db_host','127.0.0.1')) ?>" placeholder="127.0.0.1">
                </div>
                <div class="field">
                    <label>Port</label>
                    <input type="number" name="db_port" value="<?= h(sv($local,'db_port',3307)) ?>" min="1" max="65535">
                </div>
            </div>
            <div class="field">
                <label>Database Name</label>
                <input type="text" name="db_name" value="<?= h(sv($local,'db_name','')) ?>">
            </div>
            <div class="row2">
                <div class="field">
                    <label>User</label>
                    <input type="text" name="db_user" value="<?= h(sv($local,'db_user','')) ?>">
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="db_pass" autocomplete="new-password" placeholder="(ไม่เปลี่ยนหากเว้นว่าง)">
                </div>
            </div>
            <button type="button" class="btn-test" id="btnTest">ทดสอบการเชื่อมต่อ</button>
            <div class="test-result" id="testResult"></div>
        </div>

        <!-- Computer -->
        <div class="section">
            <div class="section-title">คอมพิวเตอร์จอแสดงคิว</div>
            <div class="field">
                <label>เลือก Computer (ComputerType = 4)</label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <select name="computer_id" id="computerSel" style="flex:1;min-width:180px;background:#0f172a;border:1px solid #334155;color:#f1f5f9;padding:10px 12px;border-radius:8px;font-size:15px;outline:none">
                        <option value="0">(ยังไม่เลือก)</option>
                        <?php
                        $curCid = (int)sv($local, 'computer_id', 0);
                        if ($curCid > 0):
                            $curCidLabel = 'ComputerID ' . $curCid;
                            try {
                                $tmpConn = getDbConnection();
                                $tmpRes  = $tmpConn->query("SELECT ComputerName FROM computername WHERE ComputerID = {$curCid} LIMIT 1");
                                if ($tmpRes && $tmpRes->num_rows > 0) {
                                    $n = trim((string)$tmpRes->fetch_assoc()['ComputerName']);
                                    if ($n !== '') $curCidLabel = $n . ' (ID: ' . $curCid . ')';
                                }
                                $tmpConn->close();
                            } catch (Exception $ignored) {}
                        ?>
                        <option value="<?= h($curCid) ?>" selected><?= h($curCidLabel) ?></option>
                        <?php endif; ?>
                    </select>
                    <button type="button" class="btn-test" id="btnLoadComputers">โหลดรายการ</button>
                </div>
                <div class="test-result" id="computerLoadResult"></div>
            </div>
            <div class="field">
                <label>Product Level ID (ชื่อร้าน)</label>
                <input type="number" name="product_level_id" value="<?= h(sv($local,'product_level_id',0)) ?>" min="0" style="width:140px">
                <div style="font-size:12px;color:#64748b;margin-top:4px">ใส่ ProductLevelID ที่ต้องการแสดงเป็นชื่อร้าน (0 = ไม่แสดง)</div>
            </div>
        </div>

        <!-- Queue Behavior -->
        <div class="section">
            <div class="section-title">การแสดงผล Queue</div>
            <div class="row2">
                <div class="field">
                    <label>Refresh (มิลลิวินาที)</label>
                    <input type="number" name="queue_refresh_ms" value="<?= h(sv($local,'queue_refresh_ms',5000)) ?>" min="2000" step="500">
                </div>
                <div class="field">
                    <label>แสดง READY กี่นาที (0=ทั้งวัน)</label>
                    <input type="number" name="ready_display_minutes" value="<?= h(sv($local,'ready_display_minutes',40)) ?>" min="0">
                </div>
            </div>
            <div class="row2">
                <div class="field">
                    <label>จำนวนคอลัมน์ Grid</label>
                    <input type="number" name="grid_columns" value="<?= h(sv($local,'grid_columns',2)) ?>" min="1" max="8">
                </div>
                <div class="field">
                    <label>READY แสดงสูงสุด (รายการ)</label>
                    <input type="number" name="ready_limit" value="<?= h(sv($local,'ready_limit',30)) ?>" min="1">
                </div>
            </div>
            <div class="field">
                <label>PREPARING แสดงสูงสุด (รายการ)</label>
                <input type="number" name="preparing_limit" value="<?= h(sv($local,'preparing_limit',30)) ?>" min="1">
            </div>
        </div>

        <!-- Appearance -->
        <div class="section">
            <div class="section-title">หน้าตา</div>
            <div class="field">
                <label>สีพื้นหลังหัว READY / PREPARING</label>
                <div class="color-row">
                    <input type="color" id="cp_hbg" value="<?= h(sv($local,'color_header_bg','#1a1a2e')) ?>" oninput="document.getElementById('t_hbg').value=this.value">
                    <input type="text" name="color_header_bg" id="t_hbg" value="<?= h(sv($local,'color_header_bg','#1a1a2e')) ?>" oninput="syncColor(this,'cp_hbg')">
                </div>
            </div>
            <div class="field">
                <label>สีตัวอักษรหัว (READY / PREPARING)</label>
                <div class="color-row">
                    <input type="color" id="cp_htx" value="<?= h(sv($local,'color_header_text','#ffffff')) ?>" oninput="document.getElementById('t_htx').value=this.value">
                    <input type="text" name="color_header_text" id="t_htx" value="<?= h(sv($local,'color_header_text','#ffffff')) ?>" oninput="syncColor(this,'cp_htx')">
                </div>
            </div>
            <div class="field">
                <label>สีตัวเลข Queue</label>
                <div class="color-row">
                    <input type="color" id="cp_qtx" value="<?= h(sv($local,'color_queue_text','#1a1a2e')) ?>" oninput="document.getElementById('t_qtx').value=this.value">
                    <input type="text" name="color_queue_text" id="t_qtx" value="<?= h(sv($local,'color_queue_text','#1a1a2e')) ?>" oninput="syncColor(this,'cp_qtx')">
                </div>
            </div>
            <div class="field">
                <label>สีพื้นหลังหน้าจอ</label>
                <div class="color-row">
                    <input type="color" id="cp_abg" value="<?= h(sv($local,'color_app_bg','#ffffff')) ?>" oninput="document.getElementById('t_abg').value=this.value">
                    <input type="text" name="color_app_bg" id="t_abg" value="<?= h(sv($local,'color_app_bg','#ffffff')) ?>" oninput="syncColor(this,'cp_abg')">
                </div>
            </div>
            <div class="field">
                <label>ภาพพื้นหลัง</label>
                <input type="text" name="bg_image" id="bgImageInput" value="<?= h(sv($local,'bg_image','')) ?>" placeholder="images/bg-dark.svg">
                <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" class="bg-sample-btn"
                        data-path="images/bg-dark.svg"
                        data-hbg="#1e1b4b" data-htx="#a78bfa" data-qtx="#e2e8f0" data-abg="#0f0c29"
                        style="background:linear-gradient(135deg,#0f0c29,#302b63);color:#a78bfa;border:1px solid #4c1d95;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
                        🌙 Dark
                    </button>
                    <button type="button" class="bg-sample-btn"
                        data-path="images/bg-warm.svg"
                        data-hbg="#92400e" data-htx="#fef3c7" data-qtx="#7c2d12" data-abg="#fffbeb"
                        style="background:linear-gradient(135deg,#fff7ed,#fdba74);color:#92400e;border:1px solid #d97706;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
                        ☀️ Warm
                    </button>
                    <button type="button" class="bg-sample-btn"
                        data-path="images/bg-fresh.svg"
                        data-hbg="#1e40af" data-htx="#dbeafe" data-qtx="#1e3a8a" data-abg="#eff6ff"
                        style="background:linear-gradient(135deg,#f0f9ff,#bfdbfe);color:#1e40af;border:1px solid #3b82f6;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
                        🌊 Fresh
                    </button>
                    <button type="button" class="bg-sample-btn"
                        data-path="images/bg-matcha.svg"
                        data-hbg="#2d5a1b" data-htx="#f0f5eb" data-qtx="#1e3d10" data-abg="#f0f5eb"
                        style="background:linear-gradient(135deg,#e8f0df,#8fb58a);color:#1e3d10;border:1px solid #5a8c3c;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
                        🍵 Matcha
                    </button>
                    <button type="button" class="bg-sample-btn"
                        data-path=""
                        data-hbg="#1a1a2e" data-htx="#ffffff" data-qtx="#1a1a2e" data-abg="#ffffff"
                        style="background:#1e293b;color:#94a3b8;border:1px solid #334155;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
                        ✕ ไม่มี BG
                    </button>
                </div>
                <div style="font-size:11px;color:#475569;margin-top:5px">คลิกเพื่อดู Preview — สีจะปรับตาม theme อัตโนมัติ</div>
            </div>

            <!-- ── Live Preview ── -->
            <div class="field" style="margin-top:6px">
                <label style="display:flex;align-items:center;justify-content:space-between">
                    <span>Preview หน้าจอ</span>
                    <span style="font-size:11px;color:#475569;font-weight:400">อัพเดทอัตโนมัติเมื่อเปลี่ยนสี / BG</span>
                </label>
                <div id="pvWrap" style="width:100%;aspect-ratio:16/9;border-radius:10px;overflow:hidden;margin-top:8px;border:2px solid #334155;display:flex;flex-direction:column;font-family:'Segoe UI',Arial,sans-serif;user-select:none">
                    <!-- READY section -->
                    <div style="flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden">
                        <div class="pv-head" style="display:flex;align-items:center;justify-content:center;gap:6px;padding:5px 8px;flex-shrink:0">
                            <span style="font-weight:800;font-size:14px;letter-spacing:2px">READY</span>
                            <span style="font-size:9px;opacity:.85">พร้อมเสิร์ฟ</span>
                        </div>
                        <div class="pv-grid" style="flex:1;min-height:0;display:grid;grid-template-columns:repeat(2,1fr);padding:4px 10px;align-content:start;gap:2px;overflow:hidden">
                            <div class="pv-num" style="text-align:center;font-weight:700;font-size:20px;padding:3px">0001</div>
                            <div class="pv-num" style="text-align:center;font-weight:700;font-size:20px;padding:3px">0002</div>
                            <div class="pv-num" style="text-align:center;font-weight:700;font-size:20px;padding:3px">0003</div>
                        </div>
                    </div>
                    <!-- Latest wrap -->
                    <div id="pvLatest" style="flex-shrink:0;display:flex;flex-direction:column;align-items:center;padding:4px 0;border-top:1px solid rgba(128,128,128,0.2);border-bottom:1px solid rgba(128,128,128,0.2)">
                        <div style="font-size:7px;color:#888;letter-spacing:2px;text-transform:uppercase">ล่าสุด</div>
                        <div class="pv-num" style="font-size:28px;font-weight:900;line-height:1.1">0001</div>
                    </div>
                    <!-- PREPARING section -->
                    <div style="flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden">
                        <div class="pv-head" style="display:flex;align-items:center;justify-content:center;gap:6px;padding:5px 8px;flex-shrink:0">
                            <span style="font-weight:800;font-size:14px;letter-spacing:2px">PREPARING</span>
                            <span style="font-size:9px;opacity:.85">กำลังเตรียม</span>
                        </div>
                        <div class="pv-grid" style="flex:1;min-height:0;display:grid;grid-template-columns:repeat(2,1fr);padding:4px 10px;align-content:start;gap:2px;overflow:hidden">
                            <div class="pv-num" style="text-align:center;font-weight:700;font-size:20px;padding:3px">0004</div>
                            <div class="pv-num" style="text-align:center;font-weight:700;font-size:20px;padding:3px">0005</div>
                        </div>
                    </div>
                    <!-- Shop bar -->
                    <div id="pvShopBar" style="flex-shrink:0;text-align:center;padding:4px;font-size:9px;font-weight:600;letter-spacing:2px;text-transform:uppercase;opacity:.35">ชื่อร้าน</div>
                </div>
            </div>
        </div>

        <!-- Sound -->
        <div class="section">
            <div class="section-title">เสียงแจ้งเตือน</div>
            <div class="field">
                <label class="toggle-wrap">
                    <span class="toggle-text">เปิดเสียงเมื่อมีคิว READY ใหม่</span>
                    <div class="toggle-switch">
                        <input type="checkbox" name="sound_enabled" value="1" id="soundEnabled" <?= sv($local,'sound_enabled',1) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </label>
            </div>
            <div id="soundOptions">
                <div class="field">
                    <label>ประเภทเสียง</label>
                    <div class="sound-type-row">
                        <?php
                        $curSoundType = (string)sv($local, 'sound_type', 'beep');
                        $stypes = [
                            'beep' => ['🔔', 'Beep',       'เสียงสังเคราะห์'],
                            'tts'  => ['🗣',  'พูดชื่อคิว', 'Text-to-Speech'],
                            'file' => ['🎵', 'ไฟล์เสียง',  'MP3 / WAV / OGG'],
                        ];
                        foreach ($stypes as $val => [$icon, $lbl, $sub]):
                        ?>
                        <label class="sound-type-opt">
                            <input type="radio" name="sound_type" value="<?= h($val) ?>" <?= $curSoundType === $val ? 'checked' : '' ?>>
                            <span class="st-icon"><?= $icon ?></span>
                            <span class="st-label"><?= h($lbl) ?></span>
                            <span class="st-sub"><?= h($sub) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Beep sub -->
                <div class="field sound-sub" id="subBeep">
                    <label>โทนเสียง</label>
                    <select name="sound_beep_tone" id="beepToneSelect" class="field-select">
                        <?php
                        $curTone = (string)sv($local, 'sound_beep_tone', 'ding');
                        $tones = ['ding' => 'Ding — ความถี่สูงแล้วต่ำ (ค่าเริ่มต้น)', 'double' => 'Double — สองครั้งติดกัน', 'low' => 'Low — ความถี่ต่ำ'];
                        foreach ($tones as $v => $tl):
                        ?>
                        <option value="<?= h($v) ?>" <?= $curTone === $v ? 'selected' : '' ?>><?= h($tl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- TTS sub -->
                <div class="field sound-sub" id="subTts" style="display:none">
                    <div class="sound-sub-box">
                        จะพูดชื่อโต๊ะ/คิวออกมาอัตโนมัติ เช่น <strong style="color:#f1f5f9">"โต๊ะ 5"</strong> หรือ <strong style="color:#f1f5f9">"A001"</strong><br>
                        ใช้เสียงจาก OS/เบราว์เซอร์ — Windows ต้องมี Thai TTS voice ติดตั้งจึงจะพูดภาษาไทยได้ชัด
                    </div>
                </div>

                <!-- File sub -->
                <div class="field sound-sub" id="subFile" style="display:none">
                    <input type="hidden" name="sound_file" id="soundFilePath" value="<?= h((string)sv($local,'sound_file','')) ?>">
                    <?php $curSoundFile = (string)sv($local, 'sound_file', ''); ?>
                    <div class="upload-zone" id="uploadZone">
                        <div id="uploadLabel" style="color:#94a3b8;font-size:14px">
                            <?php if ($curSoundFile !== ''): ?>
                            <div style="color:#4ade80;font-weight:600;font-size:15px">✓ <?= h(basename($curSoundFile)) ?></div>
                            <div style="font-size:12px;margin-top:4px">คลิกเพื่อเปลี่ยนไฟล์</div>
                            <?php else: ?>
                            <div style="font-size:15px">📂 คลิกเพื่อเลือกไฟล์เสียง</div>
                            <div style="font-size:12px;margin-top:4px">MP3, WAV, OGG, M4A — ไม่เกิน 5MB</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <input type="file" id="soundFileInput" accept=".mp3,.wav,.ogg,.m4a,.aac,audio/*" style="display:none">
                    <div id="uploadSoundResult" style="font-size:13px;font-weight:600;margin-top:8px"></div>
                </div>

                <!-- Volume -->
                <div class="field" id="volumeWrap" style="margin-top:4px">
                    <label>ระดับเสียง — <span id="volPct"><?= (int)sv($local,'sound_volume',70) ?></span>%</label>
                    <input type="range" name="sound_volume" id="volRange" min="0" max="100" step="5"
                           value="<?= (int)sv($local,'sound_volume',70) ?>"
                           style="width:100%;margin-top:8px;accent-color:#3b82f6;cursor:pointer;height:6px">
                </div>

                <!-- Test -->
                <div style="display:flex;align-items:center;gap:12px;margin-top:4px">
                    <button type="button" class="btn-test" id="btnTestSound">▶ ทดสอบเสียง</button>
                    <span id="testSoundResult" style="font-size:13px;font-weight:600"></span>
                </div>
            </div>
        </div>

        <!-- Display Options -->
        <div class="section">
            <div class="section-title">ตัวเลือกการแสดงผล</div>
            <div class="field">
                <label class="toggle-wrap">
                    <span class="toggle-text">แสดงชื่อคอมพิวเตอร์ที่มุมบนขวา</span>
                    <div class="toggle-switch">
                        <input type="checkbox" name="show_computer_name" value="1" <?= sv($local,'show_computer_name',1) ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </div>
                </label>
            </div>
        </div>

        <!-- Security -->
        <div class="section">
            <div class="section-title">ความปลอดภัย</div>
            <div class="field">
                <label>PIN สำหรับเข้าหน้าตั้งค่า</label>
                <input type="text" name="settings_pin" value="<?= h(sv($local,'settings_pin','1234')) ?>" maxlength="8" inputmode="numeric" style="width:200px">
                <div style="font-size:12px;color:#64748b;margin-top:4px">ตัวเลข 4–8 หลัก (ค่าเริ่มต้น 1234)</div>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn-save">บันทึกการตั้งค่า</button>
        </div>
    </form>
</div>

<script>
function syncColor(txtEl, colorId) {
    var v = txtEl.value.trim();
    if (/^#[0-9a-fA-F]{3,8}$/.test(v)) {
        document.getElementById(colorId).value = v;
    }
}

document.getElementById('btnLoadComputers').addEventListener('click', function() {
    var form = this.closest('form');
    var data = new FormData();
    data.append('action',  'list_computers');
    data.append('db_host', form.querySelector('[name=db_host]').value);
    data.append('db_port', form.querySelector('[name=db_port]').value);
    data.append('db_name', form.querySelector('[name=db_name]').value);
    data.append('db_user', form.querySelector('[name=db_user]').value);
    data.append('db_pass', form.querySelector('[name=db_pass]').value);
    var el  = document.getElementById('computerLoadResult');
    var sel = document.getElementById('computerSel');
    el.textContent = 'กำลังโหลด...';
    el.className = 'test-result';
    fetch('settings.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) {
                el.textContent = d.message || 'โหลดไม่ได้';
                el.className = 'test-result err';
                return;
            }
            var curVal = sel.value;
            sel.innerHTML = '<option value="0">(ยังไม่เลือก)</option>';
            d.computers.forEach(function(c) {
                var opt = document.createElement('option');
                opt.value = String(c.id);
                opt.textContent = c.name + ' (ID: ' + c.id + ')';
                if (String(c.id) === curVal) opt.selected = true;
                sel.appendChild(opt);
            });
            if (d.computers.length === 0) {
                el.textContent = 'ไม่พบ Computer ที่มี ComputerType = 4';
                el.className = 'test-result err';
            } else {
                el.textContent = 'พบ ' + d.computers.length + ' เครื่อง';
                el.className = 'test-result ok';
            }
        })
        .catch(function() { el.textContent = 'เกิดข้อผิดพลาด'; el.className = 'test-result err'; });
});

// ── Sound section ────────────────────────────────────────────────────────
var volRange     = document.getElementById('volRange');
var volPct       = document.getElementById('volPct');
var soundEnabled = document.getElementById('soundEnabled');
var soundOptions = document.getElementById('soundOptions');

volRange.addEventListener('input', function () { volPct.textContent = this.value; });

function applyVolumeToggle() {
    soundOptions.style.opacity       = soundEnabled.checked ? '1'  : '0.4';
    soundOptions.style.pointerEvents = soundEnabled.checked ? ''   : 'none';
}
soundEnabled.addEventListener('change', applyVolumeToggle);
applyVolumeToggle();

// Sound type switching
var soundTypeOpts = document.querySelectorAll('.sound-type-opt');
var soundSubs     = { beep: document.getElementById('subBeep'), tts: document.getElementById('subTts'), file: document.getElementById('subFile') };

function applySoundType(val) {
    soundTypeOpts.forEach(function (el) {
        el.style.borderColor  = el.querySelector('input').value === val ? '#3b82f6' : '';
        el.style.background   = el.querySelector('input').value === val ? 'rgba(59,130,246,.12)' : '';
    });
    Object.keys(soundSubs).forEach(function (k) {
        if (soundSubs[k]) soundSubs[k].style.display = k === val ? '' : 'none';
    });
}

soundTypeOpts.forEach(function (el) {
    el.querySelector('input').addEventListener('change', function () { applySoundType(this.value); });
});

var checkedRadio = document.querySelector('[name=sound_type]:checked');
applySoundType(checkedRadio ? checkedRadio.value : 'beep');

// File upload
var uploadZone     = document.getElementById('uploadZone');
var soundFileInput = document.getElementById('soundFileInput');
var soundFilePath  = document.getElementById('soundFilePath');
var uploadResult   = document.getElementById('uploadSoundResult');

if (uploadZone && soundFileInput) {
    uploadZone.addEventListener('click', function () { soundFileInput.click(); });
    soundFileInput.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        var fd = new FormData();
        fd.append('action', 'upload_sound');
        fd.append('sound_file', file);
        uploadResult.textContent = 'กำลังอัปโหลด...';
        uploadResult.style.color = '#94a3b8';
        fetch('settings.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) {
                    soundFilePath.value = d.path;
                    var safeName = String(d.name || '').replace(/[&<>"']/g, function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});
                    document.getElementById('uploadLabel').innerHTML =
                        '<div style="color:#4ade80;font-weight:600;font-size:15px">✓ ' + safeName + '</div>' +
                        '<div style="font-size:12px;margin-top:4px">คลิกเพื่อเปลี่ยนไฟล์</div>';
                    uploadResult.textContent = 'อัปโหลดสำเร็จ';
                    uploadResult.style.color = '#4ade80';
                } else {
                    uploadResult.textContent = '✗ ' + (d.message || 'อัปโหลดไม่ได้');
                    uploadResult.style.color = '#f87171';
                }
            })
            .catch(function () {
                uploadResult.textContent = '✗ เกิดข้อผิดพลาด';
                uploadResult.style.color = '#f87171';
            });
    });
}

// Test sound button
document.getElementById('btnTestSound').addEventListener('click', function () {
    var resultEl  = document.getElementById('testSoundResult');
    var typeRadio = document.querySelector('[name=sound_type]:checked');
    var soundType = typeRadio ? typeRadio.value : 'beep';
    var vol       = parseInt(volRange.value, 10) / 100;

    function showResult(ok, msg) {
        resultEl.textContent = ok ? '✓ ' + msg : '✗ ' + msg;
        resultEl.style.color = ok ? '#4ade80' : '#f87171';
        setTimeout(function () { resultEl.textContent = ''; }, 3000);
    }

    if (soundType === 'tts') {
        if (!window.speechSynthesis) { showResult(false, 'เบราว์เซอร์นี้ไม่รองรับ TTS'); return; }
        var safeVol = vol > 0 ? vol : 0.01;
        var tu1 = new SpeechSynthesisUtterance('โต๊ะ 5');
        var tu2 = new SpeechSynthesisUtterance('พร้อมเสิร์ฟ');
        tu1.lang = tu2.lang = 'th-TH';
        tu1.volume = tu2.volume = safeVol;
        tu1.rate = tu2.rate = 0.85;
        tu1.pitch = 1.1; tu2.pitch = 1.0;
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(tu1);
        window.speechSynthesis.speak(tu2);
        showResult(true, 'กำลังพูด: "โต๊ะ 5 พร้อมเสิร์ฟ"');
        return;
    }

    if (soundType === 'file') {
        var path = soundFilePath ? soundFilePath.value : '';
        if (!path) { showResult(false, 'ยังไม่ได้อัปโหลดไฟล์'); return; }
        var audio = new Audio(path + '?t=' + Date.now());
        audio.volume = vol;
        audio.play()
            .then(function () { showResult(true, 'เล่นไฟล์แล้ว'); })
            .catch(function (e) { showResult(false, e.message || 'เล่นไม่ได้'); });
        return;
    }

    // beep
    try {
        var ctx  = new (window.AudioContext || window.webkitAudioContext)();
        var tone = document.getElementById('beepToneSelect') ? document.getElementById('beepToneSelect').value : 'ding';
        var now  = ctx.currentTime;

        function beepTone(f1, f2, dur, at) {
            var o = ctx.createOscillator();
            var g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.setValueAtTime(f1, at);
            if (f2 !== f1) o.frequency.setValueAtTime(f2, at + dur * 0.3);
            g.gain.setValueAtTime(0.5 * (vol || 0.01), at);
            g.gain.exponentialRampToValueAtTime(0.001, at + dur);
            o.start(at); o.stop(at + dur);
        }

        if (tone === 'double')   { beepTone(1000, 1000, 0.18, now); beepTone(1000, 1000, 0.18, now + 0.28); }
        else if (tone === 'low') { beepTone(440, 330, 0.6, now); }
        else                     { beepTone(880, 660, 0.45, now); }

        setTimeout(function () { try { ctx.close(); } catch (e) {} }, 1200);
        showResult(true, 'เล่นเสียงแล้ว');
    } catch (e) {
        showResult(false, e.message || 'ไม่สามารถเล่นเสียงได้');
    }
});

document.getElementById('btnTest').addEventListener('click', function() {
    var form = this.closest('form');
    var data = new FormData();
    data.append('action',  'test_db');
    data.append('db_host', form.querySelector('[name=db_host]').value);
    data.append('db_port', form.querySelector('[name=db_port]').value);
    data.append('db_name', form.querySelector('[name=db_name]').value);
    data.append('db_user', form.querySelector('[name=db_user]').value);
    data.append('db_pass', form.querySelector('[name=db_pass]').value);
    var el = document.getElementById('testResult');
    el.textContent = 'กำลังทดสอบ...';
    el.className = 'test-result';
    fetch('settings.php', { method: 'POST', body: data })
        .then(function(r){ return r.json(); })
        .then(function(d){
            el.textContent = d.message;
            el.className = 'test-result ' + (d.success ? 'ok' : 'err');
        })
        .catch(function(){ el.textContent = 'เกิดข้อผิดพลาด'; el.className = 'test-result err'; });
});

// ── Live Preview ──────────────────────────────────────────────────────────
(function () {
    var pvWrap   = document.getElementById('pvWrap');
    var pvLatest = document.getElementById('pvLatest');

    function updatePreview() {
        var hbg   = (document.getElementById('t_hbg').value  || '#1a1a2e').trim();
        var htx   = (document.getElementById('t_htx').value  || '#ffffff').trim();
        var qtx   = (document.getElementById('t_qtx').value  || '#1a1a2e').trim();
        var abg   = (document.getElementById('t_abg').value  || '#ffffff').trim();
        var bgImg = document.getElementById('bgImageInput').value.trim();
        var cols  = Math.min(8, Math.max(1, parseInt(document.querySelector('[name=grid_columns]').value, 10) || 2));

        pvWrap.style.background         = abg;
        pvWrap.style.backgroundImage    = bgImg ? "url('" + encodeURI(bgImg).replace(/'/g, '%27') + "')" : '';
        pvWrap.style.backgroundSize     = 'cover';
        pvWrap.style.backgroundPosition = 'center';

        document.querySelectorAll('.pv-head').forEach(function (el) {
            el.style.background = hbg;
            el.style.color      = htx;
        });
        document.querySelectorAll('.pv-num').forEach(function (el) {
            el.style.color = qtx;
        });
        document.querySelectorAll('.pv-grid').forEach(function (el) {
            el.style.gridTemplateColumns = 'repeat(' + cols + ', 1fr)';
        });
        pvLatest.style.background = bgImg ? 'rgba(255,255,255,0.88)' : abg;
        document.getElementById('pvShopBar').style.color = qtx;
    }

    ['t_hbg', 't_htx', 't_qtx', 't_abg'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', updatePreview);
    });
    ['cp_hbg', 'cp_htx', 'cp_qtx', 'cp_abg'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', updatePreview);
    });
    document.getElementById('bgImageInput').addEventListener('input', updatePreview);
    document.querySelector('[name=grid_columns]').addEventListener('input', updatePreview);

    document.querySelectorAll('.bg-sample-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('bgImageInput').value = this.dataset.path;
            document.getElementById('t_hbg').value  = this.dataset.hbg;
            document.getElementById('cp_hbg').value = this.dataset.hbg;
            document.getElementById('t_htx').value  = this.dataset.htx;
            document.getElementById('cp_htx').value = this.dataset.htx;
            document.getElementById('t_qtx').value  = this.dataset.qtx;
            document.getElementById('cp_qtx').value = this.dataset.qtx;
            document.getElementById('t_abg').value  = this.dataset.abg;
            document.getElementById('cp_abg').value = this.dataset.abg;
            updatePreview();
        });
    });

    updatePreview();
}());
</script>
<?php endif; ?>

</body>
</html>
