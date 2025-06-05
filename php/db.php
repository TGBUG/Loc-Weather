<?php
// db.php

function get_db() {
    $dsn = "mysql:host=localhost;dbname=ep85643;charset=utf8mb4";
    $user = "ep85643";
    $pass = "jN3bQpHbPDnW";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];
    return new PDO($dsn, $user, $pass, $options);
}
