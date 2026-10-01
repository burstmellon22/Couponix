<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($name === "" || $username === "" || $email === "" || $phone === "" || $password === "") {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {

        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $error = "Username or email already exists.";
        } else {

            $rank_id = 1; // everyone starts at Bronze (lowest min_points)
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare("
                    INSERT INTO users (rank_id, points, name, username, email, phone, password)
                    VALUES (?, 0, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("isssss", $rank_id, $name, $username, $email, $phone, $hash);
                $stmt->execute();
                $new_user_id = $conn->insert_id;
                $stmt->close();

                /* Give exactly ONE existing active coupon as a free welcome coupon. */
                $stmt = $conn->prepare("
                    SELECT id FROM coupons
                    WHERE status = 'active' AND quantity > 0
                    ORDER BY id ASC LIMIT 1
                ");
                $stmt->execute();
                $welcome = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($welcome) {
                    $welcome_coupon_id = (int) $welcome["id"];
                    $stmt = $conn->prepare("
                        INSERT INTO coupon_purchases (user_id, coupon_id, quantity, unit_price, total_price, purchase_status)
                        VALUES (?, ?, 1, 0, 0, 'paid')
                    ");
                    $stmt->bind_param("ii", $new_user_id, $welcome_coupon_id);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();

                session_regenerate_id(true);
                $_SESSION["user_id"] = $new_user_id;
                $_SESSION["welcome_popup"] = true;

                header("Location: dashboard.php");
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Registration failed. Please try again.";
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
<title>Create Account - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 40px 16px; }
.auth-card { width: 100%; max-width: 460px; padding: 38px 34px; }
.auth-logo { font-family: var(--font-heading); font-size: 26px; font-weight: 800; text-align: center; margin-bottom: 4px; }
.auth-logo span { color: var(--brand); }
.auth-subtitle { text-align: center; color: var(--text-dim); font-size: 14px; margin-bottom: 26px; }
.auth-error { background: var(--danger-tint); color: var(--danger); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }
.auth-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.auth-footer { text-align: center; margin-top: 20px; color: var(--text-dim); font-size: 13px; }
.auth-footer a { font-weight: 700; text-decoration: none; }
</style>
</head>
<body>

<div class="dr-card auth-card">
    <div class="auth-logo">Coupon<span>ix</span></div>
    <div class="auth-subtitle">Create your account — your first coupon is free.</div>

    <?php if ($error): ?>
        <div class="auth-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="dr-field">
            <label>Full name</label>
            <input type="text" name="name" value="<?= h($_POST['name'] ?? '') ?>" required>
        </div>

        <div class="auth-row">
            <div class="dr-field">
                <label>Username</label>
                <input type="text" name="username" value="<?= h($_POST['username'] ?? '') ?>" required>
            </div>
            <div class="dr-field">
                <label>Phone</label>
                <input type="tel" name="phone" value="<?= h($_POST['phone'] ?? '') ?>" required>
            </div>
        </div>

        <div class="dr-field">
            <label>Email</label>
            <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
        </div>

        <div class="auth-row">
            <div class="dr-field">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="dr-field">
                <label>Confirm password</label>
                <input type="password" name="confirm_password" required>
            </div>
        </div>

        <button type="submit" class="dr-btn dr-btn-block">Create Account</button>
    </form>

    <div class="auth-footer">
        Already have an account? <a href="login.php">Log in</a>
    </div>
</div>

</body>
</html>
