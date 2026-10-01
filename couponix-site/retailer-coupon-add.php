<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/retailer-auth.php';

$retailer_name_stmt = $conn->prepare("SELECT name FROM retailers WHERE id = ?");
$retailer_name_stmt->bind_param("i", $retailer_id);
$retailer_name_stmt->execute();
$retailerRow = $retailer_name_stmt->get_result()->fetch_assoc();
$retailer_name_stmt->close();
$retailerBusinessName = $retailerRow['name'] ?? $_SESSION['retailer_name'];

$errors = [];
$old = [
    'title' => '', 'description' => '', 'category' => '', 'coupon_code' => '',
    'discount_type' => 'percentage', 'discount_value' => '', 'original_price' => '',
    'quantity' => '', 'available_from' => '', 'expires_at' => '',
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

    if ($old['title'] === '') $errors[] = "Title is required.";
    if ($old['category'] === '') $errors[] = "Category is required.";
    if ($old['coupon_code'] === '') $errors[] = "Coupon code is required.";
    if (!is_numeric($old['discount_value']) || (float) $old['discount_value'] < 0) $errors[] = "Discount value must be a positive number.";
    if (!is_numeric($old['original_price']) || (float) $old['original_price'] <= 0) $errors[] = "Original price must be a positive number.";
    if (!ctype_digit($old['quantity']) || (int) $old['quantity'] < 0) $errors[] = "Quantity must be a whole number.";
    if ($old['expires_at'] === '') $errors[] = "Expiry date is required.";

    if (empty($errors)) {
        $originalPrice = (float) $old['original_price'];
        $discountValue = (float) $old['discount_value'];

        if ($old['discount_type'] === 'percentage') {
            $finalPrice = max(0, $originalPrice - ($originalPrice * $discountValue / 100));
        } else {
            $finalPrice = max(0, $originalPrice - $discountValue);
        }

        // Ensure the code is unique
        $check = $conn->prepare("SELECT id FROM coupons WHERE coupon_code = ?");
        $check->bind_param("s", $old['coupon_code']);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $errors[] = "That coupon code is already in use — please choose another.";
        } else {
            $availableFrom = $old['available_from'] !== '' ? $old['available_from'] : date('Y-m-d H:i:s');
            $expiresAt = $old['expires_at'];

            $qty = (int) $old['quantity'];
            $title = $old['title'];
            $description = $old['description'];
            $category = $old['category'];
            $couponCode = $old['coupon_code'];
            $discountType = $old['discount_type'];

            $insert = $conn->prepare("
                INSERT INTO coupons
                    (title, description, retailer_name, retailer_id, category, coupon_code,
                     discount_type, discount_value, original_price, final_price, quantity,
                     available_from, expires_at, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $insert->bind_param(
                "sssisssdddiss",
                $title,
                $description,
                $retailerBusinessName,
                $retailer_id,
                $category,
                $couponCode,
                $discountType,
                $discountValue,
                $originalPrice,
                $finalPrice,
                $qty,
                $availableFrom,
                $expiresAt
            );

            if ($insert->execute()) {
                $insert->close();
                $conn->close();
                header("Location: retailer-coupons.php?status=created");
                exit;
            } else {
                $errors[] = "Something went wrong while saving. Please try again.";
            }
            $insert->close();
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
<title>Add Coupon - Couponix Retailer</title>
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
        <h1>Add a New Coupon</h1>
        <p>This coupon will appear live on Couponix once saved.</p>
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
                    <input type="datetime-local" name="available_from" value="<?= h(str_replace(' ', 'T', $old['available_from'])) ?>">
                </div>

                <div class="dr-field">
                    <label>Expires At</label>
                    <input type="datetime-local" name="expires_at" value="<?= h(str_replace(' ', 'T', $old['expires_at'])) ?>" required>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top: 6px;">
                <button type="submit" class="dr-btn">Save Coupon</button>
                <a href="retailer-coupons.php" class="dr-btn dr-btn-outline">Cancel</a>
            </div>
        </form>
    </div>

</div>

<script src="asset/app.js"></script>
<script src="asset/retailer-notify.js"></script>
</body>
</html>
