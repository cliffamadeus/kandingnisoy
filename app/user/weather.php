<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';

// USER ACCESS
requireRole('user');

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
    if ($curlError || $httpCode !== 200 || !$response) return null;
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
    $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
    return $dirs[round($deg / 22.5) % 16];
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
    $map = ['01'=>'sun','02'=>'cloud-sun','03'=>'cloud','04'=>'cloud','09'=>'cloud-rain','10'=>'cloud-rain','11'=>'cloud-lightning','13'=>'cloud-rain','50'=>'cloud'];
    return $map[substr($code, 0, 2)] ?? 'cloud';
}

function owDesc(string $code): string
{
    $desc = [
        '01d' => 'Sunny', '01n' => 'Clear',
        '02d' => 'Mostly Sunny', '02n' => 'Partly Cloudy',
        '03d' => 'Scattered Clouds', '03n' => 'Scattered Clouds',
        '04d' => 'Overcast', '04n' => 'Overcast',
        '09d' => 'Rain Shower', '09n' => 'Rain Shower',
        '10d' => 'Rain Shower', '10n' => 'Rain Shower',
        '11d' => 'Thunderstorm', '11n' => 'Thunderstorm',
        '13d' => 'Snow', '13n' => 'Snow',
        '50d' => 'Misty', '50n' => 'Misty',
    ];
    return $desc[$code] ?? 'Cloudy';
}

/* =========================================================
   LUCIDE-MATCHING LINE ICONS (single color, colored via CSS)
   ========================================================= */
function svgIcon(string $name, int $size = 20, string $strokeWidth = '2'): string
{
    static $paths = [
        'thermometer' => '<path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/>',
        'droplet' => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
        'waves' => '<path d="M2 6c.6.5 1.2 1 2.5 1C7 7 7 5 9.5 5c2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M2 12c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M2 18c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/>',
        'wind' => '<path d="M12.8 19.6A2 2 0 1 0 14 16H2"/><path d="M17.5 8a2.5 2.5 0 1 1 2 4H2"/><path d="M9.8 4.4A2 2 0 1 1 11 8H2"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
        'menu' => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    ];
    $path = $paths[$name] ?? '';
    if ($path === '') return '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $strokeWidth . '" stroke-linecap="round" stroke-linejoin="round" style="display:block;">' . $path . '</svg>';
}

/* =========================================================
   COLORED WEATHER ICONS (for hourly strip & 5-day rows)
   ========================================================= */
function wxIcon(string $name, int $size = 24): string
{
    $colorMap = [
        'sun'             => ['#f59e0b', '#fbbf24'],
        'cloud-sun'       => ['#60a5fa', '#fbbf24'],
        'cloud'           => ['#94a3b8', '#cbd5e1'],
        'cloud-rain'      => ['#3b82f6', '#60a5fa'],
        'cloud-lightning' => ['#475569', '#facc15'],
    ];

    $colors = $colorMap[$name] ?? $colorMap['cloud'];
    $primary = $colors[0];
    $accent  = $colors[1];

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" style="display:block;">';

    switch ($name) {
        case 'sun':
            $svg .= '
                <circle cx="12" cy="12" r="4" fill="' . $accent . '" stroke="' . $primary . '" stroke-width="1.5"/>
                <g stroke="' . $primary . '" stroke-width="2" stroke-linecap="round">
                    <line x1="12" y1="2" x2="12" y2="4"/>
                    <line x1="12" y1="20" x2="12" y2="22"/>
                    <line x1="2" y1="12" x2="4" y2="12"/>
                    <line x1="20" y1="12" x2="22" y2="12"/>
                    <line x1="4.93" y1="4.93" x2="6.34" y2="6.34"/>
                    <line x1="17.66" y1="17.66" x2="19.07" y2="19.07"/>
                    <line x1="4.93" y1="19.07" x2="6.34" y2="17.66"/>
                    <line x1="17.66" y1="6.34" x2="19.07" y2="4.93"/>
                </g>';
            break;

        case 'cloud-sun':
            $svg .= '
                <circle cx="8" cy="8" r="3" fill="' . $accent . '" stroke="' . $primary . '" stroke-width="1.2"/>
                <g stroke="' . $primary . '" stroke-width="1.6" stroke-linecap="round">
                    <line x1="8" y1="2" x2="8" y2="3.5"/>
                    <line x1="2" y1="8" x2="3.5" y2="8"/>
                    <line x1="3.8" y1="3.8" x2="4.9" y2="4.9"/>
                </g>
                <path d="M17.5 21H9a5 5 0 1 1 4.9-6h1.6a3.5 3.5 0 1 1 2 6.5Z"
                      fill="#e2e8f0" stroke="' . $primary . '" stroke-width="1.5" stroke-linejoin="round"/>';
            break;

        case 'cloud':
            $svg .= '
                <path d="M17.5 19H9a5 5 0 1 1 4.9-6h1.6a3.5 3.5 0 1 1 2 6Z"
                      fill="' . $accent . '" stroke="' . $primary . '" stroke-width="1.5" stroke-linejoin="round"/>';
            break;

        case 'cloud-rain':
            $svg .= '
                <path d="M17.5 17H9a5 5 0 1 1 4.9-6h1.6a3.5 3.5 0 1 1 2 6Z"
                      fill="#cbd5e1" stroke="' . $primary . '" stroke-width="1.5" stroke-linejoin="round"/>
                <g stroke="' . $primary . '" stroke-width="2" stroke-linecap="round">
                    <line x1="8"  y1="19" x2="8"  y2="22"/>
                    <line x1="13" y1="19" x2="13" y2="22"/>
                    <line x1="18" y1="19" x2="18" y2="22"/>
                </g>';
            break;

        case 'cloud-lightning':
            $svg .= '
                <path d="M17.5 16H9a5 5 0 1 1 4.9-6h1.6a3.5 3.5 0 1 1 2 6Z"
                      fill="#64748b" stroke="' . $primary . '" stroke-width="1.5" stroke-linejoin="round"/>
                <path d="M13 15 L10 20 L13 20 L11 24 L16 18 L13 18 L15 15 Z"
                      fill="' . $accent . '" stroke="#ca8a04" stroke-width="0.5" stroke-linejoin="round"/>';
            break;

        default:
            $svg .= '
                <path d="M17.5 19H9a5 5 0 1 1 4.9-6h1.6a3.5 3.5 0 1 1 2 6Z"
                      fill="' . $accent . '" stroke="' . $primary . '" stroke-width="1.5" stroke-linejoin="round"/>';
    }

    $svg .= '</svg>';
    return $svg;
}

/* =========================================================
   HERO ILLUSTRATION (semi-realistic weather icon)
   ========================================================= */
function heroIcon(string $name, int $size = 96): string
{
    $uid = 'h' . uniqid();
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="' . $size . '" height="' . $size . '" style="display:block;">';

    if ($name === 'sun') {
        return $svg . '
            <defs>
                <radialGradient id="' . $uid . 'g" cx="50%" cy="45%" r="55%">
                    <stop offset="0%" stop-color="#FFFDE7"/>
                    <stop offset="45%" stop-color="#FFEB3B"/>
                    <stop offset="100%" stop-color="#FFA000"/>
                </radialGradient>
            </defs>
            <g stroke="#FFB300" stroke-width="3.2" stroke-linecap="round">
                <line x1="32" y1="4" x2="32" y2="12"/>
                <line x1="32" y1="52" x2="32" y2="60"/>
                <line x1="4" y1="32" x2="12" y2="32"/>
                <line x1="52" y1="32" x2="60" y2="32"/>
                <line x1="12.5" y1="12.5" x2="17.8" y2="17.8"/>
                <line x1="46.2" y1="46.2" x2="51.5" y2="51.5"/>
                <line x1="12.5" y1="51.5" x2="17.8" y2="46.2"/>
                <line x1="46.2" y1="17.8" x2="51.5" y2="12.5"/>
            </g>
            <circle cx="32" cy="32" r="16" fill="url(#' . $uid . 'g)"/>
        </svg>';
    }

    if ($name === 'cloud-sun') {
        return $svg . '
            <defs>
                <radialGradient id="' . $uid . 's" cx="50%" cy="45%" r="55%">
                    <stop offset="0%" stop-color="#FFFDE7"/>
                    <stop offset="100%" stop-color="#FFB300"/>
                </radialGradient>
                <linearGradient id="' . $uid . 'c" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#FFFFFF"/>
                    <stop offset="55%" stop-color="#E2E8F0"/>
                    <stop offset="100%" stop-color="#94A3B8"/>
                </linearGradient>
            </defs>
            <g stroke="#FFB300" stroke-width="2.6" stroke-linecap="round">
                <line x1="22" y1="4" x2="22" y2="10"/>
                <line x1="4" y1="22" x2="10" y2="22"/>
                <line x1="8.6" y1="8.6" x2="13" y2="13"/>
                <line x1="35.4" y1="8.6" x2="31" y2="13"/>
            </g>
            <circle cx="22" cy="22" r="11" fill="url(#' . $uid . 's)"/>
            <path d="M22 46 a9 9 0 0 1 9 -10 a12 12 0 0 1 22 -1 a8 8 0 0 1 3 17 h-31 a8 8 0 0 1 -3 -6 z" fill="url(#' . $uid . 'c)"/>
        </svg>';
    }

    if ($name === 'cloud-lightning') {
        return $svg . '
            <defs>
                <linearGradient id="' . $uid . 'c" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#CBD5E1"/>
                    <stop offset="100%" stop-color="#64748B"/>
                </linearGradient>
            </defs>
            <path d="M14 40 a9 9 0 0 1 9 -10 a12 12 0 0 1 22 -1 a8 8 0 0 1 3 17 h-32 a8 8 0 0 1 -2 -6 z" fill="url(#' . $uid . 'c)"/>
            <path d="M34 42 L24 56 L32 56 L28 62 L40 48 L33 48 L40 42 Z" fill="#FACC15" stroke="#CA8A04" stroke-width="0.8" stroke-linejoin="round"/>
        </svg>';
    }

    // Default: cloud-rain
    return $svg . '
        <defs>
            <linearGradient id="' . $uid . 'c" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#F1F5F9"/>
                <stop offset="55%" stop-color="#CBD5E1"/>
                <stop offset="100%" stop-color="#94A3B8"/>
            </linearGradient>
            <linearGradient id="' . $uid . 'd" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#93C5FD"/>
                <stop offset="100%" stop-color="#2563EB"/>
            </linearGradient>
        </defs>
        <path d="M14 38 a9 9 0 0 1 9 -10 a12 12 0 0 1 22 -1 a8 8 0 0 1 3 17 h-32 a8 8 0 0 1 -2 -6 z" fill="url(#' . $uid . 'c)"/>
        <g stroke="url(#' . $uid . 'd)" stroke-width="3.4" stroke-linecap="round">
            <line x1="20" y1="42" x2="18" y2="52"/>
            <line x1="30" y1="44" x2="28" y2="54"/>
            <line x1="40" y1="42" x2="38" y2="52"/>
            <line x1="48" y1="44" x2="46" y2="54"/>
        </g>
    </svg>';
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

$lastUpdateStr = date('g:i A', $lastUpdate);
$rawEmail = $_SESSION['email'] ?? 'Farmer';
$displayName = mb_strlen($rawEmail) > 20 ? mb_substr($rawEmail, 0, 17) . '…' : $rawEmail;

$title = 'Weather — ' . $displayName;
renderHeader($title);
?>

<style>
    :root {
        --bg: #f4f6fb;
        --surface: #ffffff;
        --text: #0f172a;
        --text-2: #475569;
        --text-3: #94a3b8;
        --border: #e5e9ef;

        --blue: #3b82f6;
        --blue-soft: #eff6ff;
        --teal: #14b8a6;
        --green: #16a34a;
        --green-soft: #ecfdf5;
        --amber: #d97706;
        --amber-soft: #fffbeb;
        --red: #dc2626;
        --red-soft: #fef2f2;

        --navbar-height: 68px;
        --sidebar-width: 264px;
        --ease: cubic-bezier(0.4, 0, 0.2, 1);
    }
    *, *::before, *::after { box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; background: var(--bg); color: var(--text);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px; line-height: 1.5; overflow-x: hidden; -webkit-font-smoothing: antialiased;
    }
    img, svg { max-width: 100%; display: block; }
    button { font-family: inherit; cursor: pointer; }
    a { color: inherit; text-decoration: none; }
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
    .gf-navbar-brand { display: flex; align-items: center; gap: 12px; color: #fff; flex-shrink: 0; }
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
        display: inline-flex; align-items: center; padding: 9px 16px;
        border-radius: 10px; font-size: 14px; font-weight: 600;
        color: rgba(255, 255, 255, 0.82); white-space: nowrap; transition: all 0.2s var(--ease);
    }
    .gf-navbar-links li a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
    .gf-navbar-links li a.active { background: #fff; color: #14532d; font-weight: 700; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18); }
    .gf-navbar-links li a.logout { color: #fca5a5; }
    .gf-navbar-toggle { display: none; width: 42px; height: 42px; place-items: center; background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.16); border-radius: 10px; color: #fff; padding: 0; }
    .gf-sidebar {
        position: fixed; top: 0; right: 0; bottom: 0; width: var(--sidebar-width);
        background: linear-gradient(180deg, #0f3d24 0%, #14532d 45%, #166534 100%);
        padding: 24px 18px 40px; color: #fff; overflow-y: auto; z-index: 1060;
        display: flex; flex-direction: column; transform: translateX(100%);
        transition: transform 0.32s var(--ease); box-shadow: -8px 0 32px rgba(0, 0, 0, 0.35);
    }
    body.gf-sidebar-open .gf-sidebar { transform: translateX(0); }
    .gf-sidebar-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 6px 8px 22px; margin-bottom: 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.12); }
    .gf-sidebar-close { width: 36px; height: 36px; display: grid; place-items: center; background: rgba(255, 255, 255, 0.1); border: none; border-radius: 10px; color: #fff; padding: 0; }
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

    /* MAIN */
    .main {
        padding: calc(var(--navbar-height) + 20px) 16px 60px;
        max-width: 1400px; margin: 0 auto; width: 100%; min-height: 100vh;
    }
    @media (min-width: 768px) {
        .main { padding: calc(var(--navbar-height) + 24px) 24px 60px; }
    }
    @media (min-width: 1200px) {
        .main { padding: calc(var(--navbar-height) + 28px) 32px 60px; }
    }

    /* PAGE HEAD */
    .page-head {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 12px; margin-bottom: 18px;
    }
    .page-head-left h1 {
        font-size: 20px; font-weight: 800; margin: 0 0 3px;
        letter-spacing: -0.025em; line-height: 1.2;
    }
    .page-head-left p {
        font-size: 12.5px; color: var(--text-3); margin: 0; font-weight: 500;
    }
    .refresh-btn {
        width: 40px; height: 40px; flex-shrink: 0;
        display: grid; place-items: center;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 12px; color: var(--text-2);
        transition: all 0.2s var(--ease);
    }
    .refresh-btn:hover { background: #f8fafc; transform: rotate(180deg); }

    /* DASH GRID */
    .dash-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 16px;
    }
    @media (min-width: 1100px) {
        .dash-grid {
            grid-template-columns: 1.15fr 1fr;
            gap: 20px;
            align-items: start;
        }
        .dash-grid .col-left,
        .dash-grid .col-right {
            display: flex; flex-direction: column; gap: 16px;
        }
    }

    /* HERO BANNER */
    .hero-banner {
        position: relative;
        background: linear-gradient(135deg, #5b6ef5 0%, #7c5cf6 55%, #8b5cf6 100%);
        border-radius: 22px;
        padding: 20px 22px 18px;
        color: #fff;
        overflow: hidden;
        box-shadow: 0 16px 32px -12px rgba(99, 102, 241, 0.4);
    }
    @media (min-width: 768px) {
        .hero-banner { padding: 26px 30px 22px; border-radius: 24px; }
    }
    .hero-banner::before {
        content: '';
        position: absolute;
        top: -60%; right: -20%;
        width: 340px; height: 340px;
        background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, transparent 65%);
        pointer-events: none;
    }

    .hero-top {
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px; margin-bottom: 18px; position: relative; z-index: 1;
    }
    .hero-badge {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255,255,255,0.18);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.22);
        padding: 5px 11px;
        border-radius: 999px;
        font-size: 9.5px; font-weight: 800;
        letter-spacing: 0.14em; text-transform: uppercase;
    }
    .hero-badge-dot {
        width: 6px; height: 6px; border-radius: 50%;
        background: #4ade80;
        box-shadow: 0 0 0 3px rgba(74, 222, 128, 0.35);
        animation: heroPulse 2s infinite;
    }
    @keyframes heroPulse {
        0%   { box-shadow: 0 0 0 0 rgba(74,222,128,0.5); }
        70%  { box-shadow: 0 0 0 6px rgba(74,222,128,0); }
        100% { box-shadow: 0 0 0 0 rgba(74,222,128,0); }
    }
    .hero-forecast-btn {
        display: inline-flex; align-items: center; gap: 4px;
        background: rgba(255,255,255,0.95);
        color: #4c1d95;
        padding: 6px 12px; border-radius: 999px;
        font-size: 11px; font-weight: 800;
        transition: all 0.2s var(--ease); white-space: nowrap;
    }
    .hero-forecast-btn:hover { background: #fff; transform: translateX(2px); }

    .hero-mid {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 14px; margin-bottom: 20px; position: relative; z-index: 1;
    }
    .hero-temp-block { min-width: 0; flex: 1; }
    .hero-temp {
        display: flex; align-items: baseline;
        font-size: 54px; font-weight: 800; line-height: 1;
        letter-spacing: -0.05em; margin-bottom: 6px;
    }
    @media (min-width: 768px) { .hero-temp { font-size: 64px; } }
    .hero-temp .unit { font-size: 22px; font-weight: 700; opacity: 0.85; margin-left: 4px; }
    @media (min-width: 768px) { .hero-temp .unit { font-size: 26px; } }
    .hero-desc { font-size: 14px; font-weight: 700; opacity: 0.98; margin-bottom: 2px; }
    @media (min-width: 768px) { .hero-desc { font-size: 16px; } }
    .hero-feels { font-size: 12px; font-weight: 500; opacity: 0.8; }
    @media (min-width: 768px) { .hero-feels { font-size: 13px; } }
    .hero-icon-box {
        width: 84px; height: 84px; border-radius: 20px;
        background: rgba(255, 255, 255, 0.16);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.25);
        display: grid; place-items: center; flex-shrink: 0;
    }
    @media (min-width: 768px) { .hero-icon-box { width: 100px; height: 100px; border-radius: 22px; } }

    .hero-stats {
        display: grid; grid-template-columns: repeat(3, 1fr);
        gap: 8px; padding-top: 16px;
        border-top: 1px solid rgba(255, 255, 255, 0.18);
        position: relative; z-index: 1;
    }
    .hero-stat { display: flex; flex-direction: column; gap: 3px; }
    .hero-stat-label {
        font-size: 9px; font-weight: 800;
        letter-spacing: 0.14em; text-transform: uppercase; opacity: 0.7;
    }
    .hero-stat-value { font-size: 14px; font-weight: 800; letter-spacing: -0.02em; }
    @media (min-width: 768px) { .hero-stat-value { font-size: 16px; } }

    /* METRIC GRID */
    .metric-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    @media (min-width: 640px) {
        .metric-grid { grid-template-columns: repeat(4, 1fr); }
    }

    .metric-card {
        background: var(--surface);
        border-radius: 16px;
        padding: 14px 16px;
        border: 1px solid var(--border);
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
        display: flex; flex-direction: column; gap: 6px;
    }
    @media (min-width: 768px) {
        .metric-card { padding: 16px 18px; border-radius: 18px; }
    }
    .metric-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 8px;
    }
    .metric-label {
        font-size: 9.5px; font-weight: 800;
        letter-spacing: 0.14em; text-transform: uppercase;
        color: var(--text-3);
    }
    .metric-icon {
        width: 28px; height: 28px; border-radius: 9px;
        display: grid; place-items: center; flex-shrink: 0;
    }
    .metric-icon.orange { background: #ffedd5; color: #ea580c; }
    .metric-icon.blue   { background: #dbeafe; color: #2563eb; }
    .metric-icon.teal   { background: #ccfbf1; color: #0d9488; }

    .metric-value {
        display: flex; align-items: baseline; gap: 3px;
        font-size: 22px; font-weight: 800;
        color: var(--text); letter-spacing: -0.03em; line-height: 1;
    }
    @media (min-width: 768px) { .metric-value { font-size: 24px; } }
    .metric-value .unit { font-size: 12px; font-weight: 700; color: var(--text-2); }
    .metric-sub { font-size: 11.5px; font-weight: 600; color: var(--text-2); }

    /* SECTION CARD */
    .card {
        background: var(--surface);
        border-radius: 18px;
        padding: 18px;
        border: 1px solid var(--border);
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
    }
    @media (min-width: 768px) {
        .card { padding: 22px 24px; border-radius: 20px; }
    }

    /* GOAT CARE GUIDE */
    .guide-head {
        display: flex; align-items: center; gap: 8px;
        margin-bottom: 4px;
    }
    .guide-icon {
        width: 28px; height: 28px;
        color: var(--green);
        display: grid; place-items: center; flex-shrink: 0;
    }
    .guide-title {
        font-size: 15px; font-weight: 800; color: var(--text);
        margin: 0; flex: 1; letter-spacing: -0.01em;
    }
    .guide-priority {
        display: inline-flex; align-items: center;
        padding: 4px 9px; border-radius: 6px;
        font-size: 9px; font-weight: 800;
        letter-spacing: 0.1em; text-transform: uppercase;
        background: #fef3c7; color: #92400e;
    }
    .guide-sub {
        font-size: 12px; color: var(--text-3);
        margin: 0 0 14px; font-weight: 500;
    }

    .guide-item {
        display: flex; align-items: flex-start; gap: 12px;
        padding: 12px 14px; border-radius: 12px;
        border: 1px solid var(--border);
        background: #fafbfc;
        margin-bottom: 8px;
    }
    .guide-item:last-child { margin-bottom: 0; }
    .guide-item.green { background: var(--green-soft); border-color: #bbf7d0; }
    .guide-item.amber { background: var(--amber-soft); border-color: #fde68a; }
    .guide-item.red   { background: var(--red-soft); border-color: #fecaca; }

    .guide-body { flex: 1; min-width: 0; }
    .guide-item-head {
        display: flex; align-items: center; gap: 8px;
        margin-bottom: 4px; flex-wrap: wrap;
    }
    .guide-item-title { font-size: 12.5px; font-weight: 800; color: var(--text); }
    .guide-tag {
        display: inline-flex; align-items: center;
        padding: 2px 8px; border-radius: 5px;
        font-size: 8.5px; font-weight: 800;
        letter-spacing: 0.08em; text-transform: uppercase;
    }
    .guide-tag.green { background: #d1fae5; color: #065f46; }
    .guide-tag.amber { background: #fef3c7; color: #92400e; }
    .guide-tag.red   { background: #fee2e2; color: #b91c1c; }
    .guide-desc { font-size: 11.5px; color: var(--text-2); line-height: 1.5; }
    .guide-status-icon {
        width: 22px; height: 22px; flex-shrink: 0;
        display: grid; place-items: center; border-radius: 50%;
    }
    .guide-status-icon.check { background: #d1fae5; color: #16a34a; }
    .guide-status-icon.warn  { background: #fef3c7; color: #d97706; }
    .guide-status-icon.neutral { background: #f1f5f9; color: #64748b; }

    /* HOURLY FORECAST */
    .hourly-head {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 12px; margin-bottom: 12px; flex-wrap: wrap;
    }
    .hourly-head-left h3 {
        font-size: 15px; font-weight: 800; color: var(--text);
        margin: 0 0 2px; letter-spacing: -0.01em;
    }
    .hourly-head-left p {
        font-size: 11.5px; color: var(--text-3); margin: 0; font-weight: 500;
    }
    .hourly-legend {
        display: flex; align-items: center; gap: 12px;
        font-size: 10.5px; color: var(--text-2); font-weight: 700;
    }
    .legend-item { display: inline-flex; align-items: center; gap: 5px; }
    .legend-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
    .legend-dot.blue { background: #3b82f6; }
    .legend-dot.teal { background: #14b8a6; }

    .chart-wrap {
        position: relative;
        width: 100%;
        margin: 12px 0 8px;
        padding: 0 4px;
    }
    .chart-svg {
        width: 100%;
        height: auto;
        display: block;
        overflow: visible;
    }
    .chart-bar { transition: fill 0.15s var(--ease); cursor: pointer; }
    .chart-dot { transition: r 0.15s var(--ease), fill 0.15s var(--ease); cursor: pointer; }

    .hourly-strip {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 4px;
        padding-top: 14px;
        border-top: 1px solid #f1f5f9;
    }
    @media (min-width: 480px) { .hourly-strip { grid-template-columns: repeat(8, 1fr); gap: 2px; } }
    .hour-cell {
        display: flex; flex-direction: column; align-items: center;
        gap: 4px; padding: 8px 2px; text-align: center;
        border-radius: 10px;
        transition: all 0.2s var(--ease);
        cursor: pointer;
    }
    .hour-cell.active { background: #eff6ff; }
    .hour-time { font-size: 10px; font-weight: 700; color: var(--text-2); }
    .hour-icon {
        width: 22px; height: 22px;
        display: grid; place-items: center;
        flex-shrink: 0;
    }
    .hour-temp { font-size: 12.5px; font-weight: 800; color: var(--text); }
    .hour-pop {
        font-size: 9.5px; font-weight: 700;
        padding: 2px 7px; border-radius: 999px;
        background: #f0fdfa; color: #0d9488;
    }
    .hour-pop.zero { background: #f1f5f9; color: var(--text-3); }

    /* 5-DAY FORECAST */
    .forecast-head { margin-bottom: 14px; }
    .forecast-head h3 {
        font-size: 15px; font-weight: 800; color: var(--text);
        margin: 0 0 2px; letter-spacing: -0.01em;
    }
    .forecast-head p {
        font-size: 11.5px; color: var(--text-3); margin: 0; font-weight: 500;
    }
    .forecast-list { display: flex; flex-direction: column; gap: 6px; }
    .forecast-row {
        display: grid;
        grid-template-columns: 80px 28px 1fr auto;
        gap: 10px;
        align-items: center;
        padding: 11px 14px;
        border-radius: 12px;
        background: #fafbfc;
        border: 1px solid var(--border);
    }
    @media (min-width: 640px) {
        .forecast-row {
            grid-template-columns: 90px 30px minmax(120px, 1fr) minmax(140px, 200px) 70px;
            gap: 14px;
            padding: 12px 18px;
        }
    }
    .forecast-day {
        display: flex; align-items: center; gap: 7px;
        font-size: 12.5px; font-weight: 800; color: var(--text);
    }
    .forecast-day-dot {
        width: 6px; height: 6px; border-radius: 50%;
        background: #3b82f6; flex-shrink: 0;
    }
    .forecast-day-dot.muted { background: #cbd5e1; }
    .forecast-icon {
        width: 28px; height: 28px;
        display: grid; place-items: center;
        flex-shrink: 0;
    }
    .forecast-label {
        font-size: 11.5px; font-weight: 600; color: var(--text-2);
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .temp-range { display: none; align-items: center; gap: 8px; }
    @media (min-width: 640px) { .temp-range { display: flex; } }
    .temp-range-value { font-size: 12px; font-weight: 800; color: var(--text); white-space: nowrap; }
    .temp-range-value.lo { color: var(--text-3); font-weight: 600; }
    .temp-range-bar {
        flex: 1; height: 4px; background: #e5e9ef;
        border-radius: 999px; position: relative; min-width: 40px;
    }
    .temp-range-fill {
        position: absolute; height: 100%; border-radius: 999px;
        background: linear-gradient(90deg, #60a5fa, #2563eb);
    }
    .forecast-pop-badge {
        display: inline-flex; align-items: center; gap: 3px;
        padding: 4px 8px; border-radius: 999px;
        background: #eff6ff; color: #2563eb;
        font-size: 10.5px; font-weight: 800;
        justify-self: end; white-space: nowrap;
    }

    /* RESPONSIVE */
    @media (max-width: 991.98px) {
        .gf-navbar { padding: 0 16px; }
        .gf-navbar-links { display: none; }
        .gf-navbar-toggle { display: grid; }
    }
    @media (max-width: 767.98px) {
        .gf-navbar { height: 60px; padding: 0 12px; }
        .gf-navbar-logo { width: 38px; height: 38px; font-size: 20px; }
        .gf-navbar-title { font-size: 17px; }
        .gf-navbar-sub { font-size: 9px; }
    }
    @media (max-width: 400px) {
        .hero-temp { font-size: 46px; }
        .hero-temp .unit { font-size: 18px; }
        .hero-icon-box { width: 72px; height: 72px; border-radius: 18px; }
        .metric-value { font-size: 20px; }
    }
</style>

<!-- NAVBAR -->
<nav class="gf-navbar">
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
        <?php gfRenderNavbarLinks($_SESSION['role'] ?? '', 'weather'); ?>
    </ul>
    <button class="gf-navbar-toggle" type="button" onclick="toggleSidebar();" aria-label="Menu">
        <?php echo svgIcon('menu', 22); ?>
    </button>
</nav>

<aside class="gf-sidebar" id="gfSidebar">
    <div class="gf-sidebar-header">
        <div class="gf-brand">
            <div class="gf-brand-icon">🐐</div>
            <div>
                <div class="gf-brand-text">GoatPro</div>
                
            </div>
        </div>
        <button class="gf-sidebar-close" type="button" onclick="closeSidebar();">
            <?php echo svgIcon('x', 18); ?>
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

            <!-- PAGE HEAD -->
            <div class="page-head">
                <div class="page-head-left">
                    <h1><?php echo MANOLO_FORTICH_NAME; ?></h1>
                    <p><?php echo date('D, M j, Y'); ?></p>
                </div>
                <a href="?refresh=1" class="refresh-btn" aria-label="Refresh weather">
                    <?php echo svgIcon('refresh', 18); ?>
                </a>
            </div>

            <div class="dash-grid">

                <!-- LEFT COLUMN -->
                <div class="col-left">

                    <!-- HERO BANNER -->
                    <div class="hero-banner">
                        <div class="hero-top">
                            <span class="hero-badge">
                                <span class="hero-badge-dot"></span>
                                Live Overview
                            </span>
                            <a href="#forecast" class="hero-forecast-btn">
                                5-Day Forecast
                                <?php echo svgIcon('chevron-right', 12, '2.5'); ?>
                            </a>
                        </div>

                        <div class="hero-mid">
                            <div class="hero-temp-block">
                                <div class="hero-temp">
                                    <?php echo $temp; ?><span class="unit">°C</span>
                                </div>
                                <div class="hero-desc"><?php echo htmlspecialchars($weatherDesc); ?></div>
                                <div class="hero-feels">Feels like <?php echo $feelsLike; ?>°C</div>
                            </div>
                            <div class="hero-icon-box">
                                <?php echo heroIcon(owIconName($weatherIcon), 64); ?>
                            </div>
                        </div>

                        <div class="hero-stats">
                            <div class="hero-stat">
                                <span class="hero-stat-label">Humidity</span>
                                <span class="hero-stat-value"><?php echo $humidity; ?>%</span>
                            </div>
                            <div class="hero-stat">
                                <span class="hero-stat-label">Rainfall</span>
                                <span class="hero-stat-value"><?php echo $rainToday; ?> mm</span>
                            </div>
                            <div class="hero-stat">
                                <span class="hero-stat-label">Wind</span>
                                <span class="hero-stat-value"><?php echo $windSpeed; ?> km/h</span>
                            </div>
                        </div>
                    </div>

                    <!-- METRIC GRID -->
                    <div class="metric-grid">
                        <div class="metric-card">
                            <div class="metric-head">
                                <span class="metric-label">Temperature</span>
                                <span class="metric-icon orange"><?php echo svgIcon('thermometer', 16, '2.2'); ?></span>
                            </div>
                            <div class="metric-value">
                                <?php echo $temp; ?><span class="unit">°C</span>
                            </div>
                            <div class="metric-sub">Feels <?php echo $feelsLike; ?>°</div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-head">
                                <span class="metric-label">Rainfall</span>
                                <span class="metric-icon blue"><?php echo svgIcon('droplet', 16, '2.2'); ?></span>
                            </div>
                            <div class="metric-value">
                                <?php echo $rainToday; ?><span class="unit">mm</span>
                            </div>
                            <div class="metric-sub"><?php echo $rainInfo['label']; ?></div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-head">
                                <span class="metric-label">Humidity</span>
                                <span class="metric-icon teal"><?php echo svgIcon('waves', 16, '2.2'); ?></span>
                            </div>
                            <div class="metric-value">
                                <?php echo $humidity; ?><span class="unit">%</span>
                            </div>
                            <div class="metric-sub"><?php echo $humidity >= 60 ? 'Normal' : 'Dry'; ?></div>
                        </div>

                        <div class="metric-card">
                            <div class="metric-head">
                                <span class="metric-label">Wind</span>
                                <span class="metric-icon blue"><?php echo svgIcon('wind', 16, '2.2'); ?></span>
                            </div>
                            <div class="metric-value">
                                <?php echo $windSpeed; ?><span class="unit">km/h</span>
                            </div>
                            <div class="metric-sub">From <?php echo $windDir; ?></div>
                        </div>
                    </div>

                    <!-- HOURLY FORECAST -->
                    <div class="card">
                        <div class="hourly-head">
                            <div class="hourly-head-left">
                                <h3>Hourly Forecast</h3>
                                <p>Next 24 hours</p>
                            </div>
                            <div class="hourly-legend">
                                <span class="legend-item"><span class="legend-dot blue"></span> Temp</span>
                                <span class="legend-item"><span class="legend-dot teal"></span> Rain</span>
                            </div>
                        </div>

                        <div class="chart-wrap">
                            <svg class="chart-svg" viewBox="0 0 800 260" preserveAspectRatio="xMidYMid meet">
                                <defs>
                                    <linearGradient id="tempGrad" x1="0" y1="0" x2="1" y2="0">
                                        <stop offset="0%" stop-color="#60a5fa"/>
                                        <stop offset="100%" stop-color="#2563eb"/>
                                    </linearGradient>
                                    <linearGradient id="tempFill" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="rgba(59,130,246,0.18)"/>
                                        <stop offset="100%" stop-color="rgba(59,130,246,0)"/>
                                    </linearGradient>
                                    <linearGradient id="rainGrad" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="rgba(20,184,166,0.55)"/>
                                        <stop offset="100%" stop-color="rgba(20,184,166,0.10)"/>
                                    </linearGradient>
                                </defs>

                                <?php
                                $chartH = 260;
                                $chartW = 800;
                                $padTop = 40;
                                $padBottom = 20;
                                $padX = 30;
                                $innerH = $chartH - $padTop - $padBottom;
                                $innerW = $chartW - ($padX * 2);

                                $numPoints = max(count($hourlyData), 1);
                                $stepX = $numPoints > 1 ? $innerW / ($numPoints - 1) : $innerW;

                                $temps = array_column($hourlyData, 'temp');
                                if (!empty($temps)) {
                                    $minTemp = min($temps) - 2;
                                    $maxTemp = max($temps) + 2;
                                } else {
                                    $minTemp = 20;
                                    $maxTemp = 35;
                                }
                                $tempRange = max(1, $maxTemp - $minTemp);

                                $tempPoints = [];
                                $rainBars = [];
                                foreach ($hourlyData as $i => $h) {
                                    $x = $padX + ($i * $stepX);
                                    $yTemp = $padTop + $innerH - (($h['temp'] - $minTemp) / $tempRange) * $innerH;
                                    $tempPoints[] = [$x, $yTemp];
                                    $rainBars[] = [
                                        'x' => $x,
                                        'h' => max(6, ($h['pop'] / 100) * ($innerH * 0.55)),
                                        'pop' => $h['pop'],
                                    ];
                                }

                                $pathD = '';
                                foreach ($tempPoints as $i => $pt) {
                                    $pathD .= ($i === 0 ? 'M' : ' L') . ' ' . round($pt[0], 1) . ' ' . round($pt[1], 1);
                                }
                                $baselineY = $padTop + $innerH;
                                $fillD = $pathD
                                    . ' L ' . round($tempPoints[count($tempPoints) - 1][0], 1) . ' ' . $baselineY
                                    . ' L ' . round($tempPoints[0][0], 1) . ' ' . $baselineY . ' Z';
                                ?>

                                <!-- Horizontal grid lines -->
                                <?php for ($g = 0; $g <= 3; $g++):
                                    $gy = $padTop + ($g * ($innerH / 3));
                                ?>
                                    <line x1="<?php echo $padX; ?>" y1="<?php echo $gy; ?>"
                                          x2="<?php echo $chartW - $padX; ?>" y2="<?php echo $gy; ?>"
                                          stroke="#f1f5f9" stroke-width="1"/>
                                <?php endfor; ?>

                                <!-- Rain bars -->
                                <?php foreach ($rainBars as $i => $bar): ?>
                                    <?php if ($bar['pop'] > 0): ?>
                                        <rect x="<?php echo $bar['x'] - 11; ?>"
                                              y="<?php echo $baselineY - $bar['h']; ?>"
                                              width="22" height="<?php echo $bar['h']; ?>"
                                              rx="4"
                                              fill="url(#rainGrad)"
                                              class="chart-bar"
                                              data-index="<?php echo $i; ?>"/>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <!-- Temp fill area -->
                                <path d="<?php echo $fillD; ?>" fill="url(#tempFill)"/>

                                <!-- Temp line -->
                                <path d="<?php echo $pathD; ?>"
                                      fill="none"
                                      stroke="url(#tempGrad)"
                                      stroke-width="3.5"
                                      stroke-linecap="round"
                                      stroke-linejoin="round"/>

                                <!-- Dots + temp labels -->
                                <?php foreach ($tempPoints as $i => $pt): ?>
                                    <circle cx="<?php echo $pt[0]; ?>"
                                            cy="<?php echo $pt[1]; ?>"
                                            r="7"
                                            fill="#ffffff"
                                            stroke="#3b82f6"
                                            stroke-width="3"
                                            class="chart-dot"
                                            data-index="<?php echo $i; ?>"/>
                                    <text x="<?php echo $pt[0]; ?>"
                                          y="<?php echo $pt[1] - 18; ?>"
                                          text-anchor="middle"
                                          font-size="15"
                                          font-weight="800"
                                          fill="#1d4ed8"
                                          font-family="system-ui, -apple-system, sans-serif"><?php echo $hourlyData[$i]['temp']; ?>°</text>
                                <?php endforeach; ?>
                            </svg>
                        </div>

                        <div class="hourly-strip">
                            <?php foreach ($hourlyData as $i => $h): ?>
                                <div class="hour-cell" data-index="<?php echo $i; ?>">
                                    <span class="hour-time"><?php echo $h['time']; ?></span>
                                    <div class="hour-icon"><?php echo wxIcon(owIconName($h['icon']), 22); ?></div>
                                    <span class="hour-temp"><?php echo $h['temp']; ?>°</span>
                                    <span class="hour-pop <?php echo $h['pop'] === 0 ? 'zero' : ''; ?>"><?php echo $h['pop']; ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

                <!-- RIGHT COLUMN -->
                <div class="col-right">

                    <!-- GOAT CARE GUIDE -->
                    <div class="card">
                        <div class="guide-head">
                            
                            <h3 class="guide-title">Goat Care Guide</h3>
                            <span class="guide-priority">Priority</span>
                        </div>
                        <p class="guide-sub">Tips for grazing and shelter today</p>

                        <?php
                        $grazingLevel = $temp >= 32 ? 'red' : ($temp >= 30 ? 'amber' : 'green');
                        $grazingTag   = $temp >= 32 ? 'LIMITED' : ($temp >= 30 ? 'CAUTION' : 'SAFE');
                        $grazingDesc  = $temp >= 32
                            ? 'Too hot for extended grazing. Provide shade and water access.'
                            : ($temp >= 30
                                ? 'Safe to graze until 3-4 PM. Provide shade during peak hours.'
                                : 'Safe to graze until 4-5 PM. Good conditions for field rotation.');
                        ?>
                        <div class="guide-item <?php echo $grazingLevel; ?>">
                            <div class="guide-body">
                                <div class="guide-item-head">
                                    <span class="guide-item-title">Grazing</span>
                                    <span class="guide-tag <?php echo $grazingLevel; ?>"><?php echo $grazingTag; ?></span>
                                </div>
                                <div class="guide-desc"><?php echo $grazingDesc; ?></div>
                            </div>
                            
                        </div>

                        <?php
                        $rainLevel = $rainTonight > 5 ? 'red' : ($rainTonight > 2 ? 'amber' : 'green');
                        $rainTag   = $rainTonight > 5 ? 'ACTION NEEDED' : ($rainTonight > 2 ? 'ADVISORY' : 'NONE');
                        $rainDesc  = $rainTonight > 5
                            ? 'Heavy rain expected. Bring goats into barn.'
                            : ($rainTonight > 2
                                ? 'Rain expected (' . $rainTonight . ' mm). Bring goats into barn before dark.'
                                : 'Minimal rain expected. Normal care is fine.');
                        ?>
                        <div class="guide-item <?php echo $rainLevel; ?>">
                            <div class="guide-body">
                                <div class="guide-item-head">
                                    <span class="guide-item-title">Rain Warning</span>
                                    <span class="guide-tag <?php echo $rainLevel; ?>"><?php echo $rainTag; ?></span>
                                </div>
                                <div class="guide-desc"><?php echo $rainDesc; ?></div>
                            </div>
                            
                        </div>

                        <?php
                        $heatLevel = $heat['level'] === 'high' ? 'red' : ($heat['level'] === 'mid' ? 'amber' : 'green');
                        $heatTag   = strtoupper($heat['label']);
                        ?>
                        <div class="guide-item <?php echo $heatLevel; ?>">
                            <div class="guide-body">
                                <div class="guide-item-head">
                                    <span class="guide-item-title">Heat Level</span>
                                    <span class="guide-tag <?php echo $heatLevel; ?>"><?php echo $heatTag; ?></span>
                                </div>
                                <div class="guide-desc"><?php echo $heat['desc']; ?></div>
                            </div>
                           
                        </div>
                    </div>

                    <!-- 5-DAY FORECAST -->
                    <div class="card" id="forecast">
                        <div class="forecast-head">
                            <h3>5-Day Forecast</h3>
                            <p><?php echo MANOLO_FORTICH_NAME; ?></p>
                        </div>

                        <div class="forecast-list">
                            <?php
                            for ($i = 0; $i < 5; $i++):
                                if (isset($dailyForecast[$i])) {
                                    $d = $dailyForecast[$i];
                                    $label = $i === 0 ? 'Today' : date('D', $d['dt']);
                                    $iconName = owIconName($d['icon']);
                                    $labelText = owDesc($d['icon']);
                                    $tempHi = $d['temp_hi'];
                                    $tempLo = $d['temp_lo'];
                                    $pop = $d['pop'];
                                } else {
                                    $label = $i === 0 ? 'Today' : date('D', strtotime("+$i days"));
                                    $iconName = ['cloud-rain','cloud-sun','cloud','cloud-sun','sun'][$i];
                                    $labelText = ['Rain Shower','Mostly Sunny','Overcast','Partly Cloudy','Sunny'][$i];
                                    $tempHi = [28, 29, 27, 26, 28][$i];
                                    $tempLo = [22, 23, 22, 21, 22][$i];
                                    $pop = [60, 20, 30, 25, 10][$i];
                                }

                                $minBase = 18; $maxBase = 35;
                                $loPct = max(0, min(100, (($tempLo - $minBase) / ($maxBase - $minBase)) * 100));
                                $hiPct = max(0, min(100, (($tempHi - $minBase) / ($maxBase - $minBase)) * 100));
                                $fillLeft = $loPct;
                                $fillWidth = max(4, $hiPct - $loPct);
                            ?>
                                <div class="forecast-row">
                                    <div class="forecast-day">
                                        <span class="forecast-day-dot <?php echo $i === 0 ? '' : 'muted'; ?>"></span>
                                        <?php echo $label; ?>
                                    </div>
                                    <div class="forecast-icon"><?php echo wxIcon($iconName, 24); ?></div>
                                    <div class="forecast-label"><?php echo htmlspecialchars($labelText); ?></div>
                                    <div class="temp-range">
                                        <span class="temp-range-value"><?php echo $tempHi; ?>°</span>
                                        <div class="temp-range-bar">
                                            <div class="temp-range-fill" style="left: <?php echo $fillLeft; ?>%; width: <?php echo $fillWidth; ?>%;"></div>
                                        </div>
                                        <span class="temp-range-value lo"><?php echo $tempLo; ?>°</span>
                                    </div>
                                    <div class="forecast-pop-badge">
                                        <?php echo svgIcon('droplet', 10, '2.4'); ?>
                                        <?php echo $pop; ?>%
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                </div>

            </div>

        </main>
    </div>
</div>

<script>
function toggleSidebar() { document.body.classList.toggle('gf-sidebar-open'); }
function closeSidebar() { document.body.classList.remove('gf-sidebar-open'); }
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

// Hourly cell interaction
(function () {
    var cells = document.querySelectorAll('.hour-cell');
    var dots  = document.querySelectorAll('.chart-dot');
    var bars  = document.querySelectorAll('.chart-bar');

    cells.forEach(function (cell) {
        cell.addEventListener('click', function () {
            var idx = parseInt(this.dataset.index);
            cells.forEach(function (c) { c.classList.remove('active'); });
            this.classList.add('active');

            dots.forEach(function (d) {
                var isActive = parseInt(d.dataset.index) === idx;
                d.setAttribute('r', isActive ? '9' : '7');
                d.setAttribute('fill', isActive ? '#1d4ed8' : '#ffffff');
                d.setAttribute('stroke', isActive ? '#1d4ed8' : '#3b82f6');
            });
            bars.forEach(function (b) {
                var isActive = parseInt(b.dataset.index) === idx;
                b.setAttribute('opacity', isActive ? '1' : '0.7');
            });
        });
    });

    if (cells.length) cells[0].click();
})();
</script>

<?php renderFooter(); ?>