<?php
/**
 * Weather Forecast + Goat Farming Tips helper
 * ---------------------------------------------
 * Uses OpenWeatherMap's free "5 Day / 3 Hour Forecast" API.
 * Location is FIXED for the whole system: Manolo Fortich, Bukidnon.
 *
 * ⚠️ SETUP REQUIRED: paste your API key below before this will work.
 * Get a free key at https://openweathermap.org/api (see setup steps
 * shared in chat). New keys take 10 min - 2 hours to activate.
 *
 * Include this file, then call:
 *   $weather = getFarmWeatherForecast();
 *   $tips    = getGoatFarmingTips($weather);
 */

// ---- PASTE YOUR OPENWEATHERMAP API KEY HERE ----
if (!defined('OPENWEATHER_API_KEY')) {
    require_once __DIR__ . '/env-loader.php';
    loadEnv(__DIR__ . '/../.env');
    define('OPENWEATHER_API_KEY', getenv('OPENWEATHER_API_KEY') ?: '');
}

// ---- Fixed farm location: Manolo Fortich, Bukidnon ----
if (!defined('FARM_WEATHER_LAT')) define('FARM_WEATHER_LAT', 8.3675);
if (!defined('FARM_WEATHER_LON')) define('FARM_WEATHER_LON', 124.8656);
if (!defined('FARM_WEATHER_LABEL')) define('FARM_WEATHER_LABEL', 'Manolo Fortich, Bukidnon');

/**
 * Fetches a short daily forecast (today + next 4 days) for the farm's
 * fixed location, using OpenWeatherMap's 5-day/3-hour forecast endpoint
 * (free tier). The 3-hour blocks are aggregated into daily summaries.
 * Results are cached to disk for 60 minutes to avoid hitting the API
 * limit on every page load.
 *
 * @return array{
 *   location: string,
 *   fetched_at: string,
 *   days: array<int, array{date:string, weathercode:int, tmax:float, tmin:float, pop:int}>
 * }|null  Returns null if the forecast could not be fetched or cached.
 */
function getFarmWeatherForecast(): ?array {
    $cacheDir  = __DIR__ . '/../cache';
    $cacheFile = $cacheDir . '/weather_cache.json';
    $cacheTtl  = 60 * 60; // 1 hour

    // Serve from cache if still fresh
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['days'])) {
            return $cached;
        }
    }

    if (!defined('OPENWEATHER_API_KEY') || OPENWEATHER_API_KEY === '' || OPENWEATHER_API_KEY === 'YOUR_API_KEY_HERE') {
        // No key configured yet - fall back to stale cache if any, else null
        if (is_file($cacheFile)) {
            $stale = json_decode(file_get_contents($cacheFile), true);
            if (is_array($stale) && !empty($stale['days'])) return $stale;
        }
        return null;
    }

    $url = sprintf(
        'https://api.openweathermap.org/data/2.5/forecast?lat=%s&lon=%s&units=metric&appid=%s',
        FARM_WEATHER_LAT,
        FARM_WEATHER_LON,
        OPENWEATHER_API_KEY
    );

    $raw = null;
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $raw = @file_get_contents($url, false, $ctx);
    } catch (\Throwable $e) {
        $raw = null;
    }

    if ($raw === false || $raw === null) {
        // API unreachable/key not yet active - fall back to stale cache if we have one
        if (is_file($cacheFile)) {
            $stale = json_decode(file_get_contents($cacheFile), true);
            if (is_array($stale) && !empty($stale['days'])) return $stale;
        }
        return null;
    }

    $data = json_decode($raw, true);
    if (!isset($data['list']) || (isset($data['cod']) && (string)$data['cod'] !== '200')) {
        // API returned an error (e.g. invalid/inactive key) - fall back to stale cache
        if (is_file($cacheFile)) {
            $stale = json_decode(file_get_contents($cacheFile), true);
            if (is_array($stale) && !empty($stale['days'])) return $stale;
        }
        return null;
    }

    // Timezone offset (seconds from UTC) for this location, so "today" lines up correctly
    $tzOffset = (int)($data['city']['timezone'] ?? 28800); // default +8h (Philippines)

    // Group the 3-hour blocks into daily buckets, split into DAY (06:00-17:59)
    // and NIGHT (18:00-05:59) periods - matches the SARAI-style forecast cards.
    $buckets = [];
    foreach ($data['list'] as $entry) {
        $localTs = $entry['dt'] + $tzOffset;
        $date = gmdate('Y-m-d', $localTs);
        $hour = (int)gmdate('H', $localTs);
        $period = ($hour >= 6 && $hour < 18) ? 'day' : 'night';

        if (!isset($buckets[$date])) {
            $buckets[$date] = [
                'tmax' => -999, 'tmin' => 999, 'humidity' => [], 'total_mm' => 0,
                'day'   => ['pop' => 0, 'rain_mm' => 0, 'entries' => []],
                'night' => ['pop' => 0, 'rain_mm' => 0, 'entries' => []],
            ];
        }

        $tmax = (float)($entry['main']['temp_max'] ?? $entry['main']['temp'] ?? 0);
        $tmin = (float)($entry['main']['temp_min'] ?? $entry['main']['temp'] ?? 0);
        $pop  = (int)round((float)($entry['pop'] ?? 0) * 100); // OWM gives pop as 0-1 fraction
        $hum  = (int)($entry['main']['humidity'] ?? 0);
        $rain = (float)($entry['rain']['3h'] ?? 0);

        $buckets[$date]['tmax'] = max($buckets[$date]['tmax'], $tmax);
        $buckets[$date]['tmin'] = min($buckets[$date]['tmin'], $tmin);
        $buckets[$date]['humidity'][] = $hum;
        $buckets[$date]['total_mm'] += $rain;

        $buckets[$date][$period]['pop']      = max($buckets[$date][$period]['pop'], $pop);
        $buckets[$date][$period]['rain_mm'] += $rain;
        $buckets[$date][$period]['entries'][] = [
            'hour' => $hour,
            'id'   => (int)($entry['weather'][0]['id'] ?? 800),
        ];
    }

    $days = [];
    foreach ($buckets as $date => $b) {
        $avgHumidity = !empty($b['humidity']) ? (int)round(array_sum($b['humidity']) / count($b['humidity'])) : 0;

        // Representative condition per period: closest to midday for DAY, closest to midnight for NIGHT
        $pickRepresentative = function (array $entries, int $targetHour) {
            if (empty($entries)) return null;
            $best = $entries[0];
            $bestDiff = min(abs($best['hour'] - $targetHour), 24 - abs($best['hour'] - $targetHour));
            foreach ($entries as $e) {
                $diff = min(abs($e['hour'] - $targetHour), 24 - abs($e['hour'] - $targetHour));
                if ($diff < $bestDiff) { $best = $e; $bestDiff = $diff; }
            }
            return $best['id'];
        };

        $dayId   = $pickRepresentative($b['day']['entries'], 12);
        $nightId = $pickRepresentative($b['night']['entries'], 0);

        $days[] = [
            'date'     => $date,
            'tmax'     => $b['tmax'],
            'tmin'     => $b['tmin'],
            'humidity' => $avgHumidity,
            'total_mm' => round($b['total_mm'], 2),
            // kept for backward-compat with tips logic (whichever period has data)
            'weathercode' => $dayId ?? $nightId ?? 800,
            'pop'         => max($b['day']['pop'], $b['night']['pop']),
            'day' => [
                'available' => $dayId !== null,
                'weathercode' => $dayId ?? 800,
                'pop'         => $b['day']['pop'],
                'rain_mm'     => round($b['day']['rain_mm'], 2),
            ],
            'night' => [
                'available' => $nightId !== null,
                'weathercode' => $nightId ?? 800,
                'pop'         => $b['night']['pop'],
                'rain_mm'     => round($b['night']['rain_mm'], 2),
            ],
        ];

        if (count($days) >= 5) break; // today + next 4 days
    }

    $result = [
        'location'   => FARM_WEATHER_LABEL,
        'fetched_at' => date('Y-m-d H:i:s'),
        'days'       => $days,
    ];

    // Write cache (best-effort; don't break the page if the folder isn't writable)
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    @file_put_contents($cacheFile, json_encode($result));

    return $result;
}

/**
 * Maps an OpenWeatherMap condition ID to a display label + emoji icon.
 * Reference: https://openweathermap.org/weather-conditions
 */
function weatherCodeToDisplay(int $id): array {
    if ($id >= 200 && $id < 300) return ['Thunderstorm', '⛈️'];
    if ($id >= 300 && $id < 400) return ['Drizzle', '🌦️'];
    if ($id >= 500 && $id < 505) return ['Rain', '🌧️'];
    if ($id >= 505 && $id < 600) return ['Heavy rain', '🌧️'];
    if ($id >= 600 && $id < 700) return ['Snow', '🌨️'];
    if ($id === 701 || $id === 741) return ['Foggy/Misty', '🌫️'];
    if ($id >= 700 && $id < 800) return ['Atmosphere', '🌫️'];
    if ($id === 800) return ['Clear sky', '☀️'];
    if ($id === 801) return ['Mostly clear', '🌤️'];
    if ($id === 802) return ['Partly cloudy', '⛅'];
    if ($id === 803 || $id === 804) return ['Overcast', '☁️'];
    return ['Unknown', '🌡️'];
}

/**
 * Resolves a day's weather into a simplified icon "type" for display,
 * giving priority to rain probability over the raw OWM condition id.
 * This avoids the mismatch where OWM reports a generic "Clouds" id
 * even on a 98%-chance-of-rain day (which looked wrong visually).
 *
 * Returns ['type' => 'sunny'|'partly-cloudy'|'cloudy'|'rainy'|'stormy'|'foggy', 'label' => string]
 */
function getWeatherDisplay(int $id, int $pop): array {
    // Thunderstorm always wins - it's the most severe condition
    if ($id >= 200 && $id < 300) {
        return ['type' => 'stormy', 'label' => 'Thunderstorm'];
    }
    // High rain chance or an actual rain/drizzle code -> show rain, regardless of the "Clouds" label OWM gave it
    if ($pop >= 60 || ($id >= 500 && $id < 600) || ($id >= 300 && $id < 400)) {
        return ['type' => 'rainy', 'label' => $pop >= 80 ? 'Heavy Rain' : 'Rainy'];
    }
    if ($id === 701 || $id === 741 || ($id >= 700 && $id < 800)) {
        return ['type' => 'foggy', 'label' => 'Foggy'];
    }
    if ($id === 800 && $pop < 20) {
        return ['type' => 'sunny', 'label' => 'Sunny'];
    }
    if ($id === 801 || ($id === 802 && $pop < 40)) {
        return ['type' => 'partly-cloudy', 'label' => 'Partly Cloudy'];
    }
    return ['type' => 'cloudy', 'label' => 'Cloudy'];
}

/**
 * Renders a small animated inline SVG weather icon for the given type.
 * Uses currentColor for the "hero" element (sun/bolt) so the wrapping
 * element's CSS `color` controls the tint; cloud shapes use fixed
 * soft-gray fills so they read well on both light and gradient backgrounds.
 */
function renderWeatherIcon(string $type, int $size = 56): string {
    $cloud = '
        <g class="wi-cloud">
            <ellipse cx="23" cy="40" rx="13" ry="9" fill="#F1F5F9" opacity="0.95"/>
            <ellipse cx="35" cy="35" rx="15" ry="11" fill="#F1F5F9" opacity="0.95"/>
            <ellipse cx="45" cy="41" rx="10" ry="7" fill="#F1F5F9" opacity="0.95"/>
            <rect x="19" y="39" width="33" height="10" rx="5" fill="#F1F5F9" opacity="0.95"/>
        </g>';

    $darkCloud = '
        <g class="wi-cloud">
            <ellipse cx="23" cy="38" rx="13" ry="9" fill="#94A3B8"/>
            <ellipse cx="35" cy="33" rx="15" ry="11" fill="#94A3B8"/>
            <ellipse cx="45" cy="39" rx="10" ry="7" fill="#94A3B8"/>
            <rect x="19" y="37" width="33" height="10" rx="5" fill="#94A3B8"/>
        </g>';

    $sun = '
        <g class="wi-sun-rays">
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(45 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(90 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(135 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(180 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(225 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(270 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(315 32 32)"/>
        </g>
        <circle cx="32" cy="32" r="12" fill="currentColor"/>';

    $sunSmall = '
        <g class="wi-sun-rays" transform="translate(-6,-6) scale(0.75)">
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(60 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(120 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(180 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(240 32 32)"/>
            <line x1="32" y1="6" x2="32" y2="13" stroke="currentColor" stroke-width="3" stroke-linecap="round" transform="rotate(300 32 32)"/>
        </g>
        <circle cx="26" cy="26" r="10" fill="currentColor" transform="translate(-6,-6) scale(0.75) translate(6,6)"/>';

    $rainDrops = '
        <g>
            <line class="wi-drop" x1="25" y1="52" x2="23" y2="59" stroke="#60A5FA" stroke-width="3" stroke-linecap="round"/>
            <line class="wi-drop wi-drop-2" x1="33" y1="52" x2="31" y2="59" stroke="#60A5FA" stroke-width="3" stroke-linecap="round"/>
            <line class="wi-drop wi-drop-3" x1="41" y1="52" x2="39" y2="59" stroke="#60A5FA" stroke-width="3" stroke-linecap="round"/>
        </g>';

    $bolt = '<polygon class="wi-bolt" points="35,48 27,60 32,60 30,72 40,56 34,56" fill="#FBBF24"/>';

    $moon = '<path d="M38 16a17 17 0 1 0 12 29 14 14 0 0 1-12-29z" fill="currentColor"/>';

    $moonSmall = '<path d="M38 16a17 17 0 1 0 12 29 14 14 0 0 1-12-29z" fill="currentColor" transform="translate(-6,-6) scale(0.75)"/>';

    $fogLines = '
        <g class="wi-fog">
            <line x1="16" y1="50" x2="48" y2="50" stroke="#CBD5E1" stroke-width="3" stroke-linecap="round"/>
            <line class="wi-fog-2" x1="20" y1="56" x2="52" y2="56" stroke="#CBD5E1" stroke-width="3" stroke-linecap="round"/>
            <line class="wi-fog-3" x1="14" y1="44" x2="40" y2="44" stroke="#CBD5E1" stroke-width="3" stroke-linecap="round"/>
        </g>';

    switch ($type) {
        case 'sunny':
            $inner = $sun;
            $colorClass = 'wi-color-sunny';
            break;
        case 'clear-night':
            $inner = $moon;
            $colorClass = 'wi-color-night';
            break;
        case 'partly-cloudy':
            $inner = $sunSmall . $cloud;
            $colorClass = 'wi-color-sunny';
            break;
        case 'partly-cloudy-night':
            $inner = $moonSmall . $cloud;
            $colorClass = 'wi-color-night';
            break;
        case 'rainy':
            $inner = $darkCloud . $rainDrops;
            $colorClass = 'wi-color-rain';
            break;
        case 'stormy':
            $inner = $darkCloud . $bolt;
            $colorClass = 'wi-color-storm';
            break;
        case 'foggy':
            $inner = $cloud . $fogLines;
            $colorClass = 'wi-color-fog';
            break;
        case 'cloudy':
        default:
            $inner = $darkCloud;
            $colorClass = 'wi-color-cloud';
            break;
    }

    return '<span class="weather-icon-anim ' . $colorClass . '"><svg width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg">' . $inner . '</svg></span>';
}

/**
 * Resolves a period's (day OR night) weather into a simplified icon type,
 * with a night-aware moon icon for clear/partly-cloudy conditions.
 */
function getPeriodWeatherDisplay(int $id, int $pop, bool $isNight = false): array {
    $base = getWeatherDisplay($id, $pop);

    if ($isNight) {
        if ($base['type'] === 'sunny') return ['type' => 'clear-night', 'label' => 'Clear'];
        if ($base['type'] === 'partly-cloudy') return ['type' => 'partly-cloudy-night', 'label' => 'Mostly Clear'];
    }

    return $base;
}

/**
 * SARAI-style detailed condition text (e.g. "Scattered Thunderstorms",
 * "Showers", "Mostly Cloudy") instead of a generic one-word label.
 */
function weatherDetailedLabel(int $id, int $pop, bool $isNight = false): string {
    if ($id >= 200 && $id < 300) {
        return $pop < 70 ? 'Scattered Thunderstorms' : 'Thunderstorms';
    }
    if (in_array($id, [520, 521, 522, 531], true)) return 'Showers';
    if ($id >= 500 && $id < 505) return $pop < 50 ? 'Light Rain' : 'Rain';
    if ($id >= 505 && $id < 600) return 'Heavy Rain';
    if ($id >= 300 && $id < 400) return 'Drizzle';
    if ($id >= 600 && $id < 700) return 'Snow';
    if ($id === 701 || $id === 741 || ($id >= 700 && $id < 800)) return 'Foggy';
    if ($id === 800) return $isNight ? 'Clear' : 'Sunny';
    if ($id === 801) return $isNight ? 'Mostly Clear' : 'Mostly Sunny';
    if ($id === 802) return 'Partly Cloudy';
    if ($id === 803) return 'Mostly Cloudy';
    if ($id === 804) return 'Cloudy';
    return 'Cloudy';
}

/**
 * Like weatherCodeToDisplay(), but also factors in the day's rain
 * probability so the icon better matches what the day will actually feel
 * like (a "Clouds" condition id with 95% pop should still show rain, not
 * a plain cloud). Also returns an accent color for UI theming.
 *
 * @return array{label:string, icon:string, color:string}
 */
function getForecastVisual(int $id, int $pop): array {
    $isThunder = ($id >= 200 && $id < 300);

    if ($isThunder) {
        return ['label' => 'Thunderstorm', 'icon' => '⛈️', 'color' => '#7c3aed'];
    }
    if ($pop >= 80) {
        return ['label' => 'Heavy rain', 'icon' => '🌧️', 'color' => '#2563eb'];
    }
    if ($pop >= 50) {
        return ['label' => 'Rain showers', 'icon' => '🌦️', 'color' => '#3b82f6'];
    }
    if ($pop >= 25) {
        return ['label' => 'Scattered showers', 'icon' => '🌦️', 'color' => '#60a5fa'];
    }

    // Low rain chance - fall back to the sky condition itself
    if ($id === 800) return ['label' => 'Clear sky', 'icon' => '☀️', 'color' => '#f59e0b'];
    if ($id === 801) return ['label' => 'Mostly clear', 'icon' => '🌤️', 'color' => '#eab308'];
    if ($id === 802) return ['label' => 'Partly cloudy', 'icon' => '⛅', 'color' => '#94a3b8'];
    if ($id === 803 || $id === 804) return ['label' => 'Overcast', 'icon' => '☁️', 'color' => '#64748b'];
    if ($id === 701 || $id === 741 || ($id >= 700 && $id < 800)) return ['label' => 'Foggy/Misty', 'icon' => '🌫️', 'color' => '#94a3b8'];
    if ($id >= 600 && $id < 700) return ['label' => 'Snow', 'icon' => '🌨️', 'color' => '#38bdf8'];

    return ['label' => 'Fair', 'icon' => '🌤️', 'color' => '#eab308'];
}

/**
 * Generates a short list of goat-farming tips based on today's forecast.
 * Returns an array of ['icon' => emoji, 'text' => tip] items.
 */
function getGoatFarmingTips(?array $weather): array {
    $tips = [];

    if (!$weather || empty($weather['days'][0])) {
        return [
            ['icon' => '💧', 'color' => '#3B82F6', 'text' => 'Siguroha nga naay igo nga tubig-imnon ang mga kanding matag adlaw.'],
            ['icon' => '🌿', 'color' => '#22C55E', 'text' => 'Susiha ang kondisyon sa sagbot/pasture usa i-graze ang panon.'],
            ['icon' => '🩺', 'color' => '#22C55E', 'text' => 'Regular nga check-up ug deworming makatabang paglikay sa sakit.'],
        ];
    }

    $today = $weather['days'][0];
    $pop   = $today['pop'];   // rain probability %
    $tmax  = $today['tmax'];  // max temp °C
    $tmin  = $today['tmin'];  // min temp °C
    $id    = $today['weathercode']; // OpenWeatherMap condition id

    $isStormy = ($id >= 200 && $id < 300) || $id === 522 || $id === 504; // thunderstorm / violent/extreme rain
    $isRainy  = $pop >= 60 || ($id >= 500 && $id < 600) || ($id >= 300 && $id < 400);
    $isHot    = $tmax >= 33;
    $isCool   = $tmin <= 21;
    $isClear  = $pop < 30 && in_array($id, [800, 801, 802], true);

    if ($isStormy) {
        $tips[] = ['icon' => '⛈️', 'color' => '#7C3AED', 'text' => 'Naay bagyo o kusog nga ulan karon. Ipasulod ang mga kanding sa lig-on nga silungan ug siguroa nga dili matangtang ang atop.'];
        $tips[] = ['icon' => '🚫', 'color' => '#7C3AED', 'text' => 'Likayi una ang pag-graze/pasyada gawas hangtud mo-tin-aw ang panahon.'];
    } elseif ($isRainy) {
        $tips[] = ['icon' => '🌧️', 'color' => '#3B82F6', 'text' => 'Taas ang tsansang mo-ulan karon. Likayi sa ang pag-graze (tugway) kung basa kaayo ang sagbot. Hatagi sila og uga nga feed (sagbot) ug limpyo nga tubig aron malikayan ang sakit sa tiyan ug parasites.'];
        $tips[] = ['icon' => '🦶', 'color' => '#3B82F6', 'text' => 'Bantayi ang tiil sa mga kanding. Ang basa ug lapok nga yuta mahimong hinungdan sa foot rot. Siguroa nga uga ug limpyo ang ilang natulganan.'];
    }

    if ($isHot) {
        $tips[] = ['icon' => '🥵', 'color' => '#F59E0B', 'text' => 'Init karon (' . round($tmax) . '°C) - siguroha nga naay silong/landong ug preskong tubig kanunay, ilabina sa udto.'];
    }

    if ($isCool) {
        $tips[] = ['icon' => '🥶', 'color' => '#06B6D4', 'text' => 'Bugnaw ang buntag/gabii (' . round($tmin) . '°C) - bantayi ilabina ang mga bag-ong natawo nga kanding, siguroha nga dili sila matugnaw.'];
    }

    if ($isClear && !$isRainy) {
        $tips[] = ['icon' => '🌿', 'color' => '#22C55E', 'text' => 'Maayo ang panahon karon. Maayong adlaw para ipagawas ang mga kanding, ilisan ang ilang pasture, ug susihon ang ilang kahimtang.'];
    }

    // Always add one general reminder if the list is short
    if (count($tips) < 3) {
        $tips[] = ['icon' => '🩺', 'color' => '#22C55E', 'text' => 'Regular nga susiha ang kahimtang sa mga kanding ug sundon ang deworming schedule aron malikayan ang mga sakit.'];
    }

    return array_slice($tips, 0, 4);
}