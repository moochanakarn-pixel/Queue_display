<?php
session_name('qdisplay_cfg');
$__isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $__isHttps,
]);
session_start();
ob_start();
require_once __DIR__ . '/config.php';

// ── idle session timeout (30 นาที) — แยกจาก session ของหน้าตั้งค่า ────────────
define('QDISPLAY_STAFF_TIMEOUT', 1800);
if (!empty($_SESSION['qdisplay_staff_auth']) && !empty($_SESSION['qdisplay_staff_last_seen'])
    && (time() - (int)$_SESSION['qdisplay_staff_last_seen']) > QDISPLAY_STAFF_TIMEOUT) {
    unset($_SESSION['qdisplay_staff_auth']);
}
$_SESSION['qdisplay_staff_last_seen'] = time();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrfValid()
{
    $token = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    return hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$msg    = '';
$isErr  = false;

// ── Verify staff PIN ──────────────────────────────────────────────────────────
if ($action === 'staff_pin') {
    $now       = time();
    $attempts  = (int)($_SESSION['staff_pin_attempts']   ?? 0);
    $lockUntil = (int)($_SESSION['staff_pin_lock_until'] ?? 0);
    if ($now < $lockUntil) {
        $msg   = 'ลองผิดหลายครั้งเกินไป กรุณารออีก ' . ($lockUntil - $now) . ' วินาที';
        $isErr = true;
    } elseif (!csrfValid()) {
        $msg   = 'คำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';
        $isErr = true;
    } else {
        $pin = (string)($_POST['pin'] ?? '');
        if (hash_equals(STAFF_PIN, $pin)) {
            session_regenerate_id(true);
            $_SESSION['qdisplay_staff_auth'] = true;
            unset($_SESSION['staff_pin_attempts'], $_SESSION['staff_pin_lock_until']);
        } else {
            $attempts++;
            $_SESSION['staff_pin_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['staff_pin_lock_until'] = $now + 60 * (intdiv($attempts, 5));
            }
            $msg   = 'PIN ไม่ถูกต้อง';
            $isErr = true;
        }
    }
}

// ── Logout ───────────────────────────────────────────────────────────────────
if ($action === 'staff_logout') {
    if (csrfValid()) {
        unset($_SESSION['qdisplay_staff_auth']);
    }
    header('Location: confirm.php');
    exit;
}

// ── AJAX: รายการ PREPARING ที่ยังกดยืนยันไม่ได้ (ยังไม่ครบตามครัว/ยังไม่กดยืนยัน) ──
if ($action === 'list_preparing') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['qdisplay_staff_auth'])) {
        echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']);
        exit;
    }
    if (!MANUAL_READY_ENABLED) {
        echo json_encode(['success' => false, 'message' => 'ฟีเจอร์นี้ปิดอยู่ กรุณาเปิดจากหน้าตั้งค่า']);
        exit;
    }
    if (!csrfValid()) {
        echo json_encode(['success' => false, 'message' => 'CSRF token ไม่ถูกต้อง กรุณาโหลดหน้าใหม่']);
        exit;
    }
    try {
        $conn      = getDbConnection();
        $confirmed = loadManualReady();
        $sql = "
            SELECT
                dsq.TransactionID,
                dsq.ComputerID,
                dsq.SubmitOrderDateTime,
                TRIM(COALESCE(tr.QueueName, '')) AS QueueName,
                COALESCE(opd_stat.pending_count, 0) AS pending_count,
                COALESCE(opd_stat.done_count,    0) AS done_count,
                (SELECT CONCAT(opdf.TableID, '|', opdf.DisplayTableName)
                 FROM orderprocessdetailfront opdf
                 WHERE opdf.TransactionID = dsq.TransactionID
                   AND opdf.ComputerID    = dsq.ComputerID
                   AND opdf.ProductSetType >= 0
                 ORDER BY opdf.ProcessID ASC
                 LIMIT 1) AS TableInfo
            FROM OrderProcessDetail_DisplayStatusInQueue dsq
            LEFT JOIN ordertransactionfront tr
                ON  tr.TransactionID = dsq.TransactionID
                AND tr.ComputerID    = dsq.ComputerID
            LEFT JOIN (
                SELECT
                    TransactionID,
                    ComputerID,
                    SUM(CASE WHEN ProcessStatus IN (0,2) THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN ProcessStatus = 1      THEN 1 ELSE 0 END) AS done_count
                FROM orderprocessdetailfront
                WHERE SubmitOrderDateTime >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                  AND ProductSetType >= 0
                GROUP BY TransactionID, ComputerID
            ) opd_stat
                ON  opd_stat.TransactionID = dsq.TransactionID
                AND opd_stat.ComputerID    = dsq.ComputerID
            WHERE dsq.OrderDate = CURDATE()
            ORDER BY dsq.SubmitOrderDateTime ASC
        ";
        $result = $conn->query($sql);
        if (!$result) throw new Exception('ไม่สามารถดึงข้อมูลได้');

        $list = array();
        while ($row = $result->fetch_assoc()) {
            $pendingCount = (int)$row['pending_count'];
            $doneCount    = (int)$row['done_count'];
            if ($pendingCount === 0 && $doneCount > 0) continue; // Checker ยืนยันแล้ว ไม่ต้องโชว์ให้กดซ้ำ

            $key = $row['TransactionID'] . '_' . $row['ComputerID'];
            if (isset($confirmed[$key])) continue; // กดยืนยันเองไปแล้ว รอ refresh ฝั่งจอ

            $tableInfo = explode('|', (string)$row['TableInfo'], 2);
            $tableId   = (int)($tableInfo[0] ?? 0);
            $tableName = trim((string)($tableInfo[1] ?? ''));
            if ($tableName !== '' && $tableName !== '-') {
                $q = $tableId > 0 ? 'โต๊ะ ' . $tableName : $tableName;
            } else {
                $q = trim((string)$row['QueueName']);
                if ($q === '') $q = str_pad((int)$row['TransactionID'], 4, '0', STR_PAD_LEFT);
            }

            $list[] = array(
                'transaction_id' => (int)$row['TransactionID'],
                'computer_id'    => (int)$row['ComputerID'],
                'q'              => $q,
                't'              => (string)$row['SubmitOrderDateTime'],
            );
        }
        $conn->close();
        echo json_encode(['success' => true, 'items' => $list]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: กดยืนยันเสร็จ ───────────────────────────────────────────────────────
if ($action === 'confirm_ready') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['qdisplay_staff_auth'])) {
        echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']);
        exit;
    }
    if (!MANUAL_READY_ENABLED) {
        echo json_encode(['success' => false, 'message' => 'ฟีเจอร์นี้ปิดอยู่ กรุณาเปิดจากหน้าตั้งค่า']);
        exit;
    }
    if (!csrfValid()) {
        echo json_encode(['success' => false, 'message' => 'CSRF token ไม่ถูกต้อง กรุณาโหลดหน้าใหม่']);
        exit;
    }
    $tid = (int)($_POST['transaction_id'] ?? 0);
    $cid = (int)($_POST['computer_id']    ?? 0);
    if ($tid <= 0 || $cid <= 0) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
        exit;
    }
    $ok = setManualReadyConfirm($tid . '_' . $cid);
    echo json_encode(['success' => $ok, 'message' => $ok ? 'ยืนยันแล้ว' : 'บันทึกไม่ได้ — ตรวจสอบสิทธิ์ write ของโฟลเดอร์']);
    exit;
}

$auth = !empty($_SESSION['qdisplay_staff_auth']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Queue Display — ยืนยันเสร็จ</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI','Helvetica Neue',Arial,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}
.topbar{display:flex;align-items:center;justify-content:space-between;background:#1e293b;padding:14px 20px;border-bottom:1px solid #334155;position:sticky;top:0;z-index:10}
.topbar-title{font-size:18px;font-weight:700;color:#f1f5f9}
.btn-logout{background:transparent;border:1px solid #ef4444;color:#ef4444;padding:7px 14px;border-radius:8px;font-size:14px;cursor:pointer;transition:.15s}
.btn-logout:hover{background:#ef4444;color:#fff}
.content{max-width:720px;margin:0 auto;padding:24px 16px 60px}
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
.alert{padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:18px;font-weight:600}
.alert-ok {background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.alert-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#f87171}
.disabled-box{background:#1e293b;border-radius:14px;padding:32px 20px;text-align:center;color:#94a3b8;font-size:15px;line-height:1.8}
.q-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;margin-top:8px}
.q-card{background:#1e293b;border:2px solid #334155;border-radius:14px;padding:22px 10px;text-align:center;cursor:pointer;transition:.15s;user-select:none}
.q-card:active{transform:scale(.96);border-color:#3b82f6;background:rgba(59,130,246,.12)}
.q-num{font-size:26px;font-weight:800;color:#f1f5f9}
.q-wait{font-size:12px;color:#64748b;margin-top:6px}
.empty-state{text-align:center;color:#64748b;padding:60px 20px;font-size:15px}
.status-line{text-align:center;font-size:12px;color:#475569;margin-top:20px}
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1e293b;border:1px solid #334155;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:600;opacity:0;pointer-events:none;transition:.25s}
.toast.show{opacity:1;transform:translateX(-50%) translateY(-6px)}
.toast.ok{color:#4ade80;border-color:rgba(34,197,94,.4)}
.toast.err{color:#f87171;border-color:rgba(239,68,68,.4)}
</style>
</head>
<body>

<div class="topbar">
    <span class="topbar-title">ยืนยันออเดอร์เสร็จ</span>
    <?php if ($auth): ?>
    <form method="post" style="margin:0">
        <input type="hidden" name="action" value="staff_logout">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">
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
        <div class="pin-title">ใส่ PIN พนักงาน</div>
        <div class="pin-sub">Queue Display — ยืนยันออเดอร์เสร็จ</div>
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
            <input type="hidden" name="action" value="staff_pin">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf_token']) ?>">
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
    var inp    = document.getElementById('pinInput');
    var pinLen = <?php echo (int)min(8, max(4, strlen(STAFF_PIN))); ?>;
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

<?php elseif (!MANUAL_READY_ENABLED): ?>
<div class="content">
    <div class="disabled-box">
        ฟีเจอร์นี้ยังปิดอยู่<br>
        เข้าหน้า <strong>ตั้งค่า</strong> → เปิด "ปุ่มยืนยันเสร็จด้วยมือ" ก่อนใช้งาน
    </div>
</div>

<?php else: ?>
<!-- ── Confirm list ── -->
<div class="content">
    <div id="qGrid" class="q-grid"></div>
    <div id="emptyState" class="empty-state" style="display:none">ไม่มีออเดอร์ที่รอยืนยัน</div>
    <div class="status-line" id="statusLine"></div>
</div>
<div class="toast" id="toast"></div>

<script>
var CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf_token']); ?>;
var qGrid       = document.getElementById('qGrid');
var emptyState  = document.getElementById('emptyState');
var statusLine  = document.getElementById('statusLine');
var toast       = document.getElementById('toast');
var busyKeys    = {};

function showToast(ok, msg) {
    toast.textContent = msg;
    toast.className = 'toast show ' + (ok ? 'ok' : 'err');
    setTimeout(function () { toast.className = 'toast'; }, 2200);
}

function esc(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function confirmReady(tid, cid, key) {
    if (busyKeys[key]) return;
    busyKeys[key] = true;
    var data = new FormData();
    data.append('action', 'confirm_ready');
    data.append('csrf', CSRF_TOKEN);
    data.append('transaction_id', tid);
    data.append('computer_id', cid);
    fetch('confirm.php', { method: 'POST', body: data })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            busyKeys[key] = false;
            showToast(!!d.success, d.message || (d.success ? 'ยืนยันแล้ว' : 'ไม่สำเร็จ'));
            if (d.success) loadList();
        })
        .catch(function () {
            busyKeys[key] = false;
            showToast(false, 'เกิดข้อผิดพลาด');
        });
}

function loadList() {
    var data = new FormData();
    data.append('action', 'list_preparing');
    data.append('csrf', CSRF_TOKEN);
    fetch('confirm.php', { method: 'POST', body: data })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) {
                statusLine.textContent = d.message || 'โหลดไม่ได้';
                return;
            }
            statusLine.textContent = 'อัปเดตล่าสุด ' + new Date().toLocaleTimeString('th-TH');
            var items = d.items || [];
            if (!items.length) {
                qGrid.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }
            emptyState.style.display = 'none';
            qGrid.innerHTML = items.map(function (it) {
                var key = it.transaction_id + '_' + it.computer_id;
                return '<div class="q-card" data-key="' + esc(key) + '" data-tid="' + it.transaction_id + '" data-cid="' + it.computer_id + '">' +
                       '<div class="q-num">' + esc(it.q) + '</div>' +
                       '<div class="q-wait">แตะเพื่อยืนยันเสร็จ</div>' +
                       '</div>';
            }).join('');
        })
        .catch(function () {
            statusLine.textContent = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้';
        });
}

qGrid.addEventListener('click', function (e) {
    var card = e.target.closest('.q-card');
    if (!card) return;
    confirmReady(card.dataset.tid, card.dataset.cid, card.dataset.key);
});

loadList();
setInterval(loadList, 5000);
</script>
<?php endif; ?>

</body>
</html>
