<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

// Make SQL errors (including the OUT_OF_STOCK trigger signal) throw, on any PHP version.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$user_id = require_login();

$type = $_GET["type"] ?? "use";
$purchase_id = isset($_GET["purchase_id"]) ? (int) $_GET["purchase_id"] : 0;
$error = "";

if ($type === "payment") {

    if (empty($_SESSION["checkout_purchase_ids"])) {
        header("Location: coupons.php");
        exit;
    }

    $purchase_ids = $_SESSION["checkout_purchase_ids"];

    if (!isset($_SESSION["otp"])) {
        $_SESSION["otp"] = (string) random_int(100000, 999999);
        $_SESSION["otp_created"] = time();
    }
    $otp = $_SESSION["otp"];

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $entered = trim($_POST["otp"] ?? "");

        if (empty($_SESSION["otp"]) || time() - (int) $_SESSION["otp_created"] > 300) {
            $error = "OTP expired. Please start checkout again.";
            unset($_SESSION["otp"], $_SESSION["otp_created"]);
        } elseif (!hash_equals($_SESSION["otp"], $entered)) {
            $error = "Incorrect OTP.";
        } else {

            $conn->begin_transaction();
            try {
                $points_earned = 0;

                foreach ($purchase_ids as $pid) {
                    $pid = (int) $pid;

                    $stmt = $conn->prepare("
                        SELECT id, total_price FROM coupon_purchases
                        WHERE id = ? AND user_id = ? AND purchase_status = 'pending' LIMIT 1
                    ");
                    $stmt->bind_param("ii", $pid, $user_id);
                    $stmt->execute();
                    $purchase = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$purchase) {
                        continue;
                    }

                    $stmt = $conn->prepare("UPDATE coupon_purchases SET purchase_status = 'paid' WHERE id = ? AND user_id = ?");
                    $stmt->bind_param("ii", $pid, $user_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("
                        UPDATE transactions SET status = 'completed'
                        WHERE purchase_id = ? AND user_id = ? AND transaction_type = 'coupon_purchase' AND status = 'pending'
                    ");
                    $stmt->bind_param("ii", $pid, $user_id);
                    $stmt->execute();
                    $stmt->close();

                    $points_earned += points_for_amount((float) $purchase["total_price"]);
                }

                if ($points_earned > 0) {
                    award_points_and_recalc_rank($conn, $user_id, $points_earned);
                }

                $conn->commit();

                $_SESSION["success_purchase_ids"] = $purchase_ids;
                $_SESSION["success_points_earned"] = $points_earned;
                unset($_SESSION["checkout_purchase_ids"], $_SESSION["otp"], $_SESSION["otp_created"]);

                header("Location: success.php?type=payment");
                exit;

            } catch (Exception $e) {
                $conn->rollback();

                if (strpos($e->getMessage(), 'OUT_OF_STOCK') !== false) {
                    // Someone else bought the last units while this user was on the OTP page.
                    // Cancel the still-pending purchases so nothing is left dangling.
                    foreach ($purchase_ids as $pid) {
                        $pid = (int) $pid;
                        $c = $conn->prepare("UPDATE coupon_purchases SET purchase_status = 'cancelled' WHERE id = ? AND user_id = ? AND purchase_status = 'pending'");
                        $c->bind_param("ii", $pid, $user_id);
                        $c->execute();
                        $c->close();
                        $t = $conn->prepare("UPDATE transactions SET status = 'failed' WHERE purchase_id = ? AND user_id = ? AND status = 'pending'");
                        $t->bind_param("ii", $pid, $user_id);
                        $t->execute();
                        $t->close();
                    }
                    unset($_SESSION["checkout_purchase_ids"], $_SESSION["otp"], $_SESSION["otp_created"]);
                    flash_set("error", "Sorry, one of the coupons sold out while you were paying. You weren't charged. Please pick again.");
                    header("Location: coupons.php");
                    exit;
                }

                $error = "Payment verification failed.";
            }
        }
    }

} else {

    if ($purchase_id <= 0) {
        header("Location: my-coupons.php");
        exit;
    }

    $stmt = $conn->prepare("
        SELECT cp.id, cp.coupon_id, cp.purchase_status, c.title, c.coupon_code
        FROM coupon_purchases cp INNER JOIN coupons c ON c.id = cp.coupon_id
        WHERE cp.id = ? AND cp.user_id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $purchase_id, $user_id);
    $stmt->execute();
    $purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$purchase || $purchase["purchase_status"] !== "paid") {
        header("Location: my-coupons.php");
        exit;
    }

    $already = $conn->prepare("SELECT id FROM redemptions WHERE purchase_id = ? AND user_id = ? LIMIT 1");
    $already->bind_param("ii", $purchase_id, $user_id);
    $already->execute();
    if ($already->get_result()->fetch_assoc()) {
        $already->close();
        flash_set("error", "This coupon has already been redeemed.");
        header("Location: my-coupons.php");
        exit;
    }
    $already->close();

    if (!isset($_SESSION["use_otp_purchase"]) || (int) $_SESSION["use_otp_purchase"] !== $purchase_id) {
        $_SESSION["use_otp"] = (string) random_int(100000, 999999);
        $_SESSION["use_otp_created"] = time();
        $_SESSION["use_otp_purchase"] = $purchase_id;
    }
    $otp = $_SESSION["use_otp"];

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $entered = trim($_POST["otp"] ?? "");

        if (empty($_SESSION["use_otp"]) || time() - (int) $_SESSION["use_otp_created"] > 300) {
            $error = "OTP expired.";
            unset($_SESSION["use_otp"], $_SESSION["use_otp_created"], $_SESSION["use_otp_purchase"]);
        } elseif (!hash_equals($_SESSION["use_otp"], $entered)) {
            $error = "Incorrect OTP.";
        } else {

            $redemption_code = "DR-REDEEM-" . strtoupper(bin2hex(random_bytes(5)));

            $stmt = $conn->prepare("SELECT id FROM redemptions WHERE purchase_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param("ii", $purchase_id, $user_id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $error = "This coupon has already been redeemed.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO redemptions (user_id, purchase_id, coupon_id, redemption_code, status, redeemed_at)
                    VALUES (?, ?, ?, ?, 'redeemed', NOW())
                ");
                $coupon_id = (int) $purchase["coupon_id"];
                $stmt->bind_param("iiis", $user_id, $purchase_id, $coupon_id, $redemption_code);
                $stmt->execute();
                $stmt->close();

                $_SESSION["success_purchase_id"] = $purchase_id;
                $_SESSION["success_redemption_code"] = $redemption_code;
                unset($_SESSION["use_otp"], $_SESSION["use_otp_created"], $_SESSION["use_otp_purchase"]);

                header("Location: success.php?type=redemption");
                exit;
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OTP Verification - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 40px 16px; }
.otp-card { width: 100%; max-width: 440px; padding: 36px; text-align: center; }
.otp-display { font-size: 34px; font-weight: 800; letter-spacing: 8px; margin: 20px 0; font-family: var(--font-heading); color: var(--brand); }
.demo-box { background: var(--brand-tint); padding: 14px; border-radius: var(--radius-sm); margin-bottom: 20px; }
.demo-box small { color: var(--text-mute); }
.otp-input { width: 100%; padding: 14px; border: 1.5px solid var(--border-strong); border-radius: var(--radius-sm); text-align: center; font-size: 20px; letter-spacing: 6px; font-weight: 700; }
.auth-error { background: var(--danger-tint); color: var(--danger); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px; font-weight: 600; }
</style>
</head>
<body>

<div class="dr-card otp-card">

    <?php if ($type === "payment"): ?>
        <h1>Payment Verification</h1>
        <p>Enter the OTP generated for your payment.</p>
    <?php else: ?>
        <h1>Coupon Verification</h1>
        <p>Verify your OTP to use: <strong><?= h($purchase["title"]) ?></strong></p>
    <?php endif; ?>

    <div class="demo-box">
        <small>Your Demo OTP</small>
        <div class="otp-display"><?= h($otp) ?></div>
        <small>Valid for 5 minutes</small>
    </div>

    <?php if ($error): ?><div class="auth-error"><?= h($error) ?></div><?php endif; ?>

    <form method="POST">
        <input class="otp-input" type="text" name="otp" maxlength="6" minlength="6" pattern="[0-9]{6}" placeholder="000000" required>
        <button type="submit" class="dr-btn dr-btn-block" style="margin-top:16px;">Verify OTP</button>
    </form>

</div>

</body>
</html>
