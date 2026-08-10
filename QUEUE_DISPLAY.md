# Queue Display — เอกสารระบบ

จอแสดงคิวแบบเรียลไทม์สำหรับร้านอาหาร แสดงสถานะออเดอร์แบ่งเป็น **PREPARING** (กำลังเตรียม) และ **READY** (พร้อมเสิร์ฟ) โดยทำงานร่วมกับโปรแกรม Web Checker และระบบ POS

---

## โครงสร้างไฟล์

| ไฟล์ | หน้าที่ |
|------|---------|
| `index.php` | หน้าจอหลักแสดงคิว — HTML/CSS/JS ทั้งหมด |
| `api_queue.php` | API endpoint ส่ง JSON กลับให้ index.php |
| `config.php` | โหลดค่าตั้งต้น, DB helper, ฟังก์ชันกลาง |
| `settings.php` | หน้าตั้งค่า (PIN-protected) |
| `web.config` | IIS config — ซ่อน settings.local.php และ log |
| `settings.local.php` | **ไม่ track ใน git** — ค่าตั้งค่าเฉพาะเครื่อง (DB, PIN, สี ฯลฯ) |

---

## การตั้งค่า (settings.local.php)

ไฟล์นี้สร้างอัตโนมัติจากหน้า Settings หรือสร้างเองด้วย PHP array:

```php
<?php return [
    // ── Database ──────────────────────────────────────
    'db_host'               => '127.0.0.1',
    'db_port'               => 3307,
    'db_name'               => 'pos_database',
    'db_user'               => 'pos_user',
    'db_pass'               => 'password',

    // ── Security ──────────────────────────────────────
    'settings_pin'          => '',          // PIN 4–8 หลัก (ว่าง = ไม่ต้องใส่ PIN)

    // ── Computer / Shop ────────────────────────────────
    'computer_id'           => 0,           // ComputerID ของจอนี้ (ดูจาก computername)
    'product_level_id'      => 0,           // ProductLevelID ของร้าน (0 = ไม่แสดงชื่อร้าน)

    // ── Queue Behavior ─────────────────────────────────
    'queue_refresh_ms'      => 5000,        // รีเฟรชทุก N ms (min 2000)
    'ready_limit'           => 30,          // จำนวนคิว READY สูงสุดที่แสดง
    'preparing_limit'       => 30,          // จำนวนคิว PREPARING สูงสุดที่แสดง
    'ready_display_minutes' => 40,          // แสดงคิว READY นาน N นาทีหลังเสร็จ (0 = ตลอดไป)
    'grid_columns'          => 2,           // จำนวนคอลัมน์ในกริด

    // ── Appearance ─────────────────────────────────────
    'bg_image'              => '',          // path รูป bg เช่น images/bg.jpg (ว่าง = ไม่มี)
    'color_header_bg'       => '#1a1a2e',   // สีพื้นหลัง header
    'color_header_text'     => '#ffffff',   // สีตัวอักษร header
    'color_queue_text'      => '#1a1a2e',   // สีหมายเลขคิว
    'color_app_bg'          => '#ffffff',   // สีพื้นหลังหลัก
];
```

> **หมายเหตุ**: ค่าสีรองรับ `#RGB`, `#RRGGBB`, named colors, `rgb()`, `rgba()`, `hsl()`, `hsla()` เท่านั้น (whitelist ป้องกัน CSS injection)

---

## ตารางฐานข้อมูลที่ใช้

| ตาราง | การใช้งาน |
|-------|----------|
| `OrderProcessDetail_DisplayStatusInQueue` | รายการออเดอร์ที่ต้องแสดงบนจอคิว (POS เป็นคนเพิ่ม/ลบ) |
| `orderprocessdetailfront` | ข้อมูล item แต่ละรายการ — ใช้คำนวณสถานะ READY/PREPARING จริง |
| `ordertransactionfront` | ดึง `QueueName` (เลขโต๊ะ/คิว) ของแต่ละออเดอร์ |
| `computername` | ดึงชื่อ computer ของจอนี้; กรอง `ComputerType = 4` สำหรับ dropdown |
| `productlevel` | ดึงชื่อร้าน (`ProductLevelName`) จาก `ProductLevelID` |
| `programpropertyvalue` | ตรวจสอบ `PropertyID = 172` ต้อง = 1 จึงเปิดใช้งาน |
| `information_schema.TABLES` | ตรวจสอบว่าตาราง DisplayStatusInQueue มีอยู่จริง |

---

## เงื่อนไขการแสดงสถานะ

```
pending_count = SUM(ProcessStatus IN (0,2))   -- ยังไม่เสร็จ
done_count    = SUM(ProcessStatus = 1)         -- เสร็จแล้ว
```

| เงื่อนไข | สถานะ |
|----------|-------|
| `pending = 0` AND `done > 0` | **READY** (พร้อมเสิร์ฟ) |
| `pending > 0` | **PREPARING** (กำลังเตรียม) |
| `pending = 0` AND `done = 0` | **PREPARING** (ยังไม่มีข้อมูลครัว) |

**ลำดับการแสดง**:
- READY: เสร็จล่าสุดขึ้นก่อน (`last_finish DESC`)
- PREPARING: รอนานสุดขึ้นก่อน (`SubmitOrderDateTime ASC`)

**READY หายออกเมื่อ**: `last_finish < NOW() - INTERVAL ready_display_minutes MINUTE`

---

## Data Flow

```
POS ── เพิ่มออเดอร์ ──► OrderProcessDetail_DisplayStatusInQueue
                                         │
Web Checker ── เช็คอาหาร ──► orderprocessdetailfront (ProcessStatus 0→1)
                                         │
api_queue.php ── JOIN ──► คำนวณ pending/done per transaction
                                         │
                         pending=0,done>0 → READY
                         else             → PREPARING
                                         │
index.php ── fetch ──► แสดงผลบนจอ (รีเฟรชทุก queue_refresh_ms)
```

> **สำคัญ**: POS **ไม่** update `ProcessStatus` ใน `OrderProcessDetail_DisplayStatusInQueue` เมื่อ checker เสร็จ ต้องอ่านสถานะจริงจาก `orderprocessdetailfront` เสมอ (Hybrid Query)

---

## ข้อกำหนดก่อนใช้งาน (Setup Requirements)

ระบบจะแสดง setup error บนหน้าจอถ้าขาดข้อกำหนดต่อไปนี้ (ตรวจสอบตามลำดับ):

1. **ตาราง `OrderProcessDetail_DisplayStatusInQueue` ต้องมีอยู่** ในฐานข้อมูล  
   → รัน SQL script สร้างตารางจาก POS vendor

2. **`PropertyID = 172` ใน `programpropertyvalue` ต้องมีค่า = 1**  
   ```sql
   UPDATE programpropertyvalue SET propertyvalue = 1 WHERE propertyid = 172;
   ```

3. **ต้องตั้งค่า `computer_id` > 0** (เลือก ComputerID ของจอนี้จากหน้าตั้งค่า)

---

## การเข้าหน้าตั้งค่า

กด **มุมบนซ้ายของหน้าจอ 3 ครั้งภายใน 1.5 วินาที** → ระบบเปลี่ยนไปหน้า `settings.php`

- ถ้าตั้ง PIN ไว้ จะต้องใส่ PIN ก่อน
- ถ้าไม่ได้ตั้ง PIN จะเข้าได้ทันที
- Session timeout: 30 นาที (ชื่อ session: `qdisplay_cfg`)

**AJAX actions ใน settings.php**:
- `test_db` — ทดสอบการเชื่อมต่อฐานข้อมูล
- `list_computers` — โหลด dropdown คอมพิวเตอร์ (`ComputerType = 4`)
- `pin` — ตรวจสอบ PIN
- `logout` — ออกจาก session
- `save` — บันทึกค่าลง settings.local.php

---

## UI Layout

```
┌─────────────────────────────┐  ← compBadge (computer name, top-right)
│  READY    พร้อมเสิร์ฟ       │  ← header (color_header_bg)
├─────────────────────────────┤
│  0001  0002  0003  0004     │  ← readyGrid (grid_columns คอลัมน์)
│  0005  0006                 │
├─────────────────────────────┤
│         ล่าสุด              │  ← latest-wrap (ซ่อนถ้าไม่มี READY)
│          0006               │  ← latestNum (flash animation เมื่อเปลี่ยน)
├─────────────────────────────┤
│  PREPARING  กำลังเตรียม     │
├─────────────────────────────┤
│  0007  0008                 │  ← preparingGrid
├─────────────────────────────┤
│  ชื่อร้าน                   │  ← shopName (bottom, from productlevel)
└─────────────────────────────┘
│  [fetch error banner]       │  ← fetchError (fixed bottom, แสดงเมื่อ API fail)
```

---

## Bugs ที่พบและแก้ไขแล้ว

| # | ปัญหา | สาเหตุ | แก้ไข |
|---|-------|--------|-------|
| 1 | ออเดอร์ไม่ขึ้นจอเลย | `AND dsq.ComputerID = {$computerId}` กรองด้วย ID จอแสดงผล แต่ dsq.ComputerID = POS terminal | ลบ filter ComputerID ออก ใช้ computer_id เฉพาะดึงชื่อ |
| 2 | ไม่เคยเป็น READY | POS ไม่ update ProcessStatus ใน DisplayStatusInQueue เมื่อ checker เสร็จ | Hybrid query: join orderprocessdetailfront เพื่อคำนวณ pending/done จาก item จริง |
| 3 | PREPARING ถูก filter ออก | `readyTimeFilter` ใช้ `pending_count = 0` ผิด ทำให้ PREPARING ที่มี last_finish ถูก filter | แก้เป็น `pending_count > 0` — PREPARING ผ่านเสมอ |
| 4 | ชื่อร้านผิด (ได้ HQ แทน) | `productlevel LIMIT 1` ได้ ProductLevelID=1 (HQ) ไม่ใช่ร้าน | ใช้ `product_level_id` ที่ตั้งค่าไว้กับ `WHERE ProductLevelID = {$id}` |
| 5 | ออเดอร์ 0/0 item หายไป | `opd_stat.pending_count IS NOT NULL` บังคับ INNER JOIN กัน orders ที่ยังไม่มีข้อมูลครัว | ลบ condition ออก; ใช้ `else` แทน `elseif` → 0/0 = PREPARING |
| 6 | "-" แสดงเป็นสี่เหลี่ยมดำ | `#latestNum` มีข้อความ "-" ฟอนต์ใหญ่สีเข้ม | ซ่อน `latest-wrap` ทั้งก้อนเมื่อไม่มีคิว READY |
| 7 | สีพื้นหลัง latest-wrap ผิด | `background: #f5f5f5` hardcode ขัดกับ custom bg | เปลี่ยนเป็น `var(--c-app-bg)` |
| 8 | `shop_name` ค้างใน settings | ลบ `SHOP_NAME` constant แล้ว แต่ยังบันทึก key `shop_name` อยู่ | ลบออกจาก save handler และ form HTML |
| 9 | Error เงียบ ไม่มี feedback | API error บางประเภทไม่มีอะไรบนหน้าจอ | เพิ่ม `#fetchError` red banner ท้ายหน้าจอ |
| 10 | settings.local.php conflict ใน git | ไม่ได้ add ไว้ใน .gitignore | เพิ่มใน `.gitignore`, รัน `git rm --cached` |

---

## Environment / ข้อจำกัด

- **PHP**: 8.4+ (mysqli, ไม่มี PDO, ไม่มี Composer)
- **MySQL**: 5.1+ (ไม่ใช้ syntax ใหม่, ไม่มี ALTER/CREATE TABLE)
- **Web Server**: IIS + FastCGI (Windows) — HTTP response ต้องเป็น 200 เสมอ, error ส่งใน JSON
- **Output buffering**: `ob_start()` ทุกไฟล์ API, `jsonResponse()` clear ob ก่อน output
- **Timezone**: Asia/Bangkok (UTC+7), `SET time_zone = '+07:00'` ทุก connection
