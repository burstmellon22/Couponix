<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();

$type = $_GET["type"] ?? "";
$title = "";
$message = "";
$details = [];
$points_earned = 0;

if ($type === "payment") {

    $purchase_ids = $_SESSION["success_purchase_ids"] ?? [];
    $points_earned = (int) ($_SESSION["success_points_earned"] ?? 0);

    if (empty($purchase_ids)) {
        header("Location: dashboard.php");
        exit;
    }

    foreach ($purchase_ids as $pid) {
        $pid = (int) $pid;
        $stmt = $conn->prepare("
            SELECT cp.id, cp.total_price, c.title, c.coupon_code
            FROM coupon_purchases cp INNER JOIN coupons c ON c.id = cp.coupon_id
            WHERE cp.id = ? AND cp.user_id = ? AND cp.purchase_status = 'paid' LIMIT 1
        ");
        $stmt->bind_param("ii", $pid, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $details[] = $row;
        }
    }

    unset($_SESSION["success_purchase_ids"], $_SESSION["success_points_earned"]);
    $title = "Payment Successful!";
    $message = "Your payment has been completed successfully.";

} elseif ($type === "redemption") {

    $purchase_id = (int) ($_SESSION["success_purchase_id"] ?? 0);
    $redemption_code = $_SESSION["success_redemption_code"] ?? "";

    if ($purchase_id <= 0) {
        header("Location: my-coupons.php");
        exit;
    }

    $stmt = $conn->prepare("
        SELECT cp.id, c.title, c.coupon_code
        FROM coupon_purchases cp INNER JOIN coupons c ON c.id = cp.coupon_id
        WHERE cp.id = ? AND cp.user_id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $purchase_id, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        header("Location: my-coupons.php");
        exit;
    }
    $details[] = $row;

    unset($_SESSION["success_purchase_id"], $_SESSION["success_redemption_code"]);
    $title = "Coupon Redeemed!";
    $message = "Your coupon has been successfully redeemed.";

} else {
    header("Location: dashboard.php");
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Success - Couponix</title>
<script src="asset/theme.js"></script>
<link rel="stylesheet" href="asset/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 40px 16px; }
.success-card { width: 100%; max-width: 560px; padding: 38px; text-align: center; }
.success-icon { width: 66px; height: 66px; border-radius: 50%; background: var(--success-tint); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 30px; margin: 0 auto 14px; }
.points-banner { background: var(--brand-tint); color: var(--brand-dark); font-weight: 700; padding: 12px; border-radius: var(--radius-sm); margin: 16px 0; }
.item-box { text-align: left; background: var(--bg-soft); padding: 15px; border-radius: var(--radius-sm); margin-top: 12px; font-size: 14px; }
.btn-row { display: flex; gap: 10px; justify-content: center; margin-top: 22px; flex-wrap: wrap; }
</style>
</head>
<body>

<div class="dr-card success-card">
    <div class="success-icon">✓</div>
    <h1><?= h($title) ?></h1>
    <p><?= h($message) ?></p>

    <?php if ($type === "payment" && $points_earned > 0): ?>
        <div class="points-banner">+<?= $points_earned ?> points earned toward your next rank</div>
    <?php endif; ?>

    <?php foreach ($details as $item): ?>
        <div class="item-box">
            <strong><?= h($item["title"]) ?></strong><br>
            Coupon Code: <span class="clipboard-code" data-code="<?= h($item["coupon_code"]) ?>"><?= h($item["coupon_code"]) ?></span>
            <?php if (isset($item["total_price"])): ?>
                <br>Paid: ৳<?= number_format((float) $item["total_price"], 2) ?>
            <?php endif; ?>
            <?php if ($type === "redemption"): ?>
                <br><br>Redemption Code: <strong class="clipboard-code" data-code="<?= h($redemption_code) ?>"><?= h($redemption_code) ?></strong>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="btn-row">
        <a href="my-coupons.php" class="dr-btn">My Coupons</a>
        <a href="dashboard.php" class="dr-btn dr-btn-outline">Dashboard</a>
    </div>
</div>

<script src="asset/app.js"></script>
<script>
(function () {
    const codes = Array.from(document.querySelectorAll('.clipboard-code'))
        .map(el => el.dataset.code)
        .filter(Boolean);
    if (!codes.length || !navigator.clipboard) return;

    navigator.clipboard.writeText(codes.join(', '))
        .then(() => DR.toast(codes.length > 1 ? 'Coupon codes copied to clipboard!' : 'Coupon code copied to clipboard!', 'success'))
        .catch(() => {}); // clipboard permission denied — fail silently, code is still shown on screen
})();
</script>
</body>
</html>
