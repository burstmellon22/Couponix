<?php
/* ============================================================
   RETAILER AUTH GUARD
   require this at the very top of every retailer-only page,
   AFTER session_start() + db.php + functions.php are included.
   ============================================================ */

if (!isset($_SESSION["retailer_id"])) {
    header("Location: retailer-login.php");
    exit;
}

$retailer_id = (int) $_SESSION["retailer_id"];
