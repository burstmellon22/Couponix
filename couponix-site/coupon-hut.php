<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();

// ------------------------------------------------------------------
// POST actions
// ------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";

    if ($action === "create_listing") {

        $purchase_id = (int) ($_POST["purchase_id"] ?? 0);
        $listing_price = (float) ($_POST["listing_price"] ?? 0);

        if ($purchase_id <= 0 || $listing_price <= 0) {
            flash_set("error", "Please enter a valid resale price.");
        } else {
            // Look the purchase up on its own first, so we can say exactly WHY it can't be listed.
            $stmt = $conn->prepare("
                SELECT cp.id, cp.coupon_id, cp.user_id, cp.purchase_status, cp.total_price,
                       EXISTS(SELECT 1 FROM redemptions r WHERE r.purchase_id = cp.id) AS redeemed,
                       EXISTS(SELECT 1 FROM coupon_listings cl WHERE cl.purchase_id = cp.id AND cl.status IN ('active','sold')) AS listed
                FROM coupon_purchases cp
                WHERE cp.id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $purchase_id);
            $stmt->execute();
            $purchase = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$purchase || (int) $purchase["user_id"] !== $user_id) {
                flash_set("error", "That coupon isn't in your account.");
            } elseif ((float) $purchase["total_price"] <= 0) {
                flash_set("error", "Welcome coupons can't be resold. Use it yourself instead!");
            } elseif ($purchase["purchase_status"] !== "paid") {
                flash_set("error", "This coupon can't be listed: its purchase status is '" . $purchase["purchase_status"] . "', not 'paid'.");
            } elseif ((int) $purchase["redeemed"] === 1) {
                flash_set("error", "This coupon has already been used, so it can't be resold.");
            } elseif ((int) $purchase["listed"] === 1) {
                flash_set("error", "This coupon is already listed (or was already sold) on Coupon Hut.");
            } else {
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO coupon_listings (seller_id, purchase_id, coupon_id, listing_price, status)
                        VALUES (?, ?, ?, ?, 'active')
                    ");
                    $coupon_id = (int) $purchase["coupon_id"];
                    $stmt->bind_param("iiid", $user_id, $purchase_id, $coupon_id, $listing_price);
                    $stmt->execute();
                    $stmt->close();
                    flash_set("success", "Listed on Coupon Hut for ৳" . number_format($listing_price, 2) . ".");
                } catch (Throwable $e) {
                    if (strpos($e->getMessage(), 'FREE_COUPON_NOT_RESELLABLE') !== false) {
                        flash_set("error", "Welcome coupons can't be resold. Use it yourself instead!");
                    } else {
                        // Shown while we track this down; remove the detail once it's fixed.
                        flash_set("error", "Couldn't save the listing: " . $e->getMessage());
                    }
                }
            }
        }
        header("Location: coupon-hut.php?tab=sell");
        exit;
    }

    if ($action === "cancel_listing") {

        $listing_id = (int) ($_POST["listing_id"] ?? 0);
        $stmt = $conn->prepare("UPDATE coupon_listings SET status = 'cancelled' WHERE id = ? AND seller_id = ? AND status = 'active'");
        $stmt->bind_param("ii", $listing_id, $user_id);
        $stmt->execute();
        $stmt->close();
        flash_set("success", "Listing cancelled.");
        header("Location: coupon-hut.php?tab=sell");
        exit;
    }

    if ($action === "buy_listing") {

        $listing_id = (int) ($_POST["listing_id"] ?? 0);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT * FROM coupon_listings WHERE id = ? AND status = 'active' AND verification_status = 'approved' FOR UPDATE");
            $stmt->bind_param("i", $listing_id);
            $stmt->execute();
            $listing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$listing) {
                throw new Exception("This listing is no longer available.");
            }
            if ((int) $listing["seller_id"] === $user_id) {
                throw new Exception("You can't buy your own listing.");
            }

            $buyer = get_user_with_rank($conn, $user_id);
            $weekly_limit = (int) $buyer["coupon_limit"];
            $already_used = count_weekly_purchases($conn, $user_id);
            if ($already_used + 1 > $weekly_limit) {
                throw new Exception("Weekly limit reached for your {$buyer['rank_name']} rank ({$weekly_limit}/week).");
            }

            $price = (float) $listing["listing_price"];
            $fee = round($price * 0.05, 2);

            $stmt = $conn->prepare("
                INSERT INTO coupon_purchases (user_id, coupon_id, quantity, unit_price, total_price, purchase_status)
                VALUES (?, ?, 1, ?, ?, 'paid')
            ");
            $coupon_id = (int) $listing["coupon_id"];
            $stmt->bind_param("iidd", $user_id, $coupon_id, $price, $price);
            $stmt->execute();
            $new_purchase_id = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("UPDATE coupon_listings SET status = 'sold' WHERE id = ? AND status = 'active'");
            $stmt->bind_param("i", $listing_id);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new Exception("This listing was just sold to someone else.");
            }
            $stmt->close();

            $reference = "DR-RESALE-" . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $conn->prepare("
                INSERT INTO transactions (user_id, purchase_id, listing_id, transaction_type, amount, platform_fee, status, payment_method, transaction_reference)
                VALUES (?, ?, ?, 'coupon_resale', ?, ?, 'completed', 'Coupon Hut', ?)
            ");
            $stmt->bind_param("iiidds", $user_id, $new_purchase_id, $listing_id, $price, $fee, $reference);
            $stmt->execute();
            $stmt->close();

            award_points_and_recalc_rank($conn, $user_id, points_for_amount($price));

            $conn->commit();
            flash_set("success", "Purchased from Coupon Hut! Check My Coupons.");

        } catch (Exception $e) {
            $conn->rollback();
            flash_set("error", $e->getMessage());
        }

        header("Location: coupon-hut.php?tab=browse");
        exit;
    }
}

// ------------------------------------------------------------------
// Page data
// ------------------------------------------------------------------
$tab = $_GET["tab"] ?? (isset($_GET["list_purchase"]) ? "sell" : "browse");
$highlight_purchase = (int) ($_GET["list_purchase"] ?? 0);

// Someone opened ?list_purchase=ID for a free welcome coupon: bounce them back with a message.
if ($highlight_purchase > 0) {
    $stmt = $conn->prepare("SELECT total_price FROM coupon_purchases WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $highlight_purchase, $user_id);
    $stmt->execute();
    $hp = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($hp && (float) $hp["total_price"] <= 0) {
        flash_set("error", "Welcome coupons can't be resold. Use it yourself instead!");
        header("Location: my-coupons.php");
        exit;
    }
}

const HUT_PAGE_SIZE = 6;

$stmt = $conn->prepare("
    SELECT cl.*, c.title, c.retailer_name, c.category, c.coupon_code, c.final_price, u.username AS seller_username, u.name AS seller_name,
           rk.name AS seller_rank,
           (SELECT COUNT(*) FROM coupon_listings s WHERE s.seller_id = cl.seller_id AND s.status = 'sold') AS seller_sales
    FROM coupon_listings cl
    INNER JOIN coupons c ON cl.coupon_id = c.id
    INNER JOIN users u ON cl.seller_id = u.id
    LEFT JOIN ranks rk ON rk.id = u.rank_id
    WHERE cl.status = 'active' AND cl.verification_status = 'approved' AND cl.seller_id != ?
    ORDER BY cl.created_at DESC
    LIMIT ?
");
$hut_limit = HUT_PAGE_SIZE;
$stmt->bind_param("ii", $user_id, $hut_limit);
$stmt->execute();
$listings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$count_stmt = $conn->prepare("
    SELECT COUNT(*) AS c FROM coupon_listings cl
    WHERE cl.status = 'active' AND cl.verification_status = 'approved' AND cl.seller_id != ?
");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$total_listings = (int) $count_stmt->get_result()->fetch_assoc()["c"];
$count_stmt->close();

$hut_has_more = count($listings) < $total_listings;

// Every listing this seller has, any status — so they can see
// "Pending Verification" / "Rejected" too, not just live ones.
$stmt = $conn->prepare("
    SELECT cl.*, c.title, c.coupon_code
    FROM coupon_listings cl INNER JOIN coupons c ON cl.coupon_id = c.id
    WHERE cl.seller_id = ? AND cl.status != 'cancelled'
    ORDER BY cl.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$my_listings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT cp.id AS purchase_id, cp.total_price, cp.purchased_at,
           c.title, c.description, c.retailer_name, c.category, c.coupon_code, c.final_price
    FROM coupon_purchases cp INNER JOIN coupons c ON cp.coupon_id = c.id
    WHERE cp.user_id = ? AND cp.purchase_status = 'paid' AND cp.total_price > 0
      AND NOT EXISTS (SELECT 1 FROM redemptions r WHERE r.purchase_id = cp.id)
      AND NOT EXISTS (SELECT 1 FROM coupon_listings cl WHERE cl.purchase_id = cp.id AND cl.status IN ('active','sold'))
    ORDER BY cp.purchased_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$sellable = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// $conn stays open here — nav.php (included below) still needs it; PHP closes it automatically at script end.
$active = 'coupon-hut';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Coupon Hut - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.hut-tabs { display: flex; gap: 8px; margin-bottom: 26px; }
.hut-tabs a { text-decoration: none; padding: 10px 18px; border-radius: 20px; font-weight: 700; font-size: 14px; background: var(--surface); border: 1px solid var(--border); color: var(--text-dim); }
.hut-tabs a.active { background: var(--brand); border-color: var(--brand); color: #fff; }
/* Seller profile card on Browse listings */
.hut-seller { margin-bottom: 16px; background: var(--brand-tint); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.hut-seller-top { display: flex; align-items: center; gap: 12px; padding: 12px 14px; }
.hut-avatar { flex-shrink: 0; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    background: var(--brand); color: #14100A; font-family: var(--font-heading); font-weight: 800; font-size: 15px; letter-spacing: .5px; }
.hut-seller-info { display: flex; flex-direction: column; min-width: 0; line-height: 1.3; }
.hut-seller-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: var(--text-mute); }
.hut-seller-name { font-size: 16px; font-weight: 800; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hut-seller-meta { font-size: 12px; color: var(--text-dim); font-weight: 600; }
.hut-seller-stats { display: flex; border-top: 1px solid var(--border); background: var(--surface); }
.hut-stat { flex: 1; display: flex; flex-direction: column; gap: 2px; padding: 9px 14px; }
.hut-stat + .hut-stat { border-left: 1px solid var(--border); }
.hut-stat-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-mute); }
.hut-stat-value { font-size: 14px; font-weight: 800; color: var(--text); }
.hut-stat-rank { color: var(--brand); }
.hut-foot { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap;
    font-size: 11px; color: var(--text-mute); margin: 10px 0 12px; }
.hut-verified { color: var(--success); font-weight: 700; }
.sell-form { display: flex; gap: 8px; margin-top: 14px; align-items: center; }
.sell-form input[type="number"] { width: 110px; padding: 10px 12px; border: 1.5px solid var(--border-strong); border-radius: var(--radius-sm); }
.listed-card { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; margin-bottom: 10px; }
.listed-card .info strong { display: block; }
.listed-card .info span { font-size: 12px; color: var(--text-mute); }
.section-gap { margin-top: 40px; }

/* In-page purchase confirmation (replaces the browser's confirm() popup) */
.hut-modal { position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px;
    background: rgba(0, 0, 0, .6); backdrop-filter: blur(3px); }
.hut-modal.open { display: flex; animation: hut-fade .18s ease-out; }
.hut-modal-card { width: 100%; max-width: 420px; padding: 26px; border-radius: 16px; background: var(--surface-2, var(--surface));
    border: 1px solid var(--border-strong, var(--border)); box-shadow: var(--shadow-md, 0 20px 50px rgba(0,0,0,.45)); animation: hut-pop .2s ease-out; }
.hut-modal-card h2 { margin: 0 0 4px; font-size: 19px; }
.hut-modal-sub { margin: 0 0 18px; font-size: 13px; color: var(--text-mute); }
.hut-modal-box { border: 1px solid var(--border); border-radius: 12px; overflow: hidden; margin-bottom: 14px; }
.hut-modal-row { display: flex; justify-content: space-between; gap: 16px; padding: 11px 14px; font-size: 13px; }
.hut-modal-row + .hut-modal-row { border-top: 1px solid var(--border); }
.hut-modal-row span:first-child { color: var(--text-mute); font-weight: 600; flex-shrink: 0; }
.hut-modal-row span:last-child { color: var(--text); font-weight: 700; text-align: right; }
.hut-modal-row.total { background: var(--brand-tint); }
.hut-modal-row.total span:last-child { font-size: 18px; font-weight: 800; color: var(--brand); }
.hut-modal-note { font-size: 12px; color: var(--text-mute); margin: 0 0 20px; }
.hut-modal-actions { display: flex; gap: 10px; }
.hut-modal-actions .dr-btn { flex: 1; }
@keyframes hut-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes hut-pop { from { opacity: 0; transform: translateY(10px) scale(.97); } to { opacity: 1; transform: none; } }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container">
    <div class="dr-page-header">
        <h1>Coupon Hut</h1>
        <p>Buy and sell coupons directly with other Couponix members.</p>
    </div>

    <div class="hut-tabs">
        <a href="coupon-hut.php?tab=browse" class="<?= $tab === 'browse' ? 'active' : '' ?>">Browse Listings</a>
        <a href="coupon-hut.php?tab=sell" class="<?= $tab === 'sell' ? 'active' : '' ?>">Sell Your Coupons</a>
    </div>

    <?php if ($tab === 'browse'): ?>

        <?php if (empty($listings)): ?>
            <div class="dr-empty">
                <h2>No listings right now</h2>
                <p>Check back later, or list your own coupons for others to buy.</p>
                <a href="coupon-hut.php?tab=sell" class="dr-btn">Sell a Coupon</a>
            </div>
        <?php else: ?>
            <div class="ticket-grid" id="hut-grid">
                <?php foreach ($listings as $l) { echo render_hut_ticket($l); } ?>
            </div>
            <div id="dr-scroll-sentinel"></div>
            <div class="dr-loader" id="hut-loader" style="display:none;"><span class="dr-spinner"></span> Loading more listings...</div>
        <?php endif; ?>

    <?php else: ?>

        <?php if (!empty($my_listings)): ?>
            <h2>Your Listings</h2>
            <?php foreach ($my_listings as $ml):
                $badge = ['pending' => 'Pending Verification', 'approved' => 'Live', 'rejected' => 'Rejected by Retailer'][$ml['verification_status']] ?? '';
                if ($ml['status'] === 'sold') $badge = 'Sold';
            ?>
                <div class="dr-card listed-card">
                    <div class="info">
                        <strong><?= h($ml["title"]) ?></strong>
                        <span>Listed at ৳<?= number_format((float) $ml["listing_price"], 2) ?> &middot; <span class="status-pill status-<?= h($ml['verification_status']) ?>"><?= h($badge) ?></span></span>
                        <?php if ($ml['verification_status'] === 'rejected' && !empty($ml['rejection_reason'])): ?>
                            <span style="color:var(--danger);">Reason: <?= h($ml['rejection_reason']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($ml['status'] === 'active'): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="cancel_listing">
                            <input type="hidden" name="listing_id" value="<?= (int) $ml["id"] ?>">
                            <button type="submit" class="dr-btn dr-btn-sm dr-btn-danger">Cancel</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="section-gap"></div>
        <?php endif; ?>

        <h2>Coupons You Can Sell</h2>
        <p>Paid, unredeemed coupons you own that aren't already listed.</p>

        <?php if (empty($sellable)): ?>
            <div class="dr-empty">
                <h2>Nothing available to sell</h2>
                <p>Coupons you've used or already listed won't show up here.</p>
                <a href="my-coupons.php" class="dr-btn">View My Coupons</a>
            </div>
        <?php else: ?>
            <div class="ticket-grid">
                <?php foreach ($sellable as $s): $isHighlight = $s["purchase_id"] == $highlight_purchase; ?>
                    <div class="ticket" style="<?= $isHighlight ? 'outline:2px solid var(--brand);' : '' ?>">
                        <div class="ticket-main">
                            <div class="ticket-retailer"><?= h($s["retailer_name"]) ?><span class="ticket-category"><?= h($s["category"]) ?></span></div>
                            <h3><?= h($s["title"]) ?></h3>
                            <div class="ticket-desc"><?= h($s["description"]) ?></div>
                            <div style="font-size:12px;color:var(--text-mute);">You paid ৳<?= number_format((float) $s["total_price"], 2) ?></div>
                            <form method="POST" class="sell-form">
                                <input type="hidden" name="action" value="create_listing">
                                <input type="hidden" name="purchase_id" value="<?= (int) $s["purchase_id"] ?>">
                                <input type="number" name="listing_price" placeholder="Price ৳" min="1" step="0.01" required>
                                <button type="submit" class="dr-btn">List It</button>
                            </form>
                        </div>
                        <div class="ticket-stub">
                            <div class="ticket-code"><?= h($s["coupon_code"]) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php flash_render(); ?>
<script src="assets/app.js"></script>
<?php if ($tab === 'browse' && $hut_has_more): ?>
<script>
(function () {
    let offset = <?= count($listings) ?>;
    let hasMore = true;
    const grid = document.getElementById('hut-grid');
    const sentinel = document.getElementById('dr-scroll-sentinel');
    const loader = document.getElementById('hut-loader');
    if (!sentinel) return;

    DR.observeSentinel(sentinel, async function () {
        loader.style.display = 'flex';
        try {
            const res = await fetch('coupon_hut_load_more.php?offset=' + offset);
            const data = await res.json();
            data.items.forEach(html => grid.insertAdjacentHTML('beforeend', html));
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
<?php endif; ?>

<!-- Purchase confirmation modal (opened by the Buy Now buttons) -->
<div class="hut-modal" id="hutBuyModal" role="dialog" aria-modal="true" aria-labelledby="hutBuyHeading">
    <div class="hut-modal-card">
        <h2 id="hutBuyHeading">Confirm purchase</h2>
        <p class="hut-modal-sub">Review the details before you buy.</p>
        <div class="hut-modal-box">
            <div class="hut-modal-row"><span>Coupon</span><span id="hutBuyTitle"></span></div>
            <div class="hut-modal-row"><span>Store</span><span id="hutBuyRetailer"></span></div>
            <div class="hut-modal-row"><span>Seller</span><span id="hutBuySeller"></span></div>
            <div class="hut-modal-row total"><span>You pay</span><span id="hutBuyPrice"></span></div>
        </div>
        <p class="hut-modal-note">This purchase counts toward your weekly coupon limit. The code unlocks in My Coupons right after.</p>
        <div class="hut-modal-actions">
            <button type="button" class="dr-btn dr-btn-outline" id="hutBuyCancel">Cancel</button>
            <button type="button" class="dr-btn" id="hutBuyConfirm">Confirm &amp; Buy</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('hutBuyModal');
    if (!modal) return;
    var confirmBtn = document.getElementById('hutBuyConfirm');
    var pendingForm = null;

    function $(id) { return document.getElementById(id); }

    function openModal(form, btn) {
        pendingForm = form;
        $('hutBuyTitle').textContent = btn.dataset.title || '';
        $('hutBuyRetailer').textContent = btn.dataset.retailer || '';
        $('hutBuySeller').textContent = (btn.dataset.sellerName || '') + ' (@' + (btn.dataset.sellerUser || '') + ')';
        $('hutBuyPrice').textContent = '৳' + (btn.dataset.price || '');
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        confirmBtn.focus();
    }

    function closeModal() {
        modal.classList.remove('open');
        document.body.style.overflow = '';
        pendingForm = null;
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Confirm & Buy';
    }

    // Delegated, so it also works for cards added later by infinite scroll.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('hut-buy-form')) return;
        e.preventDefault();
        var btn = form.querySelector('.js-hut-buy');
        if (btn) openModal(form, btn);
    });

    confirmBtn.addEventListener('click', function () {
        if (!pendingForm) return;
        confirmBtn.disabled = true;            // stop double-clicks buying twice
        confirmBtn.textContent = 'Processing…';
        pendingForm.submit();                  // programmatic submit doesn't re-trigger the handler above
    });

    $('hutBuyCancel').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) closeModal(); });
    window.addEventListener('pageshow', closeModal);   // reset if the user comes back with the Back button
})();
</script>
</body>
</html>
