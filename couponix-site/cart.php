<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();
$user = get_user_with_rank($conn, $user_id);
$rank_discount = (float) ($user["discount_percent"] ?? 0);

if (isset($_GET["remove"])) {
    $cart_id = (int) $_GET["remove"];
    $stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $cart_id, $user_id);
    $stmt->execute();
    $stmt->close();
    flash_set("success", "Item removed from your cart.");
    header("Location: cart.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $cart_id = (int) ($_POST["cart_id"] ?? 0);
    $quantity = (int) ($_POST["quantity"] ?? 1);

    if ($cart_id > 0 && $quantity > 0) {
        $stmt = $conn->prepare("
            UPDATE cart c
            INNER JOIN coupons cp ON cp.id = c.coupon_id
            SET c.quantity = ?
            WHERE c.id = ? AND c.user_id = ? AND cp.status = 'active' AND ? <= cp.quantity AND (cp.expires_at IS NULL OR cp.expires_at > NOW()) AND (cp.available_from IS NULL OR cp.available_from <= NOW())
        ");
        $stmt->bind_param("iiii", $quantity, $cart_id, $user_id, $quantity);
        $stmt->execute();
        $stmt->close();
        flash_set("success", "Cart updated.");
    }
    header("Location: cart.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT
        c.id AS cart_id, c.quantity AS cart_quantity,
        cp.id AS coupon_id, cp.title, cp.retailer_name, cp.category, cp.coupon_code,
        cp.original_price, cp.final_price, cp.quantity AS stock
    FROM cart c
    INNER JOIN coupons cp ON cp.id = c.coupon_id
    WHERE c.user_id = ?
    ORDER BY c.added_at DESC
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
    $original = (float) $row["original_price"];
    $you_pay_unit = rank_discounted_price((float) $row["final_price"], $rank_discount);

    $product_value += $original * $qty;
    $coupon_discount += max(0, $original - $you_pay_unit) * $qty;
    $coupon_charge += $you_pay_unit * $qty;

    $row["you_pay_unit"] = $you_pay_unit;
    $items[] = $row;
}
$stmt->close();

$platform_fee = round($coupon_charge * 0.05, 2);
$total_payable = $coupon_charge + $platform_fee;

/* Weekly rank limit vs. what's currently sitting in the cart — flags
   which items (in the order they're already displayed) push the
   user past their remaining weekly allowance, so they can be
   removed right here instead of only failing later at checkout. */
$weekly_limit = (int) ($user["coupon_limit"] ?? 0);
$already_used_this_week = count_weekly_purchases($conn, $user_id);
$remaining_allowance = max(0, $weekly_limit - $already_used_this_week);
$cart_total_qty = array_sum(array_column($items, "cart_quantity"));
$over_by = max(0, $cart_total_qty - $remaining_allowance);

$running_qty = 0;
foreach ($items as &$item) {
    $running_qty += (int) $item["cart_quantity"];
    $item["over_limit"] = $running_qty > $remaining_allowance;
}
unset($item);

// $conn stays open here — nav.php (included below) still needs it; PHP closes it automatically at script end.
$active = 'cart';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Cart - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.cart-layout { display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start; }
.cart-item-row { margin-bottom: 16px; }
.cart-item-row .ticket-main { display: flex; flex-direction: column; gap: 4px; }
.cart-item-row form { display: flex; align-items: center; gap: 14px; margin-top: 14px; flex-wrap: wrap; }
.remove-link { color: var(--danger); font-size: 13px; font-weight: 700; text-decoration: none; margin-left: auto; }
.summary { padding: 24px; position: sticky; top: 90px; }
.summary-line { display: flex; justify-content: space-between; padding: 9px 0; font-size: 14px; color: var(--text-dim); }
.summary-line strong { color: var(--text); }
.summary-total { border-top: 1px solid var(--border); margin-top: 8px; padding-top: 16px; font-size: 20px; font-weight: 800; display: flex; justify-content: space-between; }
.rank-note { background: var(--brand-tint); color: var(--brand-dark); font-size: 12px; padding: 10px 12px; border-radius: var(--radius-sm); margin-bottom: 16px; }
.limit-banner { background: var(--danger-tint); color: var(--danger); font-size: 13px; font-weight: 600; padding: 14px 16px; border-radius: var(--radius-sm); margin-bottom: 18px; }
.limit-banner strong { font-weight: 800; }
.cart-item-row.over-limit { border-color: var(--danger); }
.cart-item-row.over-limit .ticket-stub { background: var(--danger-tint); }
.over-limit-tag { display: inline-block; font-size: 10px; font-weight: 800; text-transform: uppercase; color: var(--danger); background: var(--danger-tint); padding: 4px 9px; border-radius: 20px; margin-bottom: 6px; }
/* Quantity now auto-saves on change (see script below), so the
   manual Update button is no longer needed. */
.cart-item-row form .qty-update-btn { display: none; }
.remove-link-strong { color: #fff; background: var(--danger); font-size: 12px; font-weight: 700; text-decoration: none; padding: 8px 14px; border-radius: var(--radius-sm); margin-left: auto; }
.remove-link-strong:hover { background: #C93A3E; }
@media (max-width: 850px) { .cart-layout { grid-template-columns: 1fr; } .summary { position: static; } }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">
    <div class="dr-page-header">
        <h1>My Cart</h1>
        <p>Review your selected coupons before checkout.</p>
    </div>

    <?php if (empty($items)): ?>
        <div class="dr-empty">
            <h2>Nothing in your cart</h2>
            <p>Explore coupons and add something you like.</p>
            <a href="coupons.php" class="dr-btn">Explore Coupons</a>
        </div>
    <?php else: ?>

    <?php if ($over_by > 0): ?>
        <div class="limit-banner">
            Your <?= h($user["rank_name"]) ?> rank allows <strong><?= $weekly_limit ?> coupons/week</strong>
            (<?= $already_used_this_week ?> already used this week, <?= $remaining_allowance ?> remaining).
            Your cart has <strong><?= $over_by ?></strong> more than that — remove the highlighted item(s) below to check out.
        </div>
    <?php endif; ?>

    <div class="cart-layout">

        <div>
            <?php foreach ($items as $item): $qty = (int) $item["cart_quantity"]; $isOver = !empty($item["over_limit"]); ?>
                <div class="ticket cart-item-row<?= $isOver ? ' over-limit' : '' ?>">
                    <div class="ticket-main">
                        <?php if ($isOver): ?><span class="over-limit-tag">Over weekly limit</span><?php endif; ?>
                        <div class="ticket-retailer"><?= h($item["retailer_name"]) ?><span class="ticket-category"><?= h($item["category"]) ?></span></div>
                        <h3><?= h($item["title"]) ?></h3>
                        <div class="ticket-prices">
                            <span class="was">৳<?= number_format((float) $item["original_price"] * $qty, 2) ?></span>
                            <span class="now">৳<?= number_format($item["you_pay_unit"] * $qty, 2) ?></span>
                        </div>
                        <form method="POST" class="qty-auto-form">
                            <input type="hidden" name="cart_id" value="<?= (int) $item["cart_id"] ?>">
                            <div class="qty-stepper">
                                <button type="button" data-step="-1">−</button>
                                <input type="number" name="quantity" value="<?= $qty ?>" min="1" max="<?= (int) $item["stock"] ?>">
                                <button type="button" data-step="1">+</button>
                            </div>
                            <button type="submit" class="dr-btn dr-btn-sm dr-btn-outline qty-update-btn">Update</button>
                            <a href="cart.php?remove=<?= (int) $item["cart_id"] ?>" class="<?= $isOver ? 'remove-link-strong' : 'remove-link' ?>">Remove</a>
                        </form>
                    </div>
                    <div class="ticket-stub">
                        <div class="ticket-code locked" oncopy="return false" oncontextmenu="return false" onselectstart="return false" title="Complete checkout to reveal the code">••••••••</div>
                        <div class="ticket-lock-hint">🔒 Unlocks after checkout</div>
                        <div class="ticket-stock">Qty <strong><?= $qty ?></strong></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dr-card summary">
            <h2>Order Summary</h2>

            <?php if ($rank_discount > 0): ?>
                <div class="rank-note">Your <?= h($user["rank_name"]) ?> rank saves you an extra <?= number_format($rank_discount, 0) ?>% — already applied below.</div>
            <?php endif; ?>

            <div class="summary-line"><span>Product Value</span><strong>৳<?= number_format($product_value, 2) ?></strong></div>
            <div class="summary-line"><span>Coupon Discount</span><strong>-৳<?= number_format($coupon_discount, 2) ?></strong></div>
            <div class="summary-line"><span>Coupon Charge</span><strong>৳<?= number_format($coupon_charge, 2) ?></strong></div>
            <div class="summary-line"><span>Platform Fee (5%)</span><strong>৳<?= number_format($platform_fee, 2) ?></strong></div>
            <div class="summary-total"><span>Total Payable</span><span>৳<?= number_format($total_payable, 2) ?></span></div>

            <a href="checkout.php" class="dr-btn dr-btn-block" style="margin-top:20px;">Proceed to Checkout</a>
        </div>

    </div>

    <?php endif; ?>
</div>

<?php flash_render(); ?>
<script src="assets/app.js"></script>
<script>
// Quantity changes (stepper buttons, scroll-wheel, or typing then
// blurring) now save themselves — no manual "Update" click needed.
document.querySelectorAll('.qty-auto-form').forEach(function (form) {
    const qtyInput = form.querySelector('input[name="quantity"]');
    if (!qtyInput) return;
    qtyInput.addEventListener('change', function () {
        form.submit();
    });
});
</script>
</body>
</html>
