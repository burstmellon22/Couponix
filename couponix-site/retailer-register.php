<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

if (isset($_SESSION["retailer_id"])) {
    header("Location: retailer-dashboard.php");
    exit;
}

$errors = [];
$old = [
    'name' => '',
    'email' => '',
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $old['name'] = trim($_POST['name'] ?? '');
    $old['email'] = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($old['name'] === '') $errors[] = "Business name is required.";

    if ($old['email'] === '') {
        $errors[] = "Email is required.";
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if ($password === '') {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }

    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {
        // Email must be unique among retailers
        $check = $conn->prepare("SELECT id FROM retailers WHERE email = ?");
        $check->bind_param("s", $old['email']);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $errors[] = "An account with that email already exists — please log in instead.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $insert = $conn->prepare("
                INSERT INTO retailers (name, email, password, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $insert->bind_param("sss", $old['name'], $old['email'], $hashedPassword);

            if ($insert->execute()) {
                $newRetailerId = $insert->insert_id;
                $insert->close();

                session_regenerate_id(true);
                $_SESSION["retailer_id"] = (int) $newRetailerId;
                $_SESSION["retailer_name"] = $old['name'];

                $conn->close();
                header("Location: retailer-dashboard.php");
                exit;
            } else {
                $errors[] = "Something went wrong while creating your account. Please try again.";
            }
            $insert->close();
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
<title>Retailer Registration - Couponix</title>
<script src="asset/theme.js"></script>
<link rel="stylesheet" href="asset/style.css">
<style>
body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 40px 16px; }
.auth-card { width: 100%; max-width: 420px; padding: 38px 34px; }
.auth-logo { font-family: var(--font-heading); font-size: 22px; font-weight: 800; text-align: center; margin-bottom: 4px; }
.auth-logo span { color: var(--brand); }
.auth-subtitle { text-align: center; color: var(--text-dim); font-size: 14px; margin-bottom: 26px; }
.auth-error { background: var(--danger-tint); color: var(--danger); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }
.auth-footer { text-align: center; margin-top: 20px; color: var(--text-dim); font-size: 13px; }
.auth-footer a { font-weight: 700; text-decoration: none; }
</style>
</head>
<body>

<div class="dr-card auth-card">
    <div class="auth-logo">Coupon<span>ix</span> for Retailers</div>
    <div class="auth-subtitle">Register your business to list and manage coupons.</div>

    <?php if (!empty($errors)): ?>
        <div class="auth-error">
            <?php foreach ($errors as $err): ?>
                <div><?= h($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="dr-field">
            <label>Business Name</label>
            <input type="text" name="name" value="<?= h($old['name']) ?>" required>
        </div>
        <div class="dr-field">
            <label>Retailer Email</label>
            <input type="email" name="email" value="<?= h($old['email']) ?>" required>
        </div>
        <div class="dr-field">
            <label>Password</label>
            <input type="password" name="password" required minlength="8">
        </div>
        <div class="dr-field">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required minlength="8">
        </div>
        <button type="submit" class="dr-btn dr-btn-block">Create Account</button>
    </form>

    <div class="auth-footer">
        Already registered? <a href="retailer-login.php">Login here</a>
        <br><br>
        Not a retailer? <a href="login.php">Member login</a>
    </div>
</div>

</body>
</html>
