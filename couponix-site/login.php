<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $login = trim($_POST["login"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($login === "" || $password === "") {
        $error = "Please enter username/email and password.";
    } else {

        $stmt = $conn->prepare("
            SELECT u.id, u.password
            FROM users u
            WHERE u.username = ? OR u.email = ?
            LIMIT 1
        ");
        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user["password"])) {
            session_regenerate_id(true);
            $_SESSION["user_id"] = (int) $user["id"];
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "Invalid username/email or password.";
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
<title>Login - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 40px 16px; }
.auth-card { width: 100%; max-width: 420px; padding: 38px 34px; }
.auth-logo { font-family: var(--font-heading); font-size: 26px; font-weight: 800; text-align: center; margin-bottom: 4px; }
.auth-logo span { color: var(--brand); }
.auth-subtitle { text-align: center; color: var(--text-dim); font-size: 14px; margin-bottom: 26px; }
.auth-error { background: var(--danger-tint); color: var(--danger); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }
.auth-footer { text-align: center; margin-top: 20px; color: var(--text-dim); font-size: 13px; }
.auth-footer a { font-weight: 700; text-decoration: none; }
.demo-hint { background: var(--brand-tint); color: var(--brand-dark); font-size: 12px; padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 20px; text-align: center; }
</style>
</head>
<body>

<div class="dr-card auth-card">
    <div class="auth-logo">Coupon<span>ix</span></div>
    <div class="auth-subtitle">Welcome back — log in to your account.</div>

    <div class="demo-hint">Demo login: demo@gmail.com / DealRank@123</div>

    <?php if ($error): ?>
        <div class="auth-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="dr-field">
            <label>Username or Email</label>
            <input type="text" name="login" value="<?= h($_POST['login'] ?? '') ?>" autocomplete="username" required>
        </div>
        <div class="dr-field">
            <label>Password</label>
            <input type="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="dr-btn dr-btn-block">Login</button>
    </form>

    <div class="auth-footer">
        Don't have an account? <a href="register.php">Register</a>
        <br><br>
        <a href="retailer-login.php" style="color:var(--text-mute);font-weight:600;">Retailer? Log in here</a>
    </div>
</div>

</body>
</html>
