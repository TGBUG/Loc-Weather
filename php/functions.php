<?php
// functions.php
require_once "db.php";
require_once "config.php";

function get_client_ip() {
    $headers = apache_request_headers();
    if (!empty($headers['X-Forwarded-For'])) {
        return explode(',', $headers['X-Forwarded-For'])[0];
    } elseif (!empty($headers['X-Real-IP'])) {
        return $headers['X-Real-IP'];
    }
    return $_SERVER['REMOTE_ADDR'];
}

function log_request($pdo, $ip, $timestamp, $response_data = null, $error = null, $from_cache = 0) {
    $stmt = $pdo->prepare("INSERT INTO request_logs (ip, timestamp, response_data, from_cache, error)
                           VALUES (:ip, :timestamp, :response_data, :from_cache, :error)");
    $stmt->execute([
        ':ip' => $ip,
        ':timestamp' => $timestamp,
        ':response_data' => $response_data ? json_encode($response_data) : null,
        ':from_cache' => $from_cache,
        ':error' => $error,
    ]);
}

function is_rate_limited($pdo, $ip, $now) {
    $window_start = date("Y-m-d H:i:s", $now - RATE_LIMIT_WINDOW);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM request_logs WHERE ip = :ip AND timestamp > :window_start");
    $stmt->execute([':ip' => $ip, ':window_start' => $window_start]);
    return $stmt->fetchColumn() >= RATE_LIMIT_MAX;
}

function get_cache($pdo, $ip, $now) {
    $stmt = $pdo->prepare("SELECT response_data, cached_at FROM ip_cache WHERE ip = :ip");
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && (strtotime($row['cached_at']) > ($now - CACHE_DURATION_SECONDS))) {
        return json_decode($row['response_data'], true);
    }
    return null;
}

function update_cache($pdo, $ip, $now, $data) {
    $stmt = $pdo->prepare("REPLACE INTO ip_cache (ip, cached_at, response_data)
                           VALUES (:ip, :cached_at, :response_data)");
    $stmt->execute([
        ':ip' => $ip,
        ':cached_at' => date("Y-m-d H:i:s", $now),
        ':response_data' => json_encode($data),
    ]);
}
