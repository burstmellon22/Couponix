<?php
/**
 * db.php
 * ---------------------------------------------------------------------
 * Every page includes this instead of creating its own `new mysqli(...)`.
 * One connection definition = one place to change credentials, and no
 * risk of pages drifting out of sync with each other.
 */

$conn = new mysqli("localhost", "root", "", "couponix_db");

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
