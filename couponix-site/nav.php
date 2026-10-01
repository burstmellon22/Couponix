<?php
/**
 * nav.php
 * ---------------------------------------------------------------------
 * Included by every logged-in page after db.php + functions.php are
 * loaded and $_SESSION["user_id"] is set. Set $active to one of:
 * dashboard | coupons | cart | my-coupons | coupon-hut | community | profile | rankings
 *
 * The bell shows the seller's resale notifications (listing verified /
 * rejected), fed by notifications_api.php and the `notifications` table.
 */
$__nav_user = get_user_with_rank($conn, (int) $_SESSION["user_id"]);
$__initials = $__nav_user ? strtoupper(substr($__nav_user['name'], 0, 1) . substr(strrchr($__nav_user['name'], ' ') ?: '', 1, 1)) : '?';
?>
<nav class="dr-nav">

    <a href="dashboard.php" class="dr-logo"><span class="dr-logo-badge">🏷️</span>Coupon<span>ix</span></a>

    <div class="dr-nav-links">
        <a href="dashboard.php" class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>Home</a>
        <a href="coupons.php" class="<?= ($active ?? '') === 'coupons' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>Coupons</a>
        <a href="cart.php" class="<?= ($active ?? '') === 'cart' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M2 3h2l2.6 12.6a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 2-1.6L21 7H6"/></svg>Cart</a>
        <a href="coupon-hut.php" class="<?= ($active ?? '') === 'coupon-hut' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v10H3z"/><path d="M9 13a3 3 0 0 0 6 0"/></svg>Coupon Hut</a>
        <a href="my-coupons.php" class="<?= ($active ?? '') === 'my-coupons' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4V8z"/></svg>My Coupons</a>
        <a href="rankings.php" class="<?= ($active ?? '') === 'rankings' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M7 5H4a1 1 0 0 0-1 1 5 5 0 0 0 4 4.9M17 5h3a1 1 0 0 1 1 1 5 5 0 0 1-4 4.9"/></svg>Rankings</a>
        <a href="community.php" class="<?= ($active ?? '') === 'community' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>Community</a>
    </div>

    <div class="dr-nav-right">
        <?php if ($__nav_user): ?>
        <div class="dr-unotify" id="drUserNotifyWrap">
            <a href="my-coupons.php?tab=reselling" class="dr-nav-bell" id="drUserNotifyToggle" role="button" aria-haspopup="true" aria-label="Notifications" title="Notifications">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </a>
            <span class="dr-unotify-badge" id="drUserNotifyBadge" style="display:none;">0</span>
            <div class="dr-unotify-dropdown" id="drUserNotifyDropdown">
                <div class="dr-unotify-head">
                    <span>Notifications</span>
                    <button type="button" id="drUserNotifyReadAll" style="display:none;">Mark all read</button>
                </div>
                <div class="dr-unotify-list" id="drUserNotifyList"><div class="dr-unotify-empty">Loading…</div></div>
            </div>
        </div>
        <div class="dr-profile-menu">
            <button type="button" class="dr-nav-profile-btn" id="drProfileToggle" title="<?= h($__nav_user['name']) ?> — <?= h($__nav_user['rank_name']) ?>">
                <span class="dr-avatar"><?= h($__initials) ?></span>
            </button>
            <div class="dr-profile-dropdown" id="drProfileDropdown">
                <a href="profile.php" class="dr-menu-link <?= ($active ?? '') === 'profile' ? 'active' : '' ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Edit Profile
                </a>
                <div class="dr-submenu" id="drThemeSub">
                    <button type="button" class="dr-submenu-toggle" id="drThemeToggle" aria-expanded="false">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.4a4.6 4.6 0 0 0 4.6-4.6C22 6 17.5 2 12 2z"/></svg>
                        <span>Theme</span>
                        <svg class="dr-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                    </button>
                    <div class="dr-submenu-list" id="drThemeList">
                        <button type="button" class="dr-theme-opt" data-theme-option="default"><span class="dr-theme-dot" data-theme-option="default"></span>Default</button>
                        <button type="button" class="dr-theme-opt" data-theme-option="lime"><span class="dr-theme-dot" data-theme-option="lime"></span>Lime</button>
                        <button type="button" class="dr-theme-opt" data-theme-option="ocean"><span class="dr-theme-dot" data-theme-option="ocean"></span>Ocean</button>
                        <button type="button" class="dr-theme-opt" data-theme-option="sweet"><span class="dr-theme-dot" data-theme-option="sweet"></span>Sweet</button>
                    </div>
                </div>
                <a href="logout.php" class="logout-link">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                    Log Out
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

</nav>

<style>
/* Extra breathing room between navbar buttons */
.dr-nav .dr-nav-links { display: flex; align-items: center; gap: 12px; }
.dr-nav .dr-nav-right { display: flex; align-items: center; gap: 16px; }

#drProfileDropdown { display: none; min-width: 190px; z-index: 1000; }
#drProfileDropdown.open { display: flex; }
.dr-profile-dropdown .dr-submenu-toggle { width: 100%; }
.dr-submenu-toggle, .dr-theme-opt {
    -webkit-appearance: none; appearance: none; background: transparent; border: 0; width: 100%;
    font-family: inherit; font-weight: 600; font-size: 13px; color: var(--text-dim);
    padding: 10px 12px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px;
    text-align: left; transition: background .15s, color .15s;
}
.dr-submenu-toggle:hover, .dr-theme-opt:hover { background: var(--brand-tint); color: var(--text); }
.dr-menu-link {
    display: flex; align-items: center; gap: 8px; width: 100%; box-sizing: border-box;
    font-family: inherit; font-weight: 600; font-size: 13px; color: var(--text-dim);
    padding: 10px 12px; border-radius: 8px; text-decoration: none; transition: background .15s, color .15s;
}
.dr-menu-link:hover, .dr-menu-link.active { background: var(--brand-tint); color: var(--text); }
.dr-submenu-toggle span { flex: 1; }
.dr-chevron { transition: transform .2s; }
.dr-submenu.open .dr-chevron { transform: rotate(180deg); }
.dr-submenu-list { display: none; flex-direction: column; gap: 2px; margin: 2px 0 4px 10px; padding-left: 8px; border-left: 2px solid var(--border); }
.dr-submenu.open .dr-submenu-list { display: flex; }
.dr-theme-opt { padding: 8px 10px; }
.dr-theme-opt .dr-theme-dot { pointer-events: none; flex-shrink: 0; }
.dr-theme-opt.active { color: var(--text); }
</style>
<script>
/* Profile dropdown: Theme (submenu) + Log Out. Self-contained: it stops the click from
   reaching any other script that might also toggle the menu (which would open+close it). */
(function () {
    var toggle    = document.getElementById('drProfileToggle');
    var dropdown  = document.getElementById('drProfileDropdown');
    var sub       = document.getElementById('drThemeSub');
    var subToggle = document.getElementById('drThemeToggle');
    if (!toggle || !dropdown || !sub || !subToggle) return;

    function closeAll() {
        dropdown.classList.remove('open');
        sub.classList.remove('open');
        subToggle.setAttribute('aria-expanded', 'false');
    }
    function stop(e) { e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); }

    toggle.addEventListener('click', function (e) {
        stop(e);
        if (dropdown.classList.contains('open')) closeAll(); else dropdown.classList.add('open');
    });

    subToggle.addEventListener('click', function (e) {
        stop(e);
        var open = sub.classList.toggle('open');
        subToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && !toggle.contains(e.target)) closeAll();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

    var opts = dropdown.querySelectorAll('.dr-theme-opt');
    function markActive(theme) {
        opts.forEach(function (o) {
            var on = o.dataset.themeOption === theme;
            o.classList.toggle('active', on);
            o.querySelector('.dr-theme-dot').classList.toggle('active', on);
        });
    }
    var saved = 'default';
    try { saved = localStorage.getItem('dr-theme') || 'default'; } catch (err) {}
    markActive(saved);

    /* capture phase: runs before anything attached to the theme buttons/dots themselves */
    dropdown.addEventListener('click', function (e) {
        var opt = e.target.closest ? e.target.closest('.dr-theme-opt') : null;
        if (!opt) return;
        stop(e);
        var theme = opt.dataset.themeOption;
        if (theme === 'default') {
            document.documentElement.removeAttribute('data-theme');
            try { localStorage.removeItem('dr-theme'); } catch (err) {}
        } else {
            document.documentElement.setAttribute('data-theme', theme);
            try { localStorage.setItem('dr-theme', theme); } catch (err) {}
        }
        markActive(theme);
        closeAll();
    }, true);
})();
</script>

<style>
.dr-unotify { position: relative; display: inline-flex; align-items: center; }
.dr-unotify-badge {
    position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 5px;
    border-radius: 9px; background: var(--danger, #EF5350); color: #fff; font-size: 10px; font-weight: 800;
    display: flex; align-items: center; justify-content: center; pointer-events: none;
    box-shadow: 0 0 0 2px var(--bg, transparent);
}
.dr-unotify-dropdown {
    display: none; position: absolute; right: 0; top: calc(100% + 12px); width: 330px; max-width: 92vw;
    background: var(--surface-2, var(--surface)); border: 1px solid var(--border-strong, var(--border));
    border-radius: 12px; box-shadow: var(--shadow-md, 0 10px 30px rgba(0,0,0,.35)); z-index: 1000; overflow: hidden;
}
.dr-unotify-dropdown.open { display: block; }
.dr-unotify-head {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 12px 14px; border-bottom: 1px solid var(--border); font-size: 13px; font-weight: 800; color: var(--text);
}
.dr-unotify-head button {
    -webkit-appearance: none; appearance: none; background: none; border: 0; padding: 0; cursor: pointer;
    font-family: inherit; font-size: 12px; font-weight: 700; color: var(--brand);
}
.dr-unotify-list { max-height: 360px; overflow-y: auto; }
.dr-unotify-item {
    display: block; padding: 12px 14px 12px 26px; position: relative; text-decoration: none;
    border-bottom: 1px solid var(--border); color: var(--text-dim); font-size: 12px; line-height: 1.45;
}
.dr-unotify-item:last-child { border-bottom: 0; }
.dr-unotify-item:hover { background: var(--brand-tint); }
.dr-unotify-item strong { display: block; font-size: 13px; color: var(--text); margin-bottom: 2px; }
.dr-unotify-item em { display: block; font-style: normal; font-size: 11px; color: var(--text-mute); margin-top: 3px; }
.dr-unotify-item.unread::before {
    content: ""; position: absolute; left: 11px; top: 18px; width: 8px; height: 8px; border-radius: 50%; background: var(--brand);
}
.dr-unotify-empty { padding: 26px 14px; text-align: center; font-size: 13px; color: var(--text-mute); }
</style>
<script>
/* User notification bell: polls notifications_api.php, shows the unread badge + dropdown,
   and pops a toast when a new one arrives while the page is open. */
(function () {
    var toggle   = document.getElementById('drUserNotifyToggle');
    var dropdown = document.getElementById('drUserNotifyDropdown');
    var badge    = document.getElementById('drUserNotifyBadge');
    var list     = document.getElementById('drUserNotifyList');
    var readAll  = document.getElementById('drUserNotifyReadAll');
    if (!toggle || !dropdown || !badge || !list || !readAll) return;

    var POLL_MS = 10000;
    var baseline = null;   // highest notification id already seen on this page

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function render(data) {
        var unread = data.unread || 0;
        var items = data.items || [];

        if (unread > 0) { badge.style.display = 'flex'; badge.textContent = unread > 9 ? '9+' : String(unread); }
        else { badge.style.display = 'none'; }
        readAll.style.display = unread > 0 ? '' : 'none';

        if (!items.length) {
            list.innerHTML = '<div class="dr-unotify-empty">No notifications yet.</div>';
        } else {
            list.innerHTML = items.map(function (i) {
                return '<a class="dr-unotify-item' + (i.read ? '' : ' unread') + '" href="' + i.url + '">' +
                       '<strong>' + esc(i.title) + '</strong><span>' + esc(i.message) + '</span><em>' + esc(i.time) + '</em></a>';
            }).join('');
        }

        // Toast anything that arrived since the last poll (never on the first load).
        var maxId = items.length ? items[0].id : 0;
        if (baseline === null) { baseline = maxId; }
        else if (maxId > baseline) {
            items.filter(function (i) { return i.id > baseline && !i.read; }).reverse().forEach(function (i) {
                if (window.DR && DR.toast) DR.toast(i.title + ' — ' + i.message, i.type === 'resale_rejected' ? 'error' : 'success');
            });
            baseline = maxId;
        }
    }

    function poll() {
        if (document.hidden) return;
        fetch('notifications_api.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) render(d); })
            .catch(function () { /* try again next tick */ });
    }

    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });

    // Capture phase, so it still fires when the profile menu stops click propagation.
    document.addEventListener('click', function (e) {
        if (!dropdown.contains(e.target) && !toggle.contains(e.target)) dropdown.classList.remove('open');
    }, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') dropdown.classList.remove('open'); });

    readAll.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('action', 'read_all');
        fetch('notifications_api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) render(d); });
    });

    poll();
    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll);
})();
</script>
