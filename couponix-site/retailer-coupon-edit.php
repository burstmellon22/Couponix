<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/retailer-auth.php';

$couponId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($couponId <= 0) {
    header("Location: retailer-coupons.php");
    exit;
}

// Ownership check — a retailer may only edit their own coupons
$stmt = $conn->prepare("SELECT * FROM coupons WHERE id = ? AND retailer_id = ?");
$stmt->bind_param("ii", $couponId, $retailer_id);
$stmt->execute();
$coupon = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$coupon) {
    header("Location: retailer-coupons.php");
    exit;
}

$errors = [];
$old = [
    'title' => $coupon['title'],
    'description' => $coupon['description'],
    'category' => $coupon['category'],
    'coupon_code' => $coupon['coupon_code'],
    'discount_type' => $coupon['discount_type'],
    'discount_value' => $coupon['discount_value'],
    'original_price' => $coupon['original_price'],
    'quantity' => $coupon['quantity'],
    'available_from' => $coupon['available_from'] ? str_replace(' ', 'T', $coupon['available_from']) : '',
    'expires_at' => $coupon['expires_at'] ? str_replace(' ', 'T', $coupon['expires_at']) : '',
    'status' => $coupon['status'],
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $old['title'] = trim($_POST['title'] ?? '');
    $old['description'] = trim($_POST['description'] ?? '');
    $old['category'] = trim($_POST['category'] ?? '');
    $old['coupon_code'] = strtoupper(trim($_POST['coupon_code'] ?? ''));
    $old['discount_type'] = ($_POST['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percentage';
    $old['discount_value'] = trim($_POST['discount_value'] ?? '');
    $old['original_price'] = trim($_POST['original_price'] ?? '');
    $old['quantity'] = trim($_POST['quantity'] ?? '');
    $old['available_from'] = trim($_POST['available_from'] ?? '');
    $old['expires_at'] = trim($_POST['expires_at'] ?? '');
    $old['status'] = in_array($_POST['status'] ?? '', ['active', 'inactive', 'expired', 'sold_out'], true)
        ? $_POST['status'] : 'active';

    if ($old['title'] === '') $errors[] = "Title is required.";
    if ($old['category'] === '') $errors[] = "Category is required.";
    if ($old['coupon_code'] === '') $errors[] = "Coupon code is required.";
    if (!is_numeric($old['discount_value']) || (float) $old['discount_value'] < 0) $errors[] = "Discount value must be a positive number.";
    if (!is_numeric($old['original_price']) || (float) $old['original_price'] <= 0) $errors[] = "Original price must be a positive number.";
    if (!ctype_digit((string) $old['quantity']) || (int) $old['quantity'] < 0) $errors[] = "Quantity must be a whole number.";
    if ($old['expires_at'] === '') $errors[] = "Expiry date is required.";

    if (empty($errors)) {
        // Coupon code must stay unique (excluding this coupon itself)
        $check = $conn->prepare("SELECT id FROM coupons WHERE coupon_code = ? AND id != ?");
        $check->bind_param("si", $old['coupon_code'], $couponId);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $errors[] = "That coupon code is already in use by another coupon.";
        } else {
            $qty = (int) $old['quantity'];

            // Quantity is REMAINING stock. The database triggers lower it on every
            // paid purchase and keep status in step, so no 'already sold' guard here.
        }

        if (empty($errors)) {
            $originalPrice = (float) $old['original_price'];
            $discountValue = (float) $old['discount_value'];

            if ($old['discount_type'] === 'percentage') {
                $finalPrice = max(0, $originalPrice - ($originalPrice * $discountValue / 100));
            } else {
                $finalPrice = max(0, $originalPrice - $discountValue);
            }

            $availableFrom = $old['available_from'] !== '' ? str_replace('T', ' ', $old['available_from']) : null;
            $expiresAt = str_replace('T', ' ', $old['expires_at']);

            $update = $conn->prepare("
                UPDATE coupons SET
                    title = ?, description = ?, category = ?, coupon_code = ?,
                    discount_type = ?, discount_value = ?, original_price = ?,
                    final_price = ?, quantity = ?, available_from = ?, expires_at = ?,
                    status = ?
                WHERE id = ? AND retailer_id = ?
            ");
            $update->bind_param(
                "sssssdddisssii",
                $old['title'],
                $old['description'],
                $old['category'],
                $old['coupon_code'],
                $old['discount_type'],
                $discountValue,
                $originalPrice,
                $finalPrice,
                $qty,
                $availableFrom,
                $expiresAt,
                $old['status'],
                $couponId,
                $retailer_id
            );

            if ($update->execute()) {
                $update->close();
                $conn->close();
                header("Location: retailer-coupons.php?status=updated");
                exit;
            } else {
                $errors[] = "Something went wrong while saving. Please try again.";
            }
            $update->close();
        }
    }
}

// $conn stays open here — retailer-nav.php (included below) still needs it; PHP closes it automatically at script end.
$active_page = 'coupons';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Coupon - Couponix Retailer</title>
<script src="asset/theme.js"></script>
<link rel="stylesheet" href="asset/style.css">
<style>
    /* Datetime inputs already get their colours from style.css tokens,
       so they follow the active theme. Only the calendar icon needs help. */
    .dr-field input[type="datetime-local"] { width: 100%; color: var(--text); color-scheme: dark; }
    .dr-field input[type="datetime-local"]::-webkit-calendar-picker-indicator {
        filter: invert(1);
        opacity: .65;
        cursor: pointer;
        padding: 4px;
        border-radius: 6px;
        transition: opacity .15s ease, background-color .15s ease;
    }
    .dr-field input[type="datetime-local"]::-webkit-calendar-picker-indicator:hover {
        opacity: 1;
        background-color: var(--brand-tint);
    }
</style>
</head>
<body>

<?php require __DIR__ . '/retailer-nav.php'; ?>

<div class="container" style="max-width: 760px;">

    <div class="dr-page-header">
        <h1>Edit Coupon</h1>
        <p>Editing <strong><?= h($coupon['title']) ?></strong> (<?= h($coupon['coupon_code']) ?>)</p>
    </div>

    <div class="dr-card" style="padding: 28px 30px;">

        <?php if (!empty($errors)): ?>
            <div class="auth-error">
                <?php foreach ($errors as $err): ?>
                    <div><?= h($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="id" value="<?= (int) $couponId ?>">

            <div class="dr-form-grid">
                <div class="dr-field full">
                    <label>Title</label>
                    <input type="text" name="title" value="<?= h($old['title']) ?>" required>
                </div>

                <div class="dr-field full">
                    <label>Description</label>
                    <textarea name="description" rows="3"><?= h($old['description']) ?></textarea>
                </div>

                <div class="dr-field">
                    <label>Category</label>
                    <input type="text" name="category" value="<?= h($old['category']) ?>" required>
                </div>

                <div class="dr-field">
                    <label>Coupon Code</label>
                    <input type="text" name="coupon_code" value="<?= h($old['coupon_code']) ?>" required style="text-transform:uppercase;">
                </div>

                <div class="dr-field">
                    <label>Discount Type</label>
                    <select name="discount_type">
                        <option value="percentage" <?= $old['discount_type'] === 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                        <option value="fixed" <?= $old['discount_type'] === 'fixed' ? 'selected' : '' ?>>Fixed Amount (৳)</option>
                    </select>
                </div>

                <div class="dr-field">
                    <label>Discount Value</label>
                    <input type="number" step="0.01" min="0" name="discount_value" value="<?= h($old['discount_value']) ?>" required>
                </div>

                <div class="dr-field">
                    <label>Original Price (৳)</label>
                    <input type="number" step="0.01" min="0.01" name="original_price" value="<?= h($old['original_price']) ?>" required>
                </div>

                <div class="dr-field">
                    <label>Quantity Available</label>
                    <input type="number" min="0" name="quantity" value="<?= h($old['quantity']) ?>" required>
                </div>

                <div class="dr-field">
                    <label>Available From</label>
                    <input type="datetime-local" name="available_from" value="<?= h($old['available_from']) ?>">
                </div>

                <div class="dr-field">
                    <label>Expires At</label>
                    <input type="datetime-local" name="expires_at" value="<?= h($old['expires_at']) ?>" required>
                </div>

                <div class="dr-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= $old['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $old['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="expired" <?= $old['status'] === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="sold_out" <?= $old['status'] === 'sold_out' ? 'selected' : '' ?>>Sold Out</option>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top: 6px;">
                <button type="submit" class="dr-btn">Save Changes</button>
                <a href="retailer-coupons.php" class="dr-btn dr-btn-outline">Cancel</a>
            </div>
        </form>
    </div>

</div>

<script src="asset/app.js"></script>
<script src="asset/retailer-notify.js"></script>
</body>
</html>
