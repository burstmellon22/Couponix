<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();
$user = get_user_with_rank($conn, $user_id);

$top_sellers = get_top_sellers($conn, 20);
$my_seller_rank = get_user_seller_rank($conn, $user_id);
$my_earned = get_user_total_earned($conn, $user_id);

$ranks = $conn->query("SELECT * FROM ranks ORDER BY min_points ASC")->fetch_all(MYSQLI_ASSOC);

/* Where does everyone stand by points, for the rank-tier ladder view */
$stmt = $conn->prepare("
    SELECT u.name, u.username, u.points, r.name AS rank_name
    FROM users u JOIN ranks r ON u.rank_id = r.id
    ORDER BY u.points DESC
    LIMIT 20
");
$stmt->execute();
$points_leaderboard = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$active = 'rankings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rankings - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.rank-tabs { display: flex; gap: 8px; margin-bottom: 24px; }
.rank-tabs button {
    background: var(--surface); border: 1px solid var(--border); color: var(--text-dim);
    padding: 10px 18px; border-radius: 20px; font-weight: 700; font-size: 14px; cursor: pointer;
}
.rank-tabs button.active { background: var(--brand); border-color: var(--brand); color: #14100A; }
.rank-panel { display: none; }
.rank-panel.active { display: block; }
.big-lb-row {
    display: flex; align-items: center; gap: 16px; padding: 16px 20px; margin-bottom: 10px;
}
.big-lb-row.is-you { border-color: var(--brand); }
.big-lb-rank { width: 32px; font-size: 18px; font-weight: 800; color: var(--text-mute); text-align: center; }
.big-lb-rank.top1 { color: var(--gold); }
.big-lb-rank.top2 { color: var(--silver); }
.big-lb-rank.top3 { color: var(--bronze); }
.big-lb-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    background: linear-gradient(135deg, var(--ascendant), var(--platinum));
    color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px;
}
.big-lb-info { flex: 1; min-width: 0; }
.big-lb-info strong { display: block; font-size: 15px; }
.big-lb-info span { font-size: 12px; color: var(--text-mute); }
.big-lb-value { font-size: 17px; font-weight: 800; font-family: var(--font-heading); }
.tier-ladder { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 30px; }
.tier-ladder-card { padding: 20px; text-align: center; }
.tier-ladder-card.current { border-color: var(--brand); box-shadow: var(--shadow-md); }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">
    <div class="dr-page-header">
        <h1>Rankings</h1>
        <p>Two leaderboards: rank tiers (points from purchases) and Coupon Hut seller earnings.</p>
    </div>

    <h2 style="margin-top:10px;">Rank Tiers</h2>
    <div class="tier-ladder">
        <?php foreach ($ranks as $r): ?>
            <div class="dr-card tier-ladder-card <?= $r['id'] == $user['rank_id'] ? 'current' : '' ?>">
                <span class="rank-badge rank-<?= h($r['name']) ?>"><?= h($r['name']) ?></span>
                <div style="margin-top:10px;font-size:12px;color:var(--text-mute);"><?= (int) $r['min_points'] ?>+ pts</div>
                <?php if ($r['id'] == $user['rank_id']): ?>
                    <div style="margin-top:6px;font-size:11px;color:var(--brand);font-weight:700;">YOU ARE HERE</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="rank-tabs">
        <button type="button" class="active" data-panel="points">By Rank Points</button>
        <button type="button" data-panel="sellers">By Coupon Hut Earnings</button>
    </div>

    <div class="rank-panel active" id="panel-points">
        <?php foreach ($points_leaderboard as $i => $p): ?>
            <div class="dr-card big-lb-row <?= $p['username'] === $user['username'] ? 'is-you' : '' ?>">
                <div class="big-lb-rank <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>"><?= $i + 1 ?></div>
                <div class="big-lb-avatar"><?= h(strtoupper(substr($p['name'], 0, 2))) ?></div>
                <div class="big-lb-info">
                    <strong><?= h($p['name']) ?><?= $p['username'] === $user['username'] ? ' (You)' : '' ?></strong>
                    <span><?= h($p['rank_name']) ?> rank</span>
                </div>
                <div class="big-lb-value"><?= (int) $p['points'] ?> pts</div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="rank-panel" id="panel-sellers">
        <?php if (empty($top_sellers)): ?>
            <div class="dr-empty"><h2>No sales yet</h2><p>List a coupon on Coupon Hut to be the first on this board.</p></div>
        <?php else: ?>
            <?php foreach ($top_sellers as $i => $s): ?>
                <div class="dr-card big-lb-row <?= (int) $s['id'] === $user_id ? 'is-you' : '' ?>">
                    <div class="big-lb-rank <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>"><?= $i + 1 ?></div>
                    <div class="big-lb-avatar"><?= h(strtoupper(substr($s['name'], 0, 2))) ?></div>
                    <div class="big-lb-info">
                        <strong><?= h($s['name']) ?><?= (int) $s['id'] === $user_id ? ' (You)' : '' ?></strong>
                        <span><?= (int) $s['sales'] ?> sale<?= $s['sales'] == 1 ? '' : 's' ?></span>
                    </div>
                    <div class="big-lb-value">৳<?= number_format((float) $s['earned'], 0) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if ($my_seller_rank !== null && $my_seller_rank > count($top_sellers)): ?>
                <div class="dr-card big-lb-row is-you">
                    <div class="big-lb-rank"><?= $my_seller_rank ?></div>
                    <div class="big-lb-avatar"><?= h(strtoupper(substr($user['name'], 0, 2))) ?></div>
                    <div class="big-lb-info"><strong><?= h($user['name']) ?> (You)</strong></div>
                    <div class="big-lb-value">৳<?= number_format($my_earned, 0) ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<script src="assets/app.js"></script>
<script>
document.querySelectorAll('.rank-tabs button').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.rank-tabs button').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.rank-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('panel-' + btn.dataset.panel).classList.add('active');
    });
});
</script>
</body>
</html>
