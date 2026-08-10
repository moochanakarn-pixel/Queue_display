<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php echo h(APP_TITLE); ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            height: 100%;
            font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            background: #111;
            overflow: hidden;
        }

        #app {
            display: flex;
            flex-direction: column;
            height: 100vh;
            width: 100%;
            background: var(--c-app-bg);
        }

        /* ── Section (READY / PREPARING) ── */
        .qs {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        .qs-head {
            background: var(--c-header-bg);
            color: var(--c-header-text);
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: clamp(6px, 1.2vw, 16px);
            padding: clamp(8px, 1.8vh, 20px) 16px;
            flex-shrink: 0;
        }
        .qs-head-title {
            font-size: clamp(20px, 4.5vw, 48px);
            font-weight: 800;
            letter-spacing: 4px;
        }
        .qs-head-sub {
            font-size: clamp(13px, 2.5vw, 28px);
            font-weight: 400;
            opacity: .85;
            letter-spacing: 2px;
        }

        .qs-grid {
            display: grid;
            grid-template-columns: repeat(var(--grid-cols), 1fr);
            overflow-y: auto;
            flex: 1;
            padding: clamp(6px, 1.2vh, 16px) clamp(8px, 2vw, 24px) clamp(8px, 1.6vh, 20px);
            align-content: start;
            gap: clamp(2px, 0.6vh, 8px) 0;
        }
        .qs-grid::-webkit-scrollbar { width: 4px; }
        .qs-grid::-webkit-scrollbar-thumb { background: #ddd; border-radius: 2px; }

        .q-num {
            font-size: clamp(28px, 7.5vw, 80px);
            font-weight: 700;
            color: var(--c-queue-text);
            text-align: center;
            padding: clamp(2px, 0.6vh, 8px) 4px;
            line-height: 1.15;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            width: 100%;
            min-width: 0;
        }
        .q-item { min-width: 0; }
        .q-empty {
            grid-column: 1 / -1;
            text-align: center;
            color: #bbb;
            font-size: clamp(16px, 3.5vw, 36px);
            padding: clamp(20px, 5vh, 60px);
        }

        /* ── Latest Ready Announce ── */
        .latest-wrap {
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: clamp(4px, 0.8vh, 12px) 0;
            border-top: 2px solid rgba(128,128,128,0.2);
            border-bottom: 2px solid rgba(128,128,128,0.2);
            background: var(--c-app-bg);
            min-height: clamp(60px, 12vh, 140px);
        }

        /* ── Fetch error badge ── */
        #fetchError {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(220,38,38,0.92);
            color: #fff;
            font-size: clamp(12px, 1.8vw, 16px);
            font-weight: 600;
            text-align: center;
            padding: 8px 16px;
            z-index: 995;
        }
        .latest-label {
            font-size: clamp(11px, 2vw, 20px);
            font-weight: 700;
            letter-spacing: 3px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: clamp(1px, 0.3vh, 4px);
        }
        #latestNum {
            font-size: clamp(50px, 13vw, 140px);
            font-weight: 900;
            color: var(--c-queue-text);
            line-height: 1;
            letter-spacing: 4px;
            display: block;
            transition: color .2s, opacity .3s;
            max-width: 90vw;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ── Shop bar (bottom strip: shop name + clock) ── */
        .shop-bar {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: clamp(5px, 1vh, 12px) 16px;
            background: var(--c-app-bg);
            position: relative;
        }
        .shop-name {
            font-size: clamp(10px, 1.6vw, 18px);
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: rgba(0,0,0,0.22);
            text-align: center;
        }
        #shopClock {
            position: absolute;
            right: 16px;
            font-size: clamp(11px, 1.5vw, 17px);
            font-weight: 600;
            color: rgba(0,0,0,0.22);
            letter-spacing: 1px;
            font-variant-numeric: tabular-nums;
        }
        #latestNum.flash {
            animation: flash-ready .55s ease-in-out 4;
        }
        @keyframes flash-ready {
            0%,100% { color: var(--c-queue-text); transform: scale(1);   }
            50%     { color: #2563eb;              transform: scale(1.08); }
        }

        /* ── Setup Error Overlay ── */
        #setupError {
            display: none;
            position: fixed;
            inset: 0;
            background: #1a1a2e;
            color: #fff;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 24px;
            z-index: 999;
            text-align: center;
        }
        .se-icon  { font-size: 56px; margin-bottom: 16px; }
        .se-title { font-size: clamp(20px, 4vw, 36px); font-weight: 800; color: #f59e0b; margin-bottom: 12px; }
        .se-error { font-size: clamp(16px, 3vw, 26px); font-weight: 600; margin-bottom: 8px; }
        .se-detail{ font-size: clamp(13px, 2.2vw, 20px); opacity: .75; margin-bottom: 24px; }
        .se-steps {
            list-style: none;
            text-align: left;
            background: rgba(255,255,255,.08);
            border-radius: 10px;
            padding: 20px 28px;
            max-width: 640px;
            width: 100%;
        }
        .se-steps li {
            font-size: clamp(13px, 2.2vw, 20px);
            padding: 7px 0;
            border-bottom: 1px solid rgba(255,255,255,.1);
            counter-increment: step;
        }
        .se-steps li:last-child { border-bottom: none; }
        .se-steps li::before {
            content: counter(step) ". ";
            font-weight: 700;
            color: #f59e0b;
        }
        .se-steps { counter-reset: step; }
        .se-retry {
            margin-top: 24px;
            font-size: clamp(12px, 2vw, 18px);
            opacity: .55;
        }

        /* ── Fullscreen Overlay ── */
        #fsOverlay {
            display: flex;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.78);
            z-index: 997;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: opacity .4s;
        }
        #fsOverlay.fs-hidden { opacity: 0; pointer-events: none; }
        .fso-inner { text-align: center; color: #fff; user-select: none; }
        .fso-icon  {
            width: clamp(64px, 12vw, 100px);
            height: clamp(64px, 12vw, 100px);
            margin: 0 auto clamp(12px, 2vh, 20px);
            opacity: .9;
        }
        .fso-text  { font-size: clamp(18px, 3.5vw, 36px); font-weight: 700; margin-bottom: 8px; }
        .fso-sub   { font-size: clamp(12px, 2vw, 20px); opacity: .6; }

        /* ── Fullscreen Toggle Button ── */
        #fsBtn {
            position: fixed;
            bottom: 14px;
            right: 14px;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: rgba(0,0,0,0.45);
            color: rgba(255,255,255,0.85);
            cursor: pointer;
            z-index: 991;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        #fsBtn.fs-visible { opacity: 1; pointer-events: auto; }
        #fsBtn:hover { background: rgba(0,0,0,0.65); }

        /* ── Queue item wrapper ── */
        .q-item {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        /* ── Elapsed time (PREPARING) ── */
        .q-time {
            font-size: clamp(9px, 1.4vw, 13px);
            color: #aaa;
            text-align: center;
            line-height: 1.2;
            padding-bottom: clamp(2px, 0.4vh, 5px);
            font-variant-numeric: tabular-nums;
            letter-spacing: 0;
        }
        /* ── New-item fade-in ── */
        @keyframes q-fadein {
            from { opacity: 0; transform: scale(0.82); }
            to   { opacity: 1; transform: scale(1); }
        }
        .q-num.q-new { animation: q-fadein .3s ease-out; }
        /* ── Latest READY highlight ── */
        .q-num.q-latest {
            background: rgba(37,99,235,0.10);
            border-radius: 8px;
            color: #1d4ed8;
        }
    </style>
<style>
    :root {
        --c-header-bg:   <?php echo h(COLOR_HEADER_BG); ?>;
        --c-header-text: <?php echo h(COLOR_HEADER_TEXT); ?>;
        --c-queue-text:  <?php echo h(COLOR_QUEUE_TEXT); ?>;
        --c-app-bg:      <?php echo h(COLOR_APP_BG); ?>;
        --grid-cols:     <?php echo (int)GRID_COLUMNS; ?>;
    }
    <?php if (BG_IMAGE !== ''): ?>
    <?php
        // ใช้ rawurlencode แต่ละส่วนของ path เพื่อรองรับชื่อไฟล์ที่มีช่องว่างหรือวงเล็บ
        $__bgUrl = implode('/', array_map('rawurlencode', preg_split('#[/\\\\]#', BG_IMAGE)));
    ?>
    #app {
        background-image: url('<?php echo h($__bgUrl); ?>');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
    }
    .qs { background: transparent; }
    .qs-grid { background: transparent; }
    .latest-wrap { background: rgba(255,255,255,0.88); }
    <?php endif; ?>
</style>
</head>
<body>
<div id="fetchError"></div>
<div id="settingsTrigger" style="position:fixed;top:0;left:0;width:70px;height:70px;z-index:1000;cursor:default;"></div>
<div id="compBadge" style="position:fixed;top:0;right:0;background:rgba(0,0,0,0.45);color:rgba(255,255,255,0.85);font-size:clamp(11px,1.4vw,16px);padding:5px 14px 5px 12px;z-index:990;border-radius:0 0 0 10px;display:none;pointer-events:none;"></div>
<div id="setupError"></div>
<div id="fsOverlay">
    <div class="fso-inner">
        <svg class="fso-icon" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/>
            <line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>
        </svg>
        <div class="fso-text">แตะหน้าจอเพื่อแสดงผลเต็มจอ</div>
        <div class="fso-sub">หายไปอัตโนมัติใน <span id="fsCountdown">8</span> วินาที</div>
    </div>
</div>
<button id="fsBtn"></button>
<div id="app">

    <section class="qs" id="readySec">
        <div class="qs-head">
            <div class="qs-head-title">READY</div>
            <div class="qs-head-sub">พร้อมเสิร์ฟ</div>
        </div>
        <div class="qs-grid" id="readyGrid">
            <div class="q-empty">กำลังโหลด...</div>
        </div>
    </section>

    <div class="latest-wrap" style="display:none">
        <div class="latest-label">ล่าสุด</div>
        <span id="latestNum"></span>
    </div>

    <section class="qs" id="prepSec">
        <div class="qs-head">
            <div class="qs-head-title">PREPARING</div>
            <div class="qs-head-sub">กำลังเตรียม</div>
        </div>
        <div class="qs-grid" id="preparingGrid">
            <div class="q-empty">กำลังโหลด...</div>
        </div>
    </section>

    <div class="shop-bar">
        <div class="shop-name" id="shopName"></div>
        <div id="shopClock"></div>
    </div>

</div>
<?php if (SOUND_ENABLED): ?>
<div id="audioBanner" style="
    position:fixed;bottom:0;left:0;right:0;z-index:990;
    background:rgba(0,0,0,0.72);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
    color:#fff;text-align:center;padding:16px 20px;
    font-size:17px;font-weight:700;letter-spacing:.5px;
    cursor:pointer;user-select:none;
    transition:opacity .35s,transform .35s;
    display:flex;align-items:center;justify-content:center;gap:10px">
    <span style="font-size:22px;animation:bannerPulse 1.4s ease-in-out infinite">🔔</span>
    <span>แตะหน้าจอเพื่อเปิดเสียง</span>
</div>
<style>
@keyframes bannerPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.65;transform:scale(1.18)}}
</style>
<?php endif; ?>
<script>
(function () {
    var REFRESH_MS    = <?php echo (int)QUEUE_REFRESH_MS; ?>;
    var SOUND_ENABLED   = <?php echo SOUND_ENABLED ? 'true' : 'false'; ?>;
    var SOUND_VOLUME    = <?php echo (int)SOUND_VOLUME; ?> / 100;
    var SOUND_TYPE      = <?php echo json_encode(SOUND_TYPE); ?>;
    var SOUND_BEEP_TONE = <?php echo json_encode(SOUND_BEEP_TONE); ?>;
    var SOUND_FILE_URL  = <?php echo json_encode(SOUND_FILE !== '' ? SOUND_FILE : ''); ?>;
    var SHOW_COMPUTER   = <?php echo SHOW_COMPUTER_NAME ? 'true' : 'false'; ?>;
    var prevLatest      = null;
    var prevReady       = [];
    var prevPreparing   = [];
    var flashTimer      = null;
    var failCount       = 0;
    var MAX_FAILS       = 10;
    var fetchInFlight   = false;
    var audioCtx        = null;
    var audioUnlocked   = false;

    var audioBanner = document.getElementById('audioBanner');

    function hideBanner() {
        if (!audioBanner) return;
        audioBanner.style.opacity   = '0';
        audioBanner.style.transform = 'translateY(100%)';
        setTimeout(function () { if (audioBanner) audioBanner.style.display = 'none'; }, 380);
    }

    function initAudio() {
        if (!audioCtx) {
            try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {}
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume().then(function () {
                if (audioCtx.state === 'running') { audioUnlocked = true; hideBanner(); }
            }).catch(function () {});
        }
        if (audioCtx && audioCtx.state === 'running' && !audioUnlocked) {
            audioUnlocked = true;
            hideBanner();
        }
    }

    function onUserGesture() {
        initAudio();
        if (!audioUnlocked) {
            audioUnlocked = true;
            hideBanner();
        }
    }

    function playSound(queueLabel) {
        if (!SOUND_ENABLED) return;
        if (SOUND_TYPE === 'tts') { playTTS(queueLabel); return; }
        if (SOUND_TYPE === 'file') { playFile(); return; }
        playBeep();
    }

    function _tone(f1, f2, dur, at) {
        var o = audioCtx.createOscillator();
        var g = audioCtx.createGain();
        o.connect(g); g.connect(audioCtx.destination);
        o.frequency.setValueAtTime(f1, at);
        if (f2 !== f1) o.frequency.setValueAtTime(f2, at + dur * 0.3);
        var safeVol = SOUND_VOLUME > 0 ? SOUND_VOLUME : 0.01;
        g.gain.setValueAtTime(0.5 * safeVol, at);
        g.gain.exponentialRampToValueAtTime(0.001, at + dur);
        o.start(at);
        o.stop(at + dur);
    }

    function playBeep() {
        if (!SOUND_ENABLED || !audioCtx || audioCtx.state !== 'running') return;
        if (SOUND_VOLUME <= 0) return;
        try {
            var now = audioCtx.currentTime;
            if (SOUND_BEEP_TONE === 'double')    { _tone(1000, 1000, 0.18, now); _tone(1000, 1000, 0.18, now + 0.28); }
            else if (SOUND_BEEP_TONE === 'low')  { _tone(440, 330, 0.6, now); }
            else                                 { _tone(880, 660, 0.45, now); }
        } catch (e) {}
    }

    function playTTS(text) {
        if (!SOUND_ENABLED || !window.speechSynthesis || !text) return;
        try {
            var label = text.startsWith('โต๊ะ ')
                ? text
                : 'คิว ' + (/^\d+$/.test(text) ? String(parseInt(text, 10)) : text);
            var vol   = SOUND_VOLUME > 0 ? SOUND_VOLUME : 0.01;
            var u1    = new SpeechSynthesisUtterance(label);
            var u2    = new SpeechSynthesisUtterance('พร้อมเสิร์ฟ');
            u1.lang = u2.lang = 'th-TH';
            u1.volume = u2.volume = vol;
            u1.rate = u2.rate = 0.85;
            u1.pitch = 1.1;
            u2.pitch = 1.0;
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(u1);
            window.speechSynthesis.speak(u2);
        } catch (e) {}
    }

    function playFile() {
        if (!SOUND_ENABLED || !SOUND_FILE_URL) return;
        try {
            var audio    = new Audio(SOUND_FILE_URL);
            audio.volume = SOUND_VOLUME > 0 ? SOUND_VOLUME : 0.01;
            audio.play().catch(function () {});
        } catch (e) {}
    }

    window._initQueueAudio = onUserGesture;
    document.addEventListener('click',      onUserGesture);
    document.addEventListener('touchstart', onUserGesture, { passive: true });
    if (audioBanner) audioBanner.addEventListener('click', onUserGesture);

    initAudio();

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function elapsedText(dtStr) {
        if (!dtStr) return '';
        var t = new Date(dtStr.replace(' ', 'T') + '+07:00');
        if (isNaN(t.getTime())) return '';
        var mins = Math.floor((Date.now() - t.getTime()) / 60000);
        if (mins < 0)  return '';
        if (mins < 1)  return '< 1 นาที';
        if (mins < 60) return mins + ' นาที';
        var h = Math.floor(mins / 60);
        var m = mins % 60;
        return h + ' ชม.' + (m ? ' ' + m + ' น.' : '');
    }

    function renderGrid(el, items, opts) {
        opts = opts || {};
        var prevSet = {};
        (opts.prev || []).forEach(function (q) { prevSet[q] = true; });
        var latestQ = opts.latest || '';
        var times   = opts.times  || [];
        if (!items || !items.length) {
            el.innerHTML = '<div class="q-empty">ไม่มีรายการ</div>';
            return;
        }
        el.innerHTML = items.map(function (q, i) {
            var cls = 'q-num';
            if (!prevSet[q])   cls += ' q-new';
            if (q === latestQ) cls += ' q-latest';
            var inner = '<div class="' + cls + '">' + esc(q) + '</div>';
            if (times[i]) inner += '<div class="q-time">' + esc(elapsedText(times[i])) + '</div>';
            return '<div class="q-item">' + inner + '</div>';
        }).join('');
    }

    function showFetchError(msg) {
        var el = document.getElementById('fetchError');
        el.textContent = msg;
        el.style.display = 'block';
    }
    function hideFetchError() {
        document.getElementById('fetchError').style.display = 'none';
    }

    function showSetupError(d) {
        var steps = (d.steps || []).map(function (s) {
            return '<li>' + esc(s) + '</li>';
        }).join('');
        var el = document.getElementById('setupError');
        el.innerHTML =
            '<div class="se-icon">&#9888;</div>' +
            '<div class="se-title">ต้องตั้งค่าระบบก่อนใช้งาน</div>' +
            '<div class="se-error">' + esc(d.error || '') + '</div>' +
            (d.detail ? '<div class="se-detail">' + esc(d.detail) + '</div>' : '') +
            (steps ? '<ol class="se-steps">' + steps + '</ol>' : '') +
            '<div class="se-retry">ระบบจะตรวจสอบซ้ำอัตโนมัติทุก ' + Math.round(REFRESH_MS / 1000) + ' วินาที</div>';
        el.style.display = 'flex';
        document.getElementById('app').style.display = 'none';
    }

    function hideSetupError() {
        document.getElementById('setupError').style.display = 'none';
        document.getElementById('app').style.display = 'flex';
    }

    function fetchQueue() {
        if (fetchInFlight) return;
        fetchInFlight = true;
        fetch('api_queue.php?_=' + Date.now())
            .then(function (r) { return r.json(); })
            .then(function (d) {
                fetchInFlight = false;
                if (!d.success) {
                    if (d.setup_required) { showSetupError(d); return; }
                    showFetchError('ข้อผิดพลาด: ' + (d.error || 'ไม่สามารถดึงข้อมูลได้'));
                    return;
                }
                hideSetupError();
                hideFetchError();
                failCount = 0;
                sessionStorage.removeItem('qdReloads');

                var badge = document.getElementById('compBadge');
                if (SHOW_COMPUTER && d.computer_name) {
                    badge.textContent = d.computer_name;
                    badge.style.display = 'block';
                }
                if (d.shop_name) {
                    document.getElementById('shopName').textContent = d.shop_name;
                }

                var readyArr  = d.ready            || [];
                var prepArr   = d.preparing        || [];
                var prepTimes = d.preparing_times  || [];
                var latest    = d.latest_ready     || '';

                var readyGridEl = document.getElementById('readyGrid');
                var prepGridEl  = document.getElementById('preparingGrid');
                // ── Render grids ──────────────────────────────────────────
                renderGrid(readyGridEl, readyArr, { prev: prevReady,     latest: latest });
                renderGrid(prepGridEl,  prepArr,  { prev: prevPreparing, times:  prepTimes });
                prevReady     = readyArr;
                prevPreparing = prepArr;

                // ── Latest READY announce ─────────────────────────────────
                var latestWrap = document.querySelector('.latest-wrap');
                var latestEl   = document.getElementById('latestNum');
                if (latest) {
                    latestWrap.style.display = 'flex';
                    latestEl.textContent = latest;
                } else {
                    latestWrap.style.display = 'none';
                }

                if (latest && prevLatest !== null && latest !== prevLatest) {
                    if (flashTimer) { clearTimeout(flashTimer); flashTimer = null; }
                    latestEl.classList.remove('flash');
                    void latestEl.offsetWidth;
                    latestEl.classList.add('flash');
                    flashTimer = setTimeout(function () { latestEl.classList.remove('flash'); flashTimer = null; }, 2400);
                    playSound(latest);
                }
                prevLatest = latest;
            })
            .catch(function (e) {
                fetchInFlight = false;
                console.warn('queue fetch error', e);
                showFetchError('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');
                failCount++;
                if (failCount >= MAX_FAILS) {
                    var reloads = parseInt(sessionStorage.getItem('qdReloads') || '0', 10);
                    if (reloads < 3) {
                        sessionStorage.setItem('qdReloads', String(reloads + 1));
                        setTimeout(function () { location.reload(); }, 1500);
                    }
                }
            });
    }

    fetchQueue();
    setInterval(fetchQueue, REFRESH_MS);
}());

(function () {
    var clicks = [];
    document.getElementById('settingsTrigger').addEventListener('click', function () {
        var now = Date.now();
        clicks = clicks.filter(function (t) { return now - t < 1500; });
        clicks.push(now);
        if (clicks.length >= 3) { clicks = []; window.location.href = 'settings.php'; }
    });
}());

(function () {
    var overlay  = document.getElementById('fsOverlay');
    var btn      = document.getElementById('fsBtn');
    var cntEl    = document.getElementById('fsCountdown');
    var hideTimer = null;
    var cntTimer  = null;
    var cntVal    = 8;

    var SVG_EXPAND   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>';
    var SVG_COMPRESS = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>';

    function isFs() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }

    function enterFs() {
        var el = document.documentElement;
        if (el.requestFullscreen)            el.requestFullscreen();
        else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
    }

    function exitFs() {
        if (document.exitFullscreen)              document.exitFullscreen();
        else if (document.webkitExitFullscreen)   document.webkitExitFullscreen();
    }

    function dismissOverlay() {
        clearInterval(cntTimer);
        overlay.classList.add('fs-hidden');
        if (window._initQueueAudio) window._initQueueAudio();
    }

    function updateBtn() {
        btn.innerHTML = isFs() ? SVG_COMPRESS : SVG_EXPAND;
        btn.title     = isFs() ? 'ออกจากเต็มจอ' : 'แสดงผลเต็มจอ';
    }

    if (isFs()) {
        overlay.style.display = 'none';
    } else {
        cntTimer = setInterval(function () {
            cntVal--;
            if (cntEl) cntEl.textContent = cntVal;
            if (cntVal <= 0) dismissOverlay();
        }, 1000);
    }

    overlay.addEventListener('click', function () { enterFs(); dismissOverlay(); });
    btn.addEventListener('click', function (e) { e.stopPropagation(); if (isFs()) exitFs(); else enterFs(); });

    document.addEventListener('mousemove', function () {
        btn.classList.add('fs-visible');
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () { btn.classList.remove('fs-visible'); }, 3000);
    });

    document.addEventListener('fullscreenchange', updateBtn);
    document.addEventListener('webkitfullscreenchange', updateBtn);

    updateBtn();
}());

/* ── Wake Lock: ป้องกันหน้าจอดับ ── */
(function () {
    var wl = null;
    function acquire() {
        if (!navigator.wakeLock) return;
        navigator.wakeLock.request('screen')
            .then(function (lock) { wl = lock; })
            .catch(function () {});
    }
    acquire();
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') acquire();
    });
}());

/* ── Reload อัตโนมัติ: ตอนตี 0 วันใหม่ ── */
(function () {
    var startDay = new Date().getDate();
    setInterval(function () {
        if (new Date().getDate() !== startDay) { location.reload(); }
    }, 60000);
}());

/* ── นาฬิกาปัจจุบัน (มุมขวาล่าง) ── */
(function () {
    var el = document.getElementById('shopClock');
    function tick() {
        var d = new Date();
        var h = ('0' + d.getHours()).slice(-2);
        var m = ('0' + d.getMinutes()).slice(-2);
        var s = ('0' + d.getSeconds()).slice(-2);
        el.textContent = h + ':' + m + ':' + s;
    }
    tick();
    setInterval(tick, 1000);
}());
</script>
</body>
</html>
