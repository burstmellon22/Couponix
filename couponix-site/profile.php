<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$user_id = require_login();   // the ONLY source of the account id — never read an id from POST/GET

/* Load the current user (read-only display fields + editable fields) */
$stmt = $conn->prepare("
    SELECT u.id, u.name, u.username, u.email, u.phone, u.password, u.points, u.created_at, r.name AS rank_name
    FROM users u
    LEFT JOIN ranks r ON r.id = u.rank_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // Account no longer exists — end the session
    session_destroy();
    header("Location: login.php");
    exit;
}

$profileErrors  = [];
$passwordErrors = [];
$flash = $_GET['status'] ?? '';

$old = [
    'name'     => $user['name'],
    'username' => $user['username'],
    'email'    => $user['email'],
    'phone'    => $user['phone'] ?? '',
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';

    /* ---------- 1) Personal details ---------- */
    if ($action === 'profile') {
        // NOTE: id, rank_id, points, password and created_at are deliberately NOT read from the form.
        $old['name']     = trim($_POST['name'] ?? '');
        $old['username'] = trim($_POST['username'] ?? '');
        $old['email']    = trim($_POST['email'] ?? '');
        $old['phone']    = trim($_POST['phone'] ?? '');

        if ($old['name'] === '') {
            $profileErrors[] = "Full name is required.";
        } elseif (mb_strlen($old['name']) > 100) {
            $profileErrors[] = "Full name is too long (100 characters max).";
        }

        if ($old['username'] === '') {
            $profileErrors[] = "Username is required.";
        } elseif (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $old['username'])) {
            $profileErrors[] = "Username must be 3–50 characters: letters, numbers, underscore or dot.";
        }

        if ($old['email'] === '') {
            $profileErrors[] = "Email is required.";
        } elseif (mb_strlen($old['email']) > 150 || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $profileErrors[] = "Please enter a valid email address.";
        }

        if ($old['phone'] !== '' && !preg_match('/^\+?[0-9][0-9\s\-]{5,28}$/', $old['phone'])) {
            $profileErrors[] = "Please enter a valid phone number.";
        }

        if (empty($profileErrors)) {
            // Username and email must stay unique among users (excluding this account)
            $check = $conn->prepare("SELECT username, email FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 2");
            $check->bind_param("ssi", $old['username'], $old['email'], $user_id);
            $check->execute();
            $rows = $check->get_result()->fetch_all(MYSQLI_ASSOC);
            $check->close();

            foreach ($rows as $r) {
                if (strcasecmp($r['username'], $old['username']) === 0) {
                    $profileErrors[] = "That username is already taken.";
                }
                if (strcasecmp($r['email'], $old['email']) === 0) {
                    $profileErrors[] = "That email is already used by another account.";
                }
            }
            $profileErrors = array_values(array_unique($profileErrors));
        }

        if (empty($profileErrors)) {
            try {
                $phoneVal = $old['phone'] === '' ? null : $old['phone'];

                // WHERE id = session user; id itself is never in the SET list.
                $upd = $conn->prepare("UPDATE users SET name = ?, username = ?, email = ?, phone = ? WHERE id = ?");
                $upd->bind_param("ssssi", $old['name'], $old['username'], $old['email'], $phoneVal, $user_id);
                $upd->execute();
                $upd->close();

                header("Location: profile.php?status=profile_updated");
                exit;
            } catch (Throwable $e) {
                $profileErrors[] = ($conn->errno === 1062)
                    ? "That username or email is already in use."
                    : "Something went wrong while saving. Please try again.";
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
            if (!password_verify($current, $user['password'])) {
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
            $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $upd->bind_param("si", $hash, $user_id);

            if ($upd->execute()) {
                $upd->close();
                session_regenerate_id(true);
                header("Location: profile.php?status=password_updated");
                exit;
            }
            $upd->close();
            $passwordErrors[] = "Something went wrong while saving. Please try again.";
        }
    }
}

$active = 'profile';
$memberSince = !empty($user['created_at']) ? date('F j, Y', strtotime($user['created_at'])) : '—';
$displayName = $old['name'] !== '' ? $old['name'] : $user['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Profile - Couponix</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
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
.profile-head .rank-badge { margin-left: 6px; }

.profile-section-title { font-size: 16px; margin: 0 0 4px; }
.profile-section-sub { margin: 0 0 20px; font-size: 13px; color: var(--text-dim); }
.dr-card + .dr-card { margin-top: 24px; }
.dr-field input[readonly] { opacity: .65; cursor: not-allowed; }
</style>
</head>
<body>

<?php include __DIR__ . '/nav.php'; ?>

<div class="container" style="max-width: 760px;">

    <div class="dr-page-header">
        <h1>Edit Profile</h1>
        <p>Update your personal details and keep your account secure.</p>
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

    <!-- Personal details -->
    <div class="dr-card" style="padding: 28px 30px;">

        <div class="profile-head">
            <div class="profile-avatar"><?= h(strtoupper(mb_substr($displayName, 0, 1))) ?></div>
            <div>
                <h2><?= h($displayName) ?></h2>
                <p>
                    Member since <?= h($memberSince) ?>
                    <?php if (!empty($user['rank_name'])): ?>
                        <span class="rank-badge rank-<?= h($user['rank_name']) ?>"><?= h($user['rank_name']) ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <h3 class="profile-section-title">Personal details</h3>
        <p class="profile-section-sub">Your rank and points are earned and can't be edited here.</p>

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
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?= h($old['name']) ?>" maxlength="100" required>
                </div>
                <div class="dr-field">
                    <label>Username</label>
                    <input type="text" name="username" value="<?= h($old['username']) ?>" maxlength="50" pattern="[A-Za-z0-9_.]{3,50}" required>
                </div>
                <div class="dr-field">
                    <label>Phone</label>
                    <input type="tel" name="phone" value="<?= h($old['phone']) ?>" maxlength="30">
                </div>
                <div class="dr-field full">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= h($old['email']) ?>" maxlength="150" required>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top: 6px;">
                <button type="submit" class="dr-btn">Save Changes</button>
                <a href="dashboard.php" class="dr-btn dr-btn-outline">Cancel</a>
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

<?php flash_render(); ?>
<script src="assets/app.js"></script>
</body>
</html>
