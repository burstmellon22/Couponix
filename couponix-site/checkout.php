<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();
$user = get_user_with_rank($conn, $user_id);
$rank_discount = (float) ($user["discount_percent"] ?? 0);

$stmt = $conn->prepare("
    SELECT
        c.id AS cart_id, c.quantity AS cart_quantity,
        cp.id AS coupon_id, cp.title, cp.retailer_name, cp.original_price, cp.final_price, cp.quantity AS stock
    FROM cart c
    INNER JOIN coupons cp ON cp.id = c.coupon_id
    WHERE c.user_id = ? AND cp.status = 'active' AND cp.quantity > 0 AND (cp.expires_at IS NULL OR cp.expires_at > NOW()) AND (cp.available_from IS NULL OR cp.available_from <= NOW())
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
$product_value = 0;
$coupon_discount = 0;
$coupon_charge = 0;

while ($row = $result->fetch_assoc()) {
    $qty = (int) $row["cart_quantity"];
    if ($qty > (int) $row["stock"]) {
        $qty = (int) $row["stock"];
    }

    $original = (float) $row["original_price"];
    $you_pay_unit = rank_discounted_price((float) $row["final_price"], $rank_discount);

    $product_value += $original * $qty;
    $coupon_discount += max(0, $original - $you_pay_unit) * $qty;
    $coupon_charge += $you_pay_unit * $qty;

    $row["cart_quantity"] = $qty;
    $row["you_pay_unit"] = $you_pay_unit;
    $items[] = $row;
}
$stmt->close();

$platform_fee = round($coupon_charge * 0.05, 2);
$total_payable = $coupon_charge + $platform_fee;

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $requested_qty = array_sum(array_column($items, "cart_quantity"));
    $weekly_limit = (int) $user["coupon_limit"];
    $already_used = count_weekly_purchases($conn, $user_id);

    if (empty($items)) {
        $error = "Your cart is empty.";
    } elseif ($already_used + $requested_qty > $weekly_limit) {
        $remaining = max(0, $weekly_limit - $already_used);
        $error = "Weekly limit reached for your {$user['rank_name']} rank ({$weekly_limit}/week, {$already_used} already used). You can still claim {$remaining} more this week — reduce your cart quantity.";
    } else {

        $conn->begin_transaction();

        try {
            $purchase_ids = [];

            foreach ($items as $item) {
                $qty = (int) $item["cart_quantity"];
                $unit_price = $item["you_pay_unit"];
                $total_price = $unit_price * $qty;

                $stmt = $conn->prepare("
                    INSERT INTO coupon_purchases (user_id, coupon_id, quantity, unit_price, total_price, purchase_status)
                    VALUES (?, ?, ?, ?, ?, 'pending')
                ");
                $coupon_id = (int) $item["coupon_id"];
                $stmt->bind_param("iiidd", $user_id, $coupon_id, $qty, $unit_price, $total_price);
                $stmt->execute();
                $purchase_id = $conn->insert_id;
                $stmt->close();

                $item_fee = round($total_price * 0.05, 2);
                $transaction_type = "coupon_purchase";
                $status = "pending";
                $payment_method = "Demo Payment";
                $reference = "DR-TXN-" . strtoupper(bin2hex(random_bytes(4)));

                $stmt = $conn->prepare("
                    INSERT INTO transactions (user_id, purchase_id, transaction_type, amount, platform_fee, status, payment_method, transaction_reference)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("iisddsss", $user_id, $purchase_id, $transaction_type, $total_price, $item_fee, $status, $payment_method, $reference);
                $stmt->execute();
                $stmt->close();

                $purchase_ids[] = $purchase_id;
            }

            $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $_SESSION["checkout_purchase_ids"] = $purchase_ids;
            $_SESSION["otp"] = (string) random_int(100000, 999999);
            $_SESSION["otp_created"] = time();

            header("Location: otp.php?type=payment");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Unable to create checkout. Please try again.";
        }
    }
}

// $conn stays open here — nav.php (included below) still needs it; PHP closes it automatically at script end.
$active = 'cart';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.checkout-layout { display: grid; grid-template-columns: 1fr 360px; gap: 24px; align-items: start; }
.checkout-box { padding: 26px; }
.checkout-item { padding: 16px 0; border-bottom: 1px solid var(--border); font-size: 14px; }
.checkout-item:last-child { border-bottom: 0; }
.checkout-item .name { font-weight: 700; }
.summary-line { display: flex; justify-content: space-between; padding: 9px 0; font-size: 14px; color: var(--text-dim); }
.summary-total { border-top: 1px solid var(--border); margin-top: 8px; padding-top: 16px; font-size: 20px; font-weight: 800; display: flex; justify-content: space-between; }
.auth-error { background: var(--danger-tint); color: var(--danger); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }
@media (max-width: 850px) { .checkout-layout { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">
    <div class="dr-page-header"><h1>Checkout</h1></div>

    <?php if ($error): ?><div class="auth-error"><?= h($error) ?></div><?php endif; ?>

    <?php if (empty($items)): ?>
        <div class="dr-empty">
            <h2>Your cart is empty</h2>
            <a href="coupons.php" class="dr-btn">Explore Coupons</a>
        </div>
    <?php else: ?>

    <div class="checkout-layout">

        <div class="dr-card checkout-box">
            <h2>Order Items</h2>
            <?php foreach ($items as $item): ?>
                <div class="checkout-item">
                    <div class="name"><?= h($item["title"]) ?></div>
                    <div style="color:var(--text-mute);"><?= h($item["retailer_name"]) ?> &middot; Qty <?= (int) $item["cart_quantity"] ?></div>
                    <div>Coupon Charge: <strong>৳<?= number_format($item["you_pay_unit"] * (int) $item["cart_quantity"], 2) ?></strong></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dr-card checkout-box">
            <h2>Payment Summary</h2>
            <div class="summary-line"><span>Product Value</span><strong>৳<?= number_format($product_value, 2) ?></strong></div>
            <div class="summary-line"><span>Coupon Discount</span><strong>-৳<?= number_format($coupon_discount, 2) ?></strong></div>
            <div class="summary-line"><span>Coupon Charge</span><strong>৳<?= number_format($coupon_charge, 2) ?></strong></div>
            <div class="summary-line"><span>Platform Fee</span><strong>৳<?= number_format($platform_fee, 2) ?></strong></div>
            <div class="summary-total"><span>Total Payable</span><span>৳<?= number_format($total_payable, 2) ?></span></div>
            <p style="color:var(--text-mute);font-size:12px;margin-top:14px;">Payment method: Demo Payment</p>
            <form method="POST">
                <button type="submit" class="dr-btn dr-btn-block" style="margin-top:6px;">Proceed to Payment</button>
            </form>
        </div>

    </div>

    <?php endif; ?>
</div>

<script src="assets/app.js"></script>
</body>
</html>
