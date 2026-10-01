<?php
session_start();
$_SESSION = [];
session_destroy();
header("Location: retailer-login.php");
exit;
