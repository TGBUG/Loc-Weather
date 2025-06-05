<?php
// db.php

function get_db() {
    $dsn = "mysql:host=localhost;dbname=;charset=utf8mb4";
    $user = "";
    $pass = "";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];
    return new PDO($dsn, $user, $pass, $options);
}
