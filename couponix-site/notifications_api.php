<?php
/**
 * notifications_api.php — feeds the USER bell in nav.php.
 *
 *   GET  notifications_api.php                  -> { unread, items[] }   (polled)
 *   GET  notifications_api.php?action=go&id=12  -> marks it read, redirects to the target page
 *   POST notifications_api.php  action=read_all -> mark everything read, returns the fresh list
 */
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

// Where a seller sees their listing's status.
const USER_LISTING_PAGE = 'my-coupons.php?tab=reselling';
// Where a seller sees listings that have already sold.
const SOLD_LISTING_PAGE = 'coupon-hut.php?tab=sell';

function time_ago(string $ts): string
{
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' hr ago';
    $days = (int) floor($d / 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['unread' => 0, 'items' => []]);
    exit;
}

$uid = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// ---- go: mark read + redirect (works from a plain link) ----
if ($action === 'go') {
    $id = (int) ($_GET['id'] ?? 0);

    $stmt = $conn->prepare("SELECT type FROM notifications WHERE id = ? AND recipient_type = 'user' AND recipient_id = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $n = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $target = USER_LISTING_PAGE;
    if ($n) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        // A sold coupon leaves My Coupons, so send the seller to Coupon Hut > Your Listings (shows "Sold").
        if ($n['type'] === 'resale_sold') {
            $target = SOLD_LISTING_PAGE;
        }
    }
    header('Location: ' . $target);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'read_all') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_type = 'user' AND recipient_id = ? AND is_read = 0");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE recipient_type = 'user' AND recipient_id = ? AND is_read = 0");
$stmt->bind_param("i", $uid);
$stmt->execute();
$unread = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("
    SELECT id, type, title, message, is_read, created_at
    FROM notifications
    WHERE recipient_type = 'user' AND recipient_id = ?
    ORDER BY id DESC LIMIT 15
");
$stmt->bind_param("i", $uid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$items = array_map(function ($n) {
    return [
        'id'      => (int) $n['id'],
        'type'    => $n['type'],
        'title'   => $n['title'],
        'message' => $n['message'],
        'read'    => (bool) $n['is_read'],
        'time'    => time_ago($n['created_at']),
        'url'     => 'notifications_api.php?action=go&id=' . (int) $n['id'],
    ];
}, $rows);

echo json_encode(['unread' => $unread, 'items' => $items]);
