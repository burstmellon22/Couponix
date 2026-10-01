<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();
$user = get_user_with_rank($conn, $user_id);
$rank_discount = (float) ($user["discount_percent"] ?? 0);

const PAGE_SIZE = 3;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $coupon_id = isset($_POST["coupon_id"]) ? (int) $_POST["coupon_id"] : 0;
    $quantity = isset($_POST["quantity"]) ? (int) $_POST["quantity"] : 1;

    if ($coupon_id <= 0 || $quantity <= 0) {
        flash_set("error", "Invalid coupon or quantity.");
    } else {

        $stmt = $conn->prepare("SELECT id, quantity, status, ((expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW())) AS is_live FROM coupons WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $coupon_id);
        $stmt->execute();
        $coupon = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$coupon || $coupon["status"] !== "active" || !$coupon["is_live"]) {
            flash_set("error", "Coupon is not available.");
        } elseif ((int) $coupon["quantity"] < $quantity) {
            flash_set("error", "Not enough coupon quantity available.");
        } else {

            $stmt = $conn->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND coupon_id = ? LIMIT 1");
            $stmt->bind_param("ii", $user_id, $coupon_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $new_quantity = (int) $existing["quantity"] + $quantity;
                if ($new_quantity > (int) $coupon["quantity"]) {
                    flash_set("error", "Cart quantity exceeds available stock.");
                } else {
                    $stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?");
                    $stmt->bind_param("iii", $new_quantity, $existing["id"], $user_id);
                    $stmt->execute();
                    $stmt->close();
                    flash_set("success", "Coupon quantity updated in your cart.");
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO cart (user_id, coupon_id, quantity) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $user_id, $coupon_id, $quantity);
                $stmt->execute();
                $stmt->close();
                flash_set("success", "Coupon added to cart.");
            }
        }
    }

    header("Location: coupons.php" . (isset($_GET["category"]) ? "?category=" . urlencode($_GET["category"]) : ""));
    exit;
}

$category = trim($_GET["category"] ?? "");

$categories = $conn->query("SELECT DISTINCT category FROM coupons WHERE status = 'active' AND quantity > 0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW()) ORDER BY category ASC")->fetch_all(MYSQLI_ASSOC);

if ($category !== "") {
    $page_size_bind = PAGE_SIZE;
    $stmt = $conn->prepare("
        SELECT * FROM coupons
        WHERE status = 'active' AND quantity > 0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW()) AND category = ?
        ORDER BY id DESC LIMIT ?
    ");
    $stmt->bind_param("si", $category, $page_size_bind);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $stmt = $conn->prepare("
        SELECT * FROM coupons
        WHERE status = 'active' AND quantity > 0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW())
        ORDER BY id DESC LIMIT ?
    ");
    $limit_bind = PAGE_SIZE;
    $stmt->bind_param("i", $limit_bind);
    $stmt->execute();
    $result = $stmt->get_result();
}

$coupons = $result->fetch_all(MYSQLI_ASSOC);

if ($category !== "") {
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM coupons WHERE status='active' AND quantity>0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW()) AND category=?");
    $count_stmt->bind_param("s", $category);
    $count_stmt->execute();
    $total_active = (int) $count_stmt->get_result()->fetch_assoc()["c"];
    $count_stmt->close();
} else {
    $total_active = (int) $conn->query("SELECT COUNT(*) AS c FROM coupons WHERE status='active' AND quantity>0 AND (expires_at IS NULL OR expires_at > NOW()) AND (available_from IS NULL OR available_from <= NOW())")->fetch_assoc()["c"];
}

$has_more = $total_active > 0;

// $conn stays open here — nav.php (included below) still needs it; PHP closes it automatically at script end.

$active = 'coupons';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Explore Coupons - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
.filter-bar a {
    text-decoration: none; padding: 8px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;
    background: var(--surface); border: 1px solid var(--border); color: var(--text-dim);
}
.filter-bar a.active, .filter-bar a:hover { background: var(--brand); border-color: var(--brand); color: #fff; }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">

    <div class="dr-page-header">
        <h1>Explore Coupons</h1>
        <p>Prices below already include your <?= h($user["rank_name"]) ?> rank discount<?= $rank_discount > 0 ? ' (extra ' . number_format($rank_discount, 0) . '% off)' : '' ?>.</p>
    </div>

    <div class="filter-bar">
        <a href="coupons.php" class="<?= $category === '' ? 'active' : '' ?>">All</a>
        <?php foreach ($categories as $cat): ?>
            <a href="coupons.php?category=<?= urlencode($cat['category']) ?>" class="<?= $category === $cat['category'] ? 'active' : '' ?>"><?= h($cat['category']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($coupons)): ?>
        <div class="dr-empty">
            <h2>No coupons found</h2>
            <p>Try a different category, or check back later.</p>
        </div>
    <?php else: ?>
        <div class="ticket-grid" id="coupon-grid">
            <?php foreach ($coupons as $c) { echo render_browse_ticket($c, $rank_discount); } ?>
        </div>
        <div id="dr-scroll-sentinel"></div>
        <div class="dr-loader" id="dr-loader" style="display:none;"><span class="dr-spinner"></span> Loading more deals...</div>
    <?php endif; ?>

</div>

<?php flash_render(); ?>
<script src="assets/app.js"></script>
<script>
(function () {
    let offset = <?= count($coupons) ?>;
    let hasMore = <?= $has_more ? 'true' : 'false' ?>;
    const category = <?= json_encode($category) ?>;
    const grid = document.getElementById('coupon-grid');
    const sentinel = document.getElementById('dr-scroll-sentinel');
    const loader = document.getElementById('dr-loader');

    if (!sentinel || !hasMore) return;

    DR.observeSentinel(sentinel, async function () {
        loader.style.display = 'flex';
        try {
            const res = await fetch('coupons_load_more.php?offset=' + offset + '&category=' + encodeURIComponent(category));
            const data = await res.json();
            data.items.forEach(html => grid.insertAdjacentHTML('beforeend', html));
            DR.initQtyStepper(grid);
            offset += data.items.length;
            hasMore = data.has_more;
        } catch (e) {
            hasMore = false;
        }
        loader.style.display = 'none';
        return hasMore;
    });
})();
</script>
</body>
</html>
