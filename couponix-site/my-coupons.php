<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();

// The LEFT JOIN pulls this purchase's currently-active resale listing (if any),
// including its verification status, so the Reselling tab needs no second query.
$stmt = $conn->prepare("
    SELECT
        cp.id AS purchase_id, cp.quantity, cp.unit_price, cp.total_price, cp.purchased_at,
        c.id AS coupon_id, c.title, c.description, c.retailer_name, c.category,
        c.coupon_code, c.final_price, c.expires_at,
        EXISTS(SELECT 1 FROM redemptions r WHERE r.purchase_id = cp.id) AS is_redeemed,
        cl.id AS listing_id, cl.listing_price, cl.verification_status, cl.rejection_reason
    FROM coupon_purchases cp
    INNER JOIN coupons c ON cp.coupon_id = c.id
    LEFT JOIN coupon_listings cl ON cl.id = (
        SELECT MAX(l2.id) FROM coupon_listings l2
        WHERE l2.purchase_id = cp.id AND l2.status = 'active'
    )
    WHERE cp.user_id = ? AND cp.purchase_status = 'paid'
      AND NOT EXISTS (SELECT 1 FROM coupon_listings cl2 WHERE cl2.purchase_id = cp.id AND cl2.status = 'sold')
    ORDER BY cp.purchased_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$coupons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// $conn stays open here — nav.php (included below) still needs it; PHP closes it automatically at script end.

/** Label + pill class for a listing's verification state. Mirrored in the JS below. */
function resell_badge(string $verification): array
{
    switch ($verification) {
        case 'approved': return ['Verified · Live on Coupon Hut', 'status-approved'];
        case 'rejected': return ['Rejected by retailer', 'status-rejected'];
        default:         return ['Under verification', 'status-pending'];
    }
}

$initial_tab = $_GET["tab"] ?? "all";
if (!in_array($initial_tab, ["all", "active", "used", "reselling"], true)) {
    $initial_tab = "all";
}

$active = 'my-coupons';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Coupons - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.owned-tag { display: inline-block; font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 4px 9px; border-radius: 20px; margin-bottom: 8px; }
.owned-tag.gift { background: var(--success-tint); color: var(--success); }
.owned-tag.owned { background: var(--brand-tint); color: var(--brand-dark); }
.owned-tag.used { background: var(--border); color: var(--text-mute); }
.ticket-form { flex-direction: row; flex-wrap: wrap; }
.ticket-form .dr-btn { flex: 1; min-width: 120px; }
.coupon-tabs { display: flex; gap: 8px; margin-bottom: 26px; flex-wrap: wrap; }
.coupon-tabs button { text-decoration: none; padding: 10px 18px; border-radius: 20px; font-weight: 700; font-size: 14px; background: var(--surface); border: 1px solid var(--border); color: var(--text-dim); cursor: pointer; }
.coupon-tabs button.active { background: var(--brand); border-color: var(--brand); color: #14100A; }
.coupon-tabs .count { opacity: .75; font-weight: 600; margin-left: 4px; }

/* Resale status box shown on listed coupons */
.resell-box { margin-top: 12px; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 12px; color: var(--text-mute); }
.resell-box .row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.resell-box .reason { margin-top: 6px; color: var(--danger); font-weight: 600; }
.resell-box a { color: var(--brand); font-weight: 700; text-decoration: none; }
.live-note { display: none; align-items: center; gap: 8px; font-size: 12px; color: var(--text-mute); margin: -12px 0 18px; }
.live-note.show { display: flex; }
.live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--success); animation: live-pulse 1.6s ease-in-out infinite; }
@keyframes live-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
.filter-empty { display: none; }
button.dr-btn { font: inherit; cursor: pointer; }
/* Coupon code of a listed coupon: visible but can't be selected or copied */
.code-locked { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; pointer-events: none; cursor: default; }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">
    <div class="dr-page-header">
        <h1>My Coupons</h1>
        <p>View, use, or resell the coupons you currently own.</p>
    </div>

    <?php if (empty($coupons)): ?>
        <div class="dr-empty">
            <h2>Nothing here yet</h2>
            <p>You don't have any coupons yet. Explore available deals to get started.</p>
            <a href="coupons.php" class="dr-btn">Explore Coupons</a>
        </div>
    <?php else: ?>
        <?php
        $used_count = 0;
        $resell_count = 0;
        foreach ($coupons as $c) {
            if ((bool) $c["is_redeemed"]) $used_count++;
            if (!empty($c["listing_id"])) $resell_count++;
        }
        $active_count = count($coupons) - $used_count;
        ?>
        <div class="coupon-tabs">
            <button type="button" data-filter="all">All <span class="count">(<?= count($coupons) ?>)</span></button>
            <button type="button" data-filter="active">Active <span class="count">(<?= $active_count ?>)</span></button>
            <button type="button" data-filter="used">Used <span class="count">(<?= $used_count ?>)</span></button>
            <button type="button" data-filter="reselling">Reselling <span class="count">(<?= $resell_count ?>)</span></button>
        </div>
        <div class="live-note" id="live-note"><span class="live-dot"></span> Verification status updates automatically.</div>

        <div class="ticket-grid" id="my-coupons-grid">
            <?php foreach ($coupons as $c):
                $is_welcome = ((float) $c["total_price"] <= 0);
                $is_redeemed = (bool) $c["is_redeemed"];
                $is_listed = !empty($c["listing_id"]);
                $tags = [$is_redeemed ? 'used' : 'active'];
                if ($is_listed) $tags[] = 'reselling';
                if ($is_listed) { [$badge_label, $badge_class] = resell_badge((string) $c["verification_status"]); }
            ?>
                <div class="ticket"
                     data-tags="<?= h(implode(' ', $tags)) ?>"
                     data-purchase="<?= (int) $c["purchase_id"] ?>"
                     data-listed="<?= $is_listed ? '1' : '0' ?>">
                    <div class="ticket-main">
                        <?php if ($is_welcome): ?>
                            <span class="owned-tag gift">🎁 Welcome Coupon</span>
                        <?php elseif ($is_redeemed): ?>
                            <span class="owned-tag used">Already Used</span>
                        <?php else: ?>
                            <span class="owned-tag owned">Owned</span>
                        <?php endif; ?>

                        <div class="ticket-retailer"><?= h($c["retailer_name"]) ?><span class="ticket-category"><?= h($c["category"]) ?></span></div>
                        <h3><?= h($c["title"]) ?></h3>
                        <div class="ticket-desc"><?= h($c["description"]) ?></div>

                        <div class="ticket-prices">
                            <span class="now"><?= $is_welcome ? 'FREE' : '৳' . number_format((float) $c["total_price"], 2) ?></span>
                        </div>
                        <div style="font-size:12px;color:var(--text-mute);margin-top:4px;">
                            Purchased <?= h(date("d M Y", strtotime($c["purchased_at"]))) ?>
                            <?php if (!empty($c["expires_at"])): ?> &middot; Expires <?= h(date("d M Y", strtotime($c["expires_at"]))) ?><?php endif; ?>
                        </div>

                        <?php if ($is_listed): ?>
                            <div class="resell-box">
                                <div class="row">
                                    <span class="status-pill <?= h($badge_class) ?>" data-role="pill"><?= h($badge_label) ?></span>
                                    <span>Listed at <strong data-role="price">৳<?= number_format((float) $c["listing_price"], 2) ?></strong></span>
                                </div>
                                <div class="reason" data-role="reason" style="<?= ($c['verification_status'] === 'rejected' && !empty($c['rejection_reason'])) ? '' : 'display:none;' ?>">
                                    <?= !empty($c["rejection_reason"]) ? 'Reason: ' . h($c["rejection_reason"]) : '' ?>
                                </div>
                                <div style="margin-top:6px;"><a href="coupon-hut.php?tab=sell">Manage listing</a></div>
                            </div>
                        <?php endif; ?>

                        <div class="ticket-form">
                            <?php if (!$is_redeemed): ?>
                                <?php if (!$is_listed): ?>
                                    <a href="otp.php?type=use&purchase_id=<?= (int) $c["purchase_id"] ?>" class="dr-btn">Use Coupon</a>
                                    <?php if ($is_welcome): ?>
                                        <button type="button" class="dr-btn dr-btn-outline js-no-resell">Resell</button>
                                    <?php else: ?>
                                        <a href="coupon-hut.php?list_purchase=<?= (int) $c["purchase_id"] ?>" class="dr-btn dr-btn-outline">Resell</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="dr-btn" style="pointer-events:none;opacity:.5;" title="Cancel the listing first to use this coupon">Use Coupon</span>
                                    <span class="dr-btn dr-btn-outline" style="pointer-events:none;opacity:.6;">Listed for Resale</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="dr-btn dr-btn-outline" style="pointer-events:none;opacity:.6;">Already Redeemed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ticket-stub">
                        <div class="ticket-code<?= $is_listed ? ' code-locked' : '' ?>"<?= $is_listed ? ' oncopy="return false" oncontextmenu="return false" ondragstart="return false"' : '' ?>><?= h($c["coupon_code"]) ?></div>
                        <div class="ticket-stock">Qty <strong><?= (int) $c["quantity"] ?></strong></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dr-empty filter-empty" id="filter-empty">
            <h2 id="filter-empty-title">Nothing here</h2>
            <p id="filter-empty-text"></p>
        </div>
    <?php endif; ?>
</div>

<?php flash_render(); ?>
<script src="assets/app.js"></script>
<script>
(function () {
    const tabs = document.querySelectorAll('.coupon-tabs button');
    const tickets = document.querySelectorAll('#my-coupons-grid .ticket');
    const liveNote = document.getElementById('live-note');
    const emptyBox = document.getElementById('filter-empty');
    if (!tabs.length) return;

    // Welcome (free) coupons can't be resold: show a message instead of opening the listing page.
    document.querySelectorAll('.js-no-resell').forEach(function (btn) {
        btn.addEventListener('click', function () {
            DR.toast('Welcome coupons can\'t be resold. Use it yourself instead!', 'error');
        });
    });

    const emptyText = {
        active: ['No active coupons', 'Everything you own has been used.'],
        used: ['No used coupons yet', 'Coupons you redeem will show up here.'],
        reselling: ['Nothing listed for resale', 'Press Resell on any coupon to list it on Coupon Hut.']
    };

    let currentFilter = 'all';

    function applyFilter(filter) {
        currentFilter = filter;
        tabs.forEach(b => b.classList.toggle('active', b.dataset.filter === filter));
        let visible = 0;
        tickets.forEach(function (t) {
            const show = filter === 'all' || (t.dataset.tags || '').split(' ').includes(filter);
            t.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        liveNote.classList.toggle('show', filter === 'reselling' && visible > 0);
        if (visible === 0 && emptyText[filter]) {
            document.getElementById('filter-empty-title').textContent = emptyText[filter][0];
            document.getElementById('filter-empty-text').textContent = emptyText[filter][1];
            emptyBox.style.display = 'block';
        } else {
            emptyBox.style.display = 'none';
        }
    }

    tabs.forEach(b => b.addEventListener('click', () => applyFilter(b.dataset.filter)));
    applyFilter(<?= json_encode($initial_tab) ?>);

    // ---- Live verification status -----------------------------------
    const BADGES = {
        pending:  { label: 'Under verification',           cls: 'status-pending'  },
        approved: { label: 'Verified · Live on Coupon Hut', cls: 'status-approved' },
        rejected: { label: 'Rejected by retailer',          cls: 'status-rejected' }
    };
    const ALL_CLS = ['status-pending', 'status-approved', 'status-rejected'];

    function updateTicket(ticket, info) {
        const b = BADGES[info.verification] || BADGES.pending;
        const pill = ticket.querySelector('[data-role="pill"]');
        if (pill) {
            pill.classList.remove.apply(pill.classList, ALL_CLS);
            pill.classList.add(b.cls);
            pill.textContent = b.label;
        }
        const price = ticket.querySelector('[data-role="price"]');
        if (price) price.textContent = '৳' + info.price;
        const reason = ticket.querySelector('[data-role="reason"]');
        if (reason) {
            const show = info.verification === 'rejected' && info.reason;
            reason.style.display = show ? '' : 'none';
            reason.textContent = show ? 'Reason: ' + info.reason : '';
        }
    }

    async function poll() {
        if (document.hidden) return;
        try {
            const res = await fetch('my_listing_status.php', { cache: 'no-store' });
            if (!res.ok) return;
            const data = (await res.json()).listings || {};

            let needsReload = false;
            tickets.forEach(function (t) {
                const id = t.dataset.purchase;
                const info = data[id];
                const wasListed = t.dataset.listed === '1';

                if (wasListed && (!info || info.status === 'sold')) needsReload = true;      // cancelled or sold
                else if (!wasListed && info && info.status === 'active') needsReload = true;  // listed elsewhere
                else if (wasListed && info) updateTicket(t, info);
            });
            if (needsReload) window.location.href = 'my-coupons.php?tab=' + encodeURIComponent(currentFilter);
        } catch (e) { /* network hiccup: try again next tick */ }
    }

    if (document.querySelector('#my-coupons-grid .ticket[data-listed="1"]')) {
        setInterval(poll, 5000);
        document.addEventListener('visibilitychange', poll);
    }
})();
</script>
</body>
</html>
