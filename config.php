<?php
// Queue Display — config (no session, no auth)
function loadLocalSettings()
{
    $file = getSettingsLocalFilePath();
    if (!is_file($file)) return array();
    $settings = require $file;
    return is_array($settings) ? $settings : array();
}

function localSetting(array $settings, $key, $default)
{
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

// อนุญาตเฉพาะค่าสีที่ปลอดภัยใน CSS เพื่อป้องกัน CSS injection ผ่าน settings.local.php
function validateCssColor($value, $default)
{
    $v = trim((string)$value);
    // #RGB, #RRGGBB, #RRGGBBAA
    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)) return $v;
    // named colors (letters only, max 30 chars)
    if (preg_match('/^[a-zA-Z]{1,30}$/', $v)) return $v;
    // rgb() / rgba() / hsl() / hsla() — digits, commas, spaces, %, dots only inside
    if (preg_match('/^(rgb|rgba|hsl|hsla)\([\d,.\s%]+\)$/i', $v)) return $v;
    return $default;
}

$__localSettings = loadLocalSettings();

define('APP_TITLE',        'Queue Display');
define('APP_TIMEZONE',     'Asia/Bangkok');
define('QUEUE_REFRESH_MS',       max(2000, (int)localSetting($__localSettings, 'queue_refresh_ms', 5000)));
define('READY_LIMIT',            (int)localSetting($__localSettings, 'ready_limit',            30));
define('PREPARING_LIMIT',        (int)localSetting($__localSettings, 'preparing_limit',        30));
define('GRID_COLUMNS',           (int)localSetting($__localSettings, 'grid_columns',           2));
define('READY_DISPLAY_MINUTES',  (int)localSetting($__localSettings, 'ready_display_minutes',  40));
define('BG_IMAGE',            (string)localSetting($__localSettings, 'bg_image',              ''));
define('COLOR_HEADER_BG',     validateCssColor(localSetting($__localSettings, 'color_header_bg',   '#1a1a2e'), '#1a1a2e'));
define('COLOR_HEADER_TEXT',   validateCssColor(localSetting($__localSettings, 'color_header_text', '#ffffff'), '#ffffff'));
define('COLOR_QUEUE_TEXT',    validateCssColor(localSetting($__localSettings, 'color_queue_text',  '#1a1a2e'), '#1a1a2e'));
define('COLOR_APP_BG',        validateCssColor(localSetting($__localSettings, 'color_app_bg',      '#ffffff'), '#ffffff'));
define('QUEUE_COMPUTER_ID',      (int)localSetting($__localSettings, 'computer_id',           0));
define('SHOP_PRODUCT_LEVEL_ID',  (int)localSetting($__localSettings, 'product_level_id',       0));
define('SOUND_ENABLED',          (bool)(int)localSetting($__localSettings, 'sound_enabled',     1));
define('SOUND_VOLUME',           max(0, min(100, (int)localSetting($__localSettings, 'sound_volume',  70))));
define('SOUND_TYPE',      (string)localSetting($__localSettings, 'sound_type',      'beep'));
define('SOUND_BEEP_TONE', (string)localSetting($__localSettings, 'sound_beep_tone', 'ding'));
define('SOUND_FILE',      (string)localSetting($__localSettings, 'sound_file',      ''));
define('SHOW_COMPUTER_NAME',     (bool)(int)localSetting($__localSettings, 'show_computer_name', 1));

define('KDS_ENV_DB_HOST', 'KDS_DB_HOST');
define('KDS_ENV_DB_PORT', 'KDS_DB_PORT');
define('KDS_ENV_DB_NAME', 'KDS_DB_NAME');
define('KDS_ENV_DB_USER', 'KDS_DB_USER');
define('KDS_ENV_DB_PASS', 'KDS_DB_PASS');

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set(APP_TIMEZONE);
}

function getLocalSettings()
{
    static $settings = null;
    if ($settings === null) $settings = loadLocalSettings();
    return $settings;
}

function getSettingsLocalFilePath()
{
    $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? (string)$_SERVER['SCRIPT_FILENAME'] : '';
    if ($scriptFile !== '') {
        $scriptDir = dirname(realpath($scriptFile) ?: $scriptFile);
        $localPath = $scriptDir . DIRECTORY_SEPARATOR . 'settings.local.php';
        if (is_file($localPath)) return $localPath;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . 'settings.local.php';
}

function getDbConfig()
{
    static $dbConfig = null;
    if ($dbConfig !== null) return $dbConfig;

    $envConfig = array(
        'host' => getenv(KDS_ENV_DB_HOST) ?: '',
        'port' => getenv(KDS_ENV_DB_PORT) ?: '',
        'name' => getenv(KDS_ENV_DB_NAME) ?: '',
        'user' => getenv(KDS_ENV_DB_USER) ?: '',
        'pass' => getenv(KDS_ENV_DB_PASS) ?: '',
    );
    if ($envConfig['host'] !== '' && $envConfig['name'] !== '' && $envConfig['user'] !== '') {
        $dbConfig = normalizeDbConfig($envConfig);
        return $dbConfig;
    }

    $local = getLocalSettings();
    $dbConfig = normalizeDbConfig(array(
        'host' => localSetting($local, 'db_host', ''),
        'port' => localSetting($local, 'db_port', 3307),
        'name' => localSetting($local, 'db_name', ''),
        'user' => localSetting($local, 'db_user', ''),
        'pass' => localSetting($local, 'db_pass', ''),
    ));
    return $dbConfig;
}

function normalizeDbConfig($config)
{
    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port']  ?? 3307);
    $name = trim((string)($config['name'] ?? ''));
    $user = trim((string)($config['user'] ?? ''));
    $pass = (string)($config['pass'] ?? '');
    if ($host === '' || $name === '' || $user === '') {
        throw new Exception('DB config incomplete: host/name/user required');
    }
    if ($port <= 0) $port = 3307;
    return compact('host', 'port', 'name', 'user', 'pass');
}

function getDbConnection()
{
    $db   = getDbConfig();
    $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name'], (int)$db['port']);
    if ($conn->connect_error) throw new Exception('DB connection failed: ' . $conn->connect_error);
    if (!$conn->set_charset('utf8')) throw new Exception('Cannot set charset utf8');
    $conn->query("SET time_zone = '+07:00'");
    return $conn;
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse($payload)
{
    while (ob_get_level()) ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $body = json_encode($payload, $flags);
    echo $body !== false ? $body : '{"success":false,"error":"json encode error"}';
    exit;
}
