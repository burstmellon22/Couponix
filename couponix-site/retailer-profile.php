<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/retailer-auth.php';

// Load the current retailer
$stmt = $conn->prepare("SELECT id, name, email, password, created_at FROM retailers WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $retailer_id);
$stmt->execute();
$retailer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$retailer) {
    // Account no longer exists — end the session
    header("Location: retailer-logout.php");
    exit;
}

$profileErrors = [];
$passwordErrors = [];
$flash = $_GET['status'] ?? '';

$old = [
    'name'  => $retailer['name'],
    'email' => $retailer['email'],
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    /* ---------- 1) Business details ---------- */
    if ($action === 'profile') {
        $old['name']  = trim($_POST['name'] ?? '');
        $old['email'] = trim($_POST['email'] ?? '');

        if ($old['name'] === '') {
            $profileErrors[] = "Business name is required.";
        } elseif (mb_strlen($old['name']) > 100) {
            $profileErrors[] = "Business name is too long (100 characters max).";
        }

        if ($old['email'] === '') {
            $profileErrors[] = "Email is required.";
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $profileErrors[] = "Please enter a valid email address.";
        }

        if (empty($profileErrors)) {
            // Email must stay unique among retailers (excluding this account)
            $check = $conn->prepare("SELECT id FROM retailers WHERE email = ? AND id != ? LIMIT 1");
            $check->bind_param("si", $old['email'], $retailer_id);
            $check->execute();
            $taken = $check->get_result()->fetch_assoc();
            $check->close();

            if ($taken) {
                $profileErrors[] = "That email is already used by another retailer account.";
            }
        }

        if (empty($profileErrors)) {
            $conn->begin_transaction();
            try {
                $upd = $conn->prepare("UPDATE retailers SET name = ?, email = ? WHERE id = ?");
                $upd->bind_param("ssi", $old['name'], $old['email'], $retailer_id);
                $upd->execute();
                $upd->close();

                // coupons store a copy of the business name, keep it in sync
                $sync = $conn->prepare("UPDATE coupons SET retailer_name = ? WHERE retailer_id = ?");
                $sync->bind_param("si", $old['name'], $retailer_id);
                $sync->execute();
                $sync->close();

                $conn->commit();

                $_SESSION["retailer_name"] = $old['name'];
                header("Location: retailer-profile.php?status=profile_updated");
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $profileErrors[] = "Something went wrong while saving. Please try again.";
            }
        }
    }

    /* ---------- 2) Change password ---------- */
    if ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current === '' || $new === '' || $confirm === '') {
            $passwordErrors[] = "Please fill in all password fields.";
        } else {
            if (!password_verify($current, $retailer['password'])) {
                $passwordErrors[] = "Your current password is incorrect.";
            }
            if (strlen($new) < 8) {
                $passwordErrors[] = "New password must be at least 8 characters.";
            }
            if ($new !== $confirm) {
                $passwordErrors[] = "New passwords do not match.";
            }
            if ($current === $new) {
                $passwordErrors[] = "New password must be different from your current one.";
            }
        }

        if (empty($passwordErrors)) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE retailers SET password = ? WHERE id = ?");
            $upd->bind_param("si", $hash, $retailer_id);

            if ($upd->execute()) {
                $upd->close();
                session_regenerate_id(true);
                header("Location: retailer-profile.php?status=password_updated");
                exit;
            }
            $upd->close();
            $passwordErrors[] = "Something went wrong while saving. Please try again.";
        }
    }
}

// $conn stays open — retailer-nav.php (included below) may still need it; PHP closes it at script end.
$active_page = 'profile';
$memberSince = !empty($retailer['created_at']) ? date('F j, Y', strtotime($retailer['created_at'])) : '—';
$displayName = $_SESSION["retailer_name"] ?? $retailer['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Profile - Couponix Retailer</title>
<script src="asset/theme.js"></script>
<link rel="stylesheet" href="asset/style.css">
<style>
.dr-alert {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 20px; border-radius: var(--radius);
    border: 1px solid transparent; box-shadow: var(--shadow-sm);
    margin: 0 0 22px; font-size: 14px; font-weight: 600;
}
.dr-alert-icon {
    flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
}
.dr-alert-icon svg { width: 15px; height: 15px; }
.dr-alert-body { flex: 1; padding-top: 3px; }
.dr-alert-success { background: var(--success-tint); border-color: var(--success); color: var(--success); }
.dr-alert-success .dr-alert-icon { background: var(--success); color: #0B2A18; }

.profile-error { background: var(--danger-tint); color: var(--danger); padding: 12px 14px; border-radius: var(--radius-sm); margin-bottom: 18px; font-size: 13px; font-weight: 600; }

.profile-head { display: flex; align-items: center; gap: 18px; margin-bottom: 22px; }
.profile-avatar {
    width: 64px; height: 64px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-heading); font-size: 26px; font-weight: 800;
    background: linear-gradient(135deg, var(--brand-light), var(--brand) 50%, var(--accent-2));
    color: var(--on-brand);
    box-shadow: 0 0 0 3px var(--surface), 0 0 0 5px var(--brand-tint), 0 6px 18px -4px var(--brand-glow);
}
.profile-head h2 { margin: 0 0 2px; font-size: 20px; }
.profile-head p { margin: 0; font-size: 13px; color: var(--text-mute); }

.profile-section-title { font-size: 16px; margin: 0 0 4px; }
.profile-section-sub { margin: 0 0 20px; font-size: 13px; color: var(--text-dim); }
.dr-card + .dr-card { margin-top: 24px; }
</style>
</head>
<body>

<?php require __DIR__ . '/retailer-nav.php'; ?>

<div class="container" style="max-width: 760px;">

    <div class="dr-page-header">
        <h1>Edit Profile</h1>
        <p>Update your business details and keep your account secure.</p>
    </div>

    <?php if ($flash === 'profile_updated' || $flash === 'password_updated'): ?>
        <div class="dr-alert dr-alert-success">
            <span class="dr-alert-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </span>
            <div class="dr-alert-body">
                <?= $flash === 'profile_updated' ? 'Profile updated successfully.' : 'Password changed successfully.' ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Business details -->
    <div class="dr-card" style="padding: 28px 30px;">

        <div class="profile-head">
            <div class="profile-avatar"><?= h(strtoupper(mb_substr($displayName, 0, 1))) ?></div>
            <div>
                <h2><?= h($displayName) ?></h2>
                <p>Retailer since <?= h($memberSince) ?></p>
            </div>
        </div>

        <h3 class="profile-section-title">Business details</h3>
        <p class="profile-section-sub">This name appears on all of your coupons.</p>

        <?php if (!empty($profileErrors)): ?>
            <div class="profile-error">
                <?php foreach ($profileErrors as $err): ?>
                    <div><?= h($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="profile">
            <div class="dr-form-grid">
                <div class="dr-field full">
                    <label>Business Name</label>
                    <input type="text" name="name" value="<?= h($old['name']) ?>" maxlength="100" required>
                </div>
                <div class="dr-field full">
                    <label>Retailer Email</label>
                    <input type="email" name="email" value="<?= h($old['email']) ?>" required>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top: 6px;">
                <button type="submit" class="dr-btn">Save Changes</button>
                <a href="retailer-dashboard.php" class="dr-btn dr-btn-outline">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Change password -->
    <div class="dr-card" style="padding: 28px 30px;">
        <h3 class="profile-section-title">Change password</h3>
        <p class="profile-section-sub">Use at least 8 characters. You'll stay logged in on this device.</p>

        <?php if (!empty($passwordErrors)): ?>
            <div class="profile-error">
                <?php foreach ($passwordErrors as $err): ?>
                    <div><?= h($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="password">
            <div class="dr-form-grid">
                <div class="dr-field full">
                    <label>Current Password</label>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="dr-field">
                    <label>New Password</label>
                    <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                </div>
                <div class="dr-field">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                </div>
            </div>

            <div style="margin-top: 6px;">
                <button type="submit" class="dr-btn">Update Password</button>
            </div>
        </form>
    </div>

</div>

<script src="asset/app.js"></script>
<script src="asset/retailer-notify.js"></script>
</body>
</html>
