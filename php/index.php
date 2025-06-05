<?php
// index.php

header("Content-Type: application/json");
require_once "functions.php";

$pdo = get_db();
$ip = get_client_ip();
$now = time();

if (is_rate_limited($pdo, $ip, $now)) {
    http_response_code(403);
    echo json_encode(["error" => "Rate limit exceeded"]);
    exit;
}

// Try cache
$cached = get_cache($pdo, $ip, $now);
if ($cached) {
    log_request($pdo, $ip, date("Y-m-d H:i:s", $now), $cached, null, 1);
    echo json_encode($cached);
    exit;
}

// Fetch from AMap
function fetch_amap($ip) {
    $url = "https://restapi.amap.com/v3/ip?key=" . GAODE_KEY . "&ip=" . urlencode($ip);
    $resp = @file_get_contents($url);
    
    if (!$resp) {
        error_log("[AMAP] Failed to fetch IP location for $ip");
        return null;
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        error_log("[AMAP] Failed to decode JSON for $ip: raw = $resp");
        return null;
    }

    $city = $data['city'] ?? '';
    $adcode = $data['adcode'] ?? '';

    if (!$city || !$adcode) {
        error_log("[AMAP] Missing city or adcode for $ip: parsed = " . json_encode($data));
        return null;
    }

    error_log("[AMAP] Got city = $city, adcode = $adcode for $ip");

    // 查询天气
    $weather_url = "https://restapi.amap.com/v3/weather/weatherInfo?key=" . GAODE_KEY . "&city=" . $adcode;
    $weather_resp = @file_get_contents($weather_url);

    if (!$weather_resp) {
        error_log("[AMAP] Failed to fetch weather for $city ($adcode)");
        return null;
    }

    $weather_data = json_decode($weather_resp, true)['lives'][0] ?? [];
    if (!$weather_data) {
        error_log("[AMAP] Weather response missing or invalid: raw = $weather_resp");
    }

    return [
        "city" => $city,
        "weather" => $weather_data['weather'] ?? null,
        "temperature" => $weather_data['temperature'] ?? null,
    ];
}

function fetch_fallback($ip) {
    $info = @file_get_contents("https://ipinfo.io/widget/demo/" . $ip);
    $info_data = json_decode($info, true)['data'] ?? [];
    $city = $info_data['city'] ?? null;
    if (!$city) return null;

    $weather = @file_get_contents("https://api.weatherapi.com/v1/current.json?key=" . WEATHERAPI_KEY . "&q=" . urlencode($city) . "&aqi=no");
    $weather_data = json_decode($weather, true);

    return [
        "city" => $city,
        "weather" => $weather_data['current']['condition']['text'] ?? null,
        "temperature" => $weather_data['current']['temp_c'] ?? null,
    ];
}

// Try AMap, fallback to WeatherAPI
$response = fetch_amap($ip) ?? fetch_fallback($ip);

if ($response) {
    update_cache($pdo, $ip, $now, $response);
    log_request($pdo, $ip, date("Y-m-d H:i:s", $now), $response, null, 0);
    echo json_encode($response);
} else {
    log_request($pdo, $ip, date("Y-m-d H:i:s", $now), null, "Failed both sources", 0);
    http_response_code(502);
    echo json_encode(["error" => "Unable to retrieve weather from both sources"]);
}