<?php
// Tiny JSON endpoint polled by my-coupons.php so the "Reselling" tab
// can show verification changes live, without a page refresh.
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

header("Content-Type: application/json");
header("Cache-Control: no-store");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["listings" => new stdClass()]);
    exit;
}

$user_id = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT purchase_id, status, verification_status, rejection_reason, listing_price
    FROM coupon_listings
    WHERE seller_id = ? AND status IN ('active', 'sold')
    ORDER BY id ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

// Keyed by purchase_id; if a purchase somehow has several rows, the newest wins.
$out = [];
foreach ($rows as $r) {
    $out[(int) $r["purchase_id"]] = [
        "status" => $r["status"],
        "verification" => $r["verification_status"],
        "reason" => $r["rejection_reason"] ?? "",
        "price" => number_format((float) $r["listing_price"], 2),
    ];
}

echo json_encode(["listings" => (object) $out]);
