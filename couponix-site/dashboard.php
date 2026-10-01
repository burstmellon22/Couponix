<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();
$user = get_user_with_rank($conn, $user_id);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$next_rank = get_next_rank($conn, (int) $user["rank_id"]);
$progress_pct = $next_rank
    ? max(0, min(100, round((((int) $user["points"]) / max(1, (int) $next_rank["min_points"])) * 100)))
    : 100;

/* Weekly coupon usage (purchases, last 7 days) */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(quantity), 0) AS used_count
    FROM coupon_purchases
    WHERE user_id = ? AND purchase_status = 'paid' AND purchased_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$used = (int) $stmt->get_result()->fetch_assoc()["used_count"];
$stmt->close();

$limit = (int) $user["coupon_limit"];
$remaining = max(0, $limit - $used);

/* Stat card values */
$total_earned = get_user_total_earned($conn, $user_id);
$total_savings = get_user_total_savings($conn, $user_id);

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM coupon_listings WHERE seller_id = ? AND status = 'active'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$active_listings_count = (int) $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

/* Your active listings (what you're currently offering on Coupon Hut) */
$stmt = $conn->prepare("
    SELECT cl.id AS listing_id, cl.listing_price, c.title, c.category, c.expires_at
    FROM coupon_listings cl
    INNER JOIN coupons c ON cl.coupon_id = c.id
    WHERE cl.seller_id = ? AND cl.status = 'active'
    ORDER BY cl.created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$my_listings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Top sellers leaderboard preview */
$top_sellers = get_top_sellers($conn, 3);
$my_seller_rank = get_user_seller_rank($conn, $user_id);
$my_earned_for_lb = $total_earned;

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$showWelcome = !empty($_SESSION["welcome_popup"]);
unset($_SESSION["welcome_popup"]);

$active = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.dash-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 20px; margin-top: 30px; flex-wrap: wrap;
}
.dash-header h1 { font-size: 24px; margin: 4px 0 8px; }
.dash-greeting { color: var(--text-mute); font-size: 14px; }
.dash-badges { display: flex; align-items: center; gap: 10px; margin-top: 6px; }
.dash-badges .to-next { font-size: 13px; color: var(--text-mute); }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">

    <div class="dash-header">
        <div>
            <div class="dash-greeting"><?= $greeting ?>,</div>
            <h1><?= h($user["name"]) ?> <span style="font-size:18px;">👋</span></h1>
            <div class="dash-badges">
                <span class="rank-badge rank-<?= h($user["rank_name"]) ?>"><?= h($user["rank_name"]) ?> Member</span>
                <?php if ($next_rank): ?>
                    <span class="to-next"><?= (int) $next_rank["min_points"] - (int) $user["points"] ?> pts to <?= h($next_rank["name"]) ?> 👑</span>
                <?php else: ?>
                    <span class="to-next">Highest rank reached 🎉</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex; align-items:flex-start; gap:10px;">
            <a href="coupon-hut.php?tab=sell" class="dr-btn">+ List Coupon</a>
        </div>
    </div>

    <div class="stat-card-grid">
        <div class="dr-card stat-card c-earned">
            <div class="label">Total Earned</div>
            <div class="value">৳<?= number_format($total_earned, 0) ?></div>
            <div class="sub">from Coupon Hut sales</div>
        </div>
        <div class="dr-card stat-card c-listings">
            <div class="label">Active Listings</div>
            <div class="value"><?= $active_listings_count ?></div>
            <div class="sub">on Coupon Hut right now</div>
        </div>
        <div class="dr-card stat-card c-savings">
            <div class="label">Total Savings</div>
            <div class="value">৳<?= number_format($total_savings, 0) ?></div>
            <div class="sub">lifetime, vs. original prices</div>
        </div>
        <div class="dr-card stat-card c-score">
            <div class="label">Rank Score</div>
            <div class="value"><?= (int) $user["points"] ?></div>
            <div class="sub"><?= h($user["rank_name"]) ?> tier</div>
        </div>
    </div>

    <div class="dash-layout">

        <div>
            <div class="dash-section-head">
                <h2>Your Active Listings</h2>
                <a href="coupon-hut.php?tab=sell">View all →</a>
            </div>

            <?php if (empty($my_listings)): ?>
                <div class="dr-empty">
                    <h2>Nothing listed yet</h2>
                    <p>Sell a coupon you own on Coupon Hut and it'll show up here.</p>
                    <a href="coupon-hut.php?tab=sell" class="dr-btn">List a Coupon</a>
                </div>
            <?php else: ?>
                <?php foreach ($my_listings as $l): ?>
                    <div class="dr-card deal-row">
                        <div class="coupon-thumb" style="background:var(--brand-tint);"><?= category_emoji($l['category']) ?></div>
                        <div class="deal-row-info">
                            <h3><?= h($l["title"]) ?></h3>
                            <div class="cat"><?= h($l["category"]) ?></div>
                            <?php if (!empty($l["expires_at"])): ?>
                                <div class="deal-expiry">⏱ Expires <?= h(date("d M Y", strtotime($l["expires_at"]))) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="deal-row-right">
                            <div class="deal-row-price">৳<?= number_format((float) $l["listing_price"], 0) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div>
            <div class="dr-card progress-card">
                <div class="dash-section-head"><h2>Your Progress</h2><a href="rankings.php">Leaderboard</a></div>
                <div class="progress-card-head">
                    <div class="progress-rank-icon">🏅</div>
                    <div class="rank-line">
                        <strong><?= h($user["rank_name"]) ?> Member</strong>
                        <span><?= (int) $user["points"] ?> / <?= $next_rank ? (int) $next_rank["min_points"] : (int) $user["points"] ?> XP</span>
                    </div>
                </div>
                <div class="rank-progress-track"><div class="rank-progress-fill" style="width:<?= $progress_pct ?>%"></div></div>
                <div class="rank-progress-label">
                    <?php if ($next_rank): ?>
                        <?= (int) $next_rank["min_points"] - (int) $user["points"] ?> pts until <?= h($next_rank["name"]) ?> 👑
                    <?php else: ?>
                        You're at the top rank.
                    <?php endif; ?>
                </div>
            </div>

            <div class="dr-card leaderboard-card">
                <div class="dash-section-head"><h2>Top Sellers</h2><a href="rankings.php">See all</a></div>
                <?php if (empty($top_sellers)): ?>
                    <p style="font-size:13px;">No sales on Coupon Hut yet — be the first.</p>
                <?php else: ?>
                    <?php foreach ($top_sellers as $i => $s): ?>
                        <div class="leaderboard-row <?= (int) $s['id'] === $user_id ? 'is-you' : '' ?>">
                            <div class="lb-rank"><?= $i + 1 ?></div>
                            <div class="lb-avatar"><?= h(strtoupper(substr($s['name'], 0, 2))) ?></div>
                            <div class="lb-info">
                                <strong><?= (int) $s['id'] === $user_id ? h($s['name']) . ' (You)' : h($s['name']) ?></strong>
                                <span><?= (int) $s['sales'] ?> sale<?= $s['sales'] == 1 ? '' : 's' ?></span>
                            </div>
                            <div class="lb-value">৳<?= number_format((float) $s['earned'], 0) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($my_seller_rank !== null && $my_seller_rank > 3): ?>
                        <div class="leaderboard-row is-you">
                            <div class="lb-rank"><?= $my_seller_rank ?></div>
                            <div class="lb-avatar"><?= h(strtoupper(substr($user['name'], 0, 2))) ?></div>
                            <div class="lb-info"><strong><?= h($user['name']) ?> (You)</strong></div>
                            <div class="lb-value">৳<?= number_format($my_earned_for_lb, 0) ?></div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="dr-card quick-actions-card">
                <h2>Quick Actions</h2>
                <div class="quick-action-grid">
                    <a href="coupons.php" class="quick-action-tile"><span class="icon">🎯</span>Browse Deals</a>
                    <a href="my-coupons.php" class="quick-action-tile"><span class="icon">🎟️</span>My Coupons</a>
                    <a href="coupon-hut.php?tab=sell" class="quick-action-tile"><span class="icon">💰</span>Resell</a>
                    <a href="rankings.php" class="quick-action-tile"><span class="icon">🏆</span>Rankings</a>
                </div>
            </div>
        </div>

    </div>

</div>

<?php if ($showWelcome): ?>
<div class="dr-modal-bg" id="welcomePopup">
    <div class="dr-modal">
        <div class="dr-modal-icon">🎁</div>
        <h2>Welcome to Couponix!</h2>
        <p>Your account has been created successfully. You've received <strong>1 free welcome coupon</strong> to get started. It's yours to use &mdash; welcome coupons can't be resold.</p>
        <a href="my-coupons.php" class="dr-btn" style="margin-top:10px;">View My Coupon</a>
        <button class="close-popup" onclick="DR.closePopup('welcomePopup')">Continue browsing</button>
    </div>
</div>
<?php endif; ?>

<?php flash_render(); ?>
<script src="assets/app.js"></script>
</body>
</html>
