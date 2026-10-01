<?php
/* ============================================================
   RETAILER NAVBAR PARTIAL
   include this after retailer-auth.php has run, so $retailer_id
   and $_SESSION["retailer_name"] are already available.
   Pass $active_page (string) before including, to highlight the
   current link, e.g. $active_page = 'dashboard';
   ============================================================ */
$active_page = $active_page ?? '';
$retailer_name = $_SESSION["retailer_name"] ?? "Retailer";
$retailer_initial = strtoupper(substr($retailer_name, 0, 1));
?>
<style>
/* Extra breathing room between navbar buttons */
.dr-nav .dr-nav-links { display: flex; align-items: center; gap: 12px; }
.dr-nav .dr-nav-right { display: flex; align-items: center; gap: 16px; }

/* ---- Profile dropdown: Theme submenu + Logout ---- */
.dr-profile-dropdown .dr-menu-btn {
    -webkit-appearance: none;
    appearance: none;
    width: 100%;
    background: transparent;
    border: 0;
    margin: 0;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
    font-weight: 600;
    font-size: 13px;
    color: var(--text-dim);
    padding: 10px 12px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    transition: background .15s, color .15s;
}
.dr-profile-dropdown a.dr-menu-btn { text-decoration: none; }
.dr-profile-dropdown .dr-menu-btn:hover,
.dr-profile-dropdown .dr-menu-btn[aria-expanded="true"] {
    background: var(--brand-tint, var(--bg-soft));
    color: var(--text);
}
.dr-profile-dropdown .dr-menu-btn .dr-caret { font-size: 16px; line-height: 1; }

.dr-submenu-wrap { position: relative; }
.dr-submenu {
    display: none;
    position: absolute;
    top: -6px;
    right: calc(100% + 8px);
    min-width: 170px;
    background: var(--surface-2, var(--surface));
    border: 1px solid var(--border-strong, var(--border));
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-md);
    padding: 6px;
    flex-direction: column;
    gap: 2px;
    z-index: 210;
}
.dr-submenu.open { display: flex; }
.dr-submenu .dr-theme-dot { pointer-events: none; flex-shrink: 0; }
.dr-submenu .dr-menu-btn { justify-content: flex-start; }

/* small screens: open the theme list inline instead of as a side flyout */
@media (max-width: 640px) {
    .dr-submenu {
        position: static;
        min-width: 0;
        border: 0;
        box-shadow: none;
        background: transparent;
        padding: 2px 0 2px 10px;
    }
    .dr-profile-dropdown .dr-menu-btn .dr-caret { transform: rotate(-90deg); }
    .dr-profile-dropdown .dr-menu-btn[aria-expanded="true"] .dr-caret { transform: rotate(90deg); }
}
</style>

<nav class="dr-nav">
    <a href="retailer-dashboard.php" class="dr-logo">
        <span class="dr-logo-badge">🏷️</span>Coupon<span>ix</span> for Retailers
    </a>

    <div class="dr-nav-links">
        <a href="retailer-dashboard.php" class="<?= $active_page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="retailer-coupons.php" class="<?= $active_page === 'coupons' ? 'active' : '' ?>">My Coupons</a>
        <a href="retailer-verify.php" class="<?= $active_page === 'verify' ? 'active' : '' ?>">Verify Listings</a>
    </div>

    <div class="dr-nav-right">

        <!-- Notification bell -->
        <div class="dr-profile-menu" id="drNotifyMenu">
            <button type="button" class="dr-nav-bell" id="drNotifyToggle" aria-label="Notifications">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
            </button>
            <span id="drNotifyBadge" class="dr-notify-badge" style="display:none;">0</span>

            <div class="dr-profile-dropdown dr-notify-dropdown" id="drNotifyDropdown">
                <div class="dr-notify-head">Pending Verifications</div>
                <div id="drNotifyList" class="dr-notify-list">
                    <div class="dr-notify-empty">Loading…</div>
                </div>
                <a href="retailer-verify.php" class="dr-notify-viewall">View all →</a>
            </div>
        </div>

        <!-- Profile dropdown: 1. Edit Profile  2. Theme (opens a second dropdown)  3. Logout -->
        <div class="dr-profile-menu" id="drRetProfileMenu">
            <button type="button" class="dr-nav-profile-btn" id="drRetProfileToggle" aria-label="Account menu" aria-haspopup="true" aria-expanded="false">
                <span class="dr-avatar"><?= h($retailer_initial) ?></span>
            </button>
            <div class="dr-profile-dropdown" id="drRetProfileDropdown">

                <a href="retailer-profile.php" class="dr-menu-btn <?= $active_page === 'profile' ? 'active' : '' ?>">
                    <span>👤 Edit Profile</span>
                </a>

                <div class="dr-submenu-wrap">
                    <button type="button" class="dr-menu-btn" id="drRetThemeToggle" aria-haspopup="true" aria-expanded="false">
                        <span>🎨 Theme</span>
                        <span class="dr-caret">‹</span>
                    </button>
                    <div class="dr-submenu" id="drRetThemeSub">
                        <button type="button" class="dr-menu-btn dr-theme-opt" data-theme-option="default">
                            <span class="dr-theme-dot" data-theme-option="default"></span> Ember
                        </button>
                        <button type="button" class="dr-menu-btn dr-theme-opt" data-theme-option="lime">
                            <span class="dr-theme-dot" data-theme-option="lime"></span> Matrix
                        </button>
                        <button type="button" class="dr-menu-btn dr-theme-opt" data-theme-option="ocean">
                            <span class="dr-theme-dot" data-theme-option="ocean"></span> Deep Sea
                        </button>
                        <button type="button" class="dr-menu-btn dr-theme-opt" data-theme-option="sweet">
                            <span class="dr-theme-dot" data-theme-option="sweet"></span> Candy Night
                        </button>
                    </div>
                </div>

                <a href="retailer-logout.php" class="logout-link">Logout</a>
            </div>
        </div>

    </div>
</nav>

<script>
(function () {
    var menu     = document.getElementById('drRetProfileMenu');
    var toggle   = document.getElementById('drRetProfileToggle');
    var dropdown = document.getElementById('drRetProfileDropdown');
    var themeBtn = document.getElementById('drRetThemeToggle');
    var themeSub = document.getElementById('drRetThemeSub');
    if (!menu || !toggle || !dropdown || !themeBtn || !themeSub) return;

    function setSub(open) {
        themeSub.classList.toggle('open', open);
        themeBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function setMenu(open) {
        dropdown.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (!open) setSub(false);
    }

    // profile photo -> open/close the main dropdown
    toggle.addEventListener('click', function () {
        setMenu(!dropdown.classList.contains('open'));
    });

    // "Theme" -> open/close the second dropdown
    themeBtn.addEventListener('click', function () {
        setSub(!themeSub.classList.contains('open'));
    });

    // pick a theme (saved + applied by asset/theme.js), then close everything
    themeSub.addEventListener('click', function (e) {
        var opt = e.target.closest('.dr-theme-opt');
        if (!opt) return;
        if (window.DRTheme) window.DRTheme.set(opt.getAttribute('data-theme-option'));
        setMenu(false);
    });

    // click outside or press Esc -> close
    document.addEventListener('click', function (e) {
        if (!menu.contains(e.target)) setMenu(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setMenu(false);
    });
})();
</script>
