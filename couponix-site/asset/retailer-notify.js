/* ============================================================
   RETAILER — notification bell
   Polls retailer-notifications-api.php every 20s for pending
   coupon-listing verification requests and renders them into
   the dropdown in retailer-nav.php.
   Include AFTER app.js on every retailer page.
   ============================================================ */

(function () {
    const POLL_MS = 20000;

    function escapeHtml(str) {
        const div = document.createElement("div");
        div.textContent = str;
        return div.innerHTML;
    }

    function render(data) {
        const badge = document.getElementById("drNotifyBadge");
        const list = document.getElementById("drNotifyList");
        if (!badge || !list) return;

        const count = data.count || 0;

        if (count > 0) {
            badge.style.display = "flex";
            badge.textContent = count > 9 ? "9+" : String(count);
        } else {
            badge.style.display = "none";
        }

        if (!data.items || data.items.length === 0) {
            list.innerHTML = '<div class="dr-notify-empty">No pending verifications.</div>';
            return;
        }

        list.innerHTML = data.items.map((item) => `
            <a class="dr-notify-item" href="retailer-verify.php#listing-${item.id}">
                <strong>${escapeHtml(item.title)}</strong>
                <span>Code: ${escapeHtml(item.code)} · ৳${Number(item.price).toFixed(2)}</span>
            </a>
        `).join("");
    }

    async function poll() {
        try {
            const res = await fetch("retailer-notifications-api.php", { credentials: "same-origin" });
            if (!res.ok) return;
            const data = await res.json();
            render(data);
        } catch (err) {
            // silent fail — bell just won't update this cycle
        }
    }

    function initNotifyMenu() {
        const toggle = document.getElementById("drNotifyToggle");
        const dropdown = document.getElementById("drNotifyDropdown");
        if (!toggle || !dropdown) return;

        toggle.addEventListener("click", (e) => {
            e.stopPropagation();
            dropdown.classList.toggle("open");
        });

        document.addEventListener("click", (e) => {
            if (!dropdown.contains(e.target) && e.target !== toggle) {
                dropdown.classList.remove("open");
            }
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape") dropdown.classList.remove("open");
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        initNotifyMenu();
        poll();
        setInterval(poll, POLL_MS);
    });
})();
