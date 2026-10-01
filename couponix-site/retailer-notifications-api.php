<?php
/* ============================================================
   RETAILER NOTIFICATIONS API
   Returns pending coupon-listing verification requests for the
   coupons owned by the logged-in retailer. Polled by the bell
   icon in retailer-nav.php (see asset/retailer-notify.js).
   ============================================================ */

session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

header("Content-Type: application/json");

if (!isset($_SESSION["retailer_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not authenticated"]);
    exit;
}

$retailer_id = (int) $_SESSION["retailer_id"];

$stmt = $conn->prepare("
    SELECT cl.id, cl.listing_price, cl.created_at,
           c.title AS coupon_title, c.coupon_code
    FROM coupon_listings cl
    JOIN coupons c ON c.id = cl.coupon_id
    WHERE c.retailer_id = ?
      AND cl.verification_status = 'pending'
    ORDER BY cl.created_at ASC
    LIMIT 8
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        "id"        => (int) $row["id"],
        "title"     => $row["coupon_title"],
        "code"      => $row["coupon_code"],
        "price"     => (float) $row["listing_price"],
        "created_at" => $row["created_at"],
    ];
}
$stmt->close();

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM coupon_listings cl
    JOIN coupons c ON c.id = cl.coupon_id
    WHERE c.retailer_id = ? AND cl.verification_status = 'pending'
");
$countStmt->bind_param("i", $retailer_id);
$countStmt->execute();
$total = (int) $countStmt->get_result()->fetch_assoc()["total"];
$countStmt->close();

$conn->close();

echo json_encode([
    "count" => $total,
    "items" => $items,
]);
