<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

if (isset($_SESSION["retailer_id"])) {
    header("Location: retailer-dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $error = "Please enter email and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, name, password FROM retailers WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $retailer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($retailer && password_verify($password, $retailer["password"])) {
            session_regenerate_id(true);
            $_SESSION["retailer_id"] = (int) $retailer["id"];
            $_SESSION["retailer_name"] = $retailer["name"];
            header("Location: retailer-dashboard.php");
            exit;
        } else {
            $error = "Invalid email or password.";
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
<title>Retailer Login - Couponix</title>
<script src="asset/theme.js"></script>
<link rel="stylesheet" href="asset/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 40px 16px; }
.auth-card { width: 100%; max-width: 420px; padding: 38px 34px; }
.auth-logo { font-family: var(--font-heading); font-size: 22px; font-weight: 800; text-align: center; margin-bottom: 4px; }
.auth-logo span { color: var(--brand); }
.auth-subtitle { text-align: center; color: var(--text-dim); font-size: 14px; margin-bottom: 26px; }
.auth-error { background: var(--danger-tint); color: var(--danger); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }
.demo-hint { background: var(--brand-tint); color: var(--brand-dark); font-size: 12px; padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 20px; text-align: center; }
.auth-footer { text-align: center; margin-top: 20px; color: var(--text-dim); font-size: 13px; }
.auth-footer a { font-weight: 700; text-decoration: none; }
</style>
</head>
<body>

<div class="dr-card auth-card">
    <div class="auth-logo">Coupon<span>ix</span> for Retailers</div>
    <div class="auth-subtitle">Verify resale listings of your own coupons.</div>

    <div class="demo-hint">Demo: gadgetkotha@couponix.demo / Retailer@123</div>

    <?php if ($error): ?><div class="auth-error"><?= h($error) ?></div><?php endif; ?>

    <form method="POST">
        <div class="dr-field">
            <label>Retailer Email</label>
            <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="dr-field">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit" class="dr-btn dr-btn-block">Login</button>
    </form>

    <div class="auth-footer">
        New business? <a href="retailer-register.php">Register here</a>
        <br><br>
        Not a retailer? <a href="login.php">Member login</a>
    </div>
</div>

</body>
</html>