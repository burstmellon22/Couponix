<?php
session_start();

if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$ranks = $conn->query("SELECT * FROM ranks ORDER BY min_points ASC")->fetch_all(MYSQLI_ASSOC);

$retailers = $conn->query("
    SELECT r.id, r.name, COUNT(c.id) AS coupon_count
    FROM retailers r
    LEFT JOIN coupons c ON c.retailer_id = r.id AND c.status = 'active'
    GROUP BY r.id, r.name
    ORDER BY coupon_count DESC
")->fetch_all(MYSQLI_ASSOC);

$featured = $conn->query("
    SELECT * FROM coupons
    WHERE status = 'active' AND quantity > 0
    ORDER BY RAND() LIMIT 3
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Couponix — Coupons that get better the more you use them</title>
<?php theme_boot_script(); ?>
<link rel="stylesheet" href="assets/style.css">
<style>
.hero {
    background: radial-gradient(circle at 20% 20%, var(--brand-glow), transparent 45%),
                radial-gradient(circle at 85% 60%, var(--brand-tint), transparent 50%),
                linear-gradient(160deg, var(--bg-soft) 0%, var(--bg) 100%);
    color: var(--text);
    padding: 90px 6% 110px;
    text-align: center;
    position: relative;
    overflow: hidden;
    border-bottom: 1px solid var(--border);
}
.hero::before {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 15% 20%, var(--brand-tint), transparent 40%),
                radial-gradient(circle at 85% 75%, var(--brand-tint), transparent 45%);
}
.hero-inner { position: relative; max-width: 720px; margin: 0 auto; }
.hero h1 { color: var(--text); font-size: 44px; margin-bottom: 16px; }
.hero p { color: var(--text-dim); font-size: 17px; max-width: 560px; margin: 0 auto 30px; }
.hero-ctas { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
.hero .dr-btn-outline { background: transparent; border-color: var(--border-strong); color: var(--text); }
.hero .dr-btn-outline:hover { background: var(--brand-tint); border-color: var(--brand); color: var(--text); }

/* Decorative side art — coupon/shopping motifs flanking the hero
   copy. Pure SVG (no hotlinked photos), so nothing to break and it
   already matches the brand palette. Hidden below ~1150px so it
   never competes with the headline on tablet/mobile. */
.hero-side-art {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 220px;
    opacity: .9;
    pointer-events: none;
}
.hero-side-art.left { left: 3%; }
.hero-side-art.right { right: 3%; }
.hero-side-art svg { width: 100%; height: auto; filter: drop-shadow(0 12px 24px rgba(0,0,0,.35)); }
@media (max-width: 1150px) {
    .hero-side-art { display: none; }
}

.section { padding: 70px 6%; }
.section-head { text-align: center; max-width: 620px; margin: 0 auto 42px; }

.steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; max-width: 1000px; margin: 0 auto; }
.step { text-align: center; padding: 28px 20px; }
.step-num {
    width: 44px; height: 44px; border-radius: 50%; background: var(--brand-tint); color: var(--brand);
    display: flex; align-items: center; justify-content: center; font-weight: 800; margin: 0 auto 14px; font-family: var(--font-heading);
}

.tier-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; max-width: 1100px; margin: 0 auto; }
.tier-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px 20px; text-align: center; }
.tier-card .rank-badge { margin-bottom: 14px; }
.tier-stat { font-size: 13px; color: var(--text-dim); margin-top: 6px; }
.tier-stat strong { color: var(--text); }

.featured-wrap { max-width: 1100px; margin: 0 auto; }

.retailer-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; max-width: 1000px; margin: 0 auto; }
.retailer-chip { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; }
.retailer-chip strong { display: block; font-size: 14px; margin-bottom: 4px; }
.retailer-chip span { font-size: 12px; color: var(--text-mute); }

.cta-band {
    background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%); color: var(--on-brand); text-align: center; padding: 60px 6%; border-radius: var(--radius-lg);
    max-width: 1100px; margin: 0 auto 70px;
}
.cta-band h2 { color: var(--on-brand); }
.cta-band p { color: var(--on-brand); opacity: .85; margin-bottom: 22px; }
.cta-band .dr-btn { background: var(--on-brand); color: var(--brand-dark); }
.cta-band .dr-btn:hover { background: var(--on-brand); opacity: .9; }

footer { text-align: center; padding: 30px; color: var(--text-mute); font-size: 13px; }

@media (max-width: 800px) {
    .steps, .hero h1 { grid-template-columns: 1fr; }
    .hero h1 { font-size: 32px; }
}
</style>
</head>
<body>

<nav class="dr-nav">
    <a href="index.php" class="dr-logo">Coupon<span>ix</span></a>
    <div class="dr-nav-links">
        <a href="login.php">Login</a>
        <a href="register.php" class="active">Register</a>
        <a href="retailer-login.php" style="color:var(--text-mute);">Retailer Login</a>
    </div>
</nav>

<section class="hero">

    <div class="hero-side-art left" aria-hidden="true">
        <svg viewBox="0 0 200 260" xmlns="http://www.w3.org/2000/svg">
            <g transform="rotate(-8 100 130)">
                <rect x="20" y="40" width="160" height="100" rx="14" stroke-width="2" style="fill:var(--surface);stroke:var(--brand)"/>
                <circle cx="20" cy="90" r="10" style="fill:var(--bg)"/>
                <circle cx="180" cy="90" r="10" style="fill:var(--bg)"/>
                <line x1="34" y1="40" x2="34" y2="140" stroke-width="2" stroke-dasharray="4 5" style="stroke:var(--border-strong)"/>
                <text x="55" y="80" font-family="Poppins, sans-serif" font-weight="800" font-size="26" style="fill:var(--brand)">30%</text>
                <text x="55" y="104" font-family="Inter, sans-serif" font-size="11" letter-spacing="1" style="fill:var(--text-mute)">OFF TODAY</text>
            </g>
            <g transform="rotate(10 100 200)">
                <rect x="35" y="170" width="130" height="70" rx="12" stroke-width="2" style="fill:var(--bg-soft);stroke:var(--border-strong)"/>
                <circle cx="35" cy="205" r="8" style="fill:var(--bg)"/>
                <circle cx="165" cy="205" r="8" style="fill:var(--bg)"/>
                <text x="52" y="215" font-family="'Courier New', monospace" font-weight="700" font-size="15" letter-spacing="2" style="fill:var(--brand-text)">FOOD30</text>
            </g>
        </svg>
    </div>

    <div class="hero-side-art right" aria-hidden="true">
        <svg viewBox="0 0 200 260" xmlns="http://www.w3.org/2000/svg">
            <g transform="rotate(9 100 110)">
                <path d="M55 60 L145 60 L155 100 L45 100 Z" stroke-width="2" style="fill:var(--surface);stroke:var(--brand)"/>
                <path d="M45 100 L155 100 L145 180 L55 180 Z" stroke-width="2" style="fill:var(--surface);stroke:var(--brand)"/>
                <path d="M75 60 Q75 35 100 35 Q125 35 125 60" fill="none" stroke-width="6" stroke-linecap="round" style="stroke:var(--brand)"/>
                <text x="70" y="145" font-family="Poppins, sans-serif" font-weight="800" font-size="22" style="fill:var(--brand)">৳</text>
            </g>
            <g transform="rotate(-12 100 215)">
                <circle cx="100" cy="215" r="38" stroke-width="2" style="fill:var(--bg-soft);stroke:var(--success)"/>
                <text x="82" y="223" font-family="Poppins, sans-serif" font-weight="800" font-size="18" style="fill:var(--success)">✓</text>
            </g>
        </svg>
    </div>

    <div class="hero-inner">
        <h1>Coupons that get better the more you use them.</h1>
        <p>Couponix ranks you up from Bronze to Ascendant as you shop — every rank unlocks a bigger discount, more weekly claims, and access to Coupon Hut, our user-to-user resale marketplace.</p>
        <div class="hero-ctas">
            <a href="register.php" class="dr-btn">Get Started Free</a>
            <a href="login.php" class="dr-btn dr-btn-outline">I already have an account</a>
        </div>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2>How it works</h2>
        <p>Three steps between you and better deals.</p>
    </div>
    <div class="steps">
        <div class="step">
            <div class="step-num">1</div>
            <h3>Shop coupons</h3>
            <p>Browse live deals across food, fashion, electronics and more.</p>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <h3>Earn points, rank up</h3>
            <p>Every purchase earns points automatically. Hit a threshold, your rank updates instantly.</p>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <h3>Unlock better deals</h3>
            <p>Higher ranks stack an extra discount on every coupon, plus a higher weekly claim limit.</p>
        </div>
    </div>
</section>

<section class="section" style="background:var(--bg-soft);">
    <div class="section-head">
        <h2>Five ranks, real benefits</h2>
        <p>Pulled live from the database — not marketing copy.</p>
    </div>
    <div class="tier-grid">
        <?php foreach ($ranks as $rank): ?>
            <div class="tier-card">
                <span class="rank-badge rank-<?= h($rank['name']) ?>"><?= h($rank['name']) ?></span>
                <div class="tier-stat"><strong><?= (int) $rank['min_points'] ?>+</strong> points to reach</div>
                <div class="tier-stat"><strong><?= (int) $rank['coupon_limit'] ?></strong> coupons / week</div>
                <div class="tier-stat">extra <strong><?= number_format((float) $rank['discount_percent'], 0) ?>%</strong> off every deal</div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <h2>A few deals live right now</h2>
        <p>Sign up to see the full board, claim coupons, and start earning points.</p>
    </div>
    <div class="featured-wrap">
        <div class="ticket-grid">
            <?php foreach ($featured as $c): $saving = max(0, (float)$c['original_price'] - (float)$c['final_price']); ?>
                <div class="ticket">
                    <div class="ticket-main">
                        <div class="ticket-retailer"><?= h($c['retailer_name']) ?><span class="ticket-category"><?= h($c['category']) ?></span></div>
                        <h3><?= h($c['title']) ?></h3>
                        <div class="ticket-desc"><?= h($c['description']) ?></div>
                        <div class="ticket-prices">
                            <span class="was">৳<?= number_format((float)$c['original_price'], 2) ?></span>
                            <span class="now">৳<?= number_format((float)$c['final_price'], 2) ?></span>
                            <span class="save">You save ৳<?= number_format($saving, 2) ?></span>
                        </div>
                    </div>
                    <div class="ticket-stub">
                        <div class="ticket-code locked"><?= h($c['coupon_code']) ?></div>
                        <div class="ticket-lock-hint">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                            Sign up to reveal
                        </div>
                        <div class="ticket-stock"><strong><?= (int)$c['quantity'] ?></strong> left</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" style="background:var(--bg-soft);">
    <div class="section-head">
        <h2>Real retailers, real verification</h2>
        <p>Every resale on Coupon Hut is reviewed by the retailer before it goes live — not just posted straight from one member to another.</p>
    </div>
    <div class="retailer-grid">
        <?php foreach ($retailers as $r): ?>
            <div class="retailer-chip">
                <strong><?= h($r["name"]) ?></strong>
                <span><?= (int) $r["coupon_count"] ?> active deal<?= $r["coupon_count"] == 1 ? '' : 's' ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <p style="text-align:center;margin-top:24px;">
        <a href="retailer-login.php" style="font-weight:700;">Run one of these businesses? Log in as a retailer →</a>
    </p>
</section>

<div class="cta-band">
    <h2>Ready to start ranking up?</h2>
    <p>It takes less than a minute, and your first coupon is on us.</p>
    <a href="register.php" class="dr-btn">Create your free account</a>
</div>

<footer>Couponix — a student project. Demo payments only, nothing here is real money.</footer>

</body>
</html>
