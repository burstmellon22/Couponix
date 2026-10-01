<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["items" => [], "has_more" => false]);
    exit;
}

const HUT_PAGE_SIZE = 6;

$user_id = (int) $_SESSION["user_id"];
$offset = max(0, (int) ($_GET["offset"] ?? 0));

$stmt = $conn->prepare("
    SELECT cl.*, c.title, c.retailer_name, c.category, c.coupon_code, c.final_price, u.username AS seller_username, u.name AS seller_name,
           rk.name AS seller_rank,
           (SELECT COUNT(*) FROM coupon_listings s WHERE s.seller_id = cl.seller_id AND s.status = 'sold') AS seller_sales
    FROM coupon_listings cl
    INNER JOIN coupons c ON cl.coupon_id = c.id
    INNER JOIN users u ON cl.seller_id = u.id
    LEFT JOIN ranks rk ON rk.id = u.rank_id
    WHERE cl.status = 'active' AND cl.verification_status = 'approved' AND cl.seller_id != ?
    ORDER BY cl.created_at DESC
    LIMIT ? OFFSET ?
");
$limit = HUT_PAGE_SIZE;
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$listings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$count_stmt = $conn->prepare("
    SELECT COUNT(*) AS c FROM coupon_listings cl
    WHERE cl.status = 'active' AND cl.verification_status = 'approved' AND cl.seller_id != ?
");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$total = (int) $count_stmt->get_result()->fetch_assoc()["c"];
$count_stmt->close();

$conn->close();

$items = array_map('render_hut_ticket', $listings);
$has_more = ($offset + count($listings)) < $total;

echo json_encode(["items" => $items, "has_more" => $has_more]);
