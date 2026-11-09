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

// ---- Helper to read a specific key from the .env file ----
function wf_get_env(string $key, string $default = ''): string {
    // Adjust the path if your .env is located elsewhere
    $envPath = __DIR__ . '/../../.env'; 
    if (!file_exists($envPath)) return $default;
    
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments
        if (strpos($line, '#') === 0) continue;
        
        // Check if the line starts with our key
        if (strpos($line, $key . '=') === 0) {
            $value = substr($line, strlen($key) + 1);
            // Remove surrounding quotes if they exist
            return trim($value, '"\'');
        }
    }
    return $default;
}

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
function wf_fetch_json(string $url, bool $isMetNo = false): ?array {
    $ch = curl_init($url);
    $headers = [];
    
    // Default User-Agent for standard APIs
    $userAgent = 'GoatPro/1.0'; 
    
    if ($isMetNo) {
        // Fetch the email from the .env file, fallback to localhost if missing
        $contact = wf_get_env('MET_NORWAY_CONTACT', 'admin@localhost');
        
        // MET Norway requires the AppName/Version and contact info
        $userAgent = 'GoatPro/1.0 ' . $contact; 
        
        // Set it explicitly in the header
        $headers[] = 'User-Agent: ' . $userAgent; 
    }
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_HTTPHEADER     => $headers,
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
         . '&models=ecmwf_ifs025' // UPDATED MODEL ID
         . '&timezone=Asia%2FManila&forecast_days=2';
    $ec = wf_fetch_json($url);
    wf_cache_set('ec', $ec);
}

// ---- 4. MET Norway (Locationforecast 2.0) ----
$metno = wf_cache_get('metno');
if ($metno === null) {
    $url = 'https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=' . WF_LAT . '&lon=' . WF_LON;
    $metno = wf_fetch_json($url, true);
    wf_cache_set('metno', $metno);
}

// ---- extract current values per source ----
$sources = [];

// OpenWeather
if ($ow && isset($ow['main'])) {
    $sources['OpenWeather'] = [
        'available'  => true,
        'temp'       => (float)$ow['main']['temp'],
        'humidity'   => (float)$ow['main']['humidity'],
        'wind'       => (float)($ow['wind']['speed'] ?? 0) * 3.6,
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

// ECMWF
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

// MET Norway
if ($metno && isset($metno['properties']['timeseries'])) {
    $timeseries = $metno['properties']['timeseries'];
    $now = time();
    $closest = null;
    $minDiff = PHP_INT_MAX;
    
    foreach ($timeseries as $entry) {
        $entryTime = strtotime($entry['time']);
        $diff = abs($entryTime - $now);
        if ($diff < $minDiff) {
            $minDiff = $diff;
            $closest = $entry;
        }
    }
    
    if ($closest && isset($closest['data']['instant']['details'])) {
        $details = $closest['data']['instant']['details'];
        $sources['METNorway'] = [
            'available' => true,
            'temp'      => isset($details['air_temperature']) ? (float)$details['air_temperature'] : null,
            'humidity'  => isset($details['relative_humidity']) ? (float)$details['relative_humidity'] : null,
            'wind'      => isset($details['wind_speed']) ? (float)$details['wind_speed'] * 3.6 : null,
            'rain'      => isset($closest['data']['next_1_hours']['details']['precipitation_amount']) 
                            ? (float)$closest['data']['next_1_hours']['details']['precipitation_amount'] : 0,
        ];
    } else {
        $sources['METNorway'] = ['available' => false];
    }
} else {
    $sources['METNorway'] = ['available' => false];
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
    'metno'=> [],
    'mean' => []  
];

if ($om && isset($om['hourly']['time'])) {
    $hourlyTimes = $om['hourly']['time'];
    $omTemps = $om['hourly']['temperature_2m'] ?? [];
    $ecTemps = ($ec && isset($ec['hourly']['temperature_2m'])) ? $ec['hourly']['temperature_2m'] : [];
    
    // Create lookup arrays
    $owLookup = [];
    if ($owForecast && isset($owForecast['list'])) {
        foreach ($owForecast['list'] as $item) {
            $owLookup[$item['dt']] = (float)$item['main']['temp'];
        }
    }

    $metnoLookup = [];
    if ($metno && isset($metno['properties']['timeseries'])) {
        foreach ($metno['properties']['timeseries'] as $entry) {
            $metnoLookup[strtotime($entry['time'])] = $entry;
        }
    }

    for ($i = 0; $i < min(24, count($hourlyTimes)); $i++) {
        $t = $hourlyTimes[$i];
        $ts = strtotime($t);
        
        $omT = $omTemps[$i] ?? null;
        $ecT = $ecTemps[$i] ?? null;
        
        // Find closest OpenWeather
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
            if ($closestDelta <= 5400) $owT = $owLookup[$closestTs];
        }

        // Find closest MET Norway
        $metnoT = null;
        if (!empty($metnoLookup)) {
            $closestDiff = PHP_INT_MAX;
            $closestTime = null;
            foreach ($metnoLookup as $mTime => $mEntry) {
                $diff = abs($mTime - $ts);
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $closestTime = $mTime;
                }
            }
            if ($closestDiff <= 5400) {
                $metnoT = $metnoLookup[$closestTime]['data']['instant']['details']['air_temperature'] ?? null;
            }
        }

        // Calculate interpolated mean
        $validTemps = array_filter([$owT, $omT, $ecT, $metnoT], fn($v) => $v !== null);
        $meanT = count($validTemps) > 0 ? array_sum($validTemps) / count($validTemps) : null;

        if ($meanT !== null) {
            $chartData['time'][] = $t;
            $chartData['ow'][]   = $owT !== null ? round($owT, 1) : null;
            $chartData['om'][]   = $omT !== null ? round($omT, 1) : null;
            $chartData['ec'][]   = $ecT !== null ? round($ecT, 1) : null;
            $chartData['metno'][] = $metnoT !== null ? round($metnoT, 1) : null;
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

if (basename($_SERVER['SCRIPT_FILENAME']) === 'weather-bounds-proxy.php') {
    header('Content-Type: application/json');
    echo json_encode($result);
} else {
    return $result;
}
?>