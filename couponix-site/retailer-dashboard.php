<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/retailer-auth.php';

// --- Stats -----------------------------------------------------

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM coupons WHERE retailer_id = ?");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$totalCoupons = (int) $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM coupons WHERE retailer_id = ? AND status = 'active'");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$activeCoupons = (int) $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM coupon_listings cl
    JOIN coupons c ON c.id = cl.coupon_id
    WHERE c.retailer_id = ? AND cl.verification_status = 'pending'
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$pendingVerifications = (int) $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(cp.total_price), 0) AS total
    FROM coupon_purchases cp
    JOIN coupons c ON c.id = cp.coupon_id
    WHERE c.retailer_id = ? AND cp.purchase_status = 'paid'
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$totalRevenue = (float) $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(quantity * final_price), 0) AS total
    FROM coupons
    WHERE retailer_id = ?
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$totalListedValue = (float) $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

// --- Per-coupon breakdown for the performance chart -------------
// Shows, per coupon: how much its full listed quantity is worth
// vs. how much has actually sold (paid purchases only).

$stmt = $conn->prepare("
    SELECT c.id, c.title, c.quantity, c.final_price,
           COALESCE(SUM(CASE WHEN cp.purchase_status = 'paid' THEN cp.total_price ELSE 0 END), 0) AS sold_amount,
           COALESCE(SUM(CASE WHEN cp.purchase_status = 'paid' THEN 1 ELSE 0 END), 0) AS sold_count
    FROM coupons c
    LEFT JOIN coupon_purchases cp ON cp.coupon_id = c.id
    WHERE c.retailer_id = ?
    GROUP BY c.id, c.title, c.quantity, c.final_price
    ORDER BY (c.quantity * c.final_price) DESC
    LIMIT 8
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$chartRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$chartLabels = [];
$chartListed = [];
$chartSold = [];
$chartSoldCount = [];
foreach ($chartRows as $row) {
    $label = $row['title'];
    if (mb_strlen($label) > 16) $label = mb_substr($label, 0, 15) . '…';
    $chartLabels[] = $label;
    $chartListed[] = round(((int) $row['quantity']) * ((float) $row['final_price']), 2);
    $chartSold[] = round((float) $row['sold_amount'], 2);
    $chartSoldCount[] = (int) $row['sold_count'];
}

// Data for the chart is rendered client-side (see script at the
// bottom) so the bars always stretch to fill the actual card width,
// however many coupons there are — no external library, no CDN.
$chartDataJson = json_encode([
    'labels' => $chartLabels,
    'listed' => $chartListed,
    'sold' => $chartSold,
    'soldCount' => $chartSoldCount,
]);

// --- Recent pending listings (preview, max 5) -------------------

$stmt = $conn->prepare("
    SELECT cl.id, cl.listing_price, cl.created_at,
           c.title AS coupon_title, c.coupon_code,
           u.name AS seller_name
    FROM coupon_listings cl
    JOIN coupons c ON c.id = cl.coupon_id
    JOIN users u ON u.id = cl.seller_id
    WHERE c.retailer_id = ? AND cl.verification_status = 'pending'
    ORDER BY cl.created_at ASC
    LIMIT 5
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$recentPending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

$active_page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Retailer Dashboard - Couponix</title>
<script src="asset/theme.js"></script>
<link rel="stylesheet" href="asset/style.css">
<style>
/* ---- Dashboard-only layout polish ---- */

.dash-hero {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 16px;
    margin: 32px 0 26px;
    padding-bottom: 22px;
    border-bottom: 1px solid var(--border);
}
.dash-hero h1 { font-size: 27px; margin-bottom: 6px; }
.dash-hero p { margin: 0; font-size: 14px; }
.dash-hero-date {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-mute);
    background: var(--bg-soft);
    border: 1px solid var(--border);
    padding: 8px 14px;
    border-radius: 20px;
}

.stat-card { position: relative; overflow: hidden; transition: transform .15s ease, box-shadow .15s ease; }
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
.stat-card .icon-badge {
    position: absolute;
    top: 18px;
    right: 18px;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}
.stat-card.c-listings .icon-badge { background: var(--brand-tint); }
.stat-card.c-score .icon-badge { background: var(--ascendant-tint); }
.stat-card.c-earned .icon-badge { background: var(--success-tint); }
.stat-card.c-savings .icon-badge { background: var(--platinum-tint); }

.chart-card {
    padding: 26px 28px 22px;
    margin-bottom: 28px;
}
.chart-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 6px;
}
.chart-card-head h2 { font-size: 17px; margin: 0 0 4px; }
.chart-card-head p { margin: 0; font-size: 13px; }
.chart-legend-note {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    font-size: 12px;
    color: var(--text-mute);
    font-weight: 600;
}
.chart-legend-note span { display: inline-flex; align-items: center; gap: 6px; }
.chart-legend-note i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
.chart-canvas-wrap {
    margin-top: 18px;
    width: 100%;
    height: 320px;
}
.chart-canvas-wrap svg { display: block; width: 100%; height: 100%; }
.chart-empty {
    padding: 60px 20px;
    text-align: center;
    color: var(--text-dim);
}

@media (max-width: 950px) {
    .stat-card-grid { grid-template-columns: 1fr 1fr; }
    .dash-layout { grid-template-columns: 1fr; }
}
@media (max-width: 560px) {
    .stat-card-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<?php require __DIR__ . '/retailer-nav.php'; ?>

<div class="container">

    <div class="dash-hero">
        <div>
            <h1>Welcome back, <?= h($_SESSION["retailer_name"]) ?></h1>
            <p>Here's how your coupons are performing on Couponix.</p>
        </div>
        <div class="dash-hero-date"><?= h(date('l, F j, Y')) ?></div>
    </div>

    <div class="stat-card-grid">
        <div class="dr-card stat-card c-listings">
            <span class="icon-badge">🎟️</span>
            <div class="label">Total Coupons</div>
            <div class="value"><?= $totalCoupons ?></div>
            <div class="sub"><?= $activeCoupons ?> currently active</div>
        </div>
        <div class="dr-card stat-card c-score">
            <span class="icon-badge">✅</span>
            <div class="label">Pending Verifications</div>
            <div class="value"><?= $pendingVerifications ?></div>
            <div class="sub">Resale listings awaiting your review</div>
        </div>
        <div class="dr-card stat-card c-earned">
            <span class="icon-badge">💰</span>
            <div class="label">Total Sold Amount</div>
            <div class="value">৳<?= number_format($totalRevenue, 2) ?></div>
            <div class="sub">From paid purchases</div>
        </div>
        <div class="dr-card stat-card c-savings">
            <span class="icon-badge">📦</span>
            <div class="label">Total Listed Value</div>
            <div class="value">৳<?= number_format($totalListedValue, 2) ?></div>
            <div class="sub">Full value of listed quantity</div>
        </div>
    </div>

    <div class="dr-card chart-card">
        <div class="chart-card-head">
            <div>
                <h2>Coupon Performance</h2>
                <p>Listed value vs. amount actually sold, per coupon.</p>
            </div>
            <div class="chart-legend-note">
                <span><i style="background: var(--brand);"></i> Total Listed Value</span>
                <span><i style="background: var(--success);"></i> Sold Amount</span>
            </div>
        </div>

        <?php if (empty($chartRows)): ?>
            <div class="chart-empty">
                <p>Add a coupon to start seeing performance data here.</p>
            </div>
        <?php else: ?>
            <div class="chart-canvas-wrap" id="couponPerfChart"></div>
        <?php endif; ?>
    </div>

    <div class="dash-layout">
        <div>
            <div class="dash-section-head">
                <h2>Pending Verification Requests</h2>
                <a href="retailer-verify.php">View all →</a>
            </div>

            <?php if (empty($recentPending)): ?>
                <div class="dr-empty">
                    <p>No pending verification requests right now.</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentPending as $row): ?>
                    <div class="dr-card deal-row" id="listing-<?= (int) $row['id'] ?>">
                        <div class="coupon-thumb" style="background: var(--brand-tint);">🎟️</div>
                        <div class="deal-row-info">
                            <h3><?= h($row['coupon_title']) ?></h3>
                            <div class="cat">Seller: <?= h($row['seller_name']) ?> · Code: <?= h($row['coupon_code']) ?></div>
                        </div>
                        <div class="deal-row-right">
                            <div class="deal-row-price">৳<?= number_format($row['listing_price'], 2) ?></div>
                            <a href="retailer-verify.php#listing-<?= (int) $row['id'] ?>" class="dr-btn dr-btn-sm dr-btn-outline">Review</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div>
            <div class="dr-card quick-actions-card">
                <h2 style="font-size:15px;margin:0;">Quick Actions</h2>
                <div class="quick-action-grid">
                    <a href="retailer-coupon-add.php" class="quick-action-tile">
                        <span class="icon">➕</span>
                        Add Coupon
                    </a>
                    <a href="retailer-coupons.php" class="quick-action-tile">
                        <span class="icon">🎟️</span>
                        My Coupons
                    </a>
                    <a href="retailer-verify.php" class="quick-action-tile">
                        <span class="icon">✅</span>
                        Verify Listings
                    </a>
                    <a href="retailer-dashboard.php" class="quick-action-tile">
                        <span class="icon">📊</span>
                        Refresh Stats
                    </a>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="asset/app.js"></script>
<script src="asset/retailer-notify.js"></script>
<?php if (!empty($chartRows)): ?>
<script>
/* ============================================================
   Coupon performance chart — hand-rolled, dependency-free SVG bar
   chart. Rebuilt on load and on resize so it always stretches to
   fill the card's actual width instead of clipping to a fixed
   pixel size, and gives every bar a visible minimum height.
   ============================================================ */
(function () {
    const chartData = <?= $chartDataJson ?>;
    const svgNS = 'http://www.w3.org/2000/svg';

    function fmtCompact(v) {
        if (v >= 100000) return '৳' + (v / 100000).toFixed(1) + 'L';
        if (v >= 1000) return '৳' + (v / 1000).toFixed(1) + 'k';
        return '৳' + Math.round(v);
    }

    function el(name, attrs) {
        const e = document.createElementNS(svgNS, name);
        for (const k in attrs) e.setAttribute(k, attrs[k]);
        return e;
    }

    function render() {
        const wrap = document.getElementById('couponPerfChart');
        if (!wrap) return;
        const { labels, listed, sold, soldCount } = chartData;
        const count = labels.length;
        if (count === 0) return;

        const width = Math.max(320, wrap.clientWidth);
        const height = wrap.clientHeight || 320;
        const margin = { top: 26, right: 16, bottom: 46, left: 68 };
        const areaW = width - margin.left - margin.right;
        const areaH = height - margin.top - margin.bottom;
        const baseline = margin.top + areaH;

        const maxVal = Math.max(1, ...listed, ...sold);
        const groupW = areaW / count;
        const barW = Math.max(12, Math.min(38, groupW * 0.30));
        const barGap = Math.max(4, barW * 0.22);
        const MIN_BAR_PX = 4;

        const styles = getComputedStyle(document.documentElement);
        const brand = styles.getPropertyValue('--brand').trim();
        const success = styles.getPropertyValue('--success').trim();
        const border = styles.getPropertyValue('--border').trim();
        const borderStrong = styles.getPropertyValue('--border-strong').trim();
        const textMute = styles.getPropertyValue('--text-mute').trim();
        const textDim = styles.getPropertyValue('--text-dim').trim();

        const svg = el('svg', { viewBox: `0 0 ${width} ${height}`, width: '100%', height: '100%' });

        // gridlines + y-axis value labels
        const steps = 4;
        for (let s = 0; s <= steps; s++) {
            const frac = s / steps;
            const y = baseline - frac * areaH;
            svg.appendChild(el('line', {
                x1: margin.left, y1: y, x2: width - margin.right, y2: y,
                stroke: border, 'stroke-width': 1
            }));
            const t = el('text', {
                x: margin.left - 10, y: y + 4, 'text-anchor': 'end',
                'font-size': 10, fill: textMute, 'font-family': 'Inter, sans-serif'
            });
            t.textContent = '৳' + Math.round(maxVal * frac).toLocaleString();
            svg.appendChild(t);
        }

        // axis lines
        svg.appendChild(el('line', { x1: margin.left, y1: margin.top, x2: margin.left, y2: baseline, stroke: borderStrong, 'stroke-width': 1.5 }));
        svg.appendChild(el('line', { x1: margin.left, y1: baseline, x2: width - margin.right, y2: baseline, stroke: borderStrong, 'stroke-width': 1.5 }));

        labels.forEach((label, i) => {
            const groupX0 = margin.left + i * groupW;
            const pairW = barW * 2 + barGap;
            const x0 = groupX0 + (groupW - pairW) / 2;

            const listedVal = listed[i];
            const soldVal = sold[i];
            const listedH = listedVal > 0 ? Math.max(MIN_BAR_PX, (listedVal / maxVal) * areaH) : 0;
            const soldH = soldVal > 0 ? Math.max(MIN_BAR_PX, (soldVal / maxVal) * areaH) : 0;

            const listedX = x0;
            const soldX = x0 + barW + barGap;

            const listedRect = el('rect', {
                x: listedX, y: baseline - listedH, width: barW, height: listedH,
                rx: 4, fill: brand, style: 'transition: opacity .15s ease;'
            });
            const listedTitle = el('title', {});
            listedTitle.textContent = label + ' — Listed: ৳' + listedVal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            listedRect.appendChild(listedTitle);
            svg.appendChild(listedRect);

            const soldRect = el('rect', {
                x: soldX, y: baseline - soldH, width: barW, height: soldH,
                rx: 4, fill: success, style: 'transition: opacity .15s ease;'
            });
            const soldTitle = el('title', {});
            soldTitle.textContent = label + ' — Sold: ৳' + soldVal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' (' + soldCount[i] + ' sold)';
            soldRect.appendChild(soldTitle);
            svg.appendChild(soldRect);

            // value labels so small bars are still readable
            if (listedVal > 0 && barW >= 16) {
                const lt = el('text', { x: listedX + barW / 2, y: baseline - listedH - 6, 'text-anchor': 'middle', 'font-size': 9, 'font-weight': 700, fill: textDim });
                lt.textContent = fmtCompact(listedVal);
                svg.appendChild(lt);
            }
            if (soldVal > 0 && barW >= 16) {
                const st = el('text', { x: soldX + barW / 2, y: baseline - soldH - 6, 'text-anchor': 'middle', 'font-size': 9, 'font-weight': 700, fill: textDim });
                st.textContent = fmtCompact(soldVal);
                svg.appendChild(st);
            }

            const xt = el('text', { x: groupX0 + groupW / 2, y: baseline + 22, 'text-anchor': 'middle', 'font-size': 10, 'font-weight': 600, fill: textDim });
            xt.textContent = label;
            svg.appendChild(xt);
        });

        wrap.innerHTML = '';
        wrap.appendChild(svg);
    }

    let resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(render, 120);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
})();
</script>
<?php endif; ?>
</body>
</html>
