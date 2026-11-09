<?php
// Because this file is in the /weather/ folder, it goes up 2 levels to find /config/
require_once '../../config/config.php';
require_once '../../config/functions.php';

$currentRole = $_SESSION['role'] ?? null;
if ($currentRole !== 'admin') {
    if (basename($_SERVER['SCRIPT_FILENAME']) === 'weather-bounds-proxy.php') {
        http_response_code(403);
        exit('Access denied');
    }
    return null;
}

define('WF_LAT', 8.3678);
define('WF_LON', 124.8663);
define('WF_CACHE_TTL', 1800);

// ---- session cache helper ----
function wf_cache_get(string $key) {
    if (isset($_SESSION['_wf_bounds'][$key]) &&
        (time() - $_SESSION['_wf_bounds'][$key]['t']) < WF_CACHE_TTL) {
        return $_SESSION['_wf_bounds'][$key]['v'];
    }
    return null;
}
function wf_cache_set(string $key, $value) {
    $_SESSION['_wf_bounds'][$key] = ['t' => time(), 'v' => $value];
}

// ---- generic JSON fetch ----
function wf_fetch_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'GoatPro/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

// ---- get the hourly value closest to now ----
function wf_pick_current_hour(array $hourly, string $key): ?float {
    if (empty($hourly['time']) || !isset($hourly[$key])) return null;
    $now = time();
    $best = null;
    $bestDelta = PHP_INT_MAX;
    foreach ($hourly['time'] as $i => $t) {
        $ts = strtotime($t);
        if ($ts === false) continue;
        $delta = abs($ts - $now);
        if ($delta < $bestDelta) {
            $bestDelta = $delta;
            $best = $hourly[$key][$i] ?? null;
        }
    }
    return $best !== null ? (float)$best : null;
}

// ---- 1. OpenWeather (current) ----
$ow = wf_cache_get('ow');
if ($ow === null) {
    if (defined('OPENWEATHER_API_KEY')) {
        $url = 'https://api.openweathermap.org/data/2.5/weather?lat=' . WF_LAT
             . '&lon=' . WF_LON . '&units=metric&appid=' . OPENWEATHER_API_KEY;
        $ow = wf_fetch_json($url);
        wf_cache_set('ow', $ow);
    }
}

// ---- 1b. OpenWeather (forecast) ----
$owForecast = wf_cache_get('ow_forecast');
if ($owForecast === null) {
    if (defined('OPENWEATHER_API_KEY')) {
        $url = 'https://api.openweathermap.org/data/2.5/forecast?lat=' . WF_LAT
             . '&lon=' . WF_LON . '&units=metric&appid=' . OPENWEATHER_API_KEY;
        $owForecast = wf_fetch_json($url);
        wf_cache_set('ow_forecast', $owForecast);
    }
}

// ---- 2. Open-Meteo (blended multi-model) ----
$om = wf_cache_get('om');
if ($om === null) {
    $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . WF_LAT
         . '&longitude=' . WF_LON
         . '&hourly=temperature_2m,relative_humidity_2m,wind_speed_10m,precipitation'
         . '&current=temperature_2m,relative_humidity_2m,wind_speed_10m,precipitation'
         . '&timezone=Asia%2FManila&forecast_days=2';
    $om = wf_fetch_json($url);
    wf_cache_set('om', $om);
}

// ---- 3. ECMWF IFS via Open-Meteo model selector ----
$ec = wf_cache_get('ec');
if ($ec === null) {
    $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . WF_LAT
         . '&longitude=' . WF_LON
         . '&hourly=temperature_2m,relative_humidity_2m,wind_speed_10m,precipitation'
         . '&models=ecmwf_ifs04'
         . '&timezone=Asia%2FManila&forecast_days=2';
    $ec = wf_fetch_json($url);
    wf_cache_set('ec', $ec);
}

// ---- extract current values per source ----
$sources = [];

// OpenWeather
if ($ow && isset($ow['main'])) {
    $sources['OpenWeather'] = [
        'available'  => true,
        'temp'       => (float)$ow['main']['temp'],
        'humidity'   => (float)$ow['main']['humidity'],
        'wind'       => (float)($ow['wind']['speed'] ?? 0) * 3.6, // m/s -> km/h
        'rain'       => (float)($ow['rain']['1h'] ?? $ow['rain']['3h'] ?? 0),
    ];
} else {
    $sources['OpenWeather'] = ['available' => false];
}

// Open-Meteo (current block)
if ($om && isset($om['current'])) {
    $sources['OpenMeteo'] = [
        'available' => true,
        'temp'      => (float)$om['current']['temperature_2m'],
        'humidity'  => (float)$om['current']['relative_humidity_2m'],
        'wind'      => (float)$om['current']['wind_speed_10m'],
        'rain'      => (float)$om['current']['precipitation'],
    ];
} elseif ($om && isset($om['hourly'])) {
    $sources['OpenMeteo'] = [
        'available' => true,
        'temp'      => wf_pick_current_hour($om['hourly'], 'temperature_2m'),
        'humidity'  => wf_pick_current_hour($om['hourly'], 'relative_humidity_2m'),
        'wind'      => wf_pick_current_hour($om['hourly'], 'wind_speed_10m'),
        'rain'      => wf_pick_current_hour($om['hourly'], 'precipitation'),
    ];
} else {
    $sources['OpenMeteo'] = ['available' => false];
}

// ECMWF (via Open-Meteo; only has hourly)
if ($ec && isset($ec['hourly'])) {
    $sources['ECMWF_IFS'] = [
        'available' => true,
        'temp'      => wf_pick_current_hour($ec['hourly'], 'temperature_2m'),
        'humidity'  => wf_pick_current_hour($ec['hourly'], 'relative_humidity_2m'),
        'wind'      => wf_pick_current_hour($ec['hourly'], 'wind_speed_10m'),
        'rain'      => wf_pick_current_hour($ec['hourly'], 'precipitation'),
    ];
} else {
    $sources['ECMWF_IFS'] = ['available' => false];
}

// ---- compute bounds per variable ----
function wf_bounds(array $sources, string $field): array {
    $vals = [];
    foreach ($sources as $s) {
        if (!empty($s['available']) && isset($s[$field]) && $s[$field] !== null) {
            $vals[] = $s[$field];
        }
    }
    if (empty($vals)) {
        return ['value' => null, 'min' => null, 'max' => null, 'spread' => null, 'n' => 0];
    }
    $min = min($vals);
    $max = max($vals);
    $mean = array_sum($vals) / count($vals);
    return [
        'value'  => round($mean, 1),
        'min'    => round($min, 1),
        'max'    => round($max, 1),
        'spread' => round($max - $min, 1),
        'n'      => count($vals),
    ];
}

$bounds = [
    'temp'     => wf_bounds($sources, 'temp'),
    'humidity' => wf_bounds($sources, 'humidity'),
    'wind'     => wf_bounds($sources, 'wind'),
    'rain'     => wf_bounds($sources, 'rain'),
];

// strip internal 'available' flag from source output for display
$publicSources = [];
foreach ($sources as $name => $s) {
    $publicSources[$name] = [
        'available' => !empty($s['available']),
        'temp'      => $s['temp']     ?? null,
        'humidity'  => $s['humidity'] ?? null,
        'wind'      => $s['wind']     ?? null,
        'rain'      => $s['rain']     ?? null,
    ];
}

// ============================================================
// EXTRACT 24H TRENDLINE DATA FOR COMPARISON CHART
// ============================================================
$chartData = [
    'time' => [], 
    'ow'   => [], 
    'om'   => [], 
    'ec'   => [], 
    'mean' => []  
];

if ($om && isset($om['hourly']['time'])) {
    $hourlyTimes = $om['hourly']['time'];
    $omTemps = $om['hourly']['temperature_2m'] ?? [];
    $ecTemps = ($ec && isset($ec['hourly']['temperature_2m'])) ? $ec['hourly']['temperature_2m'] : [];
    
    // Create lookup array for OpenWeather (mapping unix timestamps to temps)
    $owLookup = [];
    if ($owForecast && isset($owForecast['list'])) {
        foreach ($owForecast['list'] as $item) {
            $owLookup[$item['dt']] = (float)$item['main']['temp'];
        }
    }

    for ($i = 0; $i < min(24, count($hourlyTimes)); $i++) {
        $t = $hourlyTimes[$i];
        $ts = strtotime($t);
        
        $omT = $omTemps[$i] ?? null;
        $ecT = $ecTemps[$i] ?? null;
        
        // Find closest OpenWeather timestamp (it's 3-hour intervals)
        $owT = null;
        if (!empty($owLookup)) {
            $closestDelta = PHP_INT_MAX;
            $closestTs = null;
            foreach ($owLookup as $owTs => $owTemp) {
                $delta = abs($owTs - $ts);
                if ($delta < $closestDelta) {
                    $closestDelta = $delta;
                    $closestTs = $owTs;
                }
            }
            // If the closest forecast is within 1.5 hours, use it
            if ($closestDelta <= 5400) {
                $owT = $owLookup[$closestTs];
            }
        }

        // Calculate the interpolated mean for this specific hour
        $validTemps = array_filter([$owT, $omT, $ecT], fn($v) => $v !== null);
        $meanT = count($validTemps) > 0 ? array_sum($validTemps) / count($validTemps) : null;

        if ($meanT !== null) {
            $chartData['time'][] = $t;
            $chartData['ow'][]   = $owT !== null ? round($owT, 1) : null;
            $chartData['om'][]   = $omT !== null ? round($omT, 1) : null;
            $chartData['ec'][]   = $ecT !== null ? round($ecT, 1) : null;
            $chartData['mean'][] = round($meanT, 1);
        }
    }
}

$result = [
    'fetched_at' => date('c'),
    'location'   => ['lat' => WF_LAT, 'lon' => WF_LON],
    'sources'    => $publicSources,
    'bounds'     => $bounds,
    'chart_data' => $chartData,
    'active'     => count(array_filter($publicSources, fn($s) => $s['available'])),
    'total'      => count($publicSources),
];

// If accessed directly via browser, output JSON. If included via PHP, return the array.
if (basename($_SERVER['SCRIPT_FILENAME']) === 'weather-bounds-proxy.php') {
    header('Content-Type: application/json');
    echo json_encode($result);
} else {
    return $result;
}

?>