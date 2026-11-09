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
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'GoatPro/1.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError || $httpCode !== 200 || !$response) {
        error_log('[Weather] API error: ' . ($curlError ?: 'HTTP ' . $httpCode));
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data) || (isset($data['cod']) && (int)$data['cod'] !== 200)) return null;
    return $data;
}

function getCurrentWeather(): ?array
{
    $key = 'wx_current_' . date('YmdH');
    if (isset($_SESSION['_wx_cache'][$key]) && (time() - $_SESSION['_wx_cache'][$key]['t']) < WEATHER_CACHE_TTL) {
        return $_SESSION['_wx_cache'][$key]['v'];
    }
    $data = fetchOpenWeather('weather', ['lat' => MANOLO_FORTICH_LAT, 'lon' => MANOLO_FORTICH_LON, 'units' => 'metric']);
    if ($data) $_SESSION['_wx_cache'][$key] = ['t' => time(), 'v' => $data];
    return $data;
}

function getForecastWeather(): ?array
{
    $key = 'wx_forecast_' . date('YmdH');
    if (isset($_SESSION['_wx_cache'][$key]) && (time() - $_SESSION['_wx_cache'][$key]['t']) < WEATHER_CACHE_TTL) {
        return $_SESSION['_wx_cache'][$key]['v'];
    }
    $data = fetchOpenWeather('forecast', ['lat' => MANOLO_FORTICH_LAT, 'lon' => MANOLO_FORTICH_LON, 'units' => 'metric']);
    if ($data) $_SESSION['_wx_cache'][$key] = ['t' => time(), 'v' => $data];
    return $data;
}

function calculateTHI(float $temp, float $humidity): float
{
    return (1.8 * $temp + 32) - (0.55 - 0.0055 * $humidity) * (1.8 * $temp - 26);
}

function getHeatStress(float $thi): array
{
    if ($thi >= 85) return ['label' => 'Severe',   'level' => 'high', 'desc' => 'Dangerous conditions. Immediate action needed.'];
    if ($thi >= 79) return ['label' => 'Moderate', 'level' => 'mid',  'desc' => 'High heat stress. Provide shade and water.'];
    if ($thi >= 72) return ['label' => 'Mild',     'level' => 'mid',  'desc' => 'Monitor goats. Increase ventilation.'];
    return ['label' => 'Comfortable', 'level' => 'low', 'desc' => 'Good temperature for goats. Low heat stress.'];
}

function getRainLevel(float $mm): array
{
    if ($mm < 0.5) return ['label' => 'No Rain',       'color' => 'green'];
    if ($mm < 2.5) return ['label' => 'Light Rain',    'color' => 'green'];
    if ($mm < 7.5) return ['label' => 'Moderate Rain', 'color' => 'amber'];
    return ['label' => 'Heavy Rain', 'color' => 'red'];
}

function getWindDirection(int $deg): string
{
    $dirs = ['North','NE','East','SE','South','SW','West','NW'];
    return $dirs[round($deg / 45) % 8];
}

function groupForecastByDay(?array $forecast): array
{
    if (!$forecast || empty($forecast['list'])) return [];
    $grouped = [];
    foreach ($forecast['list'] as $item) {
        $key = date('Y-m-d', $item['dt']);
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['dt' => strtotime(date('Y-m-d 12:00:00', $item['dt'])), 'temps' => [], 'icons' => [], 'pops' => []];
        }
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

function getHourlyData(?array $forecast, int $limit = 8): array
{
    if (!$forecast || empty($forecast['list'])) return [];
    $hours = [];
    foreach (array_slice($forecast['list'], 0, $limit) as $item) {
        $hours[] = [
            'time' => date('H:i', $item['dt']),
            'temp' => round($item['main']['temp']),
            'pop'  => round(($item['pop'] ?? 0) * 100),
            'icon' => $item['weather'][0]['icon'] ?? '01d',
        ];
    }
    return $hours;
}

function owIconName(string $code): string
{
    $map = ['01'=>'sun','02'=>'cloud-sun','03'=>'cloud','04'=>'cloud','09'=>'cloud-rain','10'=>'cloud-rain','11'=>'cloud-rain','13'=>'cloud-rain','50'=>'cloud'];
    return $map[substr($code, 0, 2)] ?? 'cloud';
}

function wxIcon(string $name, int $size = 32): string
{
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
    function icon(string $name, int $size = 16, string $class = '', string $strokeWidth = '2'): string
    {
        static $paths = [
            'menu'    => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
            'x'       => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        ];
        $path = $paths[$name] ?? $paths['menu'];
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%2$s" stroke-linecap="round" stroke-linejoin="round"%3$s aria-hidden="true" style="display:inline-block;vertical-align:middle;flex-shrink:0;">%4$s</svg>',
            $size, htmlspecialchars($strokeWidth, ENT_QUOTES, 'UTF-8'), $classAttr, $path
        );
    }
}

// ============================================================
// FETCH DATA
// ============================================================
if (isset($_GET['refresh']) && $_GET['refresh'] === '1') {
    unset($_SESSION['_wx_cache']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$currentRaw  = getCurrentWeather();
$forecastRaw = getForecastWeather();

if ($currentRaw) {
    $temp        = round($currentRaw['main']['temp']);
    $feelsLike   = round($currentRaw['main']['feels_like']);
    $humidity    = (int)$currentRaw['main']['humidity'];
    $windSpeed   = round($currentRaw['wind']['speed'] * 3.6, 1);
    $windDeg     = (int)($currentRaw['wind']['deg'] ?? 0);
    $weatherMain = $currentRaw['weather'][0]['main'] ?? 'Clear';
    $weatherDesc = ucfirst($currentRaw['weather'][0]['description'] ?? 'Clear');
    $weatherIcon = $currentRaw['weather'][0]['icon'] ?? '01d';
    $lastUpdate  = $currentRaw['dt'] ?? time();
} else {
    $temp = 28; $feelsLike = 30; $humidity = 76;
    $windSpeed = 12; $windDeg = 90; $weatherMain = 'Clear'; $weatherDesc = 'Sunny & Dry';
    $weatherIcon = '01d'; $lastUpdate = time();
}

$thi           = calculateTHI($temp, $humidity);
$heat          = getHeatStress($thi);
$windDir       = getWindDirection($windDeg);
$dailyForecast = groupForecastByDay($forecastRaw);
$hourlyData    = getHourlyData($forecastRaw, 8);

$rainToday = 0;
if ($forecastRaw && !empty($forecastRaw['list'])) {
    $todayKey = date('Y-m-d');
    foreach ($forecastRaw['list'] as $item) {
        if (date('Y-m-d', $item['dt']) === $todayKey) {
            $rainToday += $item['rain']['3h'] ?? 0;
        }
    }
}
$rainToday = round($rainToday, 1);
$rainInfo  = getRainLevel($rainToday);

$rainTonight = 0;
if ($forecastRaw && !empty($forecastRaw['list'])) {
    $todayKey = date('Y-m-d');
    foreach ($forecastRaw['list'] as $item) {
        $h = (int)date('G', $item['dt']);
        if (date('Y-m-d', $item['dt']) === $todayKey && $h >= 18) {
            $rainTonight += $item['rain']['3h'] ?? 0;
        }
    }
}
$rainTonight = round($rainTonight, 1);

// ============================================================
// SUNRISE TIGWAY ADVISORY (Past 12 Hours Rain)
// ============================================================
$past12hRain = 0;
$currentHour = (int)date('G'); 

if ($forecastRaw && !empty($forecastRaw['list'])) {
    $now = time();
    foreach ($forecastRaw['list'] as $item) {
        if ($item['dt'] < $now && $item['dt'] >= ($now - 43200)) {
            $past12hRain += $item['rain']['3h'] ?? 0;
        }
    }
}
$past12hRain = round($past12hRain, 1);
$isMorning = ($currentHour >= 6 && $currentHour <= 9);
$morningTigwayAdvisory = ($isMorning && $past12hRain > 1);

// ============================================================
// SMS ADVISORY DETECTION (BISAYA VERSION & ACCURATE)
// ============================================================
$advisories = [];

// Check high probability of rain in the next 6 hours (Accurate detection base sa API)
$highPopHours = array_filter($hourlyData, fn($h) => $h['pop'] >= 70);
$hasHighPop = count($highPopHours) >= 3;

// 0. Sunrise Tigway Advisory (Medium Priority)
if ($morningTigwayAdvisory) {
    $advisories[] = [
        'type'     => 'rain',
        'priority' => 'medium',
        'title'    => 'Pahibalo: Pwede na Mag-tigway',
        'message'  => "Maayong buntag! Nihunong na ang ulan gabii. Pwede na ninyo matugway (ipagawas) ang mga kanding sugod 7:00 AM kay moinit na ang adlaw ug mo-uga na ang yuta.",
    ];
}

// 1. Heavy Rain Advisory (High Priority)
if ($rainTonight > 5 || $rainToday > 15) {
    $advisories[] = [
        'type'     => 'rain',
        'priority' => 'high',
        'title'    => 'Pahibalo: Kusog nga Ulan',
        'message'  => "URGENTE: Kusog nga ulan ({$rainTonight}mm) ang gi-expect karong gabhiona. Palihog pasulod sa kural o silong ang mga kanding aron dili mabasa ug matugnawan. Ayaw pagpanggawas hangtod mohunong ang ulan ug moinit ang panahon ugma.",
    ];
} 
// 2. Light Rain or High Probability (Medium Priority)
elseif ($rainTonight > 2 || $hasHighPop) {
    $advisories[] = [
        'type'     => 'rain',
        'priority' => 'medium',
        'title'    => 'Pahibalo: Posibleng Ulan',
        'message'  => "Pahibalo: Posibleng naay ulan karong adlawa o gabhiona. Kung mabasa ang kasagbutan, palihog ayaw lang sa ipagawas (itugway) ang kanding inig ka-buntag kay basa ug tugnaw pa. Hulata nga moinit ang adlaw mga 7:00 AM pataas aron mo-uga ang yuta bag-o itugway.",
    ];
}

// 3. Heat Advisory (High Priority)
if ($heat['level'] === 'high') {
    $advisories[] = [
        'type'     => 'heat',
        'priority' => 'high',
        'title'    => 'Pahibalo: Sobrang Init',
        'message'  => "Pahibalo: Grabe ang kainit karon (THI: " . round($thi) . "). Siguroha nga naay dakong landong ang kanding ug kanunay naay limpyo nga tubig nga mainom aron malikayan ang heat stress.",
    ];
} 
// 4. Mild Heat (Medium Priority)
elseif ($heat['level'] === 'mid' && $temp >= 30) {
    $advisories[] = [
        'type'     => 'heat',
        'priority' => 'medium',
        'title'    => 'Pahibalo: Init-init ang Panahon',
        'message'  => "Pahibalo: Medyo init ang panahon karon. Palihog siguroha nga naay landong ug daghan nga tubig nga mainom ang mga kanding aron dili ma-stress sa kainit.",
    ];
}

// Determine highest priority
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

        $sendResult = sendAdvisoryToFarmers(
            $pdo,
            $selected['message'],
            $selected['type'],
            $selected['priority'],
            [
                'rainToday'    => $rainToday,
                'rainTonight'  => $rainTonight,
                'temp'         => $temp,
                'humidity'     => $humidity,
                'thi'          => round($thi),
                'heat_level'   => $heat['level'],
                'triggered_by' => $_SESSION['user_id'] ?? null,
            ],
            $_SESSION['user_id'] ?? null
        );
    } else {
        $sendResult = ['success' => false, 'error' => 'Invalid advisory selection'];
    }
}

$lastUpdateStr = date('g:i A', $lastUpdate);

$rawEmail = $_SESSION['email'] ?? 'Admin';
$displayName = mb_strlen($rawEmail) > 20 ? mb_substr($rawEmail, 0, 17) . '…' : $rawEmail;

$title = 'Weather — ' . $displayName;
renderHeader($title);
?>

<style>
    :root {
        --bg: #f5f7fa; --surface: #ffffff;
        --text: #0f172a; --text-2: #475569; --text-3: #94a3b8;
        --border: #e5e9ef; --border-2: #f1f5f9;
        --primary: #2563eb; --primary-light: #dbeafe; --primary-dark: #1d4ed8;
        --green: #16a34a; --green-light: #dcfce7;
        --amber: #d97706; --amber-light: #fef3c7;
        --red: #dc2626; --red-light: #fee2e2;
        --navbar-height: 68px; --sidebar-width: 264px;
        --radius: 14px; --radius-sm: 10px;
        --ease: cubic-bezier(0.4, 0, 0.2, 1);
    }
    *, *::before, *::after { box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; background: var(--bg); color: var(--text);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px; line-height: 1.5; overflow-x: hidden; -webkit-font-smoothing: antialiased;
    }
    img, svg { max-width: 100%; display: block; }
    button { font-family: inherit; }
    a { color: inherit; }
    .main-content, .main-content .container {
        width: 100% !important; max-width: 100% !important; margin: 0 !important;
        padding: 0 !important; background: var(--bg) !important; display: block !important;
    }

    /* NAVBAR */
    .gf-navbar {
        position: fixed; top: 0; left: 0; right: 0; height: var(--navbar-height);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 24px; z-index: 1050; box-shadow: 0 2px 12px rgba(15, 23, 42, 0.15);
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
    .gf-navbar-sub { font-size: 10px; font-weight: 700; color: rgba(255, 255, 255, 0.65); text-transform: uppercase; letter-spacing: 0.12em; margin-top: 2px; }
    .gf-navbar-links { display: flex; align-items: center; gap: 4px; list-style: none; margin: 0; padding: 0; }
    .gf-navbar-links li a {
        display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px;
        border-radius: 10px; font-size: 14px; font-weight: 600;
        color: rgba(255, 255, 255, 0.82); text-decoration: none; white-space: nowrap; transition: all 0.2s var(--ease);
    }
    .gf-navbar-links li a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-navbar-links li a.active { background: #fff; color: #14532d; font-weight: 700; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18); }
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
    .gf-nav-link { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 11px; color: rgba(255, 255, 255, 0.82); font-size: 14px; font-weight: 600; text-decoration: none; transition: all 0.2s var(--ease); }
    .gf-nav-link:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-nav-link.active { background: #fff; color: #14532d; font-weight: 700; }
    .gf-nav-link.logout { color: #fca5a5; margin-top: 12px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.12); border-radius: 0; }
    .gf-backdrop { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(2px); z-index: 1055; }
    body.gf-sidebar-open .gf-backdrop { display: block; }

    /* MAIN */
    .main {
        padding: calc(var(--navbar-height) + 24px) clamp(16px, 2vw, 28px) 60px;
        max-width: 1280px; margin: 0 auto; width: 100%; min-height: 100vh;
    }

    /* PAGE HEADER */
    .page-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; margin-bottom: 18px; flex-wrap: wrap;
    }
    .page-head-left h1 { font-size: 20px; font-weight: 800; margin: 0 0 2px; letter-spacing: -0.02em; }
    .page-head-left p { font-size: 12px; color: var(--text-3); margin: 0; font-weight: 600; }
    .page-head-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .date-info {
        font-size: 12px; color: var(--text-2); font-weight: 600;
        padding: 6px 12px; background: var(--surface);
        border: 1px solid var(--border); border-radius: 8px;
    }
    .update-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 14px; background: var(--primary); color: #fff;
        border-radius: 8px; font-size: 12px; font-weight: 700;
        text-decoration: none; transition: all 0.15s var(--ease);
    }
    .update-btn:hover { background: var(--primary-dark); }
    .update-btn svg { width: 12px; height: 12px; }

    /* HERO BANNER */
    .hero-banner {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 22px 26px;
        margin-bottom: 20px;
        display: grid;
        grid-template-columns: 1.4fr 1fr 1fr auto;
        gap: 24px;
        align-items: center;
    }
    @media (max-width: 1024px) { .hero-banner { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 640px) { .hero-banner { grid-template-columns: 1fr; padding: 18px; gap: 18px; } }

    .hero-main { display: flex; align-items: center; gap: 16px; }
    .hero-icon-wrap {
        width: 64px; height: 64px; border-radius: 16px;
        background: linear-gradient(135deg, #fef3c7, #fcd34d);
        border: 1px solid #fcd34d;
        display: grid; place-items: center; flex-shrink: 0; color: #b45309;
    }
    .hero-icon-wrap.rain {
        background: linear-gradient(135deg, var(--primary-light), #bfdbfe);
        border-color: #93c5fd; color: var(--primary-dark);
    }
    .hero-info { min-width: 0; }
    .hero-label {
        font-size: 10px; font-weight: 800; color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 4px;
    }
    .hero-temp {
        font-size: 36px; font-weight: 800; line-height: 1;
        letter-spacing: -0.04em; margin-bottom: 4px; color: var(--text);
    }
    .hero-temp .unit { font-size: 18px; font-weight: 700; color: var(--text-2); margin-left: 2px; }
    .hero-desc { font-size: 13px; color: var(--text-2); font-weight: 700; margin-bottom: 4px; }
    .hero-meta { display: flex; gap: 12px; font-size: 11.5px; color: var(--text-3); font-weight: 600; flex-wrap: wrap; }
    .hero-meta strong { color: var(--text); font-weight: 700; }

    .hero-stat { text-align: left; }
    .hero-stat-value {
        font-size: 24px; font-weight: 800; line-height: 1;
        letter-spacing: -0.03em; color: var(--text); margin-bottom: 3px;
    }
    .hero-stat-value .unit { font-size: 13px; font-weight: 700; color: var(--text-2); margin-left: 2px; }
    .hero-stat-label {
        font-size: 10.5px; font-weight: 700; color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.08em;
    }
    .forecast-btn {
        display: inline-flex; align-items: center; justify-content: center;
        gap: 6px; padding: 14px 20px;
        background: var(--primary); color: #fff;
        border-radius: var(--radius-sm);
        font-size: 11px; font-weight: 800;
        text-decoration: none; transition: all 0.15s var(--ease);
        text-transform: uppercase; letter-spacing: 0.06em;
        white-space: nowrap;
    }
    .forecast-btn:hover { background: var(--primary-dark); }

    /* MAIN GRID */
    .main-layout {
        display: grid; grid-template-columns: 1.55fr 1fr;
        gap: 18px; margin-bottom: 16px;
    }
    @media (max-width: 1024px) { .main-layout { grid-template-columns: 1fr; } }
    .left-col { display: flex; flex-direction: column; gap: 16px; }
    .right-col { display: flex; flex-direction: column; gap: 16px; }

    /* METRIC STRIP */
    .metric-strip {
        display: grid; grid-template-columns: repeat(4, 1fr);
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        overflow: hidden;
    }
    @media (max-width: 640px) { .metric-strip { grid-template-columns: repeat(2, 1fr); } }
    .metric-cell {
        padding: 14px 16px;
        border-right: 1px solid var(--border-2);
    }
    .metric-cell:last-child { border-right: none; }
    @media (max-width: 640px) {
        .metric-cell:nth-child(2n) { border-right: none; }
        .metric-cell:nth-child(-n+2) { border-bottom: 1px solid var(--border-2); }
    }
    .metric-label {
        font-size: 10px; font-weight: 800; color: var(--text-3);
        text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 6px;
    }
    .metric-value {
        font-size: 22px; font-weight: 800; line-height: 1;
        letter-spacing: -0.03em; color: var(--text);
        display: flex; align-items: baseline; gap: 2px;
        margin-bottom: 4px;
    }
    .metric-value .unit { font-size: 12px; font-weight: 700; color: var(--text-2); }
    .metric-note { font-size: 10.5px; color: var(--text-3); font-weight: 600; }

    /* CARD */
    .card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); padding: 18px 20px;
    }
    .card-head {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 12px; margin-bottom: 12px; flex-wrap: wrap;
    }
    .card-title {
        font-size: 14px; font-weight: 800; color: var(--text);
        margin: 0 0 2px; letter-spacing: -0.01em;
    }
    .card-sub { font-size: 11.5px; color: var(--text-3); margin: 0; font-weight: 600; }
    .card-legend {
        display: inline-flex; align-items: center; gap: 12px;
        font-size: 10.5px; color: var(--text-2); font-weight: 700;
    }
    .legend-item { display: inline-flex; align-items: center; gap: 5px; }
    .legend-dot { width: 8px; height: 8px; border-radius: 50%; }
    .legend-dot.blue { background: var(--primary); }
    .legend-dot.teal { background: #0d9488; }

    /* HOURLY CHART */
    .chart-wrap { position: relative; height: 180px; margin: 8px 0 4px; }
    .chart-svg { width: 100%; height: 100%; overflow: visible; }

    /* CHART INTERACTIVITY */
    .chart-bar { transition: fill 0.15s var(--ease); }
    .chart-bar:hover { fill: rgba(13,148,136,0.45); }
    .chart-bar.active { fill: rgba(13,148,136,0.65); }
    .chart-dot { transition: r 0.15s var(--ease), fill 0.15s var(--ease); }
    .chart-dot.active { r: 7; fill: #1d4ed8; }
    .chart-hit:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 50%; }

    /* HOURLY STRIP */
    .hourly-strip {
        display: grid; grid-template-columns: repeat(8, 1fr);
        gap: 4px; margin-top: 12px; padding-top: 14px;
        border-top: 1px solid var(--border-2);
    }
    @media (max-width: 640px) { .hourly-strip { grid-template-columns: repeat(4, 1fr); } }
    .hour-cell {
        display: flex; flex-direction: column; align-items: center;
        gap: 4px; padding: 8px 4px; text-align: center;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.15s var(--ease);
        border: 1px solid transparent;
    }
    .hour-cell:hover { background: #f1f5f9; }
    .hour-cell.active {
        background: var(--primary-light);
        border-color: #bfdbfe;
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15);
    }
    .hour-cell:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }
    .hour-time { font-size: 10.5px; font-weight: 800; color: var(--text-2); letter-spacing: 0.02em; }
    .hour-icon { width: 20px; height: 20px; color: var(--text-2); margin: 2px 0; }
    .hour-temp { font-size: 13px; font-weight: 800; color: var(--text); letter-spacing: -0.02em; }
    .hour-temp.hot { color: var(--amber); }
    .hour-pop { font-size: 10px; font-weight: 700; color: var(--primary); }
    .hour-pop.zero { color: var(--text-3); }

    /* HOUR DETAIL PANEL */
    .hour-detail {
        margin-top: 16px;
        padding: 14px 16px;
        background: linear-gradient(135deg, #eff6ff, #f8fafc);
        border: 1px solid #bfdbfe;
        border-radius: var(--radius-sm);
        min-height: 76px;
        transition: all 0.2s var(--ease);
    }
    .hour-detail-empty {
        font-size: 12px;
        color: var(--text-3);
        font-weight: 600;
        text-align: center;
        padding: 8px 0;
    }
    .hour-detail-content { animation: fadeInDetail 0.2s var(--ease); }
    @keyframes fadeInDetail {
        from { opacity: 0; transform: translateY(-4px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .hour-detail-time {
        font-size: 13px; font-weight: 800;
        color: var(--primary-dark);
        margin-bottom: 10px;
        display: flex; align-items: center; gap: 6px;
    }
    .hour-detail-time::before {
        content: '';
        width: 8px; height: 8px; border-radius: 50%;
        background: var(--primary);
    }
    .hour-detail-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
    }
    .hd-col-label {
        font-size: 10px; font-weight: 800;
        color: var(--text-3);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-bottom: 4px;
    }
    .hd-col-value {
        font-size: 18px; font-weight: 800;
        color: var(--text);
        letter-spacing: -0.02em;
    }

    /* GOAT CARE GUIDE */
    .guide-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px; margin-bottom: 4px; flex-wrap: wrap;
    }
    .guide-title { font-size: 14px; font-weight: 800; color: var(--text); }
    .guide-badge {
        display: inline-flex; align-items: center;
        padding: 3px 9px; border-radius: 6px;
        font-size: 10px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.06em;
    }
    .guide-badge.green { background: var(--green-light); color: #15803d; }
    .guide-badge.amber { background: var(--amber-light); color: #92400e; }
    .guide-badge.red   { background: var(--red-light); color: #b91c1c; }
    .guide-sub { font-size: 11.5px; color: var(--text-3); margin: 0 0 14px; font-weight: 600; }

    .guide-item {
        padding: 12px 14px;
        background: #f8fafc;
        border: 1px solid var(--border-2);
        border-radius: var(--radius-sm);
        margin-bottom: 10px;
    }
    .guide-item:last-child { margin-bottom: 0; }
    .guide-item.amber { background: #fffbeb; border-color: #fde68a; }
    .guide-item.green { background: #f0fdf4; border-color: #bbf7d0; }
    .guide-item.red   { background: #fef2f2; border-color: #fecaca; }
    .guide-item-title {
        font-size: 12.5px; font-weight: 800; color: var(--text);
        margin-bottom: 3px; line-height: 1.3;
        display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
    }
    .guide-item-tag {
        font-size: 9.5px; font-weight: 800;
        padding: 2px 6px; border-radius: 4px;
        text-transform: uppercase; letter-spacing: 0.05em;
    }
    .guide-item.green .guide-item-tag { background: var(--green-light); color: #15803d; }
    .guide-item.amber .guide-item-tag { background: var(--amber-light); color: #92400e; }
    .guide-item.red   .guide-item-tag { background: var(--red-light); color: #b91c1c; }
    .guide-item-desc { font-size: 11.5px; color: var(--text-2); line-height: 1.5; }

    /* SMS ADVISORY SECTION */
    .advisory-section {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid var(--border-2);
    }
    .advisory-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 12px; flex-wrap: wrap; gap: 8px;
    }
    .advisory-title {
        font-size: 12.5px; font-weight: 800; color: var(--text);
        display: flex; align-items: center; gap: 6px;
    }
    .advisory-count-badge {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 18px; height: 18px; padding: 0 5px;
        border-radius: 999px; font-size: 10px; font-weight: 800;
        background: var(--red); color: #fff;
    }
    .advisory-priority-badge {
        font-size: 9.5px; font-weight: 800;
        padding: 3px 8px; border-radius: 5px;
        text-transform: uppercase; letter-spacing: 0.05em;
    }
    .advisory-priority-badge.high { background: var(--red-light); color: #b91c1c; }
    .advisory-priority-badge.medium { background: var(--amber-light); color: #92400e; }
    .advisory-priority-badge.low { background: var(--border-2); color: var(--text-2); }

    .advisory-option {
        display: block;
        padding: 10px 12px;
        margin-bottom: 8px;
        border-radius: var(--radius-sm);
        cursor: pointer;
        transition: all 0.15s var(--ease);
        border: 1.5px solid var(--border);
        background: #fafbfc;
    }
    .advisory-option:hover { background: #f1f5f9; }
    .advisory-option.high { border-color: #fecaca; background: #fef2f2; }
    .advisory-option.medium { border-color: #fde68a; background: #fffbeb; }
    .advisory-option input[type="radio"] {
        margin-right: 8px;
        accent-color: var(--green);
        cursor: pointer;
    }
    .advisory-option-title {
        font-size: 12.5px; font-weight: 800; color: var(--text);
        display: inline;
    }
    .advisory-option-msg {
        margin: 6px 0 0 24px;
        font-size: 11.5px; color: var(--text-2); line-height: 1.45;
    }

    .send-advisory-btn {
        width: 100%;
        padding: 12px;
        margin-top: 10px;
        background: linear-gradient(145deg, #22C55E, #15803D);
        color: #fff;
        border: none;
        border-radius: var(--radius-sm);
        font-weight: 800;
        font-size: 13px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        box-shadow: 0 4px 10px rgba(34, 197, 94, 0.25);
        transition: all 0.15s var(--ease);
    }
    .send-advisory-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 16px rgba(34, 197, 94, 0.35);
    }
    .send-advisory-btn:active { transform: translateY(0); }

    .no-advisory {
        margin-top: 16px;
        padding: 14px;
        border-radius: var(--radius-sm);
        background: #ecfdf5;
        border: 1px solid #bbf7d0;
        text-align: center;
    }
    .no-advisory p {
        margin: 0;
        font-size: 12.5px;
        color: #065f46;
        font-weight: 700;
    }

    .send-result {
        margin-top: 12px;
        padding: 12px 14px;
        border-radius: var(--radius-sm);
    }
    .send-result.success {
        background: #dcfce7;
        border: 1px solid #bbf7d0;
    }
    .send-result.failed {
        background: #fee2e2;
        border: 1px solid #fecaca;
    }
    .send-result strong {
        font-size: 12.5px;
        display: block;
        margin-bottom: 4px;
    }
    .send-result.success strong { color: #166534; }
    .send-result.failed strong { color: #b91c1c; }
    .send-result p {
        margin: 0;
        font-size: 11.5px;
        color: var(--text-2);
        line-height: 1.4;
    }

    /* 5-DAY FORECAST */
    .forecast-row {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 12px;
        background: #f8fafc;
        border: 1px solid var(--border-2);
        border-radius: var(--radius-sm);
        margin-bottom: 8px;
    }
    .forecast-row:last-child { margin-bottom: 0; }
    .forecast-day {
        width: 56px;
        font-size: 11.5px; font-weight: 800; color: var(--text-2);
        text-transform: uppercase; letter-spacing: 0.04em;
    }
    .forecast-icon { width: 26px; height: 26px; color: var(--primary); flex-shrink: 0; }
    .forecast-temps {
        flex: 1; display: flex; gap: 8px;
        font-size: 12.5px; font-weight: 800;
    }
    .forecast-hi { color: var(--text); }
    .forecast-lo { color: var(--text-3); font-weight: 600; }
    .forecast-pop { font-size: 11px; color: var(--primary); font-weight: 700; }

    /* FOOTER */
    .footer-meta {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; padding: 12px 4px 0;
        font-size: 11px; color: var(--text-3); font-weight: 600;
        flex-wrap: wrap;
        border-top: 1px solid var(--border-2);
        margin-top: 4px;
    }

    @media (max-width: 991.98px) {
        .gf-navbar { padding: 0 16px; }
        .gf-navbar-links { display: none; }
        .gf-navbar-toggle { display: grid; }
    }
    @media (max-width: 767.98px) {
        .gf-navbar { height: 60px; padding: 0 12px; }
        .gf-navbar-logo { width: 38px; height: 38px; font-size: 20px; }
        .gf-navbar-title { font-size: 17px; }
        .main { padding: calc(var(--navbar-height) + 16px) 12px 60px; }
        .hero-temp { font-size: 28px; }
        .hero-temp .unit { font-size: 15px; }
        .hero-icon-wrap { width: 56px; height: 56px; }
    }
</style>

<!-- NAVBAR -->
<nav class="gf-navbar">
    <a href="<?php echo BASE_URL; ?>/app/admin/dashboard.php" class="gf-navbar-brand">
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
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'weather'); ?>
    </ul>
    <button class="gf-navbar-toggle" type="button" onclick="toggleSidebar();" aria-label="Menu">
        <?php echo icon('menu', 22); ?>
    </button>
</nav>

<aside class="gf-sidebar" id="gfSidebar">
    <div class="gf-sidebar-header">
        <div class="gf-brand">
            <div class="gf-brand-icon">🐐</div>
            <div>
                <div class="gf-brand-text">GoatPro</div>
                <div class="gf-brand-sub">Admin · Weather</div>
            </div>
        </div>
        <button class="gf-sidebar-close" type="button" onclick="closeSidebar();">
            <?php echo icon('x', 18); ?>
        </button>
    </div>
    <nav class="gf-nav-section">
        <?php gfRenderSidebarLinks($_SESSION['role'] ?? '', 'weather'); ?>
    </nav>
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
                    <span class="date-info"><?php echo date('D, M j, Y'); ?></span>
                    <a href="?refresh=1" class="update-btn">
                        <?php echo icon('refresh', 12); ?> Refresh
                    </a>
                </div>
            </div>

            <!-- HERO BANNER -->
            <div class="hero-banner">
                <div class="hero-main">
                    <div class="hero-icon-wrap <?php echo $weatherMain === 'Rain' ? 'rain' : ''; ?>">
                        <?php echo wxIcon(owIconName($weatherIcon), 44); ?>
                    </div>
                    <div class="hero-info">
                        <div class="hero-label">Now</div>
                        <div class="hero-temp">
                            <?php echo $temp; ?><span class="unit">°C</span>
                        </div>
                        <div class="hero-desc"><?php echo htmlspecialchars($weatherDesc); ?></div>
                        <div class="hero-meta">
                            <span>Feels <strong><?php echo $feelsLike; ?>°</strong></span>
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

                <a href="#forecast" class="forecast-btn">
                    5-Day Forecast
                </a>
            </div>

            <!-- MAIN LAYOUT -->
            <div class="main-layout">

                <!-- LEFT COLUMN -->
                <div class="left-col">

                    <!-- METRIC STRIP -->
                    <div class="metric-strip">
                        <div class="metric-cell">
                            <div class="metric-label">Temperature</div>
                            <div class="metric-value"><?php echo $temp; ?><span class="unit">°C</span></div>
                            <div class="metric-note">Feels <?php echo $feelsLike; ?>°</div>
                        </div>
                        <div class="metric-cell">
                            <div class="metric-label">Rainfall</div>
                            <div class="metric-value"><?php echo $rainToday; ?><span class="unit">mm</span></div>
                            <div class="metric-note"><?php echo $rainInfo['label']; ?></div>
                        </div>
                        <div class="metric-cell">
                            <div class="metric-label">Humidity</div>
                            <div class="metric-value"><?php echo $humidity; ?><span class="unit">%</span></div>
                            <div class="metric-note"><?php echo $humidity >= 60 ? 'Normal' : 'Dry'; ?></div>
                        </div>
                        <div class="metric-cell">
                            <div class="metric-label">Wind</div>
                            <div class="metric-value"><?php echo $windSpeed; ?><span class="unit">km/h</span></div>
                            <div class="metric-note">From <?php echo $windDir; ?></div>
                        </div>
                    </div>

                    <!-- HOURLY CHART -->
                    <div class="card">
                        <div class="card-head">
                            <div>
                                <h3 class="card-title">Hourly Forecast</h3>
                                <p class="card-sub">Next 24 hours · click a point for details</p>
                            </div>
                            <div class="card-legend">
                                <span class="legend-item"><span class="legend-dot blue"></span> Temp</span>
                                <span class="legend-item"><span class="legend-dot teal"></span> Rain</span>
                            </div>
                        </div>

                        <div class="chart-wrap">
                            <svg class="chart-svg" viewBox="0 0 800 200" preserveAspectRatio="none" id="hourlyChart">
                                <defs>
                                    <linearGradient id="tempGrad" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#2563eb"/>
                                        <stop offset="100%" stop-color="#60a5fa"/>
                                    </linearGradient>
                                    <linearGradient id="tempFill" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="rgba(37,99,235,0.15)"/>
                                        <stop offset="100%" stop-color="rgba(37,99,235,0)"/>
                                    </linearGradient>
                                </defs>
                                <g stroke="#f1f5f9" stroke-width="1">
                                    <line x1="0" y1="50" x2="800" y2="50"/>
                                    <line x1="0" y1="100" x2="800" y2="100"/>
                                    <line x1="0" y1="150" x2="800" y2="150"/>
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
                                    <rect x="<?php echo $bar['x'] - 8; ?>"
                                          y="<?php echo $chartH - $bar['h']; ?>"
                                          width="16" height="<?php echo $bar['h']; ?>"
                                          fill="rgba(13,148,136,0.22)" rx="3"
                                          class="chart-bar"
                                          data-index="<?php echo $i; ?>"
                                          style="cursor:pointer;"/>
                                <?php endforeach; ?>

                                <path d="<?php echo $fillD; ?>" fill="url(#tempFill)"/>
                                <path d="<?php echo $pathD; ?>" fill="none" stroke="url(#tempGrad)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>

                                <?php foreach ($tempPoints as $i => $pt): ?>
                                    <circle cx="<?php echo $pt[0]; ?>"
                                            cy="<?php echo $pt[1]; ?>"
                                            r="16"
                                            fill="transparent"
                                            class="chart-hit"
                                            data-index="<?php echo $i; ?>"
                                            style="cursor:pointer;"/>
                                    <circle cx="<?php echo $pt[0]; ?>"
                                            cy="<?php echo $pt[1]; ?>"
                                            r="5"
                                            fill="#2563eb"
                                            stroke="#fff"
                                            stroke-width="2.5"
                                            class="chart-dot"
                                            data-index="<?php echo $i; ?>"
                                            style="pointer-events:none;"/>
                                    <text x="<?php echo $pt[0]; ?>"
                                          y="<?php echo $pt[1] - 12; ?>"
                                          text-anchor="middle"
                                          font-size="11" font-weight="800"
                                          fill="#1d4ed8"
                                          font-family="system-ui, sans-serif"
                                          style="pointer-events:none;"><?php echo $hourlyData[$i]['temp']; ?>°</text>
                                <?php endforeach; ?>
                            </svg>
                        </div>

                        <!-- HOUR DETAIL PANEL -->
                        <div class="hour-detail" id="hourDetail">
                            <div class="hour-detail-empty" id="hourDetailEmpty">
                                Tap a point or an hour below to see detailed forecast
                            </div>
                            <div class="hour-detail-content" id="hourDetailContent" style="display:none;">
                                <div class="hour-detail-time" id="hdTime">—</div>
                                <div class="hour-detail-row">
                                    <div class="hd-col">
                                        <div class="hd-col-label">Temperature</div>
                                        <div class="hd-col-value" id="hdTemp">—</div>
                                    </div>
                                    <div class="hd-col">
                                        <div class="hd-col-label">Rain Chance</div>
                                        <div class="hd-col-value" id="hdPop">—</div>
                                    </div>
                                    <div class="hd-col">
                                        <div class="hd-col-label">Condition</div>
                                        <div class="hd-col-value" id="hdCond">—</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- HOURLY STRIP -->
                        <div class="hourly-strip">
                            <?php foreach ($hourlyData as $i => $h):
                                $isHot = $h['temp'] >= 30;
                            ?>
                                <div class="hour-cell"
                                     data-index="<?php echo $i; ?>"
                                     data-time="<?php echo htmlspecialchars($h['time']); ?>"
                                     data-temp="<?php echo $h['temp']; ?>"
                                     data-pop="<?php echo $h['pop']; ?>"
                                     data-icon="<?php echo htmlspecialchars(owIconName($h['icon'])); ?>"
                                     role="button"
                                     tabindex="0">
                                    <span class="hour-time"><?php echo $h['time']; ?></span>
                                    <div class="hour-icon"><?php echo wxIcon(owIconName($h['icon']), 20); ?></div>
                                    <span class="hour-temp <?php echo $isHot ? 'hot' : ''; ?>"><?php echo $h['temp']; ?>°</span>
                                    <span class="hour-pop <?php echo $h['pop'] === 0 ? 'zero' : ''; ?>"><?php echo $h['pop']; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

                <!-- RIGHT COLUMN -->
                <div class="right-col">

                    <!-- GOAT CARE GUIDE + SMS ADVISORY -->
                    <div class="card">
                        <div class="guide-head">
                            <div class="guide-title">Goat Care Guide</div>
                            <span class="guide-badge <?php
                                if ($heat['level'] === 'high') echo 'red';
                                elseif ($heat['level'] === 'mid') echo 'amber';
                                else echo 'green';
                            ?>">Priority</span>
                        </div>
                        <p class="guide-sub">Tips for grazing and shelter today</p>

                        <div class="guide-item <?php echo ($temp >= 30 || $hasHighPop) ? 'amber' : 'green'; ?>">
                            <div class="guide-item-title">
                                Grazing
                                <span class="guide-item-tag">
                                    <?php echo $hasHighPop ? 'CAUTION (RAIN LIKELY)' : ($temp >= 32 ? 'LIMITED' : ($temp >= 30 ? 'CAUTION' : 'SAFE')); ?>
                                </span>
                            </div>
                            <div class="guide-item-desc">
                                <?php if ($hasHighPop): ?>
                                    High chance of rain. Prepare to bring goats to shelter.
                                <?php elseif ($temp >= 32): ?>
                                    Too hot for extended grazing. Provide shade and water access.
                                <?php elseif ($temp >= 30): ?>
                                    Safe to graze until 3-4 PM. Provide shade during peak hours.
                                <?php else: ?>
                                    Safe to graze until 4-5 PM. Good conditions for field rotation.
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="guide-item <?php echo ($rainTonight > 2 || $hasHighPop) ? 'amber' : 'green'; ?>">
                            <div class="guide-item-title">
                                Rain Warning
                                <span class="guide-item-tag">
                                    <?php echo $rainTonight > 5 ? 'ACTION NEEDED' : (($rainTonight > 2 || $hasHighPop) ? 'ADVISORY' : 'NONE'); ?>
                                </span>
                            </div>
                            <div class="guide-item-desc">
                                <?php if ($rainTonight > 5): ?>
                                    Heavy rain expected. Bring goats into barn. Move to elevated dry areas.
                                <?php elseif ($rainTonight > 2): ?>
                                    Rain expected (<?php echo $rainTonight; ?> mm). Bring goats into barn before dark.
                                <?php elseif ($hasHighPop): ?>
                                    High probability of rain in the next few hours. Monitor the sky.
                                <?php else: ?>
                                    Minimal rain expected. Normal care is fine.
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="guide-item <?php
                            if ($heat['level'] === 'high') echo 'red';
                            elseif ($heat['level'] === 'mid') echo 'amber';
                            else echo 'green';
                        ?>">
                            <div class="guide-item-title">
                                Heat Level
                                <span class="guide-item-tag"><?php echo strtoupper($heat['label']); ?></span>
                            </div>
                            <div class="guide-item-desc">
                                <?php echo $heat['desc']; ?>
                            </div>
                        </div>

                        <!-- ============================================
                             SMS ADVISORY SECTION
                        ============================================ -->
                        <?php if (!empty($advisories)): ?>
                            <div class="advisory-section">
                                <div class="advisory-header">
                                    <div class="advisory-title">
                                        ⚠️ Active Advisories
                                        <span class="advisory-count-badge"><?php echo count($advisories); ?></span>
                                    </div>
                                    <span class="advisory-priority-badge <?php echo $highestPriority; ?>">
                                        <?php echo strtoupper($highestPriority); ?> PRIORITY
                                    </span>
                                </div>

                                <form method="POST">
                                    <?php foreach ($advisories as $i => $adv): ?>
                                        <label class="advisory-option <?php echo $adv['priority']; ?>">
                                            <input type="radio"
                                                   name="advisory_index"
                                                   value="<?php echo $i; ?>"
                                                   <?php echo $i === 0 ? 'checked' : ''; ?>>
                                            <span class="advisory-option-title">
                                                <?php echo htmlspecialchars($adv['title']); ?>
                                            </span>
                                            <p class="advisory-option-msg">
                                                <?php echo htmlspecialchars($adv['message']); ?>
                                            </p>
                                        </label>
                                    <?php endforeach; ?>

                                    <button type="submit"
                                            name="send_advisory"
                                            class="send-advisory-btn"
                                            onclick="return confirm('Ipadala ang SMS advisory sa tanan opted-in farmers?');">
                                        📱 Send Advisory via SMS
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="no-advisory">
                                <p>✅ Walay active advisory. Maayo ang panahon para sa goats.</p>
                            </div>
                        <?php endif; ?>

                        <!-- SEND RESULT -->
                        <?php if ($sendResult): ?>
                            <div class="send-result <?php echo $sendResult['success'] ? 'success' : 'failed'; ?>">
                                <strong>
                                    <?php if ($sendResult['success']): ?>
                                        ✅ Advisory sent successfully!
                                    <?php else: ?>
                                        ❌ Failed to send advisory
                                    <?php endif; ?>
                                </strong>
                                <p>
                                    <?php if ($sendResult['success']): ?>
                                        Sent to <strong><?php echo $sendResult['success_count']; ?></strong>
                                        of <strong><?php echo $sendResult['recipient_count']; ?></strong> farmers.
                                    <?php else: ?>
                                        Error: <?php echo htmlspecialchars($sendResult['error'] ?? 'Unknown error'); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>

                    </div>

                    <!-- 5-DAY FORECAST -->
                    <div class="card" id="forecast">
                        <div class="guide-head">
                            <div class="guide-title">5-Day Forecast</div>
                        </div>
                        <p class="guide-sub"><?php echo MANOLO_FORTICH_NAME; ?></p>

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
                            <div class="forecast-row">
                                <div class="forecast-day"><?php echo $label; ?></div>
                                <div class="forecast-icon"><?php echo wxIcon($iconName, 24); ?></div>
                                <div class="forecast-temps">
                                    <span class="forecast-hi"><?php echo $tempHi; ?>°</span>
                                    <span class="forecast-lo"><?php echo $tempLo; ?>°</span>
                                </div>
                                <div class="forecast-pop"><?php echo $pop; ?>%</div>
                            </div>
                        <?php endfor; ?>
                    </div>

                </div>

            </div>

        </main>
    </div>
</div>

<script>
// ============================================================
// SIDEBAR
// ============================================================
function toggleSidebar() { document.body.classList.toggle('gf-sidebar-open'); }
function closeSidebar() { document.body.classList.remove('gf-sidebar-open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

// ============================================================
// INTERACTIVE HOURLY FORECAST
// ============================================================
(function() {
    'use strict';

    const hourDetailEmpty   = document.getElementById('hourDetailEmpty');
    const hourDetailContent = document.getElementById('hourDetailContent');
    const hdTime = document.getElementById('hdTime');
    const hdTemp = document.getElementById('hdTemp');
    const hdPop  = document.getElementById('hdPop');
    const hdCond = document.getElementById('hdCond');

    if (!hourDetailEmpty || !hourDetailContent) return;

    let activeIndex = -1;

    function iconLabel(iconName) {
        const map = {
            'sun': 'Sunny',
            'cloud-sun': 'Partly Cloudy',
            'cloud': 'Cloudy',
            'cloud-rain': 'Rainy',
        };
        return map[iconName] || 'Clear';
    }

    function popLabel(pop) {
        if (pop === 0) return 'None';
        if (pop < 30)  return 'Low';
        if (pop < 60)  return 'Moderate';
        if (pop < 80)  return 'High';
        return 'Very High';
    }

    function selectHour(index, scrollIntoView) {
        if (activeIndex === index) return;
        activeIndex = index;

        const cell = document.querySelector('.hour-cell[data-index="' + index + '"]');
        if (!cell) return;

        const time = cell.dataset.time;
        const temp = cell.dataset.temp;
        const pop  = cell.dataset.pop;
        const icon = cell.dataset.icon;

        hdTime.textContent = time;
        hdTemp.textContent = temp + '°C';
        hdPop.textContent  = pop + '% · ' + popLabel(parseInt(pop));
        hdCond.textContent = iconLabel(icon);

        hourDetailEmpty.style.display   = 'none';
        hourDetailContent.style.display = 'block';

        document.querySelectorAll('.hour-cell').forEach(function(el) {
            el.classList.remove('active');
        });
        cell.classList.add('active');

        document.querySelectorAll('.chart-dot').forEach(function(el) {
            el.classList.remove('active');
        });
        const dot = document.querySelector('.chart-dot[data-index="' + index + '"]');
        if (dot) dot.classList.add('active');

        document.querySelectorAll('.chart-bar').forEach(function(el) {
            el.classList.remove('active');
        });
        const bar = document.querySelector('.chart-bar[data-index="' + index + '"]');
        if (bar) bar.classList.add('active');

        if (scrollIntoView) {
            cell.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
    }

    document.querySelectorAll('.hour-cell').forEach(function(cell) {
        cell.addEventListener('click', function() {
            selectHour(parseInt(this.dataset.index), false);
        });
        cell.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                selectHour(parseInt(this.dataset.index), false);
            }
        });
    });

    document.querySelectorAll('.chart-hit').forEach(function(hit) {
        hit.addEventListener('click', function() {
            selectHour(parseInt(this.dataset.index), true);
        });
        hit.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                selectHour(parseInt(this.dataset.index), true);
            }
        });
    });

    document.querySelectorAll('.chart-bar').forEach(function(bar) {
        bar.addEventListener('click', function() {
            selectHour(parseInt(this.dataset.index), true);
        });
    });

    if (document.querySelector('.hour-cell[data-index="0"]')) {
        selectHour(0, false);
    }
})();
</script>

<?php renderFooter(); ?>