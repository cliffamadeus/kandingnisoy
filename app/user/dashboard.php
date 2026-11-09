<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/goat-functions.php';
require_once '../../includes/weather-forecast.php';

requireRole('user');

$userId = $_SESSION['user_id'] ?? 0;

// ============================================================
// CACHE CONFIGURATION
// ============================================================
define('DASHBOARD_CACHE_VERSION', 'v16');
define('WEATHER_CACHE_TTL', 600);
define('GOATS_TABLE_CACHE_TTL', 3600);

// ============================================================
// CACHE HELPERS
// ============================================================
function cacheGet(string $key, ?int $ttl = null)
{
    if (!isset($_SESSION['_cache'][$key])) return null;
    $entry = $_SESSION['_cache'][$key];
    if ($ttl !== null && $entry['expires_at'] < time()) {
        unset($_SESSION['_cache'][$key]);
        return null;
    }
    return $entry['value'];
}

function cacheSet(string $key, $value, int $ttl): void
{
    $_SESSION['_cache'][$key] = [
        'value'      => $value,
        'expires_at' => time() + $ttl,
    ];
}

// ============================================================
// WEATHER FORECAST
// ============================================================
$weatherCacheKey = 'dashboard_weather_' . DASHBOARD_CACHE_VERSION;
$weatherForecast = cacheGet($weatherCacheKey, WEATHER_CACHE_TTL);
if ($weatherForecast === null) {
    $weatherForecast = getFarmWeatherForecast();
    cacheSet($weatherCacheKey, $weatherForecast, WEATHER_CACHE_TTL);
}

// ============================================================
// FARMING TIPS
// ============================================================
$tipsCacheKey = 'dashboard_tips_' . DASHBOARD_CACHE_VERSION;
$farmingTips = cacheGet($tipsCacheKey, WEATHER_CACHE_TTL);
if ($farmingTips === null) {
    $farmingTips = getGoatFarmingTips($weatherForecast);
    if (!is_array($farmingTips)) $farmingTips = [];
    $farmingTips = array_slice($farmingTips, 0, 5);
    cacheSet($tipsCacheKey, $farmingTips, WEATHER_CACHE_TTL);
}

// ============================================================
// GOAT INVENTORY
// ============================================================
$goatsTableCacheKey = 'dashboard_goats_table_' . DASHBOARD_CACHE_VERSION;
$goatsTableExists = cacheGet($goatsTableCacheKey, GOATS_TABLE_CACHE_TTL);
if ($goatsTableExists === null) {
    try {
        $goatsTableExists = (bool) $pdo->query("SHOW TABLES LIKE 'goats'")->fetch();
    } catch (PDOException $e) {
        error_log('[Dashboard] Table check failed: ' . $e->getMessage());
        $goatsTableExists = false;
    }
    cacheSet($goatsTableCacheKey, $goatsTableExists, GOATS_TABLE_CACHE_TTL);
}

$dashboardGoats = [];
$totalGoats = 0;
if ($goatsTableExists) {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, goat_id, goat_name, status, health_status, photo_path, updated_at
             FROM goats
             WHERE user_id = ?
             ORDER BY updated_at DESC
             LIMIT 4"
        );
        $stmt->execute([$userId]);
        $dashboardGoats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[Dashboard] Goat list failed: ' . $e->getMessage());
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM goats WHERE user_id = ?");
        $stmt->execute([$userId]);
        $totalGoats = (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[Dashboard] Total count failed: ' . $e->getMessage());
    }
}

// ============================================================
// DISPLAY NAME
// ============================================================
$rawEmail = $_SESSION['email'] ?? 'Farmer';
$displayName = mb_strlen($rawEmail) > 20 ? mb_substr($rawEmail, 0, 17) . '…' : $rawEmail;

// ============================================================
// INLINE SVG ICON RENDERER
// ============================================================
function icon(string $name, int $size = 16, string $class = '', string $strokeWidth = '2'): string
{
    static $paths = [
        'sun'             => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon'            => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'cloud'           => '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>',
        'cloud-sun'       => '<path d="M12 2v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="M20 12h2"/><path d="m19.07 4.93-1.41 1.41"/><path d="M15.947 12.65a4 4 0 0 0-5.925-4.128"/><path d="M13 22H7a5 5 0 1 1 4.9-6H13a3 3 0 0 1 0 6Z"/>',
        'cloud-moon'      => '<path d="M13 16a3 3 0 1 1 0 6H7a5 5 0 1 1 4.9-6Z"/><path d="M10.1 9A6 6 0 0 1 16 4a4.24 4.24 0 0 0 6 6 6 6 0 0 1-3 5.197"/>',
        'cloud-rain'      => '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M16 14v6"/><path d="M8 14v6"/><path d="M12 16v6"/>',
        'cloud-drizzle'   => '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M8 19v1"/><path d="M8 14v1"/><path d="M16 19v1"/><path d="M16 14v1"/><path d="M12 21v1"/><path d="M12 16v1"/>',
        'cloud-fog'       => '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M16 17H7"/><path d="M17 21H9"/>',
        'cloud-lightning' => '<path d="M6 16.326A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 .5 8.973"/><path d="m13 12-3 5h4l-3 5"/>',
        'cloud-rain-wind' => '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m9.2 22 3-7"/><path d="m9 13-3 7"/><path d="m17 13-3 7"/>',
        'cloud-off'       => '<path d="m2 2 20 20"/><path d="M5.782 5.782A7 7 0 0 0 9 19h8.5a4.5 4.5 0 0 0 1.307-.193"/><path d="M21.532 16.5A4.5 4.5 0 0 0 17.5 10h-1.79A7.008 7.008 0 0 0 10 5.07"/>',
        'snowflake'       => '<line x1="2" x2="22" y1="12" y2="12"/><line x1="12" x2="12" y1="2" y2="22"/><path d="m20 16-4-4 4-4"/><path d="m4 8 4 4-4 4"/><path d="m16 4-4 4-4-4"/><path d="m8 20 4-4 4 4"/>',
        'wind'            => '<path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2"/><path d="M9.6 4.6A2 2 0 1 1 11 8H2"/><path d="M12.6 19.4A2 2 0 1 0 14 16H2"/>',
        'thermometer'     => '<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>',
        'sunrise'         => '<path d="M12 2v8"/><path d="m4.93 10.93 1.41 1.41"/><path d="M2 18h2"/><path d="M20 18h2"/><path d="m19.07 10.93-1.41 1.41"/><path d="M22 22H2"/><path d="m8 6 4-4 4 4"/><path d="M16 18a4 4 0 0 0-8 0"/>',
        'sunset'          => '<path d="M12 10V2"/><path d="m4.93 10.93 1.41 1.41"/><path d="M2 18h2"/><path d="M20 18h2"/><path d="m19.07 10.93-1.41 1.41"/><path d="M22 22H2"/><path d="m16 6-4 4-4-4"/><path d="M16 18a4 4 0 0 0-8 0"/>',
        'droplets'        => '<path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"/><path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"/>',
        'droplet'         => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
        'umbrella'        => '<path d="M22 12a10.06 10.06 0 0 0-20 0Z"/><path d="M12 12v8a2 2 0 0 0 4 0"/><path d="M12 2v1"/>',
        'gauge'           => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
        'eye'             => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'plus'            => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'minus'           => '<path d="M5 12h14"/>',
        'menu'            => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'x'               => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'chevron-right'   => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left'    => '<path d="m15 18-6-6 6-6"/>',
        'chevron-down'    => '<path d="m6 9 6 6 6-6"/>',
        'chevron-up'      => '<path d="m18 15-6-6-6 6"/>',
        'arrow-right'     => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'arrow-left'      => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'external-link'   => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'home'            => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'settings'        => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'calendar'        => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'calendar-days'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/>',
        'clock'           => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'timer'           => '<line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/>',
        'map-pin'         => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'map'             => '<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"/><path d="M15 5.764v15"/><path d="M9 3.236v15"/>',
        'paw-print'       => '<circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/>',
        'list'            => '<path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/>',
        'circle'          => '<circle cx="12" cy="12" r="10"/>',
        'tag'             => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'heart'           => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'heart-pulse'     => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>',
        'activity'        => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        'alert-circle'    => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
        'alert-triangle'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'help-circle'     => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'info'            => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'check'           => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle'    => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'lightbulb'       => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
        'sparkles'        => '<path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/>',
        'leaf'            => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'loader-circle'   => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
        'star'            => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'bookmark'        => '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>',
        'bell'            => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'user'            => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'log-out'         => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'trending-up'     => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'trending-down'   => '<polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/>',
        'goat'            => '<path d="M6 8c-1.5-1.5-2.5-3.5-2-5.5.8.2 2.2 1 3 2.2"/><path d="M18 8c1.5-1.5 2.5-3.5 2-5.5-.8.2-2.2 1-3 2.2"/><path d="M7 9a5 5 0 0 1 10 0v3a5 5 0 0 1-5 5 5 5 0 0 1-5-5V9Z"/><circle cx="10" cy="11" r=".8" fill="currentColor" stroke="none"/><circle cx="14" cy="11" r=".8" fill="currentColor" stroke="none"/><path d="M11 15h2"/><path d="M9 17v3"/><path d="M15 17v3"/><path d="M8 20h2"/><path d="M14 20h2"/>',
    ];

    $path = $paths[$name] ?? $paths['help-circle'];
    $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';

    return sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%2$s" stroke-linecap="round" stroke-linejoin="round"%3$s aria-hidden="true" style="display:inline-block;vertical-align:middle;flex-shrink:0;">%4$s</svg>',
        $size,
        htmlspecialchars($strokeWidth, ENT_QUOTES, 'UTF-8'),
        $classAttr,
        $path
    );
}

// ============================================================
// WEATHER ICONS — Match sa reference style
// ============================================================
function weatherIcon(string $name, int $size = 48, string $class = ''): string
{
    static $counter = 0;
    $counter++;
    $uid = 'wx' . $counter;

    $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
    $sizeStyle = 'display:block;width:' . $size . 'px;height:' . $size . 'px;flex-shrink:0;';

    $open  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="' . $size . '" height="' . $size . '"' . $classAttr . ' aria-hidden="true" style="' . $sizeStyle . '">';
    $close = '</svg>';
    $body = '';

    switch ($name) {
        case 'sun':
            $body = '
                <defs>
                    <radialGradient id="' . $uid . 'sunG" cx="50%" cy="45%" r="55%">
                        <stop offset="0%" stop-color="#FFF9C4"/>
                        <stop offset="30%" stop-color="#FFEB3B"/>
                        <stop offset="70%" stop-color="#FFC107"/>
                        <stop offset="100%" stop-color="#FF9800"/>
                    </radialGradient>
                </defs>
                <g stroke="#F57C00" stroke-width="3.5" stroke-linecap="round">
                    <line x1="32" y1="4"  x2="32" y2="14"/>
                    <line x1="32" y1="50" x2="32" y2="60"/>
                    <line x1="4"  y1="32" x2="14" y2="32"/>
                    <line x1="50" y1="32" x2="60" y2="32"/>
                    <line x1="12.2" y1="12.2" x2="19.3" y2="19.3"/>
                    <line x1="44.7" y1="44.7" x2="51.8" y2="51.8"/>
                    <line x1="12.2" y1="51.8" x2="19.3" y2="44.7"/>
                    <line x1="44.7" y1="19.3" x2="51.8" y2="12.2"/>
                </g>
                <circle cx="32" cy="32" r="16" fill="url(#' . $uid . 'sunG)"/>
                <circle cx="32" cy="32" r="16" fill="none" stroke="#F57C00" stroke-width="0.8" opacity="0.4"/>
            ';
            break;

        case 'moon':
            $body = '
                <defs>
                    <radialGradient id="' . $uid . 'moonG" cx="35%" cy="35%" r="65%">
                        <stop offset="0%" stop-color="#FFFFFF"/>
                        <stop offset="50%" stop-color="#FEF3C7"/>
                        <stop offset="100%" stop-color="#F59E0B"/>
                    </radialGradient>
                </defs>
                <path d="M42 8 a19 19 0 1 0 14 29 a14 14 0 1 1 -14 -29 z" fill="url(#' . $uid . 'moonG)"/>
                <circle cx="12" cy="14" r="1.5" fill="#FCD34D" opacity="0.9"/>
                <circle cx="20" cy="8" r="1" fill="#FCD34D" opacity="0.7"/>
                <circle cx="52" cy="16" r="1.2" fill="#FCD34D" opacity="0.8"/>
                <circle cx="58" cy="34" r="1" fill="#FCD34D" opacity="0.6"/>
                <circle cx="8" cy="42" r="1.3" fill="#FCD34D" opacity="0.7"/>
            ';
            break;

        case 'cloud-sun':
            $body = '
                <defs>
                    <radialGradient id="' . $uid . 'sunP" cx="50%" cy="45%" r="55%">
                        <stop offset="0%" stop-color="#FFF9C4"/>
                        <stop offset="30%" stop-color="#FFEB3B"/>
                        <stop offset="70%" stop-color="#FFC107"/>
                        <stop offset="100%" stop-color="#FF9800"/>
                    </radialGradient>
                    <linearGradient id="' . $uid . 'clW" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#FFFFFF"/>
                        <stop offset="60%" stop-color="#E2E8F0"/>
                        <stop offset="100%" stop-color="#94A3B8"/>
                    </linearGradient>
                </defs>
                <g stroke="#F57C00" stroke-width="3" stroke-linecap="round">
                    <line x1="22" y1="4"  x2="22" y2="11"/>
                    <line x1="4"  y1="22" x2="11" y2="22"/>
                    <line x1="8.6" y1="8.6" x2="13.6" y2="13.6"/>
                    <line x1="8.6" y1="35.4" x2="13.6" y2="30.4"/>
                    <line x1="35.4" y1="8.6" x2="30.4" y2="13.6"/>
                </g>
                <circle cx="22" cy="22" r="12" fill="url(#' . $uid . 'sunP)"/>
                <ellipse cx="38" cy="50" rx="18" ry="4" fill="rgba(15,23,42,0.12)"/>
                <path d="M22 48 a8 8 0 0 1 8 -8 a10 10 0 0 1 19 -2 a7 7 0 0 1 6 13 h-31 a7 7 0 0 1 -2 -3 z" fill="url(#' . $uid . 'clW)"/>
                <path d="M26 44 a6 6 0 0 1 6 -6 a8 8 0 0 1 14 -1" fill="rgba(255,255,255,0.6)"/>
            ';
            break;

        case 'cloud-moon':
            $body = '
                <defs>
                    <radialGradient id="' . $uid . 'moonP" cx="35%" cy="35%" r="65%">
                        <stop offset="0%" stop-color="#FFFFFF"/>
                        <stop offset="50%" stop-color="#FEF3C7"/>
                        <stop offset="100%" stop-color="#F59E0B"/>
                    </radialGradient>
                    <linearGradient id="' . $uid . 'clN" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#CBD5E1"/>
                        <stop offset="60%" stop-color="#94A3B8"/>
                        <stop offset="100%" stop-color="#475569"/>
                    </linearGradient>
                </defs>
                <path d="M32 6 a14 14 0 1 0 10 22 a10 10 0 1 1 -10 -22 z" fill="url(#' . $uid . 'moonP)"/>
                <circle cx="12" cy="12" r="1" fill="#FCD34D" opacity="0.8"/>
                <circle cx="52" cy="10" r="1" fill="#FCD34D" opacity="0.7"/>
                <ellipse cx="38" cy="50" rx="18" ry="4" fill="rgba(15,23,42,0.25)"/>
                <path d="M22 48 a8 8 0 0 1 8 -8 a10 10 0 0 1 19 -2 a7 7 0 0 1 6 13 h-31 a7 7 0 0 1 -2 -3 z" fill="url(#' . $uid . 'clN)"/>
            ';
            break;

        case 'cloud':
            $body = '
                <defs>
                    <linearGradient id="' . $uid . 'clG" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#E2E8F0"/>
                        <stop offset="50%" stop-color="#94A3B8"/>
                        <stop offset="100%" stop-color="#475569"/>
                    </linearGradient>
                </defs>
                <ellipse cx="34" cy="50" rx="22" ry="4" fill="rgba(15,23,42,0.15)"/>
                <path d="M10 46 a10 10 0 0 1 10 -12 a14 14 0 0 1 26 -2 a10 10 0 0 1 8 14 h-44 z" fill="url(#' . $uid . 'clG)"/>
                <path d="M16 42 a8 8 0 0 1 8 -9 a11 11 0 0 1 21 -1" fill="rgba(255,255,255,0.45)"/>
            ';
            break;

        case 'cloud-rain':
            $body = '
                <defs>
                    <linearGradient id="' . $uid . 'rainCl" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#94A3B8"/>
                        <stop offset="50%" stop-color="#475569"/>
                        <stop offset="100%" stop-color="#1E293B"/>
                    </linearGradient>
                    <linearGradient id="' . $uid . 'dropG" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#93C5FD"/>
                        <stop offset="100%" stop-color="#2563EB"/>
                    </linearGradient>
                </defs>
                <ellipse cx="34" cy="40" rx="20" ry="4" fill="rgba(15,23,42,0.2)"/>
                <path d="M10 36 a10 10 0 0 1 10 -12 a14 14 0 0 1 26 -2 a10 10 0 0 1 8 14 h-44 z" fill="url(#' . $uid . 'rainCl)"/>
                <path d="M16 32 a8 8 0 0 1 8 -9 a11 11 0 0 1 21 -1" fill="rgba(255,255,255,0.2)"/>
                <g stroke="url(#' . $uid . 'dropG)" stroke-width="3" stroke-linecap="round">
                    <line x1="18" y1="44" x2="16" y2="54"/>
                    <line x1="28" y1="46" x2="26" y2="56"/>
                    <line x1="38" y1="44" x2="36" y2="54"/>
                    <line x1="48" y1="46" x2="46" y2="56"/>
                </g>
            ';
            break;

        case 'cloud-drizzle':
            $body = '
                <defs>
                    <linearGradient id="' . $uid . 'drzCl" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#F1F5F9"/>
                        <stop offset="60%" stop-color="#CBD5E1"/>
                        <stop offset="100%" stop-color="#94A3B8"/>
                    </linearGradient>
                    <radialGradient id="' . $uid . 'drzDot" cx="40%" cy="35%" r="60%">
                        <stop offset="0%" stop-color="#BFDBFE"/>
                        <stop offset="100%" stop-color="#3B82F6"/>
                    </radialGradient>
                </defs>
                <ellipse cx="34" cy="40" rx="20" ry="4" fill="rgba(15,23,42,0.12)"/>
                <path d="M10 36 a10 10 0 0 1 10 -12 a14 14 0 0 1 26 -2 a10 10 0 0 1 8 14 h-44 z" fill="url(#' . $uid . 'drzCl)"/>
                <path d="M16 32 a8 8 0 0 1 8 -9 a11 11 0 0 1 21 -1" fill="rgba(255,255,255,0.55)"/>
                <g fill="url(#' . $uid . 'drzDot)">
                    <circle cx="20" cy="46" r="2"/>
                    <circle cx="30" cy="50" r="1.8"/>
                    <circle cx="40" cy="46" r="2"/>
                    <circle cx="46" cy="52" r="1.6"/>
                    <circle cx="24" cy="54" r="1.6"/>
                </g>
            ';
            break;

        case 'cloud-lightning':
            $body = '
                <defs>
                    <linearGradient id="' . $uid . 'stormCl" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#64748B"/>
                        <stop offset="50%" stop-color="#334155"/>
                        <stop offset="100%" stop-color="#0F172A"/>
                    </linearGradient>
                    <linearGradient id="' . $uid . 'bolt" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#FEF9C3"/>
                        <stop offset="40%" stop-color="#FACC15"/>
                        <stop offset="100%" stop-color="#F59E0B"/>
                    </linearGradient>
                </defs>
                <ellipse cx="34" cy="40" rx="20" ry="4" fill="rgba(15,23,42,0.35)"/>
                <path d="M10 36 a10 10 0 0 1 10 -12 a14 14 0 0 1 26 -2 a10 10 0 0 1 8 14 h-44 z" fill="url(#' . $uid . 'stormCl)"/>
                <path d="M16 32 a8 8 0 0 1 8 -9 a11 11 0 0 1 21 -1" fill="rgba(255,255,255,0.15)"/>
                <path d="M34 38 L22 56 L31 56 L26 64 L42 46 L34 46 L42 38 Z"
                      fill="url(#' . $uid . 'bolt)" stroke="#A16207" stroke-width="0.8" stroke-linejoin="round"/>
            ';
            break;

        case 'cloud-fog':
            $body = '
                <defs>
                    <linearGradient id="' . $uid . 'fogCl" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#E2E8F0"/>
                        <stop offset="100%" stop-color="#94A3B8"/>
                    </linearGradient>
                </defs>
                <path d="M14 26 a10 10 0 0 1 10 -12 a14 14 0 0 1 26 -2 a10 10 0 0 1 8 14 h-44 z" fill="url(#' . $uid . 'fogCl)"/>
                <rect x="8"  y="34" width="48" height="3" rx="1.5" fill="#94A3B8" opacity="0.8"/>
                <rect x="14" y="42" width="36" height="3" rx="1.5" fill="#94A3B8" opacity="0.7"/>
                <rect x="8"  y="50" width="48" height="3" rx="1.5" fill="#94A3B8" opacity="0.8"/>
            ';
            break;

        case 'snowflake':
            $body = '
                <defs>
                    <linearGradient id="' . $uid . 'snowCl" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#FFFFFF"/>
                        <stop offset="60%" stop-color="#E2E8F0"/>
                        <stop offset="100%" stop-color="#94A3B8"/>
                    </linearGradient>
                </defs>
                <ellipse cx="34" cy="40" rx="20" ry="4" fill="rgba(15,23,42,0.12)"/>
                <path d="M10 36 a10 10 0 0 1 10 -12 a14 14 0 0 1 26 -2 a10 10 0 0 1 8 14 h-44 z" fill="url(#' . $uid . 'snowCl)"/>
                <path d="M16 32 a8 8 0 0 1 8 -9 a11 11 0 0 1 21 -1" fill="rgba(255,255,255,0.7)"/>
                <g stroke="#60A5FA" stroke-width="2.2" stroke-linecap="round">
                    <line x1="20" y1="46" x2="20" y2="56"/>
                    <line x1="15" y1="51" x2="25" y2="51"/>
                    <line x1="32" y1="48" x2="32" y2="58"/>
                    <line x1="27" y1="53" x2="37" y2="53"/>
                    <line x1="44" y1="46" x2="44" y2="56"/>
                    <line x1="39" y1="51" x2="49" y2="51"/>
                </g>
            ';
            break;

        case 'cloud-rain-wind':
            $body = '
                <defs>
                    <linearGradient id="' . $uid . 'rwCl" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#94A3B8"/>
                        <stop offset="50%" stop-color="#475569"/>
                        <stop offset="100%" stop-color="#1E293B"/>
                    </linearGradient>
                    <linearGradient id="' . $uid . 'rwDrop" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#93C5FD"/>
                        <stop offset="100%" stop-color="#2563EB"/>
                    </linearGradient>
                </defs>
                <ellipse cx="34" cy="40" rx="20" ry="4" fill="rgba(15,23,42,0.25)"/>
                <path d="M10 36 a10 10 0 0 1 10 -12 a14 14 0 0 1 26 -2 a10 10 0 0 1 8 14 h-44 z" fill="url(#' . $uid . 'rwCl)"/>
                <path d="M16 32 a8 8 0 0 1 8 -9 a11 11 0 0 1 21 -1" fill="rgba(255,255,255,0.15)"/>
                <g stroke="url(#' . $uid . 'rwDrop)" stroke-width="3" stroke-linecap="round">
                    <line x1="22" y1="44" x2="16" y2="56"/>
                    <line x1="32" y1="46" x2="26" y2="58"/>
                    <line x1="42" y1="44" x2="36" y2="56"/>
                    <line x1="50" y1="46" x2="44" y2="58"/>
                </g>
            ';
            break;

        case 'cloud-off':
        default:
            $body = '
                <defs>
                    <linearGradient id="' . $uid . 'offG" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#E2E8F0"/>
                        <stop offset="100%" stop-color="#94A3B8"/>
                    </linearGradient>
                </defs>
                <path d="M14 42 a10 10 0 0 1 10 -12 a14 14 0 0 1 26 -2 a10 10 0 0 1 8 14 h-44 z" fill="url(#' . $uid . 'offG)" opacity="0.6"/>
                <line x1="14" y1="18" x2="50" y2="52" stroke="#475569" stroke-width="3.5" stroke-linecap="round"/>
            ';
            break;
    }

    return $open . $body . $close;
}

// ============================================================
// WEATHER CODE → ICON NAME MAPPING
// ============================================================
function weathercodeToIconName(int $code, bool $isNight): string
{
    if ($code >= 95)                 return 'cloud-lightning';
    if ($code >= 80 && $code <= 82)  return 'cloud-rain-wind';
    if ($code >= 71 && $code <= 77)  return 'snowflake';
    if ($code >= 61 && $code <= 67)  return 'cloud-rain';
    if ($code >= 51 && $code <= 57)  return 'cloud-drizzle';
    if ($code === 45 || $code === 48) return 'cloud-fog';
    if ($code === 3)                 return 'cloud';
    if ($code === 2)                 return $isNight ? 'cloud-moon' : 'cloud-sun';
    if ($code === 1)                 return $isNight ? 'moon' : 'sun';
    return $isNight ? 'moon' : 'sun';
}

// ============================================================
// OTHER HELPERS
// ============================================================
function resolvePhotoUrl(?string $photoPath): string
{
    $photoPath = trim((string) $photoPath);
    if ($photoPath === '') return '';
    $isAbsolute = preg_match('#^(https?:)?//#', $photoPath) || $photoPath[0] === '/';
    return $isAbsolute ? $photoPath : BASE_URL . '/app/goats/' . ltrim($photoPath, '/');
}

function healthTokens(string $status): array
{
    $map = [
        'healthy' => ['color' => '#22c55e', 'bg' => '#dcfce7', 'text' => '#166534', 'icon' => 'heart'],
        'sick'    => ['color' => '#ef4444', 'bg' => '#fee2e2', 'text' => '#b91c1c', 'icon' => 'alert-circle'],
    ];
    return $map[$status] ?? ['color' => '#94a3b8', 'bg' => '#f1f5f9', 'text' => '#475569', 'icon' => 'help-circle'];
}

$title = 'Dashboard — ' . $displayName;
renderHeader($title);
?>

<style>
    :root {
        --gf-primary: #16a34a;
        --gf-primary-dark: #14532d;
        --gf-primary-mid: #15803d;
        --gf-primary-light: #dcfce7;
        --gf-primary-lighter: #f0fdf4;
        --gf-success: #22c55e;
        --gf-warning: #f59e0b;
        --gf-danger: #ef4444;
        --gf-info: #3b82f6;
        --gf-indigo: #6366f1;
        --gf-purple: #7c3aed;
        --gf-bg: #f1f5f9;
        --gf-surface: #ffffff;
        --gf-text: #0f172a;
        --gf-text-secondary: #475569;
        --gf-text-muted: #94a3b8;
        --gf-border: #e2e8f0;
        --gf-border-strong: #cbd5e1;
        --gf-navbar-height: 68px;
        --gf-sidebar-width: 264px;
        --gf-radius-lg: 18px;
        --gf-radius: 14px;
        --gf-radius-sm: 10px;
        --gf-shadow-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
        --gf-shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
        --gf-shadow-md: 0 4px 12px rgba(15, 23, 42, 0.06), 0 2px 4px rgba(15, 23, 42, 0.04);
        --gf-shadow-lg: 0 12px 28px rgba(15, 23, 42, 0.10), 0 4px 10px rgba(15, 23, 42, 0.06);
        --gf-shadow-xl: 0 24px 48px rgba(15, 23, 42, 0.14);
        --gf-ease: cubic-bezier(0.4, 0, 0.2, 1);
        --gf-ease-out: cubic-bezier(0.16, 1, 0.3, 1);
    }

    *, *::before, *::after { box-sizing: border-box; }

    html, body {
        margin: 0; padding: 0;
        background: var(--gf-bg); color: var(--gf-text);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 15px; line-height: 1.5; overflow-x: hidden;
        -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
    }

    img, svg { max-width: 100%; height: auto; display: block; }
    button { font-family: inherit; }
    :focus-visible { outline: 2px solid var(--gf-primary); outline-offset: 2px; border-radius: 4px; }

    .gf-skip-link {
        position: absolute; top: -100px; left: 16px; z-index: 10000;
        padding: 10px 16px; background: var(--gf-primary-dark); color: #fff;
        text-decoration: none; border-radius: var(--gf-radius-sm);
        font-weight: 700; transition: top 0.2s var(--gf-ease);
    }
    .gf-skip-link:focus { top: 16px; }

    .main-content, .main-content .container {
        width: 100% !important; max-width: 100% !important; margin: 0 !important;
        padding: 0 !important; background: var(--gf-bg) !important; display: block !important;
    }

    /* NAVBAR */
    .gf-navbar {
        position: fixed; top: 0; left: 0; right: 0; height: var(--gf-navbar-height);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 24px; z-index: 1050; box-shadow: 0 2px 12px rgba(15, 23, 42, 0.15);
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }
    .gf-navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: #fff; flex-shrink: 0; }
    .gf-navbar-logo {
        width: 46px; height: 46px; border-radius: 13px;
        background: rgba(255, 255, 255, 0.14);
        backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.16);
        display: grid; place-items: center; font-size: 24px; flex-shrink: 0; overflow: hidden;
    }
    .gf-navbar-logo img { width: 100%; height: 100%; object-fit: contain; padding: 6px; }
    .gf-navbar-brand-text { display: flex; flex-direction: column; line-height: 1.1; }
    .gf-navbar-title { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
    .gf-navbar-sub { font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.65); text-transform: uppercase; letter-spacing: 0.12em; margin-top: 2px; }
    .gf-navbar-links { display: flex; align-items: center; gap: 4px; list-style: none; margin: 0; padding: 0; }
    .gf-navbar-links li a {
        display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px;
        border-radius: 10px; font-size: 14px; font-weight: 600;
        color: rgba(255, 255, 255, 0.82); text-decoration: none; transition: background 0.2s var(--gf-ease), color 0.2s var(--gf-ease);
        white-space: nowrap;
    }
    .gf-navbar-links li a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-navbar-links li a.active {
        background: #fff; color: var(--gf-primary-dark); font-weight: 700;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
    }
    .gf-navbar-links li a.logout { color: #fca5a5; }
    .gf-navbar-links li a.logout:hover { background: rgba(220, 38, 38, 0.18); color: #fff; }
    .gf-navbar-toggle {
        display: none; width: 42px; height: 42px; place-items: center;
        background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 10px; cursor: pointer; color: #fff; padding: 0; transition: background 0.15s var(--gf-ease);
    }
    .gf-navbar-toggle:hover { background: rgba(255, 255, 255, 0.2); }

    /* SIDEBAR */
    .gf-sidebar {
        position: fixed; top: 0; right: 0; bottom: 0; width: var(--gf-sidebar-width);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        padding: 24px 18px 40px; color: #fff; overflow-y: auto; -webkit-overflow-scrolling: touch;
        z-index: 1060; display: flex; flex-direction: column;
        transform: translateX(100%); transition: transform 0.32s var(--gf-ease-out);
        will-change: transform; box-shadow: -8px 0 32px rgba(0, 0, 0, 0.35);
    }
    body.gf-sidebar-open .gf-sidebar { transform: translateX(0); }
    .gf-sidebar-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 8px 22px; margin-bottom: 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.12); flex-shrink: 0; }
    .gf-sidebar-close { width: 36px; height: 36px; display: grid; place-items: center; background: rgba(255, 255, 255, 0.1); border: none; border-radius: 10px; color: #fff; cursor: pointer; padding: 0; transition: background 0.15s var(--gf-ease); }
    .gf-sidebar-close:hover { background: rgba(255, 255, 255, 0.2); }
    .gf-brand { display: flex; align-items: center; gap: 12px; }
    .gf-brand-icon { width: 46px; height: 46px; border-radius: 13px; background: rgba(255, 255, 255, 0.14); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); border: 1px solid rgba(255, 255, 255, 0.16); display: grid; place-items: center; font-size: 24px; flex-shrink: 0; }
    .gf-brand-text { font-size: 16px; font-weight: 800; line-height: 1.2; letter-spacing: -0.01em; }
    .gf-brand-sub { font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.65); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.1em; }
    .gf-nav-section { display: flex; flex-direction: column; gap: 4px; flex: 1; overflow-y: auto; }
    .gf-nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 11px; color: rgba(255, 255, 255, 0.82); font-size: 14px; font-weight: 600; text-decoration: none; transition: background 0.2s var(--gf-ease), color 0.2s var(--gf-ease), transform 0.15s var(--gf-ease); position: relative; flex-shrink: 0; }
    .gf-nav-link:hover { background: rgba(255, 255, 255, 0.1); color: #fff; transform: translateX(-2px); }
    .gf-nav-link.active { background: #fff; color: var(--gf-primary-dark); font-weight: 700; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18); }
    .gf-nav-link.active::before { content: ''; position: absolute; right: -18px; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: var(--gf-success); border-radius: 4px 0 0 4px; }
    .gf-nav-link.logout { color: #fca5a5; margin-top: 12px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.12); border-radius: 0; }
    .gf-nav-link.logout:hover { background: rgba(220, 38, 38, 0.15); color: #fff; border-radius: 11px; }

    /* MAIN */
    .gf-main { padding: calc(var(--gf-navbar-height) + 28px) clamp(20px, 3vw, 40px) 120px; min-height: 100vh; width: 100%; animation: gfFadeIn 0.4s var(--gf-ease-out); }
    @keyframes gfFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

    .gf-page-header { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 28px; flex-wrap: wrap; }
    .gf-page-header-left { display: flex; align-items: center; gap: 16px; min-width: 0; }
    .gf-page-header h1 { margin: 0; font-size: 25px; font-weight: 800; letter-spacing: -0.025em; line-height: 1.15; }
    .gf-page-header .gf-welcome { margin: 5px 0 0; font-size: 13px; color: var(--gf-text-muted); }
    .gf-page-header .gf-welcome strong { color: var(--gf-primary-dark); font-weight: 700; }
    .gf-date-badge { display: inline-flex; align-items: center; gap: 7px; padding: 9px 15px; border-radius: 999px; background: #fff; border: 1px solid var(--gf-border); font-size: 12px; font-weight: 700; color: var(--gf-text-secondary); white-space: nowrap; box-shadow: var(--gf-shadow-xs); }
    .gf-date-badge svg { color: var(--gf-success); }

    .gf-section { margin-bottom: 34px; }
    .gf-section-title { display: flex; align-items: center; gap: 10px; margin: 0 0 16px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gf-text-secondary); }
    .gf-section-title-icon { color: var(--gf-primary); background: var(--gf-primary-light); border-radius: 8px; width: 30px; height: 30px; display: inline-grid; place-items: center; }
    .gf-section-hint { margin-left: auto; font-size: 11px; color: var(--gf-text-secondary); text-transform: none; letter-spacing: 0; font-weight: 700; background: #fff; padding: 5px 12px; border-radius: 999px; border: 1px solid var(--gf-border); display: inline-flex; align-items: center; gap: 4px; box-shadow: var(--gf-shadow-xs); }

    /* Section head with action button */
    .gf-section-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
    .gf-section-head-left { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .gf-section-head .gf-section-title { margin: 0; font-size: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: var(--gf-text); display: block; }
    .gf-section-sub { font-size: 13px; color: var(--gf-text-muted); margin: 0; font-weight: 500; letter-spacing: -0.005em; }

    .gf-view-all-btn { display: inline-flex; align-items: center; justify-content: center; padding: 12px 24px; background: #15803d; color: #ffffff; border-radius: 10px; font-size: 14px; font-weight: 700; text-decoration: none; transition: all 0.2s var(--gf-ease); white-space: nowrap; box-shadow: 0 2px 6px rgba(21, 128, 61, 0.2); letter-spacing: -0.005em; border: none; cursor: pointer; }
    .gf-view-all-btn:hover { background: #166534; color: #ffffff; box-shadow: 0 4px 12px rgba(21, 128, 61, 0.3); transform: translateY(-1px); text-decoration: none; }
    .gf-view-all-btn:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(21, 128, 61, 0.2); }

    /* COMPACT WEATHER */
    .gf-wx-compact { display: grid; grid-template-columns: 1fr auto 1fr auto; align-items: stretch; border: 1.5px solid var(--gf-success); border-radius: var(--gf-radius); background: linear-gradient(180deg, #f0fdf4, #fff 60%); box-shadow: 0 4px 16px rgba(22, 163, 74, .1); overflow: hidden; min-height: 92px; }
    .gf-wx-side { display: grid; grid-template-columns: auto auto 1fr; align-items: center; gap: 12px; padding: 12px 16px; min-width: 0; }
    .gf-wx-side.day { background: linear-gradient(135deg, #fffbeb, #fff); }
    .gf-wx-side.night { background: linear-gradient(135deg, #eef2ff, #fff); }
    .gf-wx-period { display: inline-flex; flex-direction: column; align-items: center; gap: 3px; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; padding: 5px 8px; border-radius: 8px; flex-shrink: 0; }
    .gf-wx-side.day .gf-wx-period { color: #b45309; background: #fef3c7; }
    .gf-wx-side.night .gf-wx-period { color: #3730a3; background: #e0e7ff; }
    .gf-wx-icon-sm { width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center; background: rgba(148, 163, 184, .12); flex-shrink: 0; overflow: hidden; }
    .gf-wx-icon-sm svg { display: block; }
    .gf-wx-text { min-width: 0; }
    .gf-wx-cond { font-size: 13px; font-weight: 800; color: #1e293b; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gf-wx-stats { display: flex; gap: 8px; margin-top: 3px; font-size: 10px; font-weight: 700; color: #64748b; }
    .gf-wx-stats span { display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
    .gf-wx-stats svg { color: var(--gf-info); }
    .gf-wx-mid { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 12px 18px; background: linear-gradient(180deg, #f0fdf4, #dcfce7); border-left: 1px solid #86efac; border-right: 1px solid #86efac; text-align: center; min-width: 130px; }
    .gf-wx-date { font-size: 10px; font-weight: 700; color: var(--gf-primary-dark); text-transform: uppercase; letter-spacing: .04em; }
    .gf-wx-total { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 800; }
    .gf-wx-total svg { color: var(--gf-primary-dark); }
    .gf-live-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 999px; background: linear-gradient(135deg, var(--gf-success), var(--gf-primary)); color: #fff; font-size: 8px; font-weight: 800; letter-spacing: .08em; }
    .gf-live-dot { width: 5px; height: 5px; border-radius: 50%; background: #fff; animation: gfPulse 2s infinite; }
    @keyframes gfPulse { 0% { box-shadow: 0 0 0 0 rgba(255,255,255,.7); } 70% { box-shadow: 0 0 0 5px rgba(255,255,255,0); } 100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); } }
    .gf-wx-expand-sm { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; padding: 12px 14px; background: linear-gradient(135deg, var(--gf-primary), var(--gf-primary-dark)); color: #fff; border: none; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; cursor: pointer; transition: filter .2s; flex-shrink: 0; min-width: 68px; }
    .gf-wx-expand-sm:hover { filter: brightness(1.1); }

    /* WEATHER CARDS (modal) */
    .gf-weather-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; width: 100%; }
    .gf-weather-card { border: 1px solid #e5e9ef; border-radius: 12px; background: #fff; display: flex; flex-direction: column; overflow: hidden; min-width: 0; transition: all 0.2s var(--gf-ease); cursor: pointer; }
    .gf-weather-card:hover { border-color: #16a34a; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08); }
    .gf-weather-card:focus-visible { outline: 2px solid #16a34a; outline-offset: 2px; }
    .gf-weather-card.is-active { border-color: #16a34a; box-shadow: 0 8px 20px rgba(22, 163, 74, 0.15); }
    .gf-weather-card.today { border: 2px solid #16a34a; }
    .gf-weather-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 14px 14px 10px; }
    .gf-weather-day-block { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .gf-weather-head .day { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; line-height: 1.2; }
    .gf-weather-head .date { font-size: 10px; color: #94a3b8; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; }
    .gf-weather-card.today .gf-weather-head .day { color: #15803d; }
    .gf-weather-now-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; background: #16a34a; color: #fff; font-size: 9px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; flex-shrink: 0; }
    .gf-weather-period-block { padding: 12px 14px; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; flex: 1; }
    .gf-weather-period-block.day { background: #fefdf5; border-top: 1px solid #f1f5f9; }
    .gf-weather-period-block.night { background: #f8f9fd; border-top: 1px solid #f1f5f9; }
    .gf-period-label { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 9.5px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
    .gf-weather-period-block.day .gf-period-label { color: #92400e; background: #fef3c7; }
    .gf-weather-period-block.night .gf-period-label { color: #3730a3; background: #e0e7ff; }
    .gf-period-label svg { color: currentColor; }
    .gf-weather-icon-wrap { width: 48px; height: 48px; display: grid; place-items: center; margin: 0; overflow: visible; }
    .gf-weather-icon-wrap svg { display: block; }
    .gf-weather-desc { font-size: 12px; font-weight: 700; color: #1e293b; line-height: 1.3; min-height: 16px; }
    .gf-weather-stats { display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; margin-top: 2px; font-size: 11px; color: #64748b; font-weight: 600; }
    .gf-stat { display: inline-flex; align-items: center; gap: 3px; color: #64748b; }
    .gf-stat svg { color: #3b82f6; }
    .gf-weather-nodata { padding: 20px 0; color: #94a3b8; font-size: 11px; font-weight: 600; }
    .gf-weather-footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 14px; background: #ffffff; border-top: 1px solid #e5e9ef; margin-top: auto; }
    .gf-weather-card.today .gf-weather-footer { background: #f0fdf4; border-top-color: #bbf7d0; }
    .gf-footer-label { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #16a34a; }
    .gf-footer-label svg { color: #16a34a; }
    .gf-footer-value { font-size: 12.5px; font-weight: 800; color: #0f172a; letter-spacing: -0.01em; }

    /* WEATHER BUBBLE TOOLTIP */
    .gf-weather-bubble { position: fixed; z-index: 2100; max-width: 340px; background: #ffffff; border: 1px solid #e5e9ef; border-radius: 14px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18), 0 4px 12px rgba(15, 23, 42, 0.08); padding: 0; opacity: 0; visibility: hidden; transform: translateY(-6px); transition: opacity 0.18s var(--gf-ease), transform 0.18s var(--gf-ease), visibility 0.18s; pointer-events: none; }
    .gf-weather-bubble.is-visible { opacity: 1; visibility: visible; transform: translateY(0); pointer-events: auto; }
    .gf-weather-bubble .gf-bubble-arrow { position: absolute; width: 12px; height: 12px; background: #ffffff; border-left: 1px solid #e5e9ef; border-top: 1px solid #e5e9ef; transform: rotate(45deg); top: -7px; left: 24px; }
    .gf-weather-bubble[data-arrow="bottom"] .gf-bubble-arrow { top: auto; bottom: -7px; border-left: none; border-top: none; border-right: 1px solid #e5e9ef; border-bottom: 1px solid #e5e9ef; }
    .gf-bubble-content { padding: 14px 16px 12px; }
    .gf-bubble-header { padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; }
    .gf-bubble-day { font-size: 14px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.05em; line-height: 1.2; margin-bottom: 2px; }
    .gf-bubble-date { font-size: 11.5px; color: #94a3b8; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; }
    .gf-bubble-section { margin-bottom: 14px; }
    .gf-bubble-section:last-of-type { margin-bottom: 0; }
    .gf-bubble-section-title { display: flex; align-items: center; gap: 6px; font-size: 10.5px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px; }
    .gf-bubble-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
    .gf-bubble-dot.day-dot { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); }
    .gf-bubble-dot.night-dot { background: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15); }
    .gf-bubble-rows { display: flex; flex-direction: column; gap: 6px; }
    .gf-bubble-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 12.5px; line-height: 1.4; }
    .gf-bubble-label { color: #94a3b8; font-weight: 500; flex-shrink: 0; }
    .gf-bubble-value { color: #1e293b; font-weight: 700; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px; }
    .gf-bubble-footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding-top: 12px; margin-top: 12px; border-top: 1px solid #f1f5f9; }
    .gf-bubble-footer .gf-bubble-label { font-size: 11px; font-weight: 800; color: #16a34a; text-transform: uppercase; letter-spacing: 0.06em; }
    .gf-bubble-total { font-size: 14px; font-weight: 800; color: #0f172a; letter-spacing: -0.01em; }

    /* GOAT CARDS */
    .gf-goat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; width: 100%; }
    .gf-goat-card { border: 1px solid var(--gf-border); border-radius: var(--gf-radius); background: #fff; box-shadow: var(--gf-shadow-sm); display: flex; flex-direction: column; transition: transform 0.28s var(--gf-ease-out), box-shadow 0.28s var(--gf-ease-out), border-color 0.28s var(--gf-ease-out); text-decoration: none; color: inherit; position: relative; overflow: hidden; cursor: pointer; min-width: 0; }
    .gf-goat-card:hover { transform: translateY(-4px); box-shadow: var(--gf-shadow-lg); border-color: #86efac; color: inherit; }
    .gf-goat-card::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--goat-accent, var(--gf-success)); z-index: 2; }
    .gf-goat-media { display: flex; align-items: center; gap: 12px; padding: 18px 16px 12px; margin-top: 4px; min-width: 0; }
    .gf-goat-photo, .gf-goat-photo-placeholder { width: 56px; height: 56px; border-radius: var(--gf-radius-sm); flex-shrink: 0; border: 2px solid var(--gf-border); }
    .gf-goat-photo { object-fit: cover; background: linear-gradient(135deg, #f0fdf4, #dcfce7); }
    .gf-goat-photo-placeholder { background: linear-gradient(135deg, #f0fdf4, #dcfce7); display: grid; place-items: center; color: var(--gf-success); }
    .gf-goat-info { min-width: 0; flex: 1; }
    .gf-goat-id { color: var(--gf-primary); font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .gf-goat-name { margin-top: 3px; font-size: 15px; font-weight: 800; color: var(--gf-text); line-height: 1.25; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .gf-goat-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 0 16px 12px; }
    .gf-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 10px; font-weight: 800; line-height: 1.25; white-space: nowrap; text-transform: uppercase; letter-spacing: 0.03em; }
    .gf-badge.status { color: var(--gf-text-secondary); background: #f1f5f9; border: 1px solid var(--gf-border); }
    .gf-goat-date { padding: 0 16px 16px; color: var(--gf-text-muted); font-size: 11px; font-weight: 600; display: flex; align-items: center; gap: 5px; margin-top: auto; }

    /* TIPS */
    .gf-tips-box { border: 1px solid #86efac; border-radius: var(--gf-radius-lg); padding: 24px 26px; background: radial-gradient(circle at 100% 0%, rgba(34, 197, 94, 0.1), transparent 60%), linear-gradient(135deg, #f0fdf4 0%, #f6fcf7 100%); box-shadow: 0 4px 16px rgba(22, 163, 74, 0.08); position: relative; overflow: hidden; }
    .gf-tips-box::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: linear-gradient(180deg, var(--gf-success), var(--gf-primary-dark)); }
    .gf-tips-title { display: flex; align-items: center; gap: 10px; margin: 0 0 14px; padding-left: 6px; color: var(--gf-primary-dark); font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; }
    .gf-tips-list { list-style: none; margin: 0; padding: 0; }
    .gf-tip-item { display: flex; align-items: flex-start; gap: 12px; padding: 11px 6px; border-bottom: 1px solid #d1fae5; }
    .gf-tip-item:last-child { padding-bottom: 4px; border-bottom: none; }
    .gf-tip-item svg { flex-shrink: 0; margin-top: 2px; color: var(--gf-success); }
    .gf-tip-item span { flex: 1; color: #1e293b; font-size: 13.5px; line-height: 1.55; font-weight: 500; }

    /* EMPTY */
    .gf-empty { padding: 40px 24px; border: 2px dashed var(--gf-border-strong); border-radius: var(--gf-radius); background: #fff; text-align: center; color: var(--gf-text-secondary); }
    .gf-empty svg { margin: 0 auto 12px; color: #86efac; }
    .gf-empty p { margin: 0; font-size: 14px; font-weight: 600; }

    /* MODAL */
    .gf-modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
    .gf-modal-backdrop.is-open { display: flex; animation: gfFadeIn 0.2s var(--gf-ease); }
    .gf-modal { background: #fff; border-radius: var(--gf-radius-lg); box-shadow: var(--gf-shadow-xl); max-width: 1100px; width: 100%; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; animation: gfSlideUp 0.3s var(--gf-ease-out); }
    @keyframes gfSlideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .gf-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 20px 24px 16px; flex-shrink: 0; }
    .gf-modal-title { display: block; margin: 0; font-size: 18px; font-weight: 800; color: #0f172a; letter-spacing: -0.01em; }
    .gf-modal-sub { display: block; margin: 4px 0 0; font-size: 12.5px; color: #94a3b8; font-weight: 500; }
    .gf-modal-close { width: 36px; height: 36px; display: inline-grid; place-items: center; background: #f1f5f9; border: 1px solid transparent; border-radius: 10px; color: #475569; cursor: pointer; transition: background 0.2s var(--gf-ease), color 0.2s var(--gf-ease); flex-shrink: 0; padding: 0; line-height: 0; font-size: 0; }
    .gf-modal-close svg { width: 18px !important; height: 18px !important; stroke: currentColor !important; stroke-width: 2.5; display: block; margin: 0; }
    .gf-modal-close:hover { background: #fee2e2; color: #b91c1c; }
    .gf-modal-close:active { transform: scale(0.95); }
    .gf-modal-body { padding: 0 20px 24px; overflow-y: auto; -webkit-overflow-scrolling: touch; }

    /* FAB */
    .gf-fab-wrap { position: fixed; bottom: 32px; right: 32px; z-index: 1050; }
    .gf-fab-inner { position: relative; }
    .gf-fab-toggle { display: grid; place-items: center; width: 60px; height: 60px; background: linear-gradient(45deg, #16a34a, #14532d); color: #fff; position: absolute; border-radius: 50%; bottom: 0; right: 0; border: 5px solid #bbf7d0; transition: transform 0.4s var(--gf-ease-out), box-shadow 0.3s var(--gf-ease), background 0.3s var(--gf-ease); cursor: pointer; padding: 0; overflow: hidden; }
    .gf-fab-toggle:hover { box-shadow: 0 8px 24px rgba(22, 163, 74, 0.5); }
    .gf-fab-icon-wrap { display: inline-flex; align-items: center; justify-content: center; line-height: 1; transform: rotate(0deg); transition: transform 0.4s var(--gf-ease-out); width: 32px; height: 32px; }
    .gf-fab-icon-wrap img { width: 100%; height: 100%; object-fit: contain; display: block; pointer-events: none; filter: brightness(0) invert(1); }
    .gf-fab-wrap.is-open .gf-fab-icon-wrap { transform: rotate(0deg) scale(0.85); }
    .gf-fab-wrap.is-open .gf-fab-toggle { background: linear-gradient(45deg, #14532d, #0f3d24); }
    .gf-fab-menu { position: absolute; bottom: 68px; right: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; list-style: none; margin: 0; padding: 0; pointer-events: none; opacity: 0; transform: translateY(12px); transition: opacity 0.25s var(--gf-ease-out), transform 0.25s var(--gf-ease-out); }
    .gf-fab-wrap.is-open .gf-fab-menu { pointer-events: auto; opacity: 1; transform: translateY(0); }
    .gf-fab-menu li a, .gf-fab-menu li button { display: inline-flex; align-items: center; gap: 10px; padding: 9px 16px; background: #fff; color: #4a5e96; border-radius: 999px; white-space: nowrap; transition: all 0.25s var(--gf-ease); text-decoration: none; font-size: 13px; font-weight: 700; box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12); border: 1px solid var(--gf-border); cursor: pointer; font-family: inherit; }
    .gf-fab-menu li a:hover, .gf-fab-menu li button:hover { background: #ccd7f5; color: #2c3e70; transform: translateX(-4px); box-shadow: 0 6px 20px rgba(80, 124, 179, 0.25); }
    .gf-fab-menu li a svg, .gf-fab-menu li button svg { color: #6077b0; }

    .gf-backdrop { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(2px); z-index: 1055; cursor: pointer; }
    body.gf-sidebar-open .gf-backdrop { display: block; animation: gfFadeIn 0.2s var(--gf-ease); }

    /* RESPONSIVE */
    @media (max-width: 1200px) {
        .gf-weather-grid { gap: 10px; }
        .gf-weather-head { padding: 12px 12px 8px; }
        .gf-weather-head .day { font-size: 12px; }
        .gf-weather-period-block { padding: 10px 12px; }
        .gf-weather-desc { font-size: 11px; }
        .gf-stat { font-size: 10px; }
    }
    @media (max-width: 991.98px) {
        .gf-navbar { padding: 0 16px; }
        .gf-navbar-links { display: none; }
        .gf-navbar-toggle { display: grid; }
        .gf-main { padding-top: calc(var(--gf-navbar-height) + 20px); }
        .gf-goat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 767.98px) {
        .gf-navbar { height: 60px; padding: 0 12px; }
        .gf-navbar-logo { width: 38px; height: 38px; border-radius: 11px; font-size: 20px; }
        .gf-navbar-title { font-size: 17px; }
        .gf-navbar-sub { font-size: 9px; }
        .gf-main { padding: calc(var(--gf-navbar-height) + 14px) 16px 110px; }
        .gf-page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
        .gf-page-header h1 { font-size: 22px; }
        .gf-wx-compact { grid-template-columns: 1fr; min-height: 0; }
        .gf-wx-side { padding: 10px 12px; gap: 10px; grid-template-columns: auto auto 1fr; }
        .gf-wx-side.day { border-bottom: 1px solid var(--gf-border); }
        .gf-wx-side.night { border-top: 1px solid var(--gf-border); }
        .gf-wx-mid { order: -1; flex-direction: row; justify-content: space-between; padding: 8px 14px; border-left: none; border-right: none; border-bottom: 1px solid #86efac; min-width: 0; gap: 10px; }
        .gf-wx-date { font-size: 10px; }
        .gf-wx-total { font-size: 12px; }
        .gf-wx-expand-sm { flex-direction: row; padding: 11px; min-width: 0; font-size: 10px; gap: 6px; }
        .gf-wx-icon-sm { width: 42px; height: 42px; }
        .gf-wx-cond { font-size: 12px; }
        .gf-modal-body .gf-weather-grid { grid-template-columns: repeat(5, minmax(140px, 1fr)); overflow-x: auto; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; padding-bottom: 8px; gap: 10px; }
        .gf-modal-body .gf-weather-card { scroll-snap-align: start; }
        .gf-modal { max-height: 95vh; border-radius: var(--gf-radius); }
        .gf-modal-header { padding: 16px 18px 12px; }
        .gf-modal-title { font-size: 16px; }
        .gf-modal-body { padding: 0 16px 18px; }
        .gf-weather-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; }
        .gf-weather-card { border-radius: 10px; }
        .gf-weather-head { padding: 10px 8px 8px; }
        .gf-weather-head .day { font-size: 10px; }
        .gf-weather-head .date { font-size: 8px; }
        .gf-weather-now-badge { font-size: 7px; padding: 2px 6px; }
        .gf-weather-period-block { padding: 8px 6px; gap: 4px; }
        .gf-period-label { font-size: 7px; padding: 2px 6px; }
        .gf-weather-desc { font-size: 9px; }
        .gf-weather-icon-wrap { width: 36px; height: 36px; }
        .gf-weather-stats { font-size: 8px; gap: 4px; }
        .gf-weather-footer { padding: 6px 8px; }
        .gf-footer-label { font-size: 8px; }
        .gf-footer-value { font-size: 10px; }
        .gf-goat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .gf-goat-photo, .gf-goat-photo-placeholder { width: 46px; height: 46px; }
        .gf-goat-name { font-size: 13.5px; }
        .gf-goat-id { font-size: 10px; }
        .gf-tips-box { padding: 18px 20px; }
        .gf-tips-title { font-size: 12px; }
        .gf-tip-item span { font-size: 12.5px; }
        .gf-fab-wrap { bottom: 24px; right: 24px; }
        .gf-fab-toggle { width: 56px; height: 56px; border-width: 4px; }
        .gf-fab-icon-wrap { width: 28px; height: 28px; }
        .gf-fab-menu { bottom: 64px; }
        .gf-fab-menu li a, .gf-fab-menu li button { font-size: 12px; padding: 8px 14px; }
        .gf-weather-bubble { max-width: calc(100vw - 32px); }
        .gf-bubble-value { max-width: 140px; }
    }
    @media (max-width: 480px) {
        .gf-wx-side { padding: 9px 10px; gap: 8px; }
        .gf-wx-period { font-size: 8px; padding: 4px 6px; }
        .gf-wx-icon-sm { width: 38px; height: 38px; }
        .gf-wx-cond { font-size: 11px; }
        .gf-wx-stats { font-size: 9px; gap: 6px; }
        .gf-view-all-btn { width: 100%; padding: 12px 20px; }
        .gf-section-head .gf-section-title { font-size: 18px; }
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { transition-duration: 0.01ms !important; animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; }
    }
</style>

<a href="#main-content" class="gf-skip-link">Skip to main content</a>

<!-- NAVBAR -->
<nav class="gf-navbar" aria-label="Main navigation">
    <a href="<?php echo BASE_URL; ?>/app/user/dashboard.php" class="gf-navbar-brand">
        <div class="gf-navbar-logo">
              <?php if (file_exists(__DIR__ . '/../../assets/images/goat-logo.png')): ?>
                <img src="<?php echo BASE_URL; ?>/assets/images/goat-logo.png" alt="GoatPro Logo">
            <?php else: ?>
                <span aria-hidden="true">🐐</span>
            <?php endif; ?>
        </div>
        <div class="gf-navbar-brand-text">
            <span class="gf-navbar-title">GoatPro</span>
            
        </div>
    </a>

    <ul class="gf-navbar-links">
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'dashboard'); ?>
    </ul>

    <button class="gf-navbar-toggle" type="button" aria-label="Open menu"
            aria-controls="gfSidebar" aria-expanded="false" onclick="toggleSidebar();">
        <?php echo icon('menu', 22); ?>
    </button>
</nav>

<!-- SIDEBAR -->
<aside class="gf-sidebar" id="gfSidebar" aria-label="Main navigation">
    <div class="gf-sidebar-header">
        <div class="gf-brand">
            <div class="gf-brand-icon" aria-hidden="true">
                <?php if (file_exists(__DIR__ . '/../../assets/images/goat-logo.png')): ?>
                    <img src="<?php echo BASE_URL; ?>/assets/images/goat-logo.png" alt="" style="width:100%;height:100%;object-fit:contain;padding:5px;">
                <?php else: ?>
                    🐐
                <?php endif; ?>
            </div>
            <div>
                <div class="gf-brand-text">GoatPro</div>
                
            </div>
        </div>
        <button class="gf-sidebar-close" type="button" aria-label="Close menu" onclick="closeSidebar();">
            <?php echo icon('x', 18); ?>
        </button>
    </div>

    <nav class="gf-nav-section">
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'dashboard'); ?>
    </nav>
</aside>

<div class="gf-backdrop" onclick="closeSidebar();"></div>

<!-- MAIN -->
<div class="main-content" id="main-content">
    <div class="container">
        <main class="gf-main">

            <header class="gf-page-header">
                <div class="gf-page-header-left">
                    <div>
                        <h1>Dashboard</h1>
                        <p class="gf-welcome">
                            Welcome back, <strong><?php echo htmlspecialchars($displayName); ?></strong>
                        </p>
                    </div>
                </div>
                <span class="gf-date-badge">
                    <?php echo icon('calendar', 14); ?>
                    <?php echo date('M d, Y'); ?>
                </span>
            </header>

            <!-- WEATHER -->
            <section class="gf-section" aria-labelledby="weather-heading">
                <h2 class="gf-section-title" id="weather-heading">
                    Weather Forecast
                    <?php if ($weatherForecast && !empty($weatherForecast['location'])): ?>
                        <span class="gf-section-hint">
                            <?php echo icon('map-pin', 12); ?>
                            <?php echo htmlspecialchars($weatherForecast['location']); ?>
                        </span>
                    <?php endif; ?>
                </h2>

                <?php if ($weatherForecast && !empty($weatherForecast['days'])): ?>
                    <?php
                    $today     = $weatherForecast['days'][0];
                    $dateTs    = strtotime($today['date']);
                    $dayInfo   = $today['day']   ?? ['available' => false];
                    $nightInfo = $today['night'] ?? ['available' => false];
                    $dayText   = $dayInfo['available'] ? weatherDetailedLabel($dayInfo['weathercode'], $dayInfo['pop'], false) : null;
                    $nightText = $nightInfo['available'] ? weatherDetailedLabel($nightInfo['weathercode'], $nightInfo['pop'], true) : null;
                    $totalMm   = isset($today['total_mm']) ? (float) $today['total_mm'] : 0.0;
                    $dayIconName   = $dayInfo['available'] ? weathercodeToIconName($dayInfo['weathercode'], false) : 'sun';
                    $nightIconName = $nightInfo['available'] ? weathercodeToIconName($nightInfo['weathercode'], true) : 'moon';
                    $rainLevel = $totalMm < 1 ? 'none' : ($totalMm < 5 ? 'light' : ($totalMm < 15 ? 'moderate' : 'heavy'));
                    $rainColor = ['none' => '#94a3b8','light' => '#3b82f6','moderate' => '#2563eb','heavy' => '#1d4ed8'][$rainLevel];
                    ?>
                    <article class="gf-wx-compact">
                        <div class="gf-wx-side day">
                            <div class="gf-wx-period">
                                <?php echo icon('sun', 14); ?>
                                Day
                            </div>
                            <div class="gf-wx-icon-sm">
                                <?php echo weatherIcon($dayIconName, 46); ?>
                            </div>
                            <div class="gf-wx-text">
                                <div class="gf-wx-cond"><?php echo htmlspecialchars($dayText ?? '—'); ?></div>
                                <div class="gf-wx-stats">
                                    <span><?php echo icon('droplets', 11); ?><?php echo (int)($dayInfo['pop'] ?? 0); ?>%</span>
                                    <span><?php echo icon('cloud-rain', 11); ?><?php echo ($dayInfo['rain_mm'] ?? 0) < 1 ? '<1' : number_format((float)($dayInfo['rain_mm'] ?? 0), 1); ?>mm</span>
                                </div>
                            </div>
                        </div>

                        <div class="gf-wx-mid">
                            <div class="gf-wx-date"><?php echo date('D, M j', $dateTs); ?></div>
                            <div class="gf-wx-total">
                                <?php echo icon('umbrella', 13); ?>
                                <span style="color: <?php echo $rainColor; ?>;">
                                    <?php echo $totalMm < 1 ? '< 1 mm' : number_format($totalMm, 1) . ' mm'; ?>
                                </span>
                            </div>
                            <span class="gf-live-badge"><span class="gf-live-dot"></span> LIVE</span>
                        </div>

                        <div class="gf-wx-side night">
                            <div class="gf-wx-period">
                                <?php echo icon('moon', 14); ?>
                                Night
                            </div>
                            <div class="gf-wx-icon-sm">
                                <?php echo weatherIcon($nightIconName, 46); ?>
                            </div>
                            <div class="gf-wx-text">
                                <div class="gf-wx-cond"><?php echo htmlspecialchars($nightText ?? '—'); ?></div>
                                <div class="gf-wx-stats">
                                    <span><?php echo icon('droplets', 11); ?><?php echo (int)($nightInfo['pop'] ?? 0); ?>%</span>
                                    <span><?php echo icon('cloud-rain', 11); ?><?php echo ($nightInfo['rain_mm'] ?? 0) < 1 ? '<1' : number_format((float)($nightInfo['rain_mm'] ?? 0), 1); ?>mm</span>
                                </div>
                            </div>
                        </div>

                        <button type="button" class="gf-wx-expand-sm" onclick="openWeatherModal();" aria-label="View 5-day forecast">
                            <?php echo icon('calendar-days', 16); ?>
                            <span>5-Day</span>
                        </button>
                    </article>
                <?php else: ?>
                    <div class="gf-empty">
                        <?php echo icon('cloud-off', 40); ?>
                        <p>Weather forecast temporarily unavailable.</p>
                    </div>
                <?php endif; ?>
            </section>

            <!-- GOATS (LIMITED TO 4) -->
            <section class="gf-section" aria-labelledby="goats-heading">
                <div class="gf-section-head">
                    <div class="gf-section-head-left">
                        <h2 class="gf-section-title" id="goats-heading">Recent Goats</h2>
                        <?php if ($totalGoats > 0): ?>
                            <p class="gf-section-sub">
                                Showing <?php echo count($dashboardGoats); ?> of <?php echo $totalGoats; ?> goats
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php if ($totalGoats > 4): ?>
                        <a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php" class="gf-view-all-btn">
                            View All Goats
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($dashboardGoats)): ?>
                    <div class="gf-empty">
                        <?php echo icon('paw-print', 40); ?>
                        <p>No goat records yet.</p>
                    </div>
                <?php else: ?>
                    <div class="gf-goat-grid">
                        <?php foreach ($dashboardGoats as $goat):
                            $tokens = healthTokens((string) ($goat['health_status'] ?? 'unknown'));
                            $healthColor = $tokens['color'];
                            $healthBg    = $tokens['bg'];
                            $healthText  = $tokens['text'];
                            $healthIcon  = $tokens['icon'];
                            $photoUrl = resolvePhotoUrl($goat['photo_path'] ?? '');
                            $updatedTs = strtotime((string) ($goat['updated_at'] ?? ''));
                            if ($updatedTs === false) $updatedTs = null;
                        ?>
                            <a class="gf-goat-card"
                               href="<?php echo BASE_URL; ?>/app/goats/goat-view.php?id=<?php echo urlencode($goat['id']); ?>"
                               style="--goat-accent: <?php echo $healthColor; ?>;">
                                <div class="gf-goat-media">
                                    <?php if ($photoUrl !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                             alt="Photo of <?php echo htmlspecialchars($goat['goat_name']); ?>"
                                             class="gf-goat-photo" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <div class="gf-goat-photo-placeholder" aria-hidden="true">
                                            <?php echo icon('paw-print', 22); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="gf-goat-info">
                                        <span class="gf-goat-id"><?php echo htmlspecialchars($goat['goat_id']); ?></span>
                                        <div class="gf-goat-name"><?php echo htmlspecialchars($goat['goat_name']); ?></div>
                                    </div>
                                </div>
                                <div class="gf-goat-badges">
                                    <span class="gf-badge" style="color: <?php echo $healthText; ?>; background: <?php echo $healthBg; ?>;">
                                        <?php echo icon($healthIcon, 10); ?>
                                        <?php echo ucfirst(htmlspecialchars((string) $goat['health_status'])); ?>
                                    </span>
                                    <?php if (!empty($goat['status'])): ?>
                                        <span class="gf-badge status">
                                            <?php echo icon('circle', 5); ?>
                                            <?php echo ucfirst(htmlspecialchars((string) $goat['status'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($updatedTs): ?>
                                    <div class="gf-goat-date">
                                        <?php echo icon('clock', 11); ?>
                                        Updated <?php echo date('M d, Y', $updatedTs); ?>
                                    </div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- TIPS -->
            <section class="gf-section" aria-labelledby="tips-heading">
                <div class="gf-tips-box">
                    <h3 class="gf-tips-title" id="tips-heading">
                        <?php echo icon('lightbulb', 18); ?>
                        Farming Tips
                    </h3>
                    <?php if (!empty($farmingTips)): ?>
                        <ul class="gf-tips-list">
                            <?php foreach ($farmingTips as $tip): ?>
                                <li class="gf-tip-item">
                                    <?php echo icon('check-circle', 16); ?>
                                    <span><?php echo htmlspecialchars($tip['text'] ?? ''); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p style="margin: 0; font-size: 13px; color: #64748b;">No tips available right now.</p>
                    <?php endif; ?>
                </div>
            </section>

        </main>
    </div>
</div>

<!-- WEATHER MODAL -->
<div class="gf-modal-backdrop"
     id="gfWeatherModal"
     aria-hidden="true"
     role="presentation"
     onclick="if (event.target === this) closeWeatherModal();">
    <div class="gf-modal" role="dialog" aria-modal="true" aria-labelledby="weather-modal-title">
        <div class="gf-modal-header">
            <div>
                <h3 id="weather-modal-title" class="gf-modal-title">5-Day Weather</h3>
                <?php if ($weatherForecast && !empty($weatherForecast['location'])): ?>
                    <p class="gf-modal-sub"><?php echo htmlspecialchars($weatherForecast['location']); ?></p>
                <?php endif; ?>
            </div>
            <button type="button"
                    class="gf-modal-close"
                    aria-label="Close forecast"
                    onclick="closeWeatherModal();">
                <?php echo icon('x', 18, '', '2.5'); ?>
            </button>
        </div>

        <div class="gf-modal-body">
            <?php if ($weatherForecast && !empty($weatherForecast['days'])): ?>
                <?php $weatherDays = array_slice($weatherForecast['days'], 0, 5); ?>
                <div class="gf-weather-grid">
                    <?php foreach ($weatherDays as $di => $day):
                        $dateTs = strtotime($day['date']);
                        $dayLabel = $di === 0 ? 'Today' : strtoupper(date('D', $dateTs));
                        $dateLabel = strtoupper(date('M j', $dateTs));
                        $dayInfo2 = $day['day'];
                        $nightInfo2 = $day['night'];
                        $dayText2 = $dayInfo2['available'] ? weatherDetailedLabel($dayInfo2['weathercode'], $dayInfo2['pop'], false) : null;
                        $nightText2 = $nightInfo2['available'] ? weatherDetailedLabel($nightInfo2['weathercode'], $nightInfo2['pop'], true) : null;
                        $totalMm2 = isset($day['total_mm']) ? $day['total_mm'] : 0;
                        $dayIconName2 = $dayInfo2['available'] ? weathercodeToIconName($dayInfo2['weathercode'], false) : null;
                        $nightIconName2 = $nightInfo2['available'] ? weathercodeToIconName($nightInfo2['weathercode'], true) : null;
                        $dateFull = date('l, F j, Y', $dateTs);
                    ?>
                        <article class="gf-weather-card<?php echo $di === 0 ? ' today' : ''; ?>"
                                 tabindex="0"
                                 role="button"
                                 aria-label="View details for <?php echo htmlspecialchars($dateFull); ?>"
                                 data-day="<?php echo htmlspecialchars($dayLabel); ?>"
                                 data-date="<?php echo htmlspecialchars($dateFull); ?>"
                                 data-day-text="<?php echo htmlspecialchars($dayText2 ?? 'No data'); ?>"
                                 data-day-pop="<?php echo (int)($dayInfo2['pop'] ?? 0); ?>"
                                 data-day-rain="<?php echo $dayInfo2['available'] ? ($dayInfo2['rain_mm'] < 1 ? '<1' : number_format($dayInfo2['rain_mm'], 1)) : '0'; ?>"
                                 data-day-temp="<?php echo $dayInfo2['available'] ? round($dayInfo2['temp'] ?? 0) : '—'; ?>"
                                 data-day-humidity="<?php echo $dayInfo2['available'] ? (int)($dayInfo2['humidity'] ?? 0) : '—'; ?>"
                                 data-day-wind="<?php echo $dayInfo2['available'] ? round($dayInfo2['wind'] ?? 0) : '—'; ?>"
                                 data-night-text="<?php echo htmlspecialchars($nightText2 ?? 'No data'); ?>"
                                 data-night-pop="<?php echo (int)($nightInfo2['pop'] ?? 0); ?>"
                                 data-night-rain="<?php echo $nightInfo2['available'] ? ($nightInfo2['rain_mm'] < 1 ? '<1' : number_format($nightInfo2['rain_mm'], 1)) : '0'; ?>"
                                 data-night-temp="<?php echo $nightInfo2['available'] ? round($nightInfo2['temp'] ?? 0) : '—'; ?>"
                                 data-night-humidity="<?php echo $nightInfo2['available'] ? (int)($nightInfo2['humidity'] ?? 0) : '—'; ?>"
                                 data-night-wind="<?php echo $nightInfo2['available'] ? round($nightInfo2['wind'] ?? 0) : '—'; ?>"
                                 data-total-rain="<?php echo $totalMm2 < 1 ? '< 1 mm' : number_format($totalMm2, 1) . ' mm'; ?>">
                            <div class="gf-weather-head">
                                <div class="gf-weather-day-block">
                                    <span class="day"><?php echo htmlspecialchars($dayLabel); ?></span>
                                    <span class="date"><?php echo htmlspecialchars($dateLabel); ?></span>
                                </div>
                                <?php if ($di === 0): ?>
                                    <span class="gf-weather-now-badge">NOW</span>
                                <?php endif; ?>
                            </div>

                            <div class="gf-weather-period-block day">
                                <div class="gf-period-label">
                                    <?php echo icon('sun', 11); ?>
                                    <span>Day</span>
                                </div>
                                <?php if ($dayInfo2['available']): ?>
                                    <div class="gf-weather-icon-wrap">
                                        <?php echo weatherIcon($dayIconName2, 48); ?>
                                    </div>
                                    <div class="gf-weather-desc"><?php echo htmlspecialchars($dayText2); ?></div>
                                    <div class="gf-weather-stats">
                                        <span class="gf-stat">
                                            <?php echo icon('droplets', 10); ?>
                                            <?php echo $dayInfo2['pop']; ?>%
                                        </span>
                                        <span class="gf-stat">
                                            <?php echo icon('cloud-rain', 10); ?>
                                            <?php echo $dayInfo2['rain_mm'] < 1 ? '<1' : number_format($dayInfo2['rain_mm'], 1); ?> mm
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="gf-weather-nodata">No data</div>
                                <?php endif; ?>
                            </div>

                            <div class="gf-weather-period-block night">
                                <div class="gf-period-label">
                                    <?php echo icon('moon', 11); ?>
                                    <span>Night</span>
                                </div>
                                <?php if ($nightInfo2['available']): ?>
                                    <div class="gf-weather-icon-wrap">
                                        <?php echo weatherIcon($nightIconName2, 48); ?>
                                    </div>
                                    <div class="gf-weather-desc"><?php echo htmlspecialchars($nightText2); ?></div>
                                    <div class="gf-weather-stats">
                                        <span class="gf-stat">
                                            <?php echo icon('droplets', 10); ?>
                                            <?php echo $nightInfo2['pop']; ?>%
                                        </span>
                                        <span class="gf-stat">
                                            <?php echo icon('cloud-rain', 10); ?>
                                            <?php echo $nightInfo2['rain_mm'] < 1 ? '<1' : number_format($nightInfo2['rain_mm'], 1); ?> mm
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="gf-weather-nodata">No data</div>
                                <?php endif; ?>
                            </div>

                            <div class="gf-weather-footer">
                                <span class="gf-footer-label">
                                    <?php echo icon('umbrella', 11); ?>
                                    RAIN
                                </span>
                                <strong class="gf-footer-value">
                                    <?php echo $totalMm2 < 1 ? '< 1 mm' : number_format($totalMm2, 1) . ' mm'; ?>
                                </strong>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="gf-empty">
                    <?php echo icon('cloud-off', 40); ?>
                    <p>Weather forecast temporarily unavailable.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- BUBBLE TOOLTIP -->
<div class="gf-weather-bubble" id="gfWeatherBubble" role="tooltip" aria-hidden="true">
    <div class="gf-bubble-arrow"></div>
    <div class="gf-bubble-content">
        <div class="gf-bubble-header">
            <div class="gf-bubble-day" id="bubbleDay">—</div>
            <div class="gf-bubble-date" id="bubbleDate">—</div>
        </div>

        <div class="gf-bubble-section">
            <div class="gf-bubble-section-title">
                <span class="gf-bubble-dot day-dot"></span>
                Day Forecast
            </div>
            <div class="gf-bubble-rows">
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Condition</span>
                    <span class="gf-bubble-value" id="bubbleDayText">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Temperature</span>
                    <span class="gf-bubble-value" id="bubbleDayTemp">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Rain Chance</span>
                    <span class="gf-bubble-value" id="bubbleDayPop">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Rain Amount</span>
                    <span class="gf-bubble-value" id="bubbleDayRain">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Humidity</span>
                    <span class="gf-bubble-value" id="bubbleDayHumidity">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Wind</span>
                    <span class="gf-bubble-value" id="bubbleDayWind">—</span>
                </div>
            </div>
        </div>

        <div class="gf-bubble-section">
            <div class="gf-bubble-section-title">
                <span class="gf-bubble-dot night-dot"></span>
                Night Forecast
            </div>
            <div class="gf-bubble-rows">
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Condition</span>
                    <span class="gf-bubble-value" id="bubbleNightText">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Temperature</span>
                    <span class="gf-bubble-value" id="bubbleNightTemp">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Rain Chance</span>
                    <span class="gf-bubble-value" id="bubbleNightPop">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Rain Amount</span>
                    <span class="gf-bubble-value" id="bubbleNightRain">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Humidity</span>
                    <span class="gf-bubble-value" id="bubbleNightHumidity">—</span>
                </div>
                <div class="gf-bubble-row">
                    <span class="gf-bubble-label">Wind</span>
                    <span class="gf-bubble-value" id="bubbleNightWind">—</span>
                </div>
            </div>
        </div>

        <div class="gf-bubble-footer">
            <span class="gf-bubble-label">Total Rain</span>
            <strong class="gf-bubble-total" id="bubbleTotalRain">—</strong>
        </div>
    </div>
</div>

<!-- FAB -->
<div class="gf-fab-wrap" id="gfFabWrap">
    <div class="gf-fab-inner">
        <button type="button" class="gf-fab-toggle" aria-label="Open quick menu"
                aria-expanded="false" aria-controls="gfFabMenu" id="gfFabToggle">
            <span class="gf-fab-icon-wrap" aria-hidden="true">
                <?php if (file_exists(__DIR__ . '/../../assets/img/goat-icon.png')): ?>
                    <img src="<?php echo BASE_URL; ?>/assets/img/goat-icon.png" alt="" loading="eager" decoding="async">
                <?php else: ?>
                    <?php echo icon('goat', 30, '', '1.8'); ?>
                <?php endif; ?>
            </span>
        </button>

        <ul class="gf-fab-menu" id="gfFabMenu" role="menu" aria-label="Quick actions">
            <li role="none">
                <a role="menuitem" href="<?php echo BASE_URL; ?>/app/goats/goat-create.php">
                    <?php echo icon('plus', 14); ?>
                    Add Goat
                </a>
            </li>
            <li role="none">
                <a role="menuitem" href="<?php echo BASE_URL; ?>/app/goats/dashboard.php">
                    <?php echo icon('list', 14); ?>
                    View Inventory
                </a>
            </li>
            <li role="none">
                <a role="menuitem" href="<?php echo BASE_URL; ?>/app/user/weather.php">
                    <?php echo icon('cloud-sun', 14); ?>
                    Weather
                </a>
            </li>
            </li>
        </ul>
    </div>
</div>

<script>
    function toggleSidebar() {
        document.body.classList.toggle('gf-sidebar-open');
        var isOpen = document.body.classList.contains('gf-sidebar-open');
        var toggleBtn = document.querySelector('.gf-navbar-toggle');
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
    function closeSidebar() {
        document.body.classList.remove('gf-sidebar-open');
        var toggleBtn = document.querySelector('.gf-navbar-toggle');
        if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    }

    var _lastFocusedEl = null;
    function openWeatherModal() {
        var modal = document.getElementById('gfWeatherModal');
        if (!modal) return;
        _lastFocusedEl = document.activeElement;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        var closeBtn = modal.querySelector('.gf-modal-close');
        if (closeBtn) setTimeout(function () { closeBtn.focus(); }, 60);
    }
    function closeWeatherModal() {
        var modal = document.getElementById('gfWeatherModal');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        // Hide bubble when modal closes
        var bubble = document.getElementById('gfWeatherBubble');
        if (bubble) bubble.classList.remove('is-visible');
        var activeCard = document.querySelector('.gf-weather-card.is-active');
        if (activeCard) activeCard.classList.remove('is-active');
        if (_lastFocusedEl && typeof _lastFocusedEl.focus === 'function') _lastFocusedEl.focus();
    }
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var modal = document.getElementById('gfWeatherModal');
        if (modal && modal.classList.contains('is-open')) { closeWeatherModal(); return; }
        var wrap = document.getElementById('gfFabWrap');
        if (wrap && wrap.classList.contains('is-open')) {
            wrap.classList.remove('is-open');
            var t = document.getElementById('gfFabToggle');
            if (t) { t.setAttribute('aria-expanded', 'false'); t.focus(); }
            return;
        }
        closeSidebar();
    });
    (function () {
        var wrap = document.getElementById('gfFabWrap');
        var toggle = document.getElementById('gfFabToggle');
        if (!wrap || !toggle) return;
        function setOpen(open) {
            wrap.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        function isOpen() { return wrap.classList.contains('is-open'); }
        toggle.addEventListener('click', function (e) { e.stopPropagation(); setOpen(!isOpen()); });
        document.addEventListener('click', function (e) {
            if (isOpen() && !wrap.contains(e.target)) setOpen(false);
        });
        wrap.querySelectorAll('.gf-fab-menu a, .gf-fab-menu button').forEach(function (el) {
            el.addEventListener('click', function () { setOpen(false); });
        });
    })();

    // ============================================================
    // WEATHER BUBBLE TOOLTIP
    // ============================================================
    (function () {
        'use strict';

        var bubble = document.getElementById('gfWeatherBubble');
        if (!bubble) return;

        var arrow = bubble.querySelector('.gf-bubble-arrow');
        var activeCard = null;
        var cards = document.querySelectorAll('.gf-weather-card');

        var fields = {
            day:           document.getElementById('bubbleDay'),
            date:          document.getElementById('bubbleDate'),
            dayText:       document.getElementById('bubbleDayText'),
            dayTemp:       document.getElementById('bubbleDayTemp'),
            dayPop:        document.getElementById('bubbleDayPop'),
            dayRain:       document.getElementById('bubbleDayRain'),
            dayHumidity:   document.getElementById('bubbleDayHumidity'),
            dayWind:       document.getElementById('bubbleDayWind'),
            nightText:     document.getElementById('bubbleNightText'),
            nightTemp:     document.getElementById('bubbleNightTemp'),
            nightPop:      document.getElementById('bubbleNightPop'),
            nightRain:     document.getElementById('bubbleNightRain'),
            nightHumidity: document.getElementById('bubbleNightHumidity'),
            nightWind:     document.getElementById('bubbleNightWind'),
            totalRain:     document.getElementById('bubbleTotalRain')
        };

        function showBubble(card) {
            if (!card) return;
            var d = card.dataset;

            fields.day.textContent           = d.day || '—';
            fields.date.textContent          = d.date || '—';
            fields.dayText.textContent       = d.dayText || '—';
            fields.dayTemp.textContent       = (d.dayTemp !== '—' && d.dayTemp) ? d.dayTemp + '°C' : '—';
            fields.dayPop.textContent        = (d.dayPop || d.dayPop === '0') ? d.dayPop + '%' : '—';
            fields.dayRain.textContent       = d.dayRain ? d.dayRain + ' mm' : '—';
            fields.dayHumidity.textContent   = (d.dayHumidity !== '—' && d.dayHumidity) ? d.dayHumidity + '%' : '—';
            fields.dayWind.textContent       = (d.dayWind !== '—' && d.dayWind) ? d.dayWind + ' km/h' : '—';

            fields.nightText.textContent     = d.nightText || '—';
            fields.nightTemp.textContent     = (d.nightTemp !== '—' && d.nightTemp) ? d.nightTemp + '°C' : '—';
            fields.nightPop.textContent      = (d.nightPop || d.nightPop === '0') ? d.nightPop + '%' : '—';
            fields.nightRain.textContent     = d.nightRain ? d.nightRain + ' mm' : '—';
            fields.nightHumidity.textContent = (d.nightHumidity !== '—' && d.nightHumidity) ? d.nightHumidity + '%' : '—';
            fields.nightWind.textContent     = (d.nightWind !== '—' && d.nightWind) ? d.nightWind + ' km/h' : '—';

            fields.totalRain.textContent     = d.totalRain || '—';

            bubble.classList.add('is-visible');
            bubble.setAttribute('aria-hidden', 'false');
            positionBubble(card);

            if (activeCard) activeCard.classList.remove('is-active');
            card.classList.add('is-active');
            activeCard = card;
        }

        function positionBubble(card) {
            var rect   = card.getBoundingClientRect();
            var bRect  = bubble.getBoundingClientRect();
            var margin = 8;

            var top  = rect.top - bRect.height - margin;
            var left = rect.left + (rect.width / 2) - (bRect.width / 2);
            var arrowPos = 'top';

            if (top < margin) {
                top = rect.bottom + margin;
                arrowPos = 'bottom';
            }

            if (left < margin) left = margin;
            if (left + bRect.width > window.innerWidth - margin) {
                left = window.innerWidth - bRect.width - margin;
            }

            bubble.style.top  = top + 'px';
            bubble.style.left = left + 'px';
            bubble.setAttribute('data-arrow', arrowPos);

            var cardCenterX = rect.left + (rect.width / 2);
            var arrowLeft = cardCenterX - left - 6;
            arrowLeft = Math.max(16, Math.min(arrowLeft, bRect.width - 28));
            arrow.style.left = arrowLeft + 'px';
        }

        function hideBubble() {
            bubble.classList.remove('is-visible');
            bubble.setAttribute('aria-hidden', 'true');
            if (activeCard) {
                activeCard.classList.remove('is-active');
                activeCard = null;
            }
        }

        cards.forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.stopPropagation();
                if (activeCard === card) hideBubble();
                else showBubble(card);
            });
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (activeCard === card) hideBubble();
                    else showBubble(card);
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (!bubble.contains(e.target) && !e.target.closest('.gf-weather-card')) {
                hideBubble();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && activeCard) hideBubble();
        });

        window.addEventListener('resize', function () {
            if (activeCard) positionBubble(activeCard);
        });
        window.addEventListener('scroll', function () {
            if (activeCard) positionBubble(activeCard);
        }, true);
    })();
</script>

<?php renderFooter(); ?>