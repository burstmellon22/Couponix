<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $purchase_id = (int) ($_POST["purchase_id"] ?? 0);
    $rating = (int) ($_POST["rating"] ?? 0);
    $comment = trim($_POST["comment"] ?? "");

    if ($purchase_id <= 0 || $rating < 1 || $rating > 5) {
        $error = "Please select a coupon and rating.";
    } else {

        $check = $conn->prepare("
            SELECT cp.id, cp.coupon_id, cp.purchase_status
            FROM coupon_purchases cp
            WHERE cp.id = ? AND cp.user_id = ? LIMIT 1
        ");
        $check->bind_param("ii", $purchase_id, $user_id);
        $check->execute();
        $purchase = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$purchase) {
            $error = "Invalid purchase.";
        } elseif ($purchase["purchase_status"] !== "paid") {
            $error = "Only paid coupons can be reviewed.";
        } else {
            $coupon_id = (int) $purchase["coupon_id"];

            $duplicate = $conn->prepare("SELECT id FROM reviews WHERE user_id = ? AND coupon_id = ? AND purchase_id = ? LIMIT 1");
            $duplicate->bind_param("iii", $user_id, $coupon_id, $purchase_id);
            $duplicate->execute();
            $exists = $duplicate->get_result()->num_rows > 0;
            $duplicate->close();

            if ($exists) {
                $error = "You already reviewed this coupon.";
            } else {
                $insert = $conn->prepare("INSERT INTO reviews (user_id, coupon_id, purchase_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
                $insert->bind_param("iiiis", $user_id, $coupon_id, $purchase_id, $rating, $comment);
                $message = $insert->execute() ? "Review posted successfully." : "Unable to post review.";
                $insert->close();
            }
        }
    }
}

$purchases_stmt = $conn->prepare("
    SELECT cp.id AS purchase_id, c.title
    FROM coupon_purchases cp
    INNER JOIN coupons c ON cp.coupon_id = c.id
    LEFT JOIN reviews rv ON rv.purchase_id = cp.id AND rv.user_id = cp.user_id AND rv.coupon_id = cp.coupon_id
    WHERE cp.user_id = ? AND cp.purchase_status = 'paid' AND rv.id IS NULL
    ORDER BY cp.purchased_at DESC
");
$purchases_stmt->bind_param("i", $user_id);
$purchases_stmt->execute();
$purchases = $purchases_stmt->get_result();

$reviews_stmt = $conn->prepare("
    SELECT rv.id, rv.rating, rv.comment, rv.created_at, u.username, c.title
    FROM reviews rv
    INNER JOIN users u ON rv.user_id = u.id
    INNER JOIN coupons c ON rv.coupon_id = c.id
    ORDER BY rv.created_at DESC
");
$reviews_stmt->execute();
$reviews = $reviews_stmt->get_result();

$active = 'community';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Community - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.community-card { padding: 26px; margin-bottom: 22px; }
.auth-error { background: var(--danger-tint); color: var(--danger); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px; font-weight: 600; }
.auth-success { background: var(--success-tint); color: var(--success); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px; font-weight: 600; }
.review { border-bottom: 1px solid var(--border); padding: 18px 0; }
.review:last-child { border-bottom: 0; }
.review-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.username { font-weight: 700; }
.coupon-title { color: var(--text-mute); font-size: 13px; margin-top: 3px; }
.stars { color: var(--gold); font-weight: 700; margin: 6px 0; }
.react-btn { width: auto; padding: 7px 13px; border-radius: 20px; font-size: 13px; background: var(--surface); border: 1px solid var(--border); color: var(--text-dim); cursor: pointer; }
.react-btn.active { color: var(--danger); border-color: var(--danger); background: var(--danger-tint); }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">
    <div class="dr-page-header">
        <h1>Couponix Community</h1>
        <p>Share your experience and see what other members think.</p>
    </div>

    <?php if ($message): ?><div class="auth-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="auth-error"><?= h($error) ?></div><?php endif; ?>

    <div class="dr-card community-card">
        <h2>Write a Review</h2>

        <?php if ($purchases->num_rows > 0): ?>
            <form method="POST">
                <div class="dr-field">
                    <label>Coupon</label>
                    <select name="purchase_id" required>
                        <option value="">Select purchased coupon</option>
                        <?php while ($purchase = $purchases->fetch_assoc()): ?>
                            <option value="<?= (int) $purchase["purchase_id"] ?>"><?= h($purchase["title"]) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="dr-field">
                    <label>Rating</label>
                    <select name="rating" required>
                        <option value="">Select rating</option>
                        <option value="5">5 - Excellent</option>
                        <option value="4">4 - Good</option>
                        <option value="3">3 - Average</option>
                        <option value="2">2 - Poor</option>
                        <option value="1">1 - Bad</option>
                    </select>
                </div>
                <div class="dr-field">
                    <label>Comment</label>
                    <textarea name="comment" placeholder="Write your review..." maxlength="1000" style="min-height:90px;"></textarea>
                </div>
                <button type="submit" class="dr-btn dr-btn-block">Post Review</button>
            </form>
        <?php else: ?>
            <div class="dr-empty">You have no purchased coupons available for review.</div>
        <?php endif; ?>
    </div>

    <div class="dr-card community-card">
        <h2>Community Reviews</h2>

        <?php if ($reviews->num_rows === 0): ?>
            <div class="dr-empty">No reviews have been posted yet.</div>
        <?php else: ?>
            <?php while ($review = $reviews->fetch_assoc()): $review_id = (int) $review["id"]; ?>
                <div class="review">
                    <div class="review-head">
                        <div>
                            <div class="username">@<?= h($review["username"]) ?></div>
                            <div class="coupon-title"><?= h($review["title"]) ?></div>
                        </div>
                        <button type="button" class="react-btn" data-review-id="<?= $review_id ?>" onclick="toggleReaction(this)">♡ <span>0</span></button>
                    </div>
                    <div class="stars"><?= str_repeat("★", (int) $review["rating"]) . str_repeat("☆", 5 - (int) $review["rating"]) ?></div>
                    <?php if (!empty($review["comment"])): ?><p><?= h($review["comment"]) ?></p><?php endif; ?>
                    <small style="color:var(--text-mute);"><?= h($review["created_at"]) ?></small>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>
</div>

<script src="assets/app.js"></script>
<script>
function toggleReaction(button) {
    const reviewId = button.dataset.reviewId;
    const key = "couponix_reaction_" + reviewId;
    const countElement = button.querySelector("span");
    const reacted = localStorage.getItem(key) === "1";
    if (reacted) {
        localStorage.removeItem(key);
        button.classList.remove("active");
        countElement.textContent = "0";
    } else {
        localStorage.setItem(key, "1");
        button.classList.add("active");
        countElement.textContent = "1";
    }
}
document.querySelectorAll(".react-btn").forEach((button) => {
    const key = "couponix_reaction_" + button.dataset.reviewId;
    if (localStorage.getItem(key) === "1") {
        button.classList.add("active");
        button.querySelector("span").textContent = "1";
    }
});
</script>
</body>
</html>

<?php
$purchases_stmt->close();
$reviews_stmt->close();
$conn->close();
?>
