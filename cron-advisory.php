<?php
/**
 * AUTOMATIC ADVISORY CRON SCRIPT
 * 
 * Kini nga script kay mag-run sa background (via Task Scheduler).
 * Mag-check siya sa weather ug automatic nga mag-send og SMS.
 */

// CLI ONLY: dili pwede i-open pinaagi sa browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

// I-set ang PH Time
date_default_timezone_set('Asia/Manila');

// I-load ang config
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/functions.php';

// I-set ang Database Timezone sa PH Time
$pdo->exec("SET time_zone = '+08:00'");

// ============================================================
// CONFIGURATION
// ============================================================
define('LAT', 8.3678);
define('LON', 124.8663);

// COOLDOWN SETTINGS (sa minutos)
define('COOLDOWN_MEDIUM', 180); // 3 hours para sa medium warnings
define('COOLDOWN_DUPLICATE', 15); // 15 mins para sa parehas nga message

$logFile = __DIR__ . '/cron-advisory.log';

function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d h:i A'); // PH Time format
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

writeLog("--- CRON STARTED ---");

// ============================================================
// 1. FETCH WEATHER DATA
// ============================================================
function fetchWeather($endpoint, $params) {
    $params['appid'] = OPENWEATHER_API_KEY;
    $url = 'https://api.openweathermap.org/data/2.5/' . $endpoint . '?' . http_build_query($params);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

$current = fetchWeather('weather', ['lat' => LAT, 'lon' => LON, 'units' => 'metric']);
$forecast = fetchWeather('forecast', ['lat' => LAT, 'lon' => LON, 'units' => 'metric']);

if (!$current || !$forecast) {
    writeLog("ERROR: Failed to fetch weather data.");
    exit("Failed to fetch weather.");
}

// ============================================================
// 2. COMPUTE WEATHER METRICS
// ============================================================
$temp = round($current['main']['temp']);
$humidity = (int)$current['main']['humidity'];
$thi = (1.8 * $temp + 32) - (0.55 - 0.0055 * $humidity) * (1.8 * $temp - 26);

$rainToday = 0;
$rainTonight = 0;
$todayKey = date('Y-m-d');

foreach ($forecast['list'] as $item) {
    if (date('Y-m-d', $item['dt']) === $todayKey) {
        $rainToday += $item['rain']['3h'] ?? 0;
        
        $h = (int)date('G', $item['dt']);
        if ($h >= 18) {
            $rainTonight += $item['rain']['3h'] ?? 0;
        }
    }
}
$rainToday = round($rainToday, 1);
$rainTonight = round($rainTonight, 1);

writeLog("Weather Data: Temp={$temp}°C, RainToday={$rainToday}mm, RainTonight={$rainTonight}mm");

// ============================================================
// 3. CHECK HIGH PROBABILITY & SUNRISE LOGIC
// ============================================================
$highPopCount = 0;
$maxPop = 0;
$now = time();

// Check sa tanang forecast entries karon ug ugma (dili lang limit sa 6 hours)
foreach ($forecast['list'] as $item) {
    if ($item['dt'] >= $now) {
        $pop = round(($item['pop'] ?? 0) * 100);
        if ($pop > $maxPop) $maxPop = $pop;
        if ($pop >= 70) {
            $highPopCount++;
        }
    }
}
writeLog("Forecast Check: Max Pop = {$maxPop}%, High Pop Count = {$highPopCount}");

$currentHour = (int)date('G');
$past12hRain = 0;
foreach ($forecast['list'] as $item) {
    if ($item['dt'] < $now && $item['dt'] >= ($now - 43200)) {
        $past12hRain += $item['rain']['3h'] ?? 0;
    }
}
$past12hRain = round($past12hRain, 1);
$isMorning = ($currentHour >= 6 && $currentHour <= 9);

// ============================================================
// 4. DETERMINE ADVISORY & PRIORITY
// ============================================================
$advisory = null;
$priority = 'low';

// Rule 1: Sunrise Tigway Advisory
if ($isMorning && $past12hRain > 1) {
    $priority = 'high'; 
    $msg = "Maayong buntag! Nihunong na ang ulan gabii. Pwede na ninyo matugway (ipagawas) ang mga kanding sugod 7:00 AM kay moinit na ang adlaw ug mo-uga na ang sagbot.";
    $advisory = ['type' => 'rain', 'msg' => $msg];
}
// Rule 2: Heavy Rain (HIGH)
elseif ($rainTonight > 5 || $rainToday > 15) {
    $priority = 'high';
    $msg = "URGENTE: Kusog nga ulan ({$rainTonight}mm) ang gi-expect karong gabhiona. Palihog pasulod sa kural o silong ang mga kanding aron dili mabasa ug matugnawan pwede ra ninyo kumpayan. Ayaw pagpanggawas hangtod mohunong ang ulan ug moinit ang panahon ugma.";
    $advisory = ['type' => 'rain', 'msg' => $msg];
} 
// Rule 3: Light Rain or High Probability (MEDIUM)
elseif ($rainTonight > 2 || $highPopCount >= 2) {
    $priority = 'medium';
    $msg = "Pahibalo: Base sa forecast, dako ang tsansa nga mo-ulan ugma sa buntag. Palihog siguroha nga naa sa silong o kural ang mga kanding inig ka-buntag ug naay dry nga pagkaon (kumpay) nga ready, kay posible nga dili sila pwede itugway kung basa ang sagbot.";
    $advisory = ['type' => 'rain', 'msg' => $msg];
}
// Rule 4: Severe Heat (HIGH)
elseif ($thi >= 85) {
    $priority = 'high';
    $msg = "Pahibalo: Grabe ang kainit karon (THI: " . round($thi) . "). Siguroha nga naay dakong landong ang kanding ug kanunay naay limpyo nga tubig nga mainom aron malikayan ang heat stress.";
    $advisory = ['type' => 'heat', 'msg' => $msg];
} 
// Rule 5: Mild Heat (MEDIUM)
elseif ($thi >= 79 && $temp >= 30) {
    $priority = 'medium';
    $msg = "Pahibalo: Medyo init ang panahon karon. Palihog siguroha nga naay landong ug daghan nga tubig nga mainom ang mga kanding aron dili ma-stress sa kainit.";
    $advisory = ['type' => 'heat', 'msg' => $msg];
}

if (!$advisory) {
    writeLog("No advisory triggered. Weather is normal.");  
    exit("No advisory.");
}

writeLog("Advisory Detected: [{$priority}] {$advisory['msg']}");

// ============================================================
// 5. CHECK COOLDOWN
// ============================================================
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM advisory_logs 
    WHERE message = :msg 
    AND created_at >= DATE_SUB(NOW(), INTERVAL " . COOLDOWN_DUPLICATE . " MINUTE)
");
$stmt->execute([':msg' => $advisory['msg']]);
$isDuplicate = $stmt->fetchColumn() > 0;

if ($isDuplicate) {
    writeLog("SKIPPED: Duplicate message sent in the last " . COOLDOWN_DUPLICATE . " minutes.");
    exit("Duplicate skipped.");
}

if ($priority === 'medium') {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM advisory_logs 
        WHERE advisory_type = :type 
        AND priority = 'medium'
        AND created_at >= DATE_SUB(NOW(), INTERVAL " . COOLDOWN_MEDIUM . " MINUTE)
    ");
    $stmt->execute([':type' => $advisory['type']]);
    $isCooldown = $stmt->fetchColumn() > 0;

    if ($isCooldown) {
        writeLog("SKIPPED: Medium {$advisory['type']} advisory sent in the last 3 hours.");
        exit("Cooldown skipped.");
    }
}

// ============================================================
// 6. SEND SMS
// ============================================================
writeLog("Sending advisory to farmers...");

$result = sendAdvisoryToFarmers(
    $pdo,
    $advisory['msg'],
    $advisory['type'],
    $priority,
    [
        'trigger_source' => 'automatic_cron',
        'rainToday'      => $rainToday,
        'rainTonight'    => $rainTonight,
        'maxPop'         => $maxPop,
        'temp'           => $temp,
        'humidity'       => $humidity,
        'thi'            => round($thi)
    ],
    null
);

if ($result['success']) {
    writeLog("SUCCESS: Sent to {$result['success_count']} of {$result['recipient_count']} farmers.");
    echo "Success";
} else {
    writeLog("FAILED: " . ($result['error'] ?? 'Unknown error'));
    echo "Failed";
}