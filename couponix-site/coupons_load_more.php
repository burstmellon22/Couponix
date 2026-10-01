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

const PAGE_SIZE = 3;

$user_id = (int) $_SESSION["user_id"];
$user = get_user_with_rank($conn, $user_id);
$rank_discount = (float) ($user["discount_percent"] ?? 0);

$requested_offset = max(0, (int) ($_GET["offset"] ?? 0));
$category = trim($_GET["category"] ?? "");

// Count first — we need the total before we can wrap the offset.
if ($category !== "") {
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM coupons WHERE status='active' AND quantity>0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW()) AND category=?");
    $count_stmt->bind_param("s", $category);
    $count_stmt->execute();
    $total_active = (int) $count_stmt->get_result()->fetch_assoc()["c"];
    $count_stmt->close();
} else {
    $total_active = (int) $conn->query("SELECT COUNT(*) AS c FROM coupons WHERE status='active' AND quantity>0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW())")->fetch_assoc()["c"];
}

if ($total_active === 0) {
    $conn->close();
    echo json_encode(["items" => [], "has_more" => false]);
    exit;
}

// True infinite scroll: once the offset passes the last coupon, wrap
// back to the start instead of stopping. The client just keeps
// counting upward forever; the server folds it back into range.
$offset = $requested_offset % $total_active;

if ($category !== "") {
    $stmt = $conn->prepare("
        SELECT * FROM coupons
        WHERE status = 'active' AND quantity > 0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW()) AND category = ?
        ORDER BY id DESC LIMIT ? OFFSET ?
    ");
    $limit = PAGE_SIZE;
    $stmt->bind_param("sii", $category, $limit, $offset);
} else {
    $stmt = $conn->prepare("
        SELECT * FROM coupons
        WHERE status = 'active' AND quantity > 0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW())
        ORDER BY id DESC LIMIT ? OFFSET ?
    ");
    $limit = PAGE_SIZE;
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$coupons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// The wrapped batch might land partly at the tail end (fewer than
// PAGE_SIZE left before the wrap point) — top it up from the front
// so every batch is still a full page and nothing looks truncated.
if (count($coupons) < PAGE_SIZE) {
    $remaining = PAGE_SIZE - count($coupons);
    if ($category !== "") {
        $stmt = $conn->prepare("
            SELECT * FROM coupons
            WHERE status = 'active' AND quantity > 0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW()) AND category = ?
            ORDER BY id DESC LIMIT ?
        ");
        $stmt->bind_param("si", $category, $remaining);
    } else {
        $stmt = $conn->prepare("
            SELECT * FROM coupons
            WHERE status = 'active' AND quantity > 0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW())
            ORDER BY id DESC LIMIT ?
        ");
        $stmt->bind_param("i", $remaining);
    }
    $stmt->execute();
    $coupons = array_merge($coupons, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
}

$conn->close();

$items = array_map(fn($c) => render_browse_ticket($c, $rank_discount), $coupons);

echo json_encode(["items" => $items, "has_more" => true]);
