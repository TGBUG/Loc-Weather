CREATE TABLE request_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45),
    timestamp DATETIME,
    response_data JSON DEFAULT NULL,
    from_cache TINYINT(1) DEFAULT 0,
    error TEXT DEFAULT NULL
);

CREATE TABLE ip_cache (
    ip VARCHAR(45) PRIMARY KEY,
    cached_at DATETIME,
    response_data JSON
);
