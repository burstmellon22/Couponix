<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/retailer-auth.php';

$flash = ['type' => '', 'message' => ''];

// --- Handle approve / reject actions ----------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $listingId = (int) ($_POST['listing_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $rejectionReason = trim($_POST['rejection_reason'] ?? '');

    // Ownership check: the listing must belong to a coupon this retailer owns
    $stmt = $conn->prepare("
        SELECT cl.id, cl.verification_status
        FROM coupon_listings cl
        JOIN coupons c ON c.id = cl.coupon_id
        WHERE cl.id = ? AND c.retailer_id = ?
    ");
    $stmt->bind_param("ii", $listingId, $retailer_id);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$listing) {
        $flash = ['type' => 'error', 'message' => 'Listing not found or not yours to verify.'];
    } elseif ($listing['verification_status'] !== 'pending') {
        $flash = ['type' => 'error', 'message' => 'That listing has already been reviewed.'];
    } elseif ($action === 'approve') {
        $update = $conn->prepare("
            UPDATE coupon_listings
            SET verification_status = 'approved', verified_by = ?, verified_at = NOW(), rejection_reason = NULL
            WHERE id = ?
        ");
        $update->bind_param("ii", $retailer_id, $listingId);
        $update->execute();
        $update->close();
        $flash = ['type' => 'success', 'message' => 'Listing approved.'];
    } elseif ($action === 'reject') {
        if ($rejectionReason === '') $rejectionReason = 'No reason provided.';
        $update = $conn->prepare("
            UPDATE coupon_listings
            SET verification_status = 'rejected', status = 'cancelled', verified_by = ?, verified_at = NOW(), rejection_reason = ?
            WHERE id = ?
        ");
        $update->bind_param("isi", $retailer_id, $rejectionReason, $listingId);
        $update->execute();
        $update->close();
        $flash = ['type' => 'success', 'message' => 'Listing rejected.'];
    }
}

// --- Pending listings for this retailer's coupons ---------------
$stmt = $conn->prepare("
    SELECT cl.id, cl.listing_price, cl.created_at,
           c.title AS coupon_title, c.coupon_code, c.final_price AS coupon_price,
           u.name AS seller_name, u.email AS seller_email
    FROM coupon_listings cl
    JOIN coupons c ON c.id = cl.coupon_id
    JOIN users u ON u.id = cl.seller_id
    WHERE c.retailer_id = ? AND cl.verification_status = 'pending'
    ORDER BY cl.created_at ASC
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pendingTotalValue = 0.0;
foreach ($pending as $p) $pendingTotalValue += (float) $p['listing_price'];

// --- Recently reviewed (last 10, for context) --------------------
$stmt = $conn->prepare("
    SELECT cl.id, cl.listing_price, cl.verification_status, cl.verified_at, cl.rejection_reason,
           c.title AS coupon_title, c.coupon_code,
           u.name AS seller_name
    FROM coupon_listings cl
    JOIN coupons c ON c.id = cl.coupon_id
    JOIN users u ON u.id = cl.seller_id
    WHERE c.retailer_id = ? AND cl.verification_status != 'pending'
    ORDER BY cl.verified_at DESC
    LIMIT 10
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$reviewed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Quick approved/rejected counts for the summary strip (all-time)
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN cl.verification_status = 'approved' THEN 1 ELSE 0 END), 0) AS approved_count,
        COALESCE(SUM(CASE WHEN cl.verification_status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected_count
    FROM coupon_listings cl
    JOIN coupons c ON c.id = cl.coupon_id
    WHERE c.retailer_id = ?
");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$verifyCounts = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->close();
$active_page = 'verify';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Listings - Couponix Retailer</title>
<script src="asset/theme.js"></script>
<link rel="stylesheet" href="asset/style.css">
<style>
/* ---- Verify-page-only layout polish (spacing, no new colors) ---- */

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

.verify-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin: 22px 0 34px;
}
.verify-summary-grid .stat-card { padding: 20px 22px; }

.verify-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
}
.verify-section-head h2 { font-size: 18px; margin: 0; }
.verify-section-head .count-pill {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-mute);
    background: var(--bg-soft);
    border: 1px solid var(--border);
    padding: 4px 12px;
    border-radius: 20px;
}

.verify-list { display: flex; flex-direction: column; gap: 18px; margin-bottom: 44px; }

.verify-item {
    padding: 26px 28px;
    transition: box-shadow .15s ease, transform .15s ease;
}
.verify-item:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }

.verify-item-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    padding-bottom: 18px;
    margin-bottom: 18px;
    border-bottom: 1px solid var(--border);
}

.verify-item-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.verify-item-title h3 { margin: 0; font-size: 16px; }

.verify-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    font-size: 13px;
}
.verify-meta-item { display: flex; flex-direction: column; gap: 3px; }
.verify-meta-item .m-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--text-mute); }
.verify-meta-item .m-value { color: var(--text); font-weight: 600; }

.verify-price-block { text-align: right; flex-shrink: 0; }
.verify-price-block .cap { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-mute); margin-bottom: 4px; }

.verify-actions { display: flex; gap: 12px; margin-top: 4px; }

.reject-box {
    display: grid;
    grid-template-rows: 0fr;
    opacity: 0;
    margin-top: 0;
    transition: grid-template-rows .25s ease, opacity .2s ease, margin-top .25s ease;
}
.reject-box.open { grid-template-rows: 1fr; opacity: 1; margin-top: 18px; }
.reject-box > form {
    overflow: hidden;
    background: var(--bg-soft);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 18px 20px;
}

.reviewed-list { display: flex; flex-direction: column; gap: 14px; margin-bottom: 48px; }

.reviewed-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    padding: 20px 24px;
    border-left: 3px solid var(--border-strong);
    transition: box-shadow .15s ease, transform .15s ease;
}
.reviewed-item:hover { box-shadow: var(--shadow-sm); transform: translateY(-1px); }
.reviewed-item.is-approved { border-left-color: var(--success); }
.reviewed-item.is-rejected { border-left-color: var(--danger); }

.reviewed-item-main { flex: 1; min-width: 240px; }
.reviewed-item-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.reviewed-item-title h3 { margin: 0; font-size: 15px; }

.reviewed-meta {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    font-size: 12.5px;
    color: var(--text-mute);
}
.reviewed-meta strong { color: var(--text-dim); font-weight: 600; }

.reviewed-note {
    margin-top: 12px;
    font-size: 12.5px;
    color: var(--danger);
    background: var(--danger-tint);
    padding: 8px 14px;
    border-radius: var(--radius-sm);
    display: inline-block;
}

.reviewed-price-block { text-align: right; flex-shrink: 0; }
.reviewed-price-block .cap { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-mute); margin-bottom: 4px; }
.reviewed-price-block .price { font-size: 16px; font-weight: 800; color: var(--text); font-family: var(--font-heading); }

@media (max-width: 950px) {
    .verify-summary-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
    .verify-summary-grid { grid-template-columns: 1fr; }
    .verify-item { padding: 20px; }
    .verify-price-block, .reviewed-price-block { text-align: left; }
}
</style>
</head>
<body>

<?php require __DIR__ . '/retailer-nav.php'; ?>

<div class="container">

    <div class="dr-page-header">
        <h1>Verify Coupon Listings</h1>
        <p>Approve or reject resale listings created against your coupons.</p>
    </div>

    <?php if ($flash['message']): ?>
        <?php $isSuccess = $flash['type'] === 'success'; ?>
        <div class="dr-alert <?= $isSuccess ? 'dr-alert-success' : 'dr-alert-error' ?>">
            <span class="dr-alert-icon">
                <?php if ($isSuccess): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                <?php endif; ?>
            </span>
            <div class="dr-alert-body"><?= h($flash['message']) ?></div>
        </div>
    <?php endif; ?>

    <div class="verify-summary-grid">
        <div class="dr-card stat-card c-score">
            <div class="label">Pending Now</div>
            <div class="value"><?= count($pending) ?></div>
            <div class="sub">Awaiting your decision</div>
        </div>
        <div class="dr-card stat-card c-listings">
            <div class="label">Pending Value</div>
            <div class="value">৳<?= number_format($pendingTotalValue, 2) ?></div>
            <div class="sub">Combined listing price</div>
        </div>
        <div class="dr-card stat-card c-earned">
            <div class="label">Approved (all time)</div>
            <div class="value"><?= (int) ($verifyCounts['approved_count'] ?? 0) ?></div>
            <div class="sub">Listings you've cleared</div>
        </div>
        <div class="dr-card stat-card c-savings">
            <div class="label">Rejected (all time)</div>
            <div class="value"><?= (int) ($verifyCounts['rejected_count'] ?? 0) ?></div>
            <div class="sub">Listings you've declined</div>
        </div>
    </div>

    <div class="verify-section-head">
        <h2>Pending Requests</h2>
        <span class="count-pill"><?= count($pending) ?> waiting</span>
    </div>

    <?php if (empty($pending)): ?>
        <div class="dr-empty" style="margin-bottom: 44px;">
            <p>No pending verification requests.</p>
        </div>
    <?php else: ?>
        <div class="verify-list">
            <?php foreach ($pending as $item): ?>
                <div class="dr-card verify-item" id="listing-<?= (int) $item['id'] ?>">
                    <div class="verify-item-head">
                        <div style="flex:1; min-width: 240px;">
                            <div class="verify-item-title">
                                <h3><?= h($item['coupon_title']) ?></h3>
                                <span class="status-pill status-pending">Pending</span>
                            </div>
                            <div class="verify-meta-grid">
                                <div class="verify-meta-item">
                                    <span class="m-label">Coupon Code</span>
                                    <span class="m-value"><?= h($item['coupon_code']) ?></span>
                                </div>
                                <div class="verify-meta-item">
                                    <span class="m-label">Original Price</span>
                                    <span class="m-value">৳<?= number_format($item['coupon_price'], 2) ?></span>
                                </div>
                                <div class="verify-meta-item">
                                    <span class="m-label">Seller</span>
                                    <span class="m-value"><?= h($item['seller_name']) ?></span>
                                </div>
                                <div class="verify-meta-item">
                                    <span class="m-label">Submitted</span>
                                    <span class="m-value"><?= h(date('M j, Y g:ia', strtotime($item['created_at']))) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="verify-price-block">
                            <div class="cap">Listing Price</div>
                            <div class="deal-row-price">৳<?= number_format($item['listing_price'], 2) ?></div>
                        </div>
                    </div>

                    <div class="verify-actions">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="listing_id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="dr-btn dr-btn-sm">Approve</button>
                        </form>
                        <button type="button" class="dr-btn dr-btn-sm dr-btn-danger" onclick="document.getElementById('reject-<?= (int) $item['id'] ?>').classList.toggle('open')">Reject</button>
                    </div>

                    <div class="reject-box" id="reject-<?= (int) $item['id'] ?>">
                        <form method="POST">
                            <input type="hidden" name="listing_id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <div class="dr-field" style="margin-bottom:10px;">
                                <label>Reason for rejection</label>
                                <input type="text" name="rejection_reason" placeholder="e.g. Coupon code already used" required>
                            </div>
                            <button type="submit" class="dr-btn dr-btn-sm dr-btn-danger">Confirm Rejection</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($reviewed)): ?>
        <div class="verify-section-head">
            <h2>Recently Reviewed</h2>
            <span class="count-pill">Last <?= count($reviewed) ?></span>
        </div>
        <div class="reviewed-list">
            <?php foreach ($reviewed as $r): ?>
                <?php $isApproved = $r['verification_status'] === 'approved'; ?>
                <div class="dr-card reviewed-item <?= $isApproved ? 'is-approved' : 'is-rejected' ?>">
                    <div class="reviewed-item-main">
                        <div class="reviewed-item-title">
                            <h3><?= h($r['coupon_title']) ?></h3>
                            <span class="status-pill status-<?= $isApproved ? 'approved' : 'rejected' ?>">
                                <?= h(ucfirst($r['verification_status'])) ?>
                            </span>
                        </div>
                        <div class="reviewed-meta">
                            <span><strong>Code:</strong> <?= h($r['coupon_code']) ?></span>
                            <span><strong>Seller:</strong> <?= h($r['seller_name']) ?></span>
                            <span><strong>Reviewed:</strong> <?= $r['verified_at'] ? h(date('M j, Y', strtotime($r['verified_at']))) : '—' ?></span>
                        </div>
                        <?php if (!$isApproved && !empty($r['rejection_reason'])): ?>
                            <div class="reviewed-note">Reason: <?= h($r['rejection_reason']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="reviewed-price-block">
                        <div class="cap">Listing Price</div>
                        <div class="price">৳<?= number_format($r['listing_price'], 2) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="asset/app.js"></script>
<script src="asset/retailer-notify.js"></script>
</body>
</html>
