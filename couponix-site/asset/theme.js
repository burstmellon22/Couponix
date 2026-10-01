/* ============================================================
   COUPONIX — GLOBAL THEME MANAGER
   Load in <head>, BEFORE style.css, on EVERY page:
       <script src="assets/theme.js"></script>
   - Applies the saved theme instantly (no flash of the wrong colours)
   - Handles every .dr-theme-dot button on any page
   - Syncs open tabs (change theme in one tab, the others follow)
   Valid themes: default | lime | ocean | sweet   (see style.css)
   ============================================================ */
(function () {
    var KEY = 'dr-theme';
    var VALID = ['default', 'lime', 'ocean', 'sweet'];
    var root = document.documentElement;

    function read() {
        try { var t = localStorage.getItem(KEY); return VALID.indexOf(t) > -1 ? t : 'default'; }
        catch (e) { return 'default'; }
    }

    function apply(theme) {
        if (theme === 'default') root.removeAttribute('data-theme');
        else root.setAttribute('data-theme', theme);
        if (document.body) markActive(theme);
    }

    function save(theme) {
        try {
            if (theme === 'default') localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, theme);
        } catch (e) {}
    }

    function markActive(theme) {
        document.querySelectorAll('.dr-theme-dot').forEach(function (d) {
            d.classList.toggle('active', d.dataset.themeOption === theme);
        });
    }

    // 1) apply immediately (runs in <head>, before first paint)
    apply(read());

    // 2) wire up switcher buttons wherever they exist
    document.addEventListener('click', function (e) {
        var dot = e.target.closest && e.target.closest('.dr-theme-dot');
        if (!dot) return;
        var theme = dot.dataset.themeOption || 'default';
        save(theme);
        apply(theme);
    });

    document.addEventListener('DOMContentLoaded', function () { markActive(read()); });

    // 3) keep other open tabs in sync
    window.addEventListener('storage', function (e) {
        if (e.key === KEY || e.key === null) apply(read());
    });

    window.DRTheme = { set: function (t) { save(t); apply(t); }, get: read };
})();
