<?php
ob_start();
require_once __DIR__ . '/config.php';

set_exception_handler(function ($e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()]);
});

try {
    // ── 0. ตรวจ DB config ก่อน connect ────────────────────────────────────────
    try {
        $conn = getDbConnection();
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'DB config incomplete') !== false) {
            jsonResponse(array(
                'success'        => false,
                'setup_required' => true,
                'error'          => 'ยังไม่ได้ตั้งค่าการเชื่อมต่อฐานข้อมูล',
                'detail'         => 'กรุณากรอก Host, Database Name, User ในหน้าตั้งค่า',
                'steps'          => array(
                    'กดมุมบนซ้ายของหน้าจอ 3 ครั้งเพื่อเข้าหน้าตั้งค่า',
                    'กรอกข้อมูลฐานข้อมูล (Host, Port, DB Name, User, Password)',
                    'กด "ทดสอบการเชื่อมต่อ" เพื่อตรวจสอบ',
                    'กด "บันทึกการตั้งค่า"',
                ),
            ));
        }
        throw $e;
    }

    // ── 1. ตรวจตารางมีอยู่มั้ย (case-insensitive ใช้ information_schema) ─────
    $tableCheck = $conn->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND LOWER(TABLE_NAME) = 'orderprocessdetail_displaystatusinqueue'
          LIMIT 1"
    );
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        $conn->close();
        jsonResponse(array(
            'success'        => false,
            'setup_required' => true,
            'error'          => 'ไม่พบตาราง OrderProcessDetail_DisplayStatusInQueue',
            'detail'         => 'ระบบ POS ยังไม่ได้อัพเดทฐานข้อมูลให้รองรับ Queue Display',
            'steps'          => array(
                'รัน SQL สร้างตาราง OrderProcessDetail_DisplayStatusInQueue ในฐานข้อมูล POS',
                'รัน SQL: INSERT INTO ProgramProperty / ProgramPropertyValue ตามสคริปต์ที่ได้รับ',
                'รัน SQL: UPDATE programpropertyvalue SET propertyvalue = 1 WHERE propertyid = 172',
                'Restart service POS หรือ reload หน้าจอแสดงผล',
            ),
        ));
    }

    // ── 2. ตรวจ feature property 172 ── แยก "ไม่มีแถว" vs "ปิดอยู่" ────────
    $propResult = $conn->query(
        "SELECT PropertyValue FROM programpropertyvalue WHERE PropertyID = 172 LIMIT 1"
    );
    if (!$propResult || $propResult->num_rows === 0) {
        $conn->close();
        jsonResponse(array(
            'success'        => false,
            'setup_required' => true,
            'error'          => 'ไม่พบ PropertyID 172 ในฐานข้อมูล',
            'detail'         => 'ยังไม่ได้รัน SQL script สำหรับ Queue Display feature',
            'steps'          => array(
                'รัน SQL: INSERT INTO ProgramProperty ตามสคริปต์ที่ได้รับ',
                'รัน SQL: INSERT INTO ProgramPropertyValue ตามสคริปต์ที่ได้รับ',
                'รัน SQL: UPDATE programpropertyvalue SET propertyvalue = 1 WHERE propertyid = 172',
                'Restart service POS หรือ reload หน้าจอแสดงผล',
            ),
        ));
    }
    $propValue = (int)$propResult->fetch_assoc()['PropertyValue'];
    if ($propValue !== 1) {
        $conn->close();
        jsonResponse(array(
            'success'        => false,
            'setup_required' => true,
            'error'          => 'Queue Display feature ยังไม่ได้เปิดใช้งาน (PropertyValue = ' . $propValue . ')',
            'detail'         => 'PropertyID 172 มีอยู่แล้วแต่ค่าเป็น ' . $propValue . ' ต้องตั้งเป็น 1',
            'steps'          => array(
                'เปิด Back Office → ระบบจัดการ → ตั้งค่าคอมพิวเตอร์',
                'เลือก Computer ที่เป็น KDS/Checker แล้วตั้ง Computer Type = Queue Terminal',
                'รัน SQL: UPDATE programpropertyvalue SET propertyvalue = 1 WHERE propertyid = 172',
                'Restart service POS หรือ reload หน้าจอแสดงผล',
            ),
        ));
    }

    // ── 3. ตรวจ Computer ID ─────────────────────────────────────────────────────
    $computerId = defined('QUEUE_COMPUTER_ID') ? (int)QUEUE_COMPUTER_ID : 0;
    if ($computerId <= 0) {
        $conn->close();
        jsonResponse(array(
            'success'        => false,
            'setup_required' => true,
            'error'          => 'ยังไม่ได้ตั้งค่า Computer ID',
            'detail'         => 'กรุณาเลือก Computer ที่เป็นจอแสดงคิวจากหน้าตั้งค่า',
            'steps'          => array(
                'กดมุมบนซ้ายของหน้าจอ 3 ครั้งเพื่อเข้าหน้าตั้งค่า',
                'ไปที่หัวข้อ "คอมพิวเตอร์จอแสดงคิว"',
                'กด "โหลดรายการ" แล้วเลือก Computer ที่ตรงกับจอนี้',
                'กด "บันทึกการตั้งค่า"',
            ),
        ));
    }

    // ── 4. ดึงชื่อ computer และชื่อร้าน ────────────────────────────────────────
    $computerName = '';
    $cnResult = $conn->query(
        "SELECT ComputerName FROM computername WHERE ComputerID = {$computerId} LIMIT 1"
    );
    if ($cnResult && $cnResult->num_rows > 0) {
        $computerName = trim((string)$cnResult->fetch_assoc()['ComputerName']);
    }

    $shopName       = '';
    $productLevelId = defined('SHOP_PRODUCT_LEVEL_ID') ? (int)SHOP_PRODUCT_LEVEL_ID : 0;
    if ($productLevelId > 0) {
        $snResult = $conn->query(
            "SELECT ProductLevelName FROM productlevel WHERE ProductLevelID = {$productLevelId} LIMIT 1"
        );
        if ($snResult && $snResult->num_rows > 0) {
            $shopName = trim((string)$snResult->fetch_assoc()['ProductLevelName']);
        }
    }

    // ── 5. ดึงข้อมูล queue ────────────────────────────────────────────────────
    // Hybrid: ใช้ OrderProcessDetail_DisplayStatusInQueue เพื่อรู้ว่ามีออเดอร์อะไร
    // แต่คำนวณสถานะ READY/PREPARING จาก orderprocessdetailfront จริง
    // เพราะ checker อัปเดต orderprocessdetailfront โดยตรง ส่วน ProcessStatus
    // ใน DisplayStatusInQueue อาจยังไม่ถูก update โดย POS
    $readyMins = defined('READY_DISPLAY_MINUTES') ? (int)READY_DISPLAY_MINUTES : 40;
    // กรองเฉพาะ READY ที่เสร็จเกิน N นาที (pending > 0 = PREPARING ผ่านเสมอ)
    $readyTimeFilter = $readyMins > 0
        ? "AND (opd_stat.pending_count > 0 OR opd_stat.last_finish IS NULL OR opd_stat.last_finish >= DATE_SUB(NOW(), INTERVAL {$readyMins} MINUTE))"
        : '';

    $sql = "
        SELECT
            dsq.TransactionID,
            dsq.ComputerID,
            dsq.SubmitOrderDateTime,
            TRIM(COALESCE(tr.QueueName, '')) AS QueueName,
            COALESCE(opd_stat.pending_count, 0) AS pending_count,
            COALESCE(opd_stat.done_count,    0) AS done_count,
            opd_stat.last_finish,
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
                SUM(CASE WHEN ProcessStatus = 1      THEN 1 ELSE 0 END) AS done_count,
                MAX(FinishDateTime) AS last_finish
            FROM orderprocessdetailfront
            WHERE SubmitOrderDateTime >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
              AND ProductSetType >= 0
            GROUP BY TransactionID, ComputerID
        ) opd_stat
            ON  opd_stat.TransactionID = dsq.TransactionID
            AND opd_stat.ComputerID    = dsq.ComputerID
        WHERE dsq.OrderDate = CURDATE()
          {$readyTimeFilter}
        ORDER BY dsq.SubmitOrderDateTime ASC
    ";

    $result = $conn->query($sql);
    if (!$result) { $conn->close(); throw new Exception('ไม่สามารถดึงข้อมูลได้'); }

    $preparing = array();
    $ready     = array();

    while ($row = $result->fetch_assoc()) {
        // แสดงเหมือน checker: dine-in → "โต๊ะ X", delivery → DisplayTableName
        // fallback: QueueName → TransactionID
        $tableInfo = isset($row['TableInfo']) ? explode('|', (string)$row['TableInfo'], 2) : array('0', '');
        $tableId   = (int)($tableInfo[0] ?? 0);
        $tableName = trim((string)($tableInfo[1] ?? ''));
        if ($tableName !== '' && $tableName !== '-') {
            $q = $tableId > 0 ? 'โต๊ะ ' . $tableName : $tableName;
        } else {
            $q = trim((string)$row['QueueName']);
            if ($q === '') {
                $q = str_pad((int)$row['TransactionID'], 4, '0', STR_PAD_LEFT);
            }
        }

        $pendingCount = (int)$row['pending_count'];
        $doneCount    = (int)$row['done_count'];

        if ($pendingCount === 0 && $doneCount > 0) {
            // ออกจาก checker ครบทุก item → READY
            $ready[] = array(
                'q' => $q,
                't' => (string)($row['last_finish'] ?: $row['SubmitOrderDateTime']),
            );
        } else {
            // pending > 0 = ยังอยู่ในครัว, หรือ 0/0 = ยังไม่มีข้อมูลครัว → PREPARING
            $preparing[] = array(
                'q' => $q,
                't' => (string)$row['SubmitOrderDateTime'],
            );
        }
    }
    $conn->close();

    // READY: เสร็จล่าสุดขึ้นก่อน
    usort($ready,     function ($a, $b) { return strcmp($b['t'], $a['t']); });
    // PREPARING: รอนานสุดขึ้นก่อน
    usort($preparing, function ($a, $b) { return strcmp($a['t'], $b['t']); });

    $readyLimit = defined('READY_LIMIT')     ? (int)READY_LIMIT     : 30;
    $prepLimit  = defined('PREPARING_LIMIT') ? (int)PREPARING_LIMIT : 30;
    $ready      = array_slice($ready,     0, $readyLimit);
    $preparing  = array_slice($preparing, 0, $prepLimit);

    jsonResponse(array(
        'success'         => true,
        'computer_name'   => $computerName,
        'shop_name'       => $shopName,
        'ready'           => array_column($ready,     'q'),
        'preparing'       => array_column($preparing, 'q'),
        'preparing_times' => array_column($preparing, 't'),
        'latest_ready'    => !empty($ready) ? $ready[0]['q'] : '',
    ));

} catch (Exception $e) {
    jsonResponse(array('success' => false, 'error' => $e->getMessage()));
}
