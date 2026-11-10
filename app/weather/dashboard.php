<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';

// ADMIN ONLY
$currentRole = $_SESSION['role'] ?? null;
if ($currentRole !== 'admin') {
    http_response_code(403);
    exit('Access denied. Required role: admin');
}

// ============================================================
// CONFIGURATION
// ============================================================
define('MANOLO_FORTICH_LAT', 8.3678);
define('MANOLO_FORTICH_LON', 124.8663);
define('MANOLO_FORTICH_NAME', 'Manolo Fortich, Bukidnon');
define('WEATHER_CACHE_TTL', 1800);

// ============================================================
// FUNCTIONS
// ============================================================
function fetchOpenWeather(string $endpoint, array $params): ?array
{
    $params['appid'] = OPENWEATHER_API_KEY;
    $url = 'https://api.openweathermap.org/data/2.5/' . $endpoint . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'GoatPro/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError || $httpCode !== 200 || !$response) return null;
    $data = json_decode($response, true);
    if (!is_array($data) || (isset($data['cod']) && (int)$data['cod'] !== 200)) return null;
    return $data;
}

function getCurrentWeather(): ?array {
    $key = 'wx_current_' . date('YmdH');
    if (isset($_SESSION['_wx_cache'][$key]) && (time() - $_SESSION['_wx_cache'][$key]['t']) < WEATHER_CACHE_TTL) return $_SESSION['_wx_cache'][$key]['v'];
    $data = fetchOpenWeather('weather', ['lat' => MANOLO_FORTICH_LAT, 'lon' => MANOLO_FORTICH_LON, 'units' => 'metric']);
    if ($data) $_SESSION['_wx_cache'][$key] = ['t' => time(), 'v' => $data];
    return $data;
}

function getForecastWeather(): ?array {
    $key = 'wx_forecast_' . date('YmdH');
    if (isset($_SESSION['_wx_cache'][$key]) && (time() - $_SESSION['_wx_cache'][$key]['t']) < WEATHER_CACHE_TTL) return $_SESSION['_wx_cache'][$key]['v'];
    $data = fetchOpenWeather('forecast', ['lat' => MANOLO_FORTICH_LAT, 'lon' => MANOLO_FORTICH_LON, 'units' => 'metric']);
    if ($data) $_SESSION['_wx_cache'][$key] = ['t' => time(), 'v' => $data];
    return $data;
}

function calculateTHI(float $temp, float $humidity): float { return (1.8 * $temp + 32) - (0.55 - 0.0055 * $humidity) * (1.8 * $temp - 26); }
function getHeatStress(float $thi): array {
    if ($thi >= 85) return ['label' => 'Severe',   'level' => 'high', 'desc' => 'Dangerous conditions. Immediate action needed.'];
    if ($thi >= 79) return ['label' => 'Moderate', 'level' => 'mid',  'desc' => 'High heat stress. Provide shade and water.'];
    if ($thi >= 72) return ['label' => 'Mild',     'level' => 'mid',  'desc' => 'Monitor goats. Increase ventilation.'];
    return ['label' => 'Comfortable', 'level' => 'low', 'desc' => 'Good temperature for goats. Low heat stress.'];
}
function getRainLevel(float $mm): array {
    if ($mm < 0.5) return ['label' => 'No Rain',       'color' => 'green'];
    if ($mm < 2.5) return ['label' => 'Light Rain',    'color' => 'green'];
    if ($mm < 7.5) return ['label' => 'Moderate Rain', 'color' => 'amber'];
    return ['label' => 'Heavy Rain', 'color' => 'red'];
}
function getWindDirection(int $deg): string {
    $dirs = ['North','NE','East','SE','South','SW','West','NW'];
    return $dirs[round($deg / 45) % 8];
}
function groupForecastByDay(?array $forecast): array {
    if (!$forecast || empty($forecast['list'])) return [];
    $grouped = [];
    foreach ($forecast['list'] as $item) {
        $key = date('Y-m-d', $item['dt']);
        if (!isset($grouped[$key])) $grouped[$key] = ['dt' => strtotime(date('Y-m-d 12:00:00', $item['dt'])), 'temps' => [], 'icons' => [], 'pops' => []];
        $grouped[$key]['temps'][] = $item['main']['temp'];
        $grouped[$key]['icons'][] = $item['weather'][0]['icon'] ?? '01d';
        $grouped[$key]['pops'][]  = $item['pop'] ?? 0;
    }
    $daily = [];
    foreach ($grouped as $d) {
        $daily[] = [
            'dt'      => $d['dt'],
            'temp_hi' => round(max($d['temps'])),
            'temp_lo' => round(min($d['temps'])),
            'icon'    => $d['icons'][floor(count($d['icons']) / 2)] ?? '01d',
            'pop'     => round(max($d['pops']) * 100),
        ];
    }
    return array_slice($daily, 0, 5);
}
function getHourlyData(?array $forecast, int $limit = 8): array {
    if (!$forecast || empty($forecast['list'])) return [];
    $hours = [];
    foreach (array_slice($forecast['list'], 0, $limit) as $item) {
        $hours[] = ['time' => date('H:i', $item['dt']), 'temp' => round($item['main']['temp']), 'pop'  => round(($item['pop'] ?? 0) * 100), 'icon' => $item['weather'][0]['icon'] ?? '01d'];
    }
    return $hours;
}
function owIconName(string $code): string {
    $map = ['01'=>'sun','02'=>'cloud-sun','03'=>'cloud','04'=>'cloud','09'=>'cloud-rain','10'=>'cloud-rain','11'=>'cloud-rain','13'=>'cloud-rain','50'=>'cloud'];
    return $map[substr($code, 0, 2)] ?? 'cloud';
}
function wxIcon(string $name, int $size = 32): string {
    static $paths = [
        'sun'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/>',
        'cloud-sun'  => '<circle cx="9" cy="9" r="3"/><path d="M9 3v1"/><path d="M3 9h1"/><path d="m5 5 .7.7"/><path d="M13 9a5 5 0 0 1 5 5h1.5a3.5 3.5 0 1 1 0 7H8a3 3 0 0 1 0-6 5 5 0 0 1 5-6Z"/>',
        'cloud'      => '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>',
        'cloud-rain' => '<path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M16 14v6"/><path d="M8 14v6"/><path d="M12 16v6"/>',
    ];
    $path = $paths[$name] ?? $paths['sun'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="display:block;">' . $path . '</svg>';
}
if (!function_exists('icon')) {
    function icon(string $name, int $size = 16, string $class = '', string $strokeWidth = '2'): string {
        static $paths = [
            'menu'    => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
            'x'       => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        ];
        $path = $paths[$name] ?? $paths['menu'];
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%2$s" stroke-linecap="round" stroke-linejoin="round"%3$s aria-hidden="true" style="display:inline-block;vertical-align:middle;flex-shrink:0;">%4$s</svg>', $size, htmlspecialchars($strokeWidth, ENT_QUOTES, 'UTF-8'), $classAttr, $path);
    }
}

// ============================================================
// FETCH DATA
// ============================================================
if (isset($_GET['refresh']) && $_GET['refresh'] === '1') {
    unset($_SESSION['_wx_cache']);
    foreach (array_keys($_SESSION) as $k) {
        if (strpos($k, '_wf_bounds') === 0) unset($_SESSION[$k]);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$currentRaw  = getCurrentWeather();
$forecastRaw = getForecastWeather();

if ($currentRaw) {
    $temp = round($currentRaw['main']['temp']); $feelsLike = round($currentRaw['main']['feels_like']);
    $humidity = (int)$currentRaw['main']['humidity']; $windSpeed = round($currentRaw['wind']['speed'] * 3.6, 1);
    $windDeg = (int)($currentRaw['wind']['deg'] ?? 0); $weatherMain = $currentRaw['weather'][0]['main'] ?? 'Clear';
    $weatherDesc = ucfirst($currentRaw['weather'][0]['description'] ?? 'Clear'); $weatherIcon = $currentRaw['weather'][0]['icon'] ?? '01d';
    $lastUpdate = $currentRaw['dt'] ?? time();
} else {
    $temp = 28; $feelsLike = 30; $humidity = 76; $windSpeed = 12; $windDeg = 90;
    $weatherMain = 'Clear'; $weatherDesc = 'Sunny & Dry'; $weatherIcon = '01d'; $lastUpdate = time();
}

$thi = calculateTHI($temp, $humidity);
$heat = getHeatStress($thi);
$windDir = getWindDirection($windDeg);
$dailyForecast = groupForecastByDay($forecastRaw);
$hourlyData = getHourlyData($forecastRaw, 8);

$rainToday = 0;
if ($forecastRaw && !empty($forecastRaw['list'])) {
    $todayKey = date('Y-m-d');
    foreach ($forecastRaw['list'] as $item) if (date('Y-m-d', $item['dt']) === $todayKey) $rainToday += $item['rain']['3h'] ?? 0;
}
$rainToday = round($rainToday, 1);
$rainInfo = getRainLevel($rainToday);

$rainTonight = 0;
if ($forecastRaw && !empty($forecastRaw['list'])) {
    $todayKey = date('Y-m-d');
    foreach ($forecastRaw['list'] as $item) {
        if (date('Y-m-d', $item['dt']) === $todayKey && (int)date('G', $item['dt']) >= 18) $rainTonight += $item['rain']['3h'] ?? 0;
    }
}
$rainTonight = round($rainTonight, 1);

// ============================================================
// SUNRISE TIGWAY ADVISORY (Past 12 Hours Rain)
// ============================================================
$past12hRain = 0; $currentHour = (int)date('G');
if ($forecastRaw && !empty($forecastRaw['list'])) {
    $now = time();
    foreach ($forecastRaw['list'] as $item) {
        if ($item['dt'] < $now && $item['dt'] >= ($now - 43200)) $past12hRain += $item['rain']['3h'] ?? 0;
    }
}
$past12hRain = round($past12hRain, 1);
$isMorning = ($currentHour >= 6 && $currentHour <= 9);
$morningTigwayAdvisory = ($isMorning && $past12hRain > 1);

// ============================================================
// MULTI-SOURCE BOUNDS
// ============================================================
$boundsData = null; $boundsError = null;
$boundsCacheKey = '_wf_bounds_page_' . date('YmdH');
if (isset($_SESSION[$boundsCacheKey]) && (time() - $_SESSION[$boundsCacheKey]['t']) < 1800) {
    $boundsData = $_SESSION[$boundsCacheKey]['v'];
} else {
    $proxyPath = __DIR__ . '/weather-bounds-proxy.php';
    if (file_exists($proxyPath)) {
        $decoded = require $proxyPath;
        if (is_array($decoded) && isset($decoded['bounds'])) {
            $boundsData = $decoded; $_SESSION[$boundsCacheKey] = ['t' => time(), 'v' => $decoded];
        } else $boundsError = 'Proxy executed but returned invalid data.';
    } else $boundsError = 'Proxy file not found at: ' . $proxyPath;
}

// ============================================================
// SMS ADVISORY DETECTION
// ============================================================
$advisories = [];
$highPopHours = array_filter($hourlyData, fn($h) => $h['pop'] >= 70);
$hasHighPop = count($highPopHours) >= 3;

if ($morningTigwayAdvisory) {
    $advisories[] = [
        'type' => 'rain', 'priority' => 'medium', 'title' => 'Pahibalo: Pwede na Mag-tigway',
        'message' => "Maayong buntag! Nihunong na ang ulan gabii. Pwede na ninyo matugway (ipagawas) ang mga kanding sugod 7:00 AM kay moinit na ang adlaw ug mo-uga na ang yuta.",
    ];
}
if ($rainTonight > 5 || $rainToday > 15) {
    $advisories[] = [
        'type' => 'rain', 'priority' => 'high', 'title' => 'Pahibalo: Kusog nga Ulan',
        'message' => "URGENTE: Kusog nga ulan ({$rainTonight}mm) ang gi-expect karong gabhiona. Palihog pasulod sa kural o silong ang mga kanding aron dili mabasa ug matugnawan. Ayaw pagpanggawas hangtod mohunong ang ulan ug moinit ang panahon ugma.",
    ];
} elseif ($rainTonight > 2 || $hasHighPop) {
    $advisories[] = [
        'type' => 'rain', 'priority' => 'medium', 'title' => 'Pahibalo: Posibleng Ulan',
        'message' => "Pahibalo: Posibleng naay ulan karong adlawa o gabhiona. Kung mabasa ang kasagbutan, palihog ayaw lang sa ipagawas (itugway) ang kanding inig ka-buntag kay basa ug tugnaw pa. Hulata nga moinit ang adlaw mga 7:00 AM pataas aron mo-uga ang yuta bag-o itugway.",
    ];
}
if ($heat['level'] === 'high') {
    $advisories[] = [
        'type' => 'heat', 'priority' => 'high', 'title' => 'Pahibalo: Sobrang Init',
        'message' => "Pahibalo: Grabe ang kainit karon (THI: " . round($thi) . "). Siguroha nga naay dakong landong ang kanding ug kanunay naay limpyo nga tubig nga mainom aron malikayan ang heat stress.",
    ];
} elseif ($heat['level'] === 'mid' && $temp >= 30) {
    $advisories[] = [
        'type' => 'heat', 'priority' => 'medium', 'title' => 'Pahibalo: Init-init ang Panahon',
        'message' => "Pahibalo: Medyo init ang panahon karon. Palihog siguroha nga naay landong ug daghan nga tubig nga mainom ang mga kanding aron dili ma-stress sa kainit.",
    ];
}

$highestPriority = 'low';
foreach ($advisories as $adv) {
    if ($adv['priority'] === 'high') { $highestPriority = 'high'; break; }
    if ($adv['priority'] === 'medium') { $highestPriority = 'medium'; }
}

// ============================================================
// HANDLE SEND ADVISORY REQUEST
// ============================================================
$sendResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_advisory'])) {
    $selectedIndex = (int)($_POST['advisory_index'] ?? 0);
    if (isset($advisories[$selectedIndex])) {
        $selected = $advisories[$selectedIndex];
        $sendResult = sendAdvisoryToFarmers($pdo, $selected['message'], $selected['type'], $selected['priority'], [
            'rainToday' => $rainToday, 'rainTonight' => $rainTonight, 'temp' => $temp, 'humidity' => $humidity,
            'thi' => round($thi), 'heat_level' => $heat['level'], 'triggered_by' => $_SESSION['user_id'] ?? null,
        ], $_SESSION['user_id'] ?? null);
    } else $sendResult = ['success' => false, 'error' => 'Invalid advisory selection'];
}

$lastUpdateStr = date('g:i A', $lastUpdate);
$rawEmail = $_SESSION['email'] ?? 'Admin';
$displayName = mb_strlen($rawEmail) > 20 ? mb_substr($rawEmail, 0, 17) . '…' : $rawEmail;

$title = 'Weather — ' . $displayName;
renderHeader($title);
?>

<style>
    /* MATERIAL DESIGN TOKENS */
    :root {
        --md-surface: #FFFFFF;
        --md-background: #F5F7FA;
        --md-primary: #1976D2;
        --md-primary-light: #E3F2FD;
        --md-text-high: #212121;
        --md-text-medium: #757575;
        --md-text-disabled: #9E9E9E;
        --md-divider: #EEEEEE;
        --md-error: #D32F2F;
        --md-warning: #FFA000;
        --md-success: #388E3C;
        --md-shadow-1: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
        --md-shadow-2: 0 3px 6px rgba(0,0,0,0.16), 0 3px 6px rgba(0,0,0,0.23);
        --md-radius: 8px;
        --navbar-height: 68px;
        --sidebar-width: 264px;
        --ease: cubic-bezier(0.4, 0, 0.2, 1);
        --font-family: 'Roboto', system-ui, -apple-system, sans-serif;
    }
    *, *::before, *::after { box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; background: var(--md-background); color: var(--md-text-high);
        font-family: var(--font-family); font-size: 14px; line-height: 1.5;
        overflow-x: hidden; -webkit-font-smoothing: antialiased;
    }
    img, svg { max-width: 100%; display: block; }
    button { font-family: inherit; }
    a { color: inherit; text-decoration: none; }
    .main-content, .main-content .container {
        width: 100% !important; max-width: 100% !important; margin: 0 !important;
        padding: 0 !important; background: var(--md-background) !important; display: block !important;
    }

    /* NAVBAR & SIDEBAR (Kept existing style but refined) */
    .gf-navbar {
        position: fixed; top: 0; left: 0; right: 0; height: var(--navbar-height);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 24px; z-index: 1050; box-shadow: var(--md-shadow-1);
    }
    .gf-navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: #fff; flex-shrink: 0; }
    .gf-navbar-logo {
        width: 46px; height: 46px; border-radius: 13px; background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.16); display: grid; place-items: center;
        font-size: 24px; flex-shrink: 0; overflow: hidden;
    }
    .gf-navbar-logo img { width: 100%; height: 100%; object-fit: contain; padding: 6px; }
    .gf-navbar-brand-text { display: flex; flex-direction: column; line-height: 1.1; }
    .gf-navbar-title { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -0.02em; }
    .gf-navbar-links { display: flex; align-items: center; gap: 4px; list-style: none; margin: 0; padding: 0; }
    .gf-navbar-links li a {
        display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px;
        border-radius: 10px; font-size: 14px; font-weight: 600;
        color: rgba(255, 255, 255, 0.82); transition: all 0.2s var(--ease);
    }
    .gf-navbar-links li a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-navbar-links li a.active { background: #fff; color: #14532d; font-weight: 700; }
    .gf-navbar-links li a.logout { color: #fca5a5; }
    .gf-navbar-toggle { display: none; width: 42px; height: 42px; place-items: center; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.16); border-radius: 10px; cursor: pointer; color: #fff; padding: 0; }
    
    .gf-sidebar {
        position: fixed; top: 0; right: 0; bottom: 0; width: var(--sidebar-width);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        padding: 24px 18px 40px; color: #fff; overflow-y: auto; z-index: 1060;
        display: flex; flex-direction: column; transform: translateX(100%);
        transition: transform 0.32s var(--ease); box-shadow: -8px 0 32px rgba(0, 0, 0, 0.35);
    }
    body.gf-sidebar-open .gf-sidebar { transform: translateX(0); }
    .gf-sidebar-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 8px 22px; margin-bottom: 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.12); }
    .gf-sidebar-close { width: 36px; height: 36px; display: grid; place-items: center; background: rgba(255, 255, 255, 0.1); border: none; border-radius: 10px; color: #fff; cursor: pointer; padding: 0; }
    .gf-brand { display: flex; align-items: center; gap: 12px; }
    .gf-brand-icon { width: 46px; height: 46px; border-radius: 13px; background: rgba(255, 255, 255, 0.14); display: grid; place-items: center; font-size: 24px; flex-shrink: 0; }
    .gf-brand-text { font-size: 16px; font-weight: 800; line-height: 1.2; }
    .gf-brand-sub { font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.65); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.1em; }
    .gf-nav-section { display: flex; flex-direction: column; gap: 4px; flex: 1; }
    .gf-nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 11px; color: rgba(255, 255, 255, 0.82); font-size: 14px; font-weight: 600; transition: all 0.2s var(--ease); }
    .gf-nav-link:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-nav-link.active { background: #fff; color: #14532d; font-weight: 700; }
    .gf-nav-link.logout { color: #fca5a5; margin-top: 12px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.12); border-radius: 0; }
    .gf-backdrop { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(2px); z-index: 1055; }
    body.gf-sidebar-open .gf-backdrop { display: block; }

    /* MAIN LAYOUT */
    .main {
        padding: calc(var(--navbar-height) + 24px) clamp(16px, 2vw, 28px) 60px;
        max-width: 1400px; margin: 0 auto; width: 100%; min-height: 100vh;
    }
    .page-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; margin-bottom: 24px; flex-wrap: wrap;
    }
    .page-head-left h1 { font-size: 24px; font-weight: 500; margin: 0 0 4px; letter-spacing: -0.01em; color: var(--md-text-high); }
    .page-head-left p { font-size: 14px; color: var(--md-text-medium); margin: 0; }
    .page-head-right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    
    /* MATERIAL BUTTONS & CHIPS */
    .md-btn {
        display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px;
        border-radius: 4px; font-size: 14px; font-weight: 500;
        text-transform: uppercase; letter-spacing: 0.5px;
        background: var(--md-primary); color: #fff;
        border: none; cursor: pointer; transition: background 0.2s var(--ease);
        box-shadow: var(--md-shadow-1);
    }
    .md-btn:hover { background: #1565C0; box-shadow: var(--md-shadow-2); }
    .md-btn.outline { background: transparent; color: var(--md-primary); box-shadow: none; border: 1px solid var(--md-primary); }
    .md-btn.outline:hover { background: var(--md-primary-light); }
    
    .md-chip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 12px; border-radius: 16px;
        font-size: 12px; font-weight: 600;
        background: var(--md-divider); color: var(--md-text-medium);
    }
    .md-chip.success { background: #E8F5E9; color: var(--md-success); }
    .md-chip.warning { background: #FFF3E0; color: var(--md-warning); }
    .md-chip.error   { background: #FFEBEE; color: var(--md-error); }

    /* HERO SECTION (Elevated Surface) */
    .hero-surface {
        background: var(--md-surface); border-radius: var(--md-radius);
        padding: 32px; margin-bottom: 24px;
        box-shadow: var(--md-shadow-1);
        display: grid; grid-template-columns: 1.5fr 1fr 1fr auto;
        gap: 24px; align-items: center;
    }
    @media (max-width: 1024px) { .hero-surface { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 640px) { .hero-surface { grid-template-columns: 1fr; padding: 24px; gap: 24px; } }

    .hero-main { display: flex; align-items: center; gap: 20px; }
    .hero-icon-wrap {
        width: 72px; height: 72px; border-radius: 50%;
        background: var(--md-primary-light); color: var(--md-primary);
        display: grid; place-items: center; flex-shrink: 0;
    }
    .hero-label { font-size: 12px; font-weight: 600; color: var(--md-text-medium); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
    .hero-temp { font-size: 48px; font-weight: 400; line-height: 1; margin-bottom: 8px; color: var(--md-text-high); }
    .hero-temp .unit { font-size: 24px; color: var(--md-text-medium); margin-left: 4px; }
    .hero-desc { font-size: 16px; font-weight: 500; color: var(--md-text-high); margin-bottom: 8px; }
    .hero-meta { display: flex; gap: 16px; font-size: 14px; color: var(--md-text-medium); }
    .hero-meta strong { color: var(--md-text-high); font-weight: 500; }

    .hero-stat { text-align: left; }
    .hero-stat-value { font-size: 32px; font-weight: 400; line-height: 1; color: var(--md-text-high); margin-bottom: 4px; }
    .hero-stat-label { font-size: 12px; font-weight: 500; color: var(--md-text-medium); text-transform: uppercase; letter-spacing: 0.5px; }

    /* GRID & CARDS */
    .dashboard-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 24px; align-items: stretch; }
    @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }
    .grid-left, .grid-right { display: flex; flex-direction: column; gap: 24px; min-width: 0; }

    .md-card {
        background: var(--md-surface); border-radius: var(--md-radius);
        box-shadow: var(--md-shadow-1); padding: 24px;
        display: flex; flex-direction: column; min-width: 0;
    }
    .md-card-header { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; }
    .md-card-title { font-size: 18px; font-weight: 500; color: var(--md-text-high); margin: 0 0 4px; }
    .md-card-sub { font-size: 13px; color: var(--md-text-medium); margin: 0; }

    /* METRICS (Minimalist) */
    .md-metric-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
    @media (max-width: 640px) { .md-metric-grid { grid-template-columns: repeat(2, 1fr); } }
    .md-metric { display: flex; flex-direction: column; }
    .md-metric-label { font-size: 12px; font-weight: 500; color: var(--md-text-medium); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
    .md-metric-value { font-size: 28px; font-weight: 400; color: var(--md-text-high); line-height: 1; margin-bottom: 4px; }
    .md-metric-value .unit { font-size: 14px; color: var(--md-text-medium); margin-left: 2px; }
    .md-metric-note { font-size: 12px; color: var(--md-text-medium); }

    /* HOURLY CHART */
    .chart-wrap { position: relative; flex: 1; min-height: 200px; margin: 16px 0; display: flex; flex-direction: column; }
    .chart-svg { width: 100%; height: 100%; flex: 1; overflow: visible; }
    .chart-bar { transition: fill 0.15s var(--ease); }
    .chart-bar:hover { fill: rgba(25, 118, 210, 0.45); }
    .chart-bar.active { fill: rgba(25, 118, 210, 0.65); }
    .chart-dot { transition: r 0.15s var(--ease), fill 0.15s var(--ease); }
    .chart-dot.active { r: 7; fill: #1565C0; }

    .hourly-strip { display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--md-divider); }
    @media (max-width: 640px) { .hourly-strip { grid-template-columns: repeat(4, 1fr); } }
    .hour-cell { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 12px 8px; text-align: center; border-radius: var(--md-radius); cursor: pointer; transition: all 0.15s var(--ease); }
    .hour-cell:hover { background: var(--md-background); }
    .hour-cell.active { background: var(--md-primary-light); }
    .hour-time { font-size: 12px; font-weight: 500; color: var(--md-text-medium); }
    .hour-icon { width: 24px; height: 24px; color: var(--md-primary); }
    .hour-temp { font-size: 16px; font-weight: 500; color: var(--md-text-high); }
    .hour-pop { font-size: 12px; color: var(--md-primary); font-weight: 500; }

    /* HOUR DETAIL PANEL */
    .md-hour-detail { margin-top: 16px; padding: 16px; background: var(--md-primary-light); border-radius: var(--md-radius); }
    .md-hour-detail-empty { font-size: 14px; color: var(--md-primary); font-weight: 500; text-align: center; }
    .md-hour-detail-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .md-hd-label { font-size: 12px; font-weight: 500; color: var(--md-primary); text-transform: uppercase; margin-bottom: 4px; }
    .md-hd-value { font-size: 20px; font-weight: 500; color: var(--md-text-high); }

    /* FORECAST (Minimalist Rows) */
    .md-forecast-list { display: flex; flex-direction: column; gap: 8px; }
    .md-forecast-row { display: flex; align-items: center; gap: 16px; padding: 12px 16px; background: var(--md-background); border-radius: var(--md-radius); }
    .md-forecast-day { width: 64px; font-size: 14px; font-weight: 500; color: var(--md-text-high); }
    .md-forecast-icon { width: 24px; height: 24px; color: var(--md-text-medium); }
    .md-forecast-temps { flex: 1; display: flex; gap: 12px; font-size: 14px; font-weight: 500; }
    .md-forecast-hi { color: var(--md-text-high); }
    .md-forecast-lo { color: var(--md-text-medium); }
    .md-forecast-pop { font-size: 13px; font-weight: 500; color: var(--md-primary); }

    /* ACTION CENTER (Material Alerts) */
    .md-alert { display: flex; gap: 12px; padding: 16px; border-radius: var(--md-radius); margin-bottom: 12px; font-size: 14px; line-height: 1.5; }
    .md-alert-icon { flex-shrink: 0; margin-top: 2px; }
    .md-alert-content { flex: 1; }
    .md-alert-title { font-weight: 500; margin-bottom: 4px; }
    .md-alert.high { background: #FFEBEE; color: var(--md-error); }
    .md-alert.medium { background: #FFF3E0; color: var(--md-warning); }
    .md-alert.success { background: #E8F5E9; color: var(--md-success); }
    .md-alert-text { color: var(--md-text-high); font-weight: 400; }

    .md-radio-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
    .md-radio-label { display: flex; align-items: flex-start; gap: 12px; padding: 12px; border: 1px solid var(--md-divider); border-radius: var(--md-radius); cursor: pointer; transition: border 0.2s; }
    .md-radio-label:hover { border-color: var(--md-primary); }
    .md-radio-label input { margin-top: 4px; }
    
    /* MODEL BOUNDS TABLE */
    .md-table-wrap { overflow-x: auto; margin-top: 16px; }
    .md-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .md-table th { text-align: left; padding: 12px; font-weight: 500; color: var(--md-text-medium); border-bottom: 1px solid var(--md-divider); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
    .md-table td { padding: 12px; border-bottom: 1px solid var(--md-divider); color: var(--md-text-high); }
    .md-table tr:last-child td { border-bottom: none; }
    .md-table tr:hover { background: var(--md-background); }
    .md-table .offline-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; background: #FFEBEE; color: var(--md-error); font-size: 10px; font-weight: 600; margin-left: 8px; }
    
    /* MEDIA QUERIES */
    @media (max-width: 991.98px) {
        .gf-navbar { padding: 0 16px; } .gf-navbar-links { display: none; } .gf-navbar-toggle { display: grid; }
    }
    @media (max-width: 767.98px) {
        .gf-navbar { height: 60px; padding: 0 12px; }
        .gf-navbar-logo { width: 38px; height: 38px; font-size: 20px; }
        .gf-navbar-title { font-size: 17px; }
        .main { padding: calc(var(--navbar-height) + 16px) 12px 60px; }
        .hero-temp { font-size: 36px; }
        .hero-temp .unit { font-size: 18px; }
        .hero-icon-wrap { width: 56px; height: 56px; }
    }
</style>

<!-- NAVBAR -->
<nav class="gf-navbar">
    <a href="<?php echo BASE_URL; ?>/app/admin/dashboard.php" class="gf-navbar-brand">
        <div class="gf-navbar-logo">
            <?php if (file_exists(__DIR__ . '/../../assets/images/goat-logo.png')): ?>
                <img src="<?php echo BASE_URL; ?>/assets/images/goat-logo.png" alt="GoatPro Logo">
            <?php else: ?><span aria-hidden="true">🐐</span><?php endif; ?>
        </div>
        <div class="gf-navbar-brand-text"><span class="gf-navbar-title">GoatPro</span></div>
    </a>
    <ul class="gf-navbar-links"><?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'weather'); ?></ul>
    <button class="gf-navbar-toggle" type="button" onclick="toggleSidebar();" aria-label="Menu"><?php echo icon('menu', 22); ?></button>
</nav>

<aside class="gf-sidebar" id="gfSidebar">
    <div class="gf-sidebar-header">
        <div class="gf-brand">
            <div class="gf-brand-icon">🐐</div>
            <div><div class="gf-brand-text">GoatPro</div><div class="gf-brand-sub">Admin · Weather</div></div>
        </div>
        <button class="gf-sidebar-close" type="button" onclick="closeSidebar();"><?php echo icon('x', 18); ?></button>
    </div>
    <nav class="gf-nav-section"><?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'weather'); ?></nav>
</aside>
<div class="gf-backdrop" onclick="closeSidebar();"></div>

<!-- MAIN -->
<div class="main-content" id="main-content">
    <div class="container">
        <main class="main">

            <!-- PAGE HEADER -->
            <div class="page-head">
                <div class="page-head-left">
                    <h1>Weather Overview</h1>
                    <p><?php echo MANOLO_FORTICH_NAME; ?></p>
                </div>
                <div class="page-head-right">
                    <span class="md-chip"><?php echo date('D, M j, Y'); ?></span>
                    <a href="?refresh=1" class="md-btn"><?php echo icon('refresh', 16); ?> Refresh</a>
                </div>
            </div>

            <!-- HERO SECTION -->
            <div class="hero-surface">
                <div class="hero-main">
                    <div class="hero-icon-wrap">
                        <?php echo wxIcon(owIconName($weatherIcon), 40); ?>
                    </div>
                    <div class="hero-info">
                        <div class="hero-label">Current Weather</div>
                        <div class="hero-temp"><?php echo $temp; ?><span class="unit">°C</span></div>
                        <div class="hero-desc"><?php echo htmlspecialchars($weatherDesc); ?></div>
                        <div class="hero-meta">
                            <span>Feels like <strong><?php echo $feelsLike; ?>°</strong></span>
                            <span>Wind <strong><?php echo $windSpeed; ?> km/h</strong></span>
                        </div>
                    </div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-value"><?php echo $humidity; ?><span class="unit">%</span></div>
                    <div class="hero-stat-label">Humidity</div>
                </div>
                <div class="hero-stat">
                    <div class="hero-stat-value"><?php echo $rainToday; ?><span class="unit">mm</span></div>
                    <div class="hero-stat-label">Rain Today</div>
                </div>
                <a href="#forecast" class="md-btn outline">5-Day Forecast</a>
            </div>

            <!-- MAIN GRID -->
            <div class="dashboard-grid">

                <!-- LEFT COLUMN -->
                <div class="grid-left">

                    <!-- METRIC STRIP -->
                    <div class="md-card">
                        <div class="md-metric-grid">
                            <div class="md-metric">
                                <div class="md-metric-label">Heat Index (THI)</div>
                                <div class="md-metric-value"><?php echo round($thi); ?><span class="unit"></span></div>
                                <div class="md-metric-note"><?php echo $heat['label']; ?> stress</div>
                            </div>
                            <div class="md-metric">
                                <div class="md-metric-label">Rain Tonight</div>
                                <div class="md-metric-value"><?php echo $rainTonight; ?><span class="unit">mm</span></div>
                                <div class="md-metric-note"><?php echo $rainTonight > 2 ? 'Bring to shelter' : 'Minimal'; ?></div>
                            </div>
                            <div class="md-metric">
                                <div class="md-metric-label">Wind Direction</div>
                                <div class="md-metric-value"><?php echo $windDir; ?></div>
                                <div class="md-metric-note"><?php echo $windSpeed; ?> km/h</div>
                            </div>
                            <div class="md-metric">
                                <div class="md-metric-label">Grazing Status</div>
                                <div class="md-metric-value" style="font-size: 20px; margin-top: 4px;"><?php echo $hasHighPop ? 'Caution' : ($temp >= 32 ? 'Limited' : 'Safe'); ?></div>
                                <div class="md-metric-note"><?php echo $hasHighPop ? 'Rain likely' : 'Good conditions'; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- HOURLY FORECAST -->
                    <div class="md-card">
                        <div class="md-card-header">
                            <div>
                                <h2 class="md-card-title">Hourly Forecast</h2>
                                <p class="md-card-sub">Next 24 hours</p>
                            </div>
                            <span class="md-chip">Click a point for details</span>
                        </div>

                        <div class="chart-wrap">
                            <svg class="chart-svg" viewBox="0 0 800 200" preserveAspectRatio="none" id="hourlyChart">
                                <defs>
                                    <linearGradient id="tempGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#1976D2"/><stop offset="100%" stop-color="#64B5F6"/></linearGradient>
                                    <linearGradient id="tempFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="rgba(25,118,210,0.15)"/><stop offset="100%" stop-color="rgba(25,118,210,0)"/></linearGradient>
                                </defs>
                                <g stroke="#EEEEEE" stroke-width="1">
                                    <line x1="0" y1="50" x2="800" y2="50"/><line x1="0" y1="100" x2="800" y2="100"/><line x1="0" y1="150" x2="800" y2="150"/>
                                </g>
                                <?php
                                $chartH = 200; $chartW = 800;
                                $numPoints = max(count($hourlyData), 1);
                                $stepX = $numPoints > 1 ? $chartW / ($numPoints - 1) : $chartW;
                                $temps = array_column($hourlyData, 'temp');
                                $maxTemp = !empty($temps) ? max($temps) + 3 : 35;
                                $minTemp = !empty($temps) ? min($temps) - 3 : 20;
                                $tempRange = max(1, $maxTemp - $minTemp);

                                $tempPoints = []; $rainBars = [];
                                foreach ($hourlyData as $i => $h) {
                                    $x = $i * $stepX;
                                    $yTemp = $chartH - (($h['temp'] - $minTemp) / $tempRange) * ($chartH - 40) - 20;
                                    $tempPoints[] = [$x, $yTemp];
                                    $rainBars[] = ['x' => $x, 'h' => ($h['pop'] / 100) * 60];
                                }
                                $pathD = '';
                                foreach ($tempPoints as $i => $pt) {
                                    $pathD .= ($i === 0 ? 'M' : ' L') . ' ' . $pt[0] . ' ' . $pt[1];
                                }
                                $fillD = $pathD . ' L ' . $chartW . ' ' . $chartH . ' L 0 ' . $chartH . ' Z';
                                ?>
                                <?php foreach ($rainBars as $i => $bar): ?>
                                    <rect x="<?php echo $bar['x'] - 8; ?>" y="<?php echo $chartH - $bar['h']; ?>" width="16" height="<?php echo $bar['h']; ?>" fill="rgba(25,118,210,0.15)" rx="3" class="chart-bar" data-index="<?php echo $i; ?>" style="cursor:pointer;"/>
                                <?php endforeach; ?>
                                <path d="<?php echo $fillD; ?>" fill="url(#tempFill)"/>
                                <path d="<?php echo $pathD; ?>" fill="none" stroke="url(#tempGrad)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                <?php foreach ($tempPoints as $i => $pt): ?>
                                    <circle cx="<?php echo $pt[0]; ?>" cy="<?php echo $pt[1]; ?>" r="16" fill="transparent" class="chart-hit" data-index="<?php echo $i; ?>" style="cursor:pointer;"/>
                                    <circle cx="<?php echo $pt[0]; ?>" cy="<?php echo $pt[1]; ?>" r="5" fill="#1976D2" stroke="#fff" stroke-width="2.5" class="chart-dot" data-index="<?php echo $i; ?>" style="pointer-events:none;"/>
                                    <text x="<?php echo $pt[0]; ?>" y="<?php echo $pt[1] - 12; ?>" text-anchor="middle" font-size="12" font-weight="600" fill="#1976D2" font-family="var(--font-family)" style="pointer-events:none;"><?php echo $hourlyData[$i]['temp']; ?>°</text>
                                <?php endforeach; ?>
                            </svg>
                        </div>

                        <div class="md-hour-detail" id="hourDetail">
                            <div class="md-hour-detail-empty" id="hourDetailEmpty">Tap a point or an hour below to see detailed forecast</div>
                            <div class="md-hour-detail-content" id="hourDetailContent" style="display:none;">
                                <div class="md-card-title" id="hdTime" style="margin-bottom: 12px;">—</div>
                                <div class="md-hour-detail-row">
                                    <div><div class="md-hd-label">Temperature</div><div class="md-hd-value" id="hdTemp">—</div></div>
                                    <div><div class="md-hd-label">Rain Chance</div><div class="md-hd-value" id="hdPop">—</div></div>
                                    <div><div class="md-hd-label">Condition</div><div class="md-hd-value" id="hdCond">—</div></div>
                                </div>
                            </div>
                        </div>

                        <div class="hourly-strip">
                            <?php foreach ($hourlyData as $i => $h): $isHot = $h['temp'] >= 30; ?>
                                <div class="hour-cell" data-index="<?php echo $i; ?>" data-time="<?php echo htmlspecialchars($h['time']); ?>" data-temp="<?php echo $h['temp']; ?>" data-pop="<?php echo $h['pop']; ?>" data-icon="<?php echo htmlspecialchars(owIconName($h['icon'])); ?>" role="button" tabindex="0">
                                    <span class="hour-time"><?php echo $h['time']; ?></span>
                                    <div class="hour-icon"><?php echo wxIcon(owIconName($h['icon']), 24); ?></div>
                                    <span class="hour-temp"><?php echo $h['temp']; ?>°</span>
                                    <span class="hour-pop"><?php echo $h['pop']; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 5-DAY FORECAST -->
                    <div class="md-card" id="forecast">
                        <div class="md-card-header">
                            <div>
                                <h2 class="md-card-title">5-Day Forecast</h2>
                                <p class="md-card-sub"><?php echo MANOLO_FORTICH_NAME; ?></p>
                            </div>
                        </div>
                        <div class="md-forecast-list">
                            <?php
                            for ($i = 0; $i < 5; $i++):
                                if (isset($dailyForecast[$i])) {
                                    $d = $dailyForecast[$i];
                                    $label = $i === 0 ? 'Today' : date('D', $d['dt']);
                                    $iconName = owIconName($d['icon']);
                                    $tempHi = $d['temp_hi'];
                                    $tempLo = $d['temp_lo'];
                                    $pop = $d['pop'];
                                } else {
                                    $label = $i === 0 ? 'Today' : date('D', strtotime("+$i days"));
                                    $iconName = ['sun','cloud-sun','cloud','cloud-rain','cloud-sun'][$i];
                                    $tempHi = [28, 29, 27, 26, 28][$i];
                                    $tempLo = [22, 23, 22, 21, 22][$i];
                                    $pop = [10, 20, 30, 60, 25][$i];
                                }
                            ?>
                                <div class="md-forecast-row">
                                    <div class="md-forecast-day"><?php echo $label; ?></div>
                                    <div class="md-forecast-icon"><?php echo wxIcon($iconName, 24); ?></div>
                                    <div class="md-forecast-temps">
                                        <span class="md-forecast-hi"><?php echo $tempHi; ?>°</span>
                                        <span class="md-forecast-lo"><?php echo $tempLo; ?>°</span>
                                    </div>
                                    <div class="md-forecast-pop"><?php echo $pop; ?>%</div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                </div>

                <!-- RIGHT COLUMN -->
                <div class="grid-right">

                    <!-- MODEL BOUNDS ANALYSIS (Now Top Right) -->
                    <div class="md-card">
                        <div class="md-card-header">
                            <div>
                                <h2 class="md-card-title">Model Bounds Analysis</h2>
                                <p class="md-card-sub">Interpolated mean with min/max envelope</p>
                            </div>
                            <span class="md-chip <?php echo ($boundsData && $boundsData['active'] == 4) ? 'success' : 'warning'; ?>">
                                <?php if ($boundsData): ?><?php echo (int)$boundsData['active']; ?>/<?php echo (int)$boundsData['total']; ?> sources<?php else: ?>unavailable<?php endif; ?>
                            </span>
                        </div>

                        <?php if ($boundsData && !empty($boundsData['bounds'])): ?>
                            <?php $b = $boundsData['bounds']; $src = $boundsData['sources']; $chartData = $boundsData['chart_data'] ?? null; ?>
                            
                            <div class="md-metric-grid" style="margin-bottom: 24px;">
                                <?php
                                $rows = [['Temperature', $b['temp'], '°C', 1], ['Humidity', $b['humidity'], '%', 0], ['Wind', $b['wind'], ' km/h', 1], ['Rain (1h)', $b['rain'], ' mm', 1]];
                                foreach ($rows as $r):
                                    [$label, $stat, $unit, $dp] = $r;
                                    $has = $stat && $stat['value'] !== null;
                                ?>
                                    <div class="md-metric">
                                        <div class="md-metric-label"><?php echo $label; ?></div>
                                        <div class="md-metric-value"><?php echo $has ? number_format($stat['value'], $dp) : '—'; ?><span class="unit"><?php echo $unit; ?></span></div>
                                        <div class="md-metric-note"><?php if ($has): ?>Bounds <?php echo number_format($stat['min'], $dp); ?> – <?php echo number_format($stat['max'], $dp); ?><?php else: ?>No data<?php endif; ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($chartData && !empty($chartData['time'])): ?>
                            <div style="margin-bottom: 24px;">
                                <div style="font-size: 12px; font-weight: 500; color: var(--md-text-medium); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">24h Temperature Trendline</div>
                                <div style="position: relative; height: 220px; width: 100%;"><canvas id="boundsChart"></canvas></div>
                            </div>
                            <?php endif; ?>

                            <div class="md-table-wrap">
                                <table class="md-table">
                                    <thead>
                                        <tr>
                                            <th>Source</th>
                                            <th style="text-align:right;">Temp</th>
                                            <th style="text-align:right;">Humid</th>
                                            <th style="text-align:right;">Wind</th>
                                            <th style="text-align:right;">Rain</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($src as $name => $s): ?>
                                            <?php $hasData = !empty($s['available']) && ($s['temp'] !== null || $s['humidity'] !== null || $s['wind'] !== null || $s['rain'] !== null); ?>
                                            <tr>
                                                <td style="font-weight: 500;">
                                                    <?php echo htmlspecialchars(str_replace('_', ' ', $name)); ?>
                                                    <?php if (!$hasData): ?><span class="offline-badge">OFFLINE</span><?php endif; ?>
                                                </td>
                                                <td style="text-align:right;"><?php echo $hasData && $s['temp'] !== null ? number_format($s['temp'], 1) . '°C' : '—'; ?></td>
                                                <td style="text-align:right;"><?php echo $hasData && $s['humidity'] !== null ? number_format($s['humidity'], 0) . '%' : '—'; ?></td>
                                                <td style="text-align:right;"><?php echo $hasData && $s['wind'] !== null ? number_format($s['wind'], 1) : '—'; ?></td>
                                                <td style="text-align:right;"><?php echo $hasData && $s['rain'] !== null ? number_format($s['rain'], 1) : '—'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="md-alert high">
                                <div class="md-alert-icon">⚠️</div>
                                <div class="md-alert-content">
                                    <div class="md-alert-title">Data Unavailable</div>
                                    <div class="md-alert-text"><?php echo htmlspecialchars($boundsError ?: 'Could not load model bounds.'); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ACTION CENTER (Now Bottom Right) -->
                    <div class="md-card">
                        <div class="md-card-header">
                            <div>
                                <h2 class="md-card-title">Action Center</h2>
                                <p class="md-card-sub">Advisories and grazing tips</p>
                            </div>
                            <span class="md-chip <?php echo $heat['level'] === 'high' ? 'error' : ($heat['level'] === 'mid' ? 'warning' : 'success'); ?>">
                                Priority
                            </span>
                        </div>

                        <?php if (!empty($advisories)): ?>
                            <form method="POST">
                                <div class="md-radio-group">
                                    <?php foreach ($advisories as $i => $adv): ?>
                                        <label class="md-radio-label <?php echo $adv['priority']; ?>">
                                            <input type="radio" name="advisory_index" value="<?php echo $i; ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                                            <div>
                                                <div class="md-alert-title" style="color: <?php echo $adv['priority'] === 'high' ? 'var(--md-error)' : 'var(--md-warning)'; ?>;">
                                                    <?php echo htmlspecialchars($adv['title']); ?>
                                                </div>
                                                <div class="md-alert-text" style="font-size: 13px; margin-top: 4px;">
                                                    <?php echo htmlspecialchars($adv['message']); ?>
                                                </div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="send_advisory" class="md-btn" style="width: 100%; justify-content: center;" onclick="return confirm('Ipadala ang SMS advisory sa tanan opted-in farmers?');">
                                    Send Advisory via SMS
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="md-alert success">
                                <div class="md-alert-icon">✅</div>
                                <div class="md-alert-content">
                                    <div class="md-alert-title">No Active Advisories</div>
                                    <div class="md-alert-text">Weather conditions are optimal for livestock.</div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($sendResult): ?>
                            <div class="md-alert <?php echo $sendResult['success'] ? 'success' : 'high'; ?>" style="margin-top: 16px;">
                                <div class="md-alert-icon"><?php echo $sendResult['success'] ? '✅' : '❌'; ?></div>
                                <div class="md-alert-content">
                                    <div class="md-alert-title"><?php echo $sendResult['success'] ? 'Advisory Sent' : 'Failed to Send'; ?></div>
                                    <div class="md-alert-text">
                                        <?php if ($sendResult['success']): ?>
                                            Sent to <?php echo $sendResult['success_count']; ?> of <?php echo $sendResult['recipient_count']; ?> farmers.
                                        <?php else: ?>
                                            Error: <?php echo htmlspecialchars($sendResult['error'] ?? 'Unknown error'); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 24px; border-top: 1px solid var(--md-divider); padding-top: 16px;">
                            <div class="md-alert <?php echo ($temp >= 30 || $hasHighPop) ? 'medium' : 'success'; ?>" style="margin-bottom: 8px; padding: 12px;">
                                <div class="md-alert-content">
                                    <div class="md-alert-title" style="font-size: 13px;">Grazing</div>
                                    <div class="md-alert-text" style="font-size: 12px;">
                                        <?php if ($hasHighPop): ?>High chance of rain. Prepare to bring goats to shelter.
                                        <?php elseif ($temp >= 32): ?>Too hot for extended grazing. Provide shade.
                                        <?php elseif ($temp >= 30): ?>Safe to graze until 3-4 PM. Provide shade.
                                        <?php else: ?>Safe to graze until 4-5 PM. Good conditions.<?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="md-alert <?php echo ($rainTonight > 2 || $hasHighPop) ? 'medium' : 'success'; ?>" style="margin-bottom: 0; padding: 12px;">
                                <div class="md-alert-content">
                                    <div class="md-alert-title" style="font-size: 13px;">Rain Warning</div>
                                    <div class="md-alert-text" style="font-size: 12px;">
                                        <?php if ($rainTonight > 5): ?>Heavy rain expected. Bring goats into barn.
                                        <?php elseif ($rainTonight > 2): ?>Rain expected (<?php echo $rainTonight; ?> mm). Bring goats into barn.
                                        <?php elseif ($hasHighPop): ?>High probability of rain. Monitor the sky.
                                        <?php else: ?>Minimal rain expected. Normal care is fine.<?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </main>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function toggleSidebar() { document.body.classList.toggle('gf-sidebar-open'); }
function closeSidebar() { document.body.classList.remove('gf-sidebar-open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

(function() {
    'use strict';
    const hourDetailEmpty = document.getElementById('hourDetailEmpty');
    const hourDetailContent = document.getElementById('hourDetailContent');
    const hdTime = document.getElementById('hdTime'), hdTemp = document.getElementById('hdTemp'), hdPop = document.getElementById('hdPop'), hdCond = document.getElementById('hdCond');
    if (!hourDetailEmpty || !hourDetailContent) return;
    let activeIndex = -1;

    function iconLabel(iconName) { const map = {'sun': 'Sunny', 'cloud-sun': 'Partly Cloudy', 'cloud': 'Cloudy', 'cloud-rain': 'Rainy'}; return map[iconName] || 'Clear'; }
    function popLabel(pop) { if (pop === 0) return 'None'; if (pop < 30) return 'Low'; if (pop < 60) return 'Moderate'; if (pop < 80) return 'High'; return 'Very High'; }

    function selectHour(index, scrollIntoView) {
        if (activeIndex === index) return; activeIndex = index;
        const cell = document.querySelector('.hour-cell[data-index="' + index + '"]'); if (!cell) return;
        hdTime.textContent = cell.dataset.time; hdTemp.textContent = cell.dataset.temp + '°C';
        hdPop.textContent = cell.dataset.pop + '% · ' + popLabel(parseInt(cell.dataset.pop)); hdCond.textContent = iconLabel(cell.dataset.icon);
        hourDetailEmpty.style.display = 'none'; hourDetailContent.style.display = 'block';

        document.querySelectorAll('.hour-cell').forEach(el => el.classList.remove('active')); cell.classList.add('active');
        document.querySelectorAll('.chart-dot').forEach(el => el.classList.remove('active'));
        const dot = document.querySelector('.chart-dot[data-index="' + index + '"]'); if (dot) dot.classList.add('active');
        document.querySelectorAll('.chart-bar').forEach(el => el.classList.remove('active'));
        const bar = document.querySelector('.chart-bar[data-index="' + index + '"]'); if (bar) bar.classList.add('active');
        if (scrollIntoView) cell.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    }

    document.querySelectorAll('.hour-cell').forEach(cell => {
        cell.addEventListener('click', () => selectHour(parseInt(cell.dataset.index), false));
        cell.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectHour(parseInt(this.dataset.index), false); } });
    });
    document.querySelectorAll('.chart-hit').forEach(hit => {
        hit.addEventListener('click', () => selectHour(parseInt(hit.dataset.index), true));
        hit.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectHour(parseInt(this.dataset.index), true); } });
    });
    document.querySelectorAll('.chart-bar').forEach(bar => bar.addEventListener('click', () => selectHour(parseInt(bar.dataset.index), true)));
    if (document.querySelector('.hour-cell[data-index="0"]')) selectHour(0, false);
})();

<?php if ($boundsData && !empty($boundsData['chart_data'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    const chartData = <?php echo json_encode($boundsData['chart_data']); ?>;
    const ctx = document.getElementById('boundsChart'); if (!ctx) return;
    const labels = chartData.time.map(t => new Date(t).toLocaleTimeString('en-US', { hour: 'numeric', hour12: true }));
    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, 'rgba(25, 118, 210, 0.2)');
    gradient.addColorStop(1, 'rgba(25, 118, 210, 0)');

    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Interpolated Mean', data: chartData.mean, borderColor: '#1976D2', backgroundColor: gradient, borderWidth: 3, pointRadius: 0, pointHoverRadius: 6, fill: true, tension: 0.4 },
                { label: 'OpenWeather', data: chartData.ow, borderColor: 'rgba(255, 160, 0, 0.7)', borderWidth: 1.5, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 4, fill: false, tension: 0.4, spanGaps: true },
                { label: 'Open-Meteo', data: chartData.om, borderColor: 'rgba(100, 181, 246, 0.7)', borderWidth: 1.5, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 4, fill: false, tension: 0.4 },
                { label: 'ECMWF', data: chartData.ec, borderColor: 'rgba(56, 142, 60, 0.7)', borderWidth: 1.5, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 4, fill: false, tension: 0.4 },
                { label: 'MET Norway', data: chartData.metno, borderColor: 'rgba(156, 39, 176, 0.7)', borderWidth: 1.5, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 4, fill: false, tension: 0.4, spanGaps: true }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: true, position: 'top', labels: { boxWidth: 8, boxHeight: 8, font: { size: 11, family: 'var(--font-family)' }, usePointStyle: true, pointStyle: 'circle' } },
                tooltip: { backgroundColor: 'rgba(33, 33, 33, 0.95)', titleFont: { size: 12, weight: '500' }, bodyFont: { size: 12 }, padding: 12, boxPadding: 6, callbacks: { label: function(context) { let label = context.dataset.label || ''; if (label) label += ': '; if (context.parsed.y !== null) label += context.parsed.y + '°C'; return label; } } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 }, color: 'var(--md-text-medium)', maxTicksLimit: 6 } },
                y: { grid: { color: 'var(--md-divider)', drawBorder: false }, ticks: { font: { size: 11 }, color: 'var(--md-text-medium)', callback: function(value) { return value + '°'; } } }
            }
        }
    });
});
<?php endif; ?>
</script>

<?php renderFooter(); ?>