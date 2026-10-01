/* ============================================================
   DEALRANK — SHARED CLIENT-SIDE HELPERS
   Loaded on every page. Exposes window.DR.
   ============================================================ */

window.DR = (function () {

    // ---------------------------------------------------------
    // Toasts (replaces static colored <div> banners)
    // ---------------------------------------------------------
    function toast(message, type) {
        type = type === "error" ? "error" : "success";
        let container = document.getElementById("dr-toast-container");
        if (!container) {
            container = document.createElement("div");
            container.id = "dr-toast-container";
            document.body.appendChild(container);
        }
        const el = document.createElement("div");
        el.className = "dr-toast" + (type === "error" ? " error" : "");
        el.textContent = message;
        container.appendChild(el);
        setTimeout(() => {
            el.style.transition = "opacity .3s, transform .3s";
            el.style.opacity = "0";
            el.style.transform = "translateX(24px)";
            setTimeout(() => el.remove(), 300);
        }, 3500);
    }

    // ---------------------------------------------------------
    // Scroll-wheel quantity stepper
    // Markup expected:
    // <div class="qty-stepper">
    //   <button type="button" data-step="-1">-</button>
    //   <input type="number" min="1" max="10" value="1">
    //   <button type="button" data-step="1">+</button>
    // </div>
    // Hovering the control and scrolling up/down changes the value;
    // the +/- buttons do the same. Always clamped to [min, max].
    // ---------------------------------------------------------
    function clamp(value, min, max) {
        if (isNaN(value)) value = min;
        return Math.min(max, Math.max(min, value));
    }

    function initQtyStepper(root) {
        root = root || document;
        root.querySelectorAll(".qty-stepper").forEach((stepper) => {
            if (stepper.dataset.drBound) return;
            stepper.dataset.drBound = "1";

            const input = stepper.querySelector("input");
            if (!input) return;
            const min = parseInt(input.min || "1", 10);
            const max = parseInt(input.max || "999", 10);

            const setValue = (v) => {
                input.value = clamp(v, min, max);
                input.dispatchEvent(new Event("change", { bubbles: true }));
            };

            stepper.querySelectorAll("button[data-step]").forEach((btn) => {
                btn.addEventListener("click", () => {
                    setValue(parseInt(input.value || min, 10) + parseInt(btn.dataset.step, 10));
                });
            });

            stepper.addEventListener("wheel", (e) => {
                e.preventDefault();
                const dir = e.deltaY < 0 ? 1 : -1;
                setValue(parseInt(input.value || min, 10) + dir);
            }, { passive: false });

            input.addEventListener("input", () => {
                input.value = clamp(parseInt(input.value || min, 10), min, max);
            });
        });
    }

    // ---------------------------------------------------------
    // Infinite scroll: observes a sentinel element and calls
    // loadMoreFn() when it enters the viewport. loadMoreFn should
    // return false (or a Promise resolving false) when there is no
    // more data, which stops further observation.
    // ---------------------------------------------------------
    function observeSentinel(sentinel, loadMoreFn) {
        if (!sentinel) return;
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(async (entry) => {
                if (!entry.isIntersecting) return;
                observer.unobserve(sentinel);
                const hasMore = await loadMoreFn();
                if (hasMore) {
                    observer.observe(sentinel);
                }
            });
        }, { rootMargin: "300px" });
        observer.observe(sentinel);
    }

    // ---------------------------------------------------------
    // Popup close (welcome modal, etc.)
    // ---------------------------------------------------------
    function closePopup(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    // ---------------------------------------------------------
    // Profile dropdown (avatar in nav.php) — logout now lives
    // inside this menu instead of a standalone nav icon, so it
    // takes an extra click to find rather than being in plain sight.
    // ---------------------------------------------------------
    function initProfileMenu() {
        const toggle = document.getElementById("drProfileToggle");
        const dropdown = document.getElementById("drProfileDropdown");
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

    // ---------------------------------------------------------
    // Coupon Hut "Buy Now" confirmation.
    // Replaces the browser's native confirm() popup with an in-page
    // window. It hooks the click in the CAPTURE phase, so it runs before
    // any inline onclick="return confirm(...)" on the button and stops
    // the native dialog from ever appearing — no matter which version
    // of the page markup is being served. Works for cards added later
    // by infinite scroll too (listeners live on document).
    // ---------------------------------------------------------
    function initHutBuyConfirm() {
        if (window.__drBuyConfirmBound) return;
        window.__drBuyConfirmBound = true;

        const css = document.createElement("style");
        css.textContent = `
        .dr-buy-modal { position: fixed; inset: 0; z-index: 5000; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(0,0,0,.6); backdrop-filter: blur(3px); }
        .dr-buy-modal.open { display: flex; animation: dr-buy-fade .18s ease-out; }
        .dr-buy-card { width: 100%; max-width: 420px; padding: 26px; border-radius: 16px; background: var(--surface-2, var(--surface, #1e1e1e)); color: var(--text, #fff); border: 1px solid var(--border-strong, var(--border, #333)); box-shadow: var(--shadow-md, 0 20px 50px rgba(0,0,0,.45)); animation: dr-buy-pop .2s ease-out; font-family: var(--font-body, inherit); }
        .dr-buy-card h2 { margin: 0 0 4px; font-size: 19px; }
        .dr-buy-sub { margin: 0 0 18px; font-size: 13px; color: var(--text-mute, #999); }
        .dr-buy-box { border: 1px solid var(--border, #333); border-radius: 12px; overflow: hidden; margin-bottom: 14px; }
        .dr-buy-row { display: flex; justify-content: space-between; gap: 16px; padding: 11px 14px; font-size: 13px; }
        .dr-buy-row + .dr-buy-row { border-top: 1px solid var(--border, #333); }
        .dr-buy-row span:first-child { color: var(--text-mute, #999); font-weight: 600; flex-shrink: 0; }
        .dr-buy-row span:last-child { font-weight: 700; text-align: right; }
        .dr-buy-row.total { background: var(--brand-tint, transparent); }
        .dr-buy-row.total span:last-child { font-size: 18px; font-weight: 800; color: var(--brand, #f90); }
        .dr-buy-note { font-size: 12px; color: var(--text-mute, #999); margin: 0 0 20px; }
        .dr-buy-actions { display: flex; gap: 10px; }
        .dr-buy-actions button { flex: 1; cursor: pointer; }
        @keyframes dr-buy-fade { from { opacity: 0; } to { opacity: 1; } }
        @keyframes dr-buy-pop { from { opacity: 0; transform: translateY(10px) scale(.97); } to { opacity: 1; transform: none; } }
        `;
        document.head.appendChild(css);

        const modal = document.createElement("div");
        modal.className = "dr-buy-modal";
        modal.setAttribute("role", "dialog");
        modal.setAttribute("aria-modal", "true");
        modal.innerHTML = `
            <div class="dr-buy-card">
                <h2>Confirm purchase</h2>
                <p class="dr-buy-sub">Review the details before you buy.</p>
                <div class="dr-buy-box">
                    <div class="dr-buy-row"><span>Coupon</span><span data-f="title"></span></div>
                    <div class="dr-buy-row"><span>Store</span><span data-f="retailer"></span></div>
                    <div class="dr-buy-row"><span>Seller</span><span data-f="seller"></span></div>
                    <div class="dr-buy-row total"><span>You pay</span><span data-f="price"></span></div>
                </div>
                <p class="dr-buy-note">This purchase counts toward your weekly coupon limit. The code unlocks in My Coupons right after.</p>
                <div class="dr-buy-actions">
                    <button type="button" class="dr-btn dr-btn-outline" data-act="cancel">Cancel</button>
                    <button type="button" class="dr-btn" data-act="confirm">Confirm &amp; Buy</button>
                </div>
            </div>`;
        document.body.appendChild(modal);

        const field = (n) => modal.querySelector('[data-f="' + n + '"]');
        const cancelBtn = modal.querySelector('[data-act="cancel"]');
        const confirmBtn = modal.querySelector('[data-act="confirm"]');
        let pendingForm = null;

        function isBuyForm(form) {
            return !!(form && form.querySelector && form.querySelector('input[name="action"][value="buy_listing"]'));
        }

        // Prefer data-* attributes; fall back to reading the card so older markup still works.
        function readInfo(form, btn) {
            const d = (btn && btn.dataset) || {};
            const card = form.closest(".ticket");
            const text = (sel) => {
                const n = card && card.querySelector(sel);
                return n ? n.textContent.trim() : "";
            };
            let retailer = d.retailer || "";
            if (!retailer && card) {
                const r = card.querySelector(".ticket-retailer");
                if (r && r.firstChild) retailer = r.firstChild.textContent.trim();
            }
            let seller = "";
            if (d.sellerName) {
                seller = d.sellerName + (d.sellerUser ? " (@" + d.sellerUser + ")" : "");
            } else if (text(".hut-seller-name")) {
                seller = text(".hut-seller-name") + " " + text(".hut-seller-meta");
            } else {
                seller = text(".seller-tag").replace(/^Sold by\s*/i, "");
            }
            return {
                title: d.title || text("h3"),
                retailer: retailer,
                seller: seller.trim(),
                price: d.price ? "৳" + d.price : text(".ticket-prices .now")
            };
        }

        function open(form, btn) {
            const info = readInfo(form, btn);
            pendingForm = form;
            field("title").textContent = info.title;
            field("retailer").textContent = info.retailer || "—";
            field("seller").textContent = info.seller || "—";
            field("price").textContent = info.price;
            modal.classList.add("open");
            document.body.style.overflow = "hidden";
            confirmBtn.focus();
        }

        function close() {
            modal.classList.remove("open");
            document.body.style.overflow = "";
            pendingForm = null;
            confirmBtn.disabled = false;
            confirmBtn.textContent = "Confirm & Buy";
        }

        // Capture phase => runs BEFORE the button's inline onclick (which held the native confirm()).
        document.addEventListener("click", (e) => {
            const btn = e.target.closest ? e.target.closest("button, input[type=submit]") : null;
            if (!btn) return;
            const form = btn.form || btn.closest("form");
            if (!isBuyForm(form)) return;
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            open(form, btn);
        }, true);

        // Covers Enter-key submits too.
        document.addEventListener("submit", (e) => {
            const form = e.target;
            if (!isBuyForm(form) || form.dataset.drConfirmed === "1") return;
            e.preventDefault();
            e.stopImmediatePropagation();
            open(form, form.querySelector("button[type=submit], button:not([type])"));
        }, true);

        confirmBtn.addEventListener("click", () => {
            if (!pendingForm) return;
            confirmBtn.disabled = true;            // stops a double-click buying twice
            confirmBtn.textContent = "Processing…";
            pendingForm.dataset.drConfirmed = "1";
            pendingForm.submit();                  // programmatic submit doesn't fire the submit event
        });

        cancelBtn.addEventListener("click", close);
        modal.addEventListener("click", (e) => { if (e.target === modal) close(); });
        document.addEventListener("keydown", (e) => { if (e.key === "Escape" && modal.classList.contains("open")) close(); });
        window.addEventListener("pageshow", () => {
            document.querySelectorAll('form[data-dr-confirmed="1"]').forEach((f) => delete f.dataset.drConfirmed);
            close();
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        initQtyStepper(document);
        initProfileMenu();
        initHutBuyConfirm();
    });

    return { toast, initQtyStepper, observeSentinel, closePopup };
})();
