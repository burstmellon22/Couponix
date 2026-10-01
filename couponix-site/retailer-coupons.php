<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/retailer-auth.php';

$stmt = $conn->prepare("
    SELECT id, title, category, coupon_code, discount_type, discount_value,
           original_price, final_price, quantity, status, expires_at
    FROM coupons
    WHERE retailer_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$coupons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// $conn stays open here — retailer-nav.php (included below) still needs it; PHP closes it automatically at script end.

$active_page = 'coupons';

$statusFlash = $_GET['status'] ?? '';

// --- Status breakdown for the donut chart -----------------------
// Reuses the $coupons list already fetched above — no extra query.
$statusMeta = [
    'active'   => ['label' => 'Active',   'var' => '--success'],
    'inactive' => ['label' => 'Inactive', 'var' => '--text-mute'],
    'expired'  => ['label' => 'Expired',  'var' => '--danger'],
    'sold_out' => ['label' => 'Sold Out', 'var' => '--brand'],
];
$statusCounts = array_fill_keys(array_keys($statusMeta), 0);
foreach ($coupons as $c) {
    $s = $c['status'];
    if (!isset($statusCounts[$s])) $statusCounts[$s] = 0;
    $statusCounts[$s]++;
}
$totalForChart = count($coupons);

// Build the donut as static inline SVG — a circle's proportions
// don't need to stretch the way a bar chart's do, so this can be
// rendered server-side with no JS at all.
$donutSvg = '';
if ($totalForChart > 0) {
    $r = 72;
    $cx = 90; $cy = 90;
    $strokeW = 26;
    $circumference = 2 * M_PI * $r;
    $cumulative = 0;
    $segments = '';

    foreach ($statusMeta as $key => $meta) {
        $count = $statusCounts[$key];
        if ($count <= 0) continue;
        $fraction = $count / $totalForChart;
        $arcLen = $fraction * $circumference;
        $offset = -$cumulative;
        $segments .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" '
            . 'stroke="var(' . $meta['var'] . ')" stroke-width="' . $strokeW . '" '
            . 'stroke-dasharray="' . $arcLen . ' ' . ($circumference - $arcLen) . '" '
            . 'stroke-dashoffset="' . $offset . '" '
            . 'transform="rotate(-90 ' . $cx . ' ' . $cy . ')">'
            . '<title>' . h($meta['label']) . ': ' . $count . ' coupon' . ($count === 1 ? '' : 's') . '</title>'
            . '</circle>';
        $cumulative += $arcLen;
    }

    $donutSvg = '<svg viewBox="0 0 180 180" width="180" height="180" class="status-donut-svg">'
        . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="var(--bg-soft)" stroke-width="' . $strokeW . '"></circle>'
        . $segments
        . '<text x="' . $cx . '" y="' . ($cy - 4) . '" text-anchor="middle" class="donut-center-value">' . $totalForChart . '</text>'
        . '<text x="' . $cx . '" y="' . ($cy + 16) . '" text-anchor="middle" class="donut-center-label">Coupons</text>'
        . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Coupons - Couponix Retailer</title>
<script src="asset/theme.js"></script>
<link rel="stylesheet" href="asset/style.css">
<style>
/* ---- My-coupons-page-only layout ---- */

/* Flash alert — replaces the unstyled .auth-error div (that class
   is only ever defined on the login page's own <style> block, so
   on every other page it rendered as a bare, unrounded color bar). */
.dr-alert {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 16px 20px;
    border-radius: var(--radius);
    border: 1px solid transparent;
    box-shadow: var(--shadow-sm);
    margin: 22px 0;
    font-size: 14px;
    font-weight: 600;
    animation: dr-alert-in .3s ease-out;
}
.dr-alert-icon {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.dr-alert-icon svg { width: 15px; height: 15px; }
.dr-alert-body { flex: 1; padding-top: 3px; }
.dr-alert-success { background: var(--success-tint); border-color: rgba(46, 204, 113, .3); color: var(--success); }
.dr-alert-success .dr-alert-icon { background: var(--success); color: #0B2A18; }
.dr-alert-error { background: var(--danger-tint); border-color: rgba(239, 83, 80, .3); color: var(--danger); }
.dr-alert-error .dr-alert-icon { background: var(--danger); color: #2A0B0B; }
@keyframes dr-alert-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }

.coupons-overview-card {
    padding: 26px 28px;
    margin: 26px 0 28px;
    display: flex;
    align-items: center;
    gap: 40px;
    flex-wrap: wrap;
}
.coupons-overview-head { flex-shrink: 0; }
.coupons-overview-head h2 { font-size: 17px; margin: 0 0 4px; }
.coupons-overview-head p { margin: 0; font-size: 13px; max-width: 220px; }

.donut-wrap { flex-shrink: 0; }
.status-donut-svg { display: block; }
.donut-center-value {
    font-family: var(--font-heading);
    font-size: 30px;
    font-weight: 800;
    fill: var(--text);
}
.donut-center-label {
    font-family: var(--font-body);
    font-size: 11px;
    font-weight: 600;
    fill: var(--text-mute);
}

.status-legend {
    display: grid;
    grid-template-columns: repeat(2, minmax(140px, 1fr));
    gap: 14px 28px;
    flex: 1;
    min-width: 260px;
}
.status-legend-item { display: flex; align-items: center; gap: 12px; }
.status-legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
.status-legend-info { display: flex; flex-direction: column; }
.status-legend-info .count { font-size: 17px; font-weight: 800; font-family: var(--font-heading); color: var(--text); }
.status-legend-info .label { font-size: 12px; color: var(--text-mute); font-weight: 600; }

@media (max-width: 700px) {
    .coupons-overview-card { flex-direction: column; align-items: flex-start; gap: 24px; }
    .status-legend { width: 100%; }
}
</style>
</head>
<body>

<?php require __DIR__ . '/retailer-nav.php'; ?>

<div class="container">

    <div class="dr-page-toolbar" style="margin-top:32px;">
        <div class="dr-page-header" style="margin:0;">
            <h1>My Coupons</h1>
            <p>Manage the coupons your business offers on Couponix.</p>
        </div>
        <a href="retailer-coupon-add.php" class="dr-btn">+ Add Coupon</a>
    </div>

    <?php if ($statusFlash === 'created' || $statusFlash === 'updated'): ?>
        <div class="dr-alert dr-alert-success">
            <span class="dr-alert-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </span>
            <div class="dr-alert-body">
                <?= $statusFlash === 'created' ? 'Coupon created successfully.' : 'Coupon updated successfully.' ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($totalForChart > 0): ?>
        <div class="dr-card coupons-overview-card">
            <div class="coupons-overview-head">
                <h2>Coupon Status Overview</h2>
                <p>How your listed coupons break down right now.</p>
            </div>

            <div class="donut-wrap"><?= $donutSvg ?></div>

            <div class="status-legend">
                <?php foreach ($statusMeta as $key => $meta): ?>
                    <div class="status-legend-item">
                        <span class="status-legend-dot" style="background: var(<?= h($meta['var']) ?>);"></span>
                        <div class="status-legend-info">
                            <span class="count"><?= (int) $statusCounts[$key] ?></span>
                            <span class="label"><?= h($meta['label']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($coupons)): ?>
        <div class="dr-empty">
            <p>You haven't added any coupons yet.</p>
            <a href="retailer-coupon-add.php" class="dr-btn">+ Add Your First Coupon</a>
        </div>
    <?php else: ?>
        <div class="dr-card" style="padding: 6px; overflow-x:auto;">
            <table class="dr-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Code</th>
                        <th class="num">Price</th>
                        <th class="num">Stock</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($coupons as $c): ?>
                        <tr>
                            <td><strong><?= h($c['title']) ?></strong></td>
                            <td><?= h($c['category']) ?></td>
                            <td><code><?= h($c['coupon_code']) ?></code></td>
                            <td class="num">
                                ৳<?= number_format($c['final_price'], 2) ?>
                                <?php if ($c['discount_type'] === 'percentage'): ?>
                                    <div class="cat" style="font-weight:400;">-<?= (float) $c['discount_value'] ?>%</div>
                                <?php else: ?>
                                    <div class="cat" style="font-weight:400;">-৳<?= number_format($c['discount_value'], 2) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= (int) $c['quantity'] ?></td>
                            <td>
                                <span class="status-pill status-<?= $c['status'] === 'active' ? 'approved' : ($c['status'] === 'expired' || $c['status'] === 'sold_out' ? 'rejected' : 'pending') ?>">
                                    <?= h(ucfirst($c['status'])) ?>
                                </span>
                            </td>
                            <td><?= $c['expires_at'] ? h(date('M j, Y', strtotime($c['expires_at']))) : '—' ?></td>
                            <td class="actions">
                                <a href="retailer-coupon-edit.php?id=<?= (int) $c['id'] ?>" class="dr-btn dr-btn-sm dr-btn-outline">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<script src="asset/app.js"></script>
<script src="asset/retailer-notify.js"></script>
</body>
</html>
