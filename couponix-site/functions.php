<?php
/**
 * functions.php
 * ---------------------------------------------------------------------
 * Shared helpers. Include after db.php (needs $conn) and after
 * session_start(). Centralizing this fixes two real bugs from v1:
 *   - ranks.discount_percent was fetched everywhere but never
 *     actually applied to a price. rank_discounted_price() fixes
 *     that — cart.php and checkout.php both call it now.
 *   - users.rank_id was set once at signup and never changed.
 *     award_points_and_recalc_rank() is what makes rank-ups real —
 *     called from otp.php right after a payment is confirmed, and
 *     from coupon-hut.php after a resale purchase.
 */

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Emoji placeholder for a coupon's category — no product images in this schema, so this stands in for a thumbnail. */
function category_emoji(string $category): string
{
    $map = [
        'Food' => '🍔', 'Fashion' => '👗', 'Electronics' => '💻', 'Entertainment' => '🎬',
        'Transport' => '🚗', 'Grocery' => '🛒', 'Books' => '📚', 'Lifestyle' => '🧘', 'Beauty' => '💄',
    ];
    return $map[$category] ?? '🏷️';
}

function require_login(): int
{
    if (!isset($_SESSION["user_id"])) {
        header("Location: login.php");
        exit;
    }
    return (int) $_SESSION["user_id"];
}

function require_retailer_login(): int
{
    if (!isset($_SESSION["retailer_id"])) {
        header("Location: retailer-login.php");
        exit;
    }
    return (int) $_SESSION["retailer_id"];
}

/** One extra discount %, from the user's rank, stacked on top of a coupon's own final_price. */
function rank_discounted_price(float $finalPrice, float $rankDiscountPercent): float
{
    $price = $finalPrice * (1 - ($rankDiscountPercent / 100));
    return round(max(0, $price), 2);
}

function get_user_with_rank(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            u.id, u.name, u.username, u.email, u.phone, u.points,
            r.id AS rank_id, r.name AS rank_name, r.coupon_limit,
            r.discount_percent, r.min_points
        FROM users u
        JOIN ranks r ON u.rank_id = r.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

/** The next rank up from the given rank id, or null if already at the top. */
function get_next_rank(mysqli $conn, int $currentRankId): ?array
{
    $stmt = $conn->prepare("
        SELECT * FROM ranks
        WHERE min_points > (SELECT min_points FROM ranks WHERE id = ?)
        ORDER BY min_points ASC
        LIMIT 1
    ");
    $stmt->bind_param("i", $currentRankId);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $next ?: null;
}

/**
 * Adds points and re-evaluates rank against ranks.min_points.
 * Call this every time points change — never update users.points
 * anywhere else, or rank-ups will silently stop working there.
 */
function award_points_and_recalc_rank(mysqli $conn, int $userId, int $pointsToAdd): void
{
    $stmt = $conn->prepare("UPDATE users SET points = points + ? WHERE id = ?");
    $stmt->bind_param("ii", $pointsToAdd, $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        UPDATE users u
        JOIN (
            SELECT id FROM ranks
            WHERE min_points <= (SELECT points FROM users WHERE id = ?)
            ORDER BY min_points DESC
            LIMIT 1
        ) r ON 1 = 1
        SET u.rank_id = r.id
        WHERE u.id = ?
    ");
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $stmt->close();
}

/** Points earned per taka spent on a confirmed purchase. Tune here, nowhere else. */
function points_for_amount(float $amount): int
{
    return (int) floor($amount / 10); // 1 point per ৳10 spent
}

/** Units bought (paid) in the last 7 days — what a rank's weekly coupon_limit is checked against. */
function count_weekly_purchases(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(quantity), 0) AS c
        FROM coupon_purchases
        WHERE user_id = ? AND purchase_status = 'paid'
          AND purchased_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()["c"];
    $stmt->close();
    return $count;
}

// ------------------------------------------------------------------
// COUPON MANAGEMENT — shared by retailer-dashboard.php's "Add
// Coupon" and "Edit Coupon" forms, so the two can't validate
// differently from each other.
// ------------------------------------------------------------------

/**
 * Validates + normalizes raw $_POST data for creating/updating a
 * coupon. Returns ['error' => string|null, 'data' => [...]]. Caller
 * still owns the actual INSERT/UPDATE and any uniqueness/ownership
 * checks that need a query.
 */
function validate_coupon_input(array $post): array
{
    $title = trim($post["title"] ?? "");
    $category = trim($post["category"] ?? "");
    $coupon_code = strtoupper(trim($post["coupon_code"] ?? ""));
    $description = trim($post["description"] ?? "");
    $discount_type = ($post["discount_type"] ?? "") === "fixed" ? "fixed" : "percentage";
    $discount_value = (float) ($post["discount_value"] ?? 0);
    $original_price = (float) ($post["original_price"] ?? 0);
    $final_price = (float) ($post["final_price"] ?? 0);
    $quantity = (int) ($post["quantity"] ?? 0);
    $status = in_array($post["status"] ?? "", ["active", "inactive", "expired", "sold_out"], true)
        ? $post["status"] : "active";
    $available_from = trim($post["available_from"] ?? "");
    $expires_at = trim($post["expires_at"] ?? "");

    $error = null;
    if ($title === "" || $category === "" || $coupon_code === "") {
        $error = "Title, category, and coupon code are required.";
    } elseif (!preg_match('/^[A-Z0-9_-]+$/', $coupon_code)) {
        $error = "Coupon code can only contain letters, numbers, hyphens and underscores.";
    } elseif ($original_price <= 0) {
        $error = "Original price must be greater than 0.";
    } elseif ($final_price < 0 || $final_price > $original_price) {
        $error = "Final price must be between 0 and the original price.";
    } elseif ($discount_value < 0) {
        $error = "Discount value can't be negative.";
    } elseif ($quantity < 0) {
        $error = "Quantity can't be negative.";
    }

    return [
        "error" => $error,
        "data" => [
            "title" => $title,
            "category" => $category,
            "coupon_code" => $coupon_code,
            "description" => $description,
            "discount_type" => $discount_type,
            "discount_value" => $discount_value,
            "original_price" => $original_price,
            "final_price" => $final_price,
            "quantity" => $quantity,
            "status" => $status,
            // HTML datetime-local gives "YYYY-MM-DDTHH:MM" — MySQL wants a space and seconds.
            "available_from" => $available_from !== "" ? str_replace("T", " ", $available_from) . ":00" : null,
            "expires_at" => $expires_at !== "" ? str_replace("T", " ", $expires_at) . ":00" : null,
        ],
    ];
}

/**
 * Renders one ticket-style coupon card for the browse/coupons page.
 * Shared between coupons.php (initial render) and
 * coupons_load_more.php (infinite scroll AJAX) so the markup can
 * never drift out of sync between the two.
 */
function render_browse_ticket(array $c, float $rankDiscountPercent): string
{
    $original = (float) $c["original_price"];
    $final = (float) $c["final_price"];
    $youPay = rank_discounted_price($final, $rankDiscountPercent);
    $saving = max(0, $original - $youPay);
    $stock = (int) $c["quantity"];

    ob_start();
    ?>
    <div class="ticket">
        <div class="ticket-main">
            <div class="ticket-retailer"><?= h($c["retailer_name"]) ?><span class="ticket-category"><?= h($c["category"]) ?></span></div>
            <h3><?= h($c["title"]) ?></h3>
            <div class="ticket-desc"><?= h($c["description"]) ?></div>
            <div class="ticket-prices">
                <span class="was">৳<?= number_format($original, 2) ?></span>
                <span class="now">৳<?= number_format($youPay, 2) ?></span>
                <span class="save">You save ৳<?= number_format($saving, 2) ?><?= $rankDiscountPercent > 0 ? ' (rank bonus included)' : '' ?></span>
            </div>
            <form method="POST" class="ticket-form">
                <input type="hidden" name="coupon_id" value="<?= (int) $c["id"] ?>">
                <div class="qty-stepper">
                    <button type="button" data-step="-1">−</button>
                    <input type="number" name="quantity" value="1" min="1" max="<?= $stock ?>">
                    <button type="button" data-step="1">+</button>
                </div>
                <span class="qty-hint">scroll over the number to adjust</span>
                <button type="submit" class="dr-btn dr-btn-block" <?= $stock < 1 ? 'disabled' : '' ?>>
                    <?= $stock < 1 ? 'Sold Out' : 'Add to Cart' ?>
                </button>
            </form>
        </div>
        <div class="ticket-stub">
            <div class="ticket-code locked" oncopy="return false" oncontextmenu="return false" onselectstart="return false" title="Claim this coupon to reveal the code">••••••••</div>
            <div class="ticket-lock-hint">🔒 Unlocks on purchase</div>
            <div class="ticket-stock"><strong><?= $stock ?></strong> left</div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Renders one ticket card for Coupon Hut's Browse tab (peer resale
 * listings, not yet owned by the viewer — same locked-code treatment
 * as render_browse_ticket). Shared with coupon_hut_load_more.php.
 *
 * The seller now gets a proper profile strip at the top of the card
 * (avatar, name, @handle, rank, sales count) so it's obvious who you
 * are buying from. Expects these optional keys on $l, and degrades
 * gracefully if any are missing: seller_username, seller_name,
 * seller_rank, seller_sales, created_at.
 */
function render_hut_ticket(array $l): string
{
    $saving = max(0, (float) $l["final_price"] - (float) $l["listing_price"]);

    $username = (string) ($l["seller_username"] ?? "");
    $name = trim((string) ($l["seller_name"] ?? ""));
    if ($name === "") {
        $name = $username;
    }
    $rank = trim((string) ($l["seller_rank"] ?? ""));
    $sales = (int) ($l["seller_sales"] ?? 0);

    // Avatar initials: first letter of first + last word (UTF-8 safe when mbstring exists).
    $sub = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    $up = function_exists('mb_strtoupper') ? 'mb_strtoupper' : 'strtoupper';
    $words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = $words ? $up($sub($words[0], 0, 1)) : '?';
    if (count($words) > 1) {
        $initials .= $up($sub(end($words), 0, 1));
    }

    // "Listed 3 hr ago"
    $listed = "";
    if (!empty($l["created_at"])) {
        $d = max(0, time() - strtotime($l["created_at"]));
        if ($d < 3600) {
            $listed = "Listed " . max(1, (int) floor($d / 60)) . " min ago";
        } elseif ($d < 86400) {
            $listed = "Listed " . (int) floor($d / 3600) . " hr ago";
        } else {
            $days = (int) floor($d / 86400);
            $listed = "Listed " . $days . " day" . ($days === 1 ? "" : "s") . " ago";
        }
    }

    ob_start();
    ?>
    <div class="ticket">
        <div class="ticket-main">

            <div class="hut-seller">
                <div class="hut-seller-top">
                    <span class="hut-avatar"><?= h($initials) ?></span>
                    <div class="hut-seller-info">
                        <span class="hut-seller-label">Sold by</span>
                        <span class="hut-seller-name"><?= h($name) ?></span>
                        <span class="hut-seller-meta">@<?= h($username) ?></span>
                    </div>
                </div>
                <div class="hut-seller-stats">
                    <?php if ($rank !== ""): ?>
                        <div class="hut-stat"><span class="hut-stat-label">Rank</span><span class="hut-stat-value hut-stat-rank"><?= h($rank) ?></span></div>
                    <?php endif; ?>
                    <div class="hut-stat"><span class="hut-stat-label">Sales</span><span class="hut-stat-value"><?= $sales ?></span></div>
                </div>
            </div>

            <div class="ticket-retailer"><?= h($l["retailer_name"]) ?><span class="ticket-category"><?= h($l["category"]) ?></span></div>
            <h3><?= h($l["title"]) ?></h3>
            <div class="ticket-prices">
                <span class="was">৳<?= number_format((float) $l["final_price"], 2) ?></span>
                <span class="now">৳<?= number_format((float) $l["listing_price"], 2) ?></span>
                <?php if ($saving > 0): ?><span class="save">You save ৳<?= number_format($saving, 2) ?></span><?php endif; ?>
            </div>

            <div class="hut-foot">
                <span class="hut-verified">✔ Verified listing</span>
                <?php if ($listed !== ""): ?><span><?= h($listed) ?></span><?php endif; ?>
            </div>

            <form method="POST" class="ticket-form hut-buy-form">
                <input type="hidden" name="action" value="buy_listing">
                <input type="hidden" name="listing_id" value="<?= (int) $l["id"] ?>">
                <button type="submit" class="dr-btn dr-btn-block js-hut-buy"
                        data-title="<?= h($l["title"]) ?>"
                        data-retailer="<?= h($l["retailer_name"]) ?>"
                        data-seller-name="<?= h($name) ?>"
                        data-seller-user="<?= h($username) ?>"
                        data-price="<?= h(number_format((float) $l["listing_price"], 2)) ?>">Buy Now</button>
            </form>
        </div>
        <div class="ticket-stub">
            <div class="ticket-code locked" oncopy="return false" oncontextmenu="return false" onselectstart="return false" title="Buy this coupon to reveal the code">••••••••</div>
            <div class="ticket-lock-hint">🔒 Unlocks on purchase</div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ------------------------------------------------------------------
// LEADERBOARD / EARNINGS — shared between dashboard.php's preview
// widget and the full rankings.php page.
// ------------------------------------------------------------------

function get_top_sellers(mysqli $conn, int $limit = 10): array
{
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.username, SUM(cl.listing_price) AS earned, COUNT(*) AS sales
        FROM coupon_listings cl
        JOIN users u ON cl.seller_id = u.id
        WHERE cl.status = 'sold'
        GROUP BY u.id, u.name, u.username
        ORDER BY earned DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function get_user_total_earned(mysqli $conn, int $userId): float
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(listing_price),0) AS c FROM coupon_listings WHERE seller_id = ? AND status = 'sold'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $val = (float) $stmt->get_result()->fetch_assoc()["c"];
    $stmt->close();
    return $val;
}

function get_user_total_savings(mysqli $conn, int $userId): float
{
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(c.original_price - cp.unit_price), 0) AS c
        FROM coupon_purchases cp JOIN coupons c ON cp.coupon_id = c.id
        WHERE cp.user_id = ? AND cp.purchase_status = 'paid'
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $val = (float) $stmt->get_result()->fetch_assoc()["c"];
    $stmt->close();
    return $val;
}

/** 1-based position of this user in the seller-earnings leaderboard, or null if they've never sold anything. */
function get_user_seller_rank(mysqli $conn, int $userId): ?int
{
    $earned = get_user_total_earned($conn, $userId);
    if ($earned <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT COUNT(*) + 1 AS pos FROM (
            SELECT seller_id, SUM(listing_price) AS total
            FROM coupon_listings WHERE status = 'sold'
            GROUP BY seller_id HAVING total > ?
        ) t
    ");
    $stmt->bind_param("d", $earned);
    $stmt->execute();
    $pos = (int) $stmt->get_result()->fetch_assoc()["pos"];
    $stmt->close();
    return $pos;
}

// ------------------------------------------------------------------
// Flash messages: set before a redirect, read (and cleared) once on
// the next page load, rendered as a toast by assets/app.js. Replaces
// the old pattern of static colored <div> banners on every page.
// ------------------------------------------------------------------

function flash_set(string $type, string $message): void
{
    $_SESSION["flash"] = ["type" => $type, "message" => $message];
}

function flash_render(): void
{
    if (empty($_SESSION["flash"])) {
        return;
    }
    $type = $_SESSION["flash"]["type"] === "error" ? "error" : "success";
    $message = h($_SESSION["flash"]["message"]);
    unset($_SESSION["flash"]);
    echo "<script>DR.toast(\"{$message}\", \"{$type}\");</script>";
}

// ------------------------------------------------------------------
// THEME — nav.php's picker saves the choice in localStorage['dr-theme'].
// Echo this inside <head>, BEFORE the stylesheet link, so every page
// (including login/register/otp) applies the saved theme before first
// paint, with no flash of the default theme.
// ------------------------------------------------------------------
function theme_boot_script(): void
{
    echo "<script>(function(){try{var t=localStorage.getItem('dr-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>\n";
}
