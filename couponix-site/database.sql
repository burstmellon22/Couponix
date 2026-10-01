-- ============================================================
-- DEALRANK — COMPLETE DATABASE + SEED DATA (v3)
-- PHP + MySQL
--
-- v3 CHANGES:
--   - New `retailers` table — real accounts (not just a text label)
--     so a retailer can log in (retailer-login.php) and approve or
--     reject resale listings of their own coupons.
--   - coupons.retailer_id (nullable FK) added alongside the existing
--     retailer_name text column — nothing that already reads
--     retailer_name breaks.
--   - coupon_listings gets verification_status ('pending' by
--     default), verified_by, verified_at, rejection_reason. A resold
--     coupon only shows up in Coupon Hut's Browse tab once a
--     retailer approves it.
--
-- v2 CHANGES (kept from before):
--   1. ranks now has min_points + a 5th tier (Silver). Points are
--      what actually drive rank-ups now — v1 had a rank_id on
--      users that was set once at signup and never changed again.
--   2. users gets a `points` column. Points are awarded in otp.php
--      the moment a payment is confirmed (see otp.php), then the
--      user's rank is recalculated against `ranks.min_points`.
--   3. coupons gets `retailer_name` and `category` — no retailer
--      table/accounts, just an honest label of who the deal is
--      from and a category to filter by.
--   4. ranks.discount_percent is now actually USED — cart.php and
--      checkout.php apply it on top of each coupon's final_price,
--      so a higher rank visibly means a cheaper price, not just a
--      badge that does nothing.
-- Import this fresh (it drops and recreates the database, same as
-- your original file did).
-- ============================================================

DROP DATABASE IF EXISTS dealrank_db;

CREATE DATABASE dealrank_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE dealrank_db;


-- ============================================================
-- 1. RANKS
-- min_points is the threshold to REACH that rank. coupon_limit is
-- the weekly purchase cap (see dashboard.php's 7-day window query).
-- discount_percent is an extra discount applied on top of every
-- coupon's own final_price — see get_rank_discounted_price() in
-- assets/functions.php.
-- ============================================================

CREATE TABLE ranks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(50) NOT NULL UNIQUE,

    min_points INT UNSIGNED NOT NULL DEFAULT 0,

    coupon_limit INT UNSIGNED NOT NULL DEFAULT 0,

    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ============================================================
-- RANK SEED DATA — 5 tiers, per the new spec
-- ============================================================

INSERT INTO ranks
    (id, name, min_points, coupon_limit, discount_percent)
VALUES
    (1, 'Bronze',    0,    3,  0.00),
    (2, 'Silver',    500,  5,  5.00),
    (3, 'Gold',      1500, 8,  10.00),
    (4, 'Platinum',  3000, 12, 15.00),
    (5, 'Ascendant', 4000, 20, 20.00);


-- ============================================================
-- 2. USERS
-- ============================================================

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    rank_id INT UNSIGNED NOT NULL DEFAULT 1,

    points INT UNSIGNED NOT NULL DEFAULT 0,

    name VARCHAR(100) NOT NULL,

    username VARCHAR(50) NOT NULL UNIQUE,

    email VARCHAR(150) NOT NULL UNIQUE,

    phone VARCHAR(30) DEFAULT NULL,

    password VARCHAR(255) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_users_rank
        FOREIGN KEY (rank_id)
        REFERENCES ranks(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- USER SEED DATA
--
-- Demo login:
-- Email: demo@gmail.com
-- Password: DealRank@123
-- ============================================================

INSERT INTO users
    (id, rank_id, points, name, username, email, phone, password)
VALUES
(
    1, 1, 120,
    'Demo User', 'demouser', 'demo@gmail.com', '01700000000',
    '$2y$12$Putjzn3iEwBlC37izdTmneFFsFoRAnv39nL9Emi4mH1lhP5pJCCrS'
),
(
    2, 3, 1620,
    'Gold User', 'golduser', 'gold@gmail.com', '01700000001',
    '$2y$12$Putjzn3iEwBlC37izdTmneFFsFoRAnv39nL9Emi4mH1lhP5pJCCrS'
);


-- ============================================================
-- 3a. RETAILERS
-- Real accounts now (not just a text label) so a retailer can log
-- in and approve/reject resale listings of their own coupons.
-- ============================================================

CREATE TABLE retailers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL,

    email VARCHAR(150) NOT NULL UNIQUE,

    password VARCHAR(255) NOT NULL,

    contact_phone VARCHAR(30) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- Demo login for every retailer below:
-- Email: <see rows> / Password: Retailer@123
INSERT INTO retailers (id, name, email, password, contact_phone) VALUES
(1,  'TasteHub Bangladesh',   'tastehub@dealrank.demo',   '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000001'),
(2,  'Rongin Fashion House',  'rongin@dealrank.demo',     '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000002'),
(3,  'GadgetKotha',           'gadgetkotha@dealrank.demo','$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000003'),
(4,  'BeanScene Cafe',        'beanscene@dealrank.demo',  '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000004'),
(5,  'CineStar Multiplex',    'cinestar@dealrank.demo',   '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000005'),
(6,  'Shohoj Rides',          'shohoj@dealrank.demo',     '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000006'),
(7,  'TazaBazar',             'tazabazar@dealrank.demo',  '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000007'),
(8,  'BoiGhor',               'boighor@dealrank.demo',    '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000008'),
(9,  'Shanti Wellness Spa',   'shanti@dealrank.demo',     '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000009'),
(10, 'GlowUp Cosmetics',      'glowup@dealrank.demo',     '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000010'),
(11, 'SliceHouse',            'slicehouse@dealrank.demo', '$2y$10$RbJWrHnKPhSnEIEUSq59X.BdsqbgX6z83QuLzNCi2KszuZKZRT4Na', '01800000011');


-- ============================================================
-- 3b. COUPONS
-- retailer_id is new (nullable FK) — added alongside the existing
-- retailer_name text column rather than replacing it, so every page
-- that already does `SELECT c.retailer_name` keeps working
-- untouched. retailer_id is what powers login/verification.
-- ============================================================

CREATE TABLE coupons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(150) NOT NULL,

    description TEXT DEFAULT NULL,

    retailer_name VARCHAR(100) NOT NULL DEFAULT 'DealRank Marketplace',

    retailer_id INT UNSIGNED DEFAULT NULL,

    category VARCHAR(60) NOT NULL DEFAULT 'General',

    coupon_code VARCHAR(100) NOT NULL UNIQUE,

    discount_type ENUM('percentage', 'fixed')
        NOT NULL DEFAULT 'percentage',

    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    original_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    final_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    quantity INT UNSIGNED NOT NULL DEFAULT 0,

    available_from DATETIME DEFAULT NULL,

    expires_at DATETIME DEFAULT NULL,

    status ENUM(
        'active',
        'inactive',
        'expired',
        'sold_out'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_coupon_retailer
        FOREIGN KEY (retailer_id)
        REFERENCES retailers(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- COUPON SEED DATA — more rows so infinite scroll has something
-- to actually scroll through.
-- ============================================================

INSERT INTO coupons
    (id, title, description, retailer_name, retailer_id, category, coupon_code, discount_type, discount_value, original_price, final_price, quantity, available_from, expires_at, status)
VALUES
(1,  'Food Festival Discount',    'Get 30% off on selected food orders.',            'TasteHub Bangladesh',  1,  'Food',        'FOOD30',    'percentage', 30.00, 1000.00, 700.00,  100, '2026-09-01 00:00:00', '2026-12-31 23:59:59', 'active'),
(2,  'Fashion Weekend Deal',      'Save 20% on selected fashion products.',          'Rongin Fashion House', 2,  'Fashion',     'FASHION20', 'percentage', 20.00, 2500.00, 2000.00, 50,  '2026-09-01 00:00:00', '2026-11-30 23:59:59', 'active'),
(3,  'Electronics Discount',      'Get a fixed discount on selected electronics.',   'GadgetKotha',           3,  'Electronics', 'TECH500',   'fixed',      500.00, 5000.00, 4500.00, 30,  '2026-09-01 00:00:00', '2026-12-15 23:59:59', 'active'),
(4,  'Coffee Special',            'Enjoy 15% off your coffee order.',                'BeanScene Cafe',        4,  'Food',        'COFFEE15',  'percentage', 15.00, 600.00,  510.00,  75,  '2026-09-01 00:00:00', '2026-10-31 23:59:59', 'active'),
(5,  'Movie Night Coupon',        'Special discount for movie tickets.',             'CineStar Multiplex',   5,  'Entertainment','MOVIE25',   'percentage', 25.00, 800.00,  600.00,  40,  '2026-09-01 00:00:00', '2026-12-20 23:59:59', 'active'),
(6,  'Ride Saver Pass',           'Flat discount on your next 5 rides.',             'Shohoj Rides',          6,  'Transport',   'RIDE250',   'fixed',      250.00, 1200.00, 950.00,  60,  '2026-09-01 00:00:00', '2026-12-31 23:59:59', 'active'),
(7,  'Grocery Bundle Offer',      '18% off on monthly grocery bundles.',             'TazaBazar',             7,  'Grocery',     'GROCERY18', 'percentage', 18.00, 3200.00, 2624.00, 90,  '2026-09-01 00:00:00', '2026-12-31 23:59:59', 'active'),
(8,  'Bookstore Discount',        'Flat ৳150 off on any book purchase over ৳500.',   'BoiGhor',               8,  'Books',       'BOOK150',   'fixed',      150.00, 700.00,  550.00,  120, '2026-09-01 00:00:00', '2026-12-31 23:59:59', 'active'),
(9,  'Spa & Wellness Deal',       '35% off on selected spa packages.',               'Shanti Wellness Spa',   9,  'Lifestyle',   'SPA35',     'percentage', 35.00, 4000.00, 2600.00, 25,  '2026-09-01 00:00:00', '2026-11-30 23:59:59', 'active'),
(10, 'Gaming Gear Discount',      'Fixed ৳800 off on gaming accessories.',           'GadgetKotha',           3,  'Electronics', 'GAME800',   'fixed',      800.00, 6000.00, 5200.00, 20,  '2026-09-01 00:00:00', '2026-12-31 23:59:59', 'active'),
(11, 'Skincare Bundle',           '22% off on skincare bundles.',                    'GlowUp Cosmetics',      10, 'Beauty',      'GLOW22',    'percentage', 22.00, 1800.00, 1404.00, 55,  '2026-09-01 00:00:00', '2026-12-31 23:59:59', 'active'),
(12, 'Pizza Combo Deal',          'Buy one get one on selected large pizzas.',       'SliceHouse',            11, 'Food',        'PIZZABOGO', 'percentage', 50.00, 1200.00, 600.00,  80,  '2026-09-01 00:00:00', '2026-11-30 23:59:59', 'active');


-- ============================================================
-- 4. CART
-- ============================================================

CREATE TABLE cart (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    coupon_id INT UNSIGNED NOT NULL,

    quantity INT UNSIGNED NOT NULL DEFAULT 1,

    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_user_coupon (user_id, coupon_id),

    CONSTRAINT fk_cart_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_cart_coupon
        FOREIGN KEY (coupon_id)
        REFERENCES coupons(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB;


INSERT INTO cart (id, user_id, coupon_id, quantity) VALUES
(1, 1, 1, 1),
(2, 1, 4, 1);


-- ============================================================
-- 5. COUPON PURCHASES
-- ============================================================

CREATE TABLE coupon_purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    coupon_id INT UNSIGNED NOT NULL,

    quantity INT UNSIGNED NOT NULL DEFAULT 1,

    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    purchase_status ENUM(
        'pending',
        'paid',
        'cancelled',
        'refunded'
    ) NOT NULL DEFAULT 'pending',

    purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_purchase_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_purchase_coupon
        FOREIGN KEY (coupon_id)
        REFERENCES coupons(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB;


INSERT INTO coupon_purchases
    (id, user_id, coupon_id, quantity, unit_price, total_price, purchase_status, purchased_at)
VALUES
(1, 1, 1, 1, 700.00,  700.00,  'paid', '2026-09-10 14:30:00'),
(2, 2, 2, 1, 2000.00, 2000.00, 'paid', '2026-09-11 16:00:00'),
(3, 1, 5, 1, 600.00,  600.00,  'paid', '2026-09-12 10:15:00');


-- ============================================================
-- 6. COUPON LISTINGS ("Coupon Hut" resale marketplace)
-- ============================================================

CREATE TABLE coupon_listings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    seller_id INT UNSIGNED NOT NULL,

    purchase_id INT UNSIGNED DEFAULT NULL,

    coupon_id INT UNSIGNED NOT NULL,

    listing_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    status ENUM(
        'active',
        'sold',
        'cancelled',
        'expired'
    ) NOT NULL DEFAULT 'active',

    verification_status ENUM(
        'pending',
        'approved',
        'rejected'
    ) NOT NULL DEFAULT 'pending',

    verified_by INT UNSIGNED DEFAULT NULL,

    verified_at DATETIME DEFAULT NULL,

    rejection_reason VARCHAR(255) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_listing_seller
        FOREIGN KEY (seller_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_listing_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES coupon_purchases(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_listing_coupon
        FOREIGN KEY (coupon_id)
        REFERENCES coupons(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_listing_verifier
        FOREIGN KEY (verified_by)
        REFERENCES retailers(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB;


-- No seed listing this time — left for the demo to create live via
-- the new Coupon Hut "Sell" form, so the flow is visibly testable.


-- ============================================================
-- 7. TRANSACTIONS
-- ============================================================

CREATE TABLE transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    purchase_id INT UNSIGNED DEFAULT NULL,

    listing_id INT UNSIGNED DEFAULT NULL,

    transaction_type ENUM(
        'coupon_purchase',
        'coupon_resale',
        'refund'
    ) NOT NULL DEFAULT 'coupon_purchase',

    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    platform_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    status ENUM(
        'pending',
        'completed',
        'failed',
        'refunded'
    ) NOT NULL DEFAULT 'pending',

    payment_method VARCHAR(50) DEFAULT NULL,

    transaction_reference VARCHAR(150) DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_transaction_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_transaction_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES coupon_purchases(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_transaction_listing
        FOREIGN KEY (listing_id)
        REFERENCES coupon_listings(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB;


INSERT INTO transactions
    (id, user_id, purchase_id, listing_id, transaction_type, amount, platform_fee, status, payment_method, transaction_reference)
VALUES
(1, 1, 1, NULL, 'coupon_purchase', 700.00,  35.00, 'completed', 'Demo Payment', 'DR-TXN-0001'),
(2, 2, 2, NULL, 'coupon_purchase', 2000.00, 100.00,'completed', 'Demo Payment', 'DR-TXN-0002'),
(3, 1, 3, NULL, 'coupon_purchase', 600.00,  30.00, 'completed', 'Demo Payment', 'DR-TXN-0003');


-- ============================================================
-- 8. REDEMPTIONS
-- ============================================================

CREATE TABLE redemptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    purchase_id INT UNSIGNED NOT NULL,

    coupon_id INT UNSIGNED NOT NULL,

    redemption_code VARCHAR(100) DEFAULT NULL,

    status ENUM(
        'pending',
        'redeemed',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',

    redeemed_at DATETIME DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_redemption_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_redemption_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES coupon_purchases(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_redemption_coupon
        FOREIGN KEY (coupon_id)
        REFERENCES coupons(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB;


INSERT INTO redemptions
    (id, user_id, purchase_id, coupon_id, redemption_code, status, redeemed_at)
VALUES
(1, 1, 1, 1, 'DR-REDEEM-0001', 'redeemed', '2026-09-11 18:30:00');


-- ============================================================
-- 9. REVIEWS
-- ============================================================

CREATE TABLE reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id INT UNSIGNED NOT NULL,

    coupon_id INT UNSIGNED NOT NULL,

    purchase_id INT UNSIGNED DEFAULT NULL,

    rating TINYINT UNSIGNED NOT NULL,

    comment TEXT DEFAULT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_review_rating
        CHECK (rating >= 1 AND rating <= 5),

    CONSTRAINT fk_review_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_review_coupon
        FOREIGN KEY (coupon_id)
        REFERENCES coupons(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_review_purchase
        FOREIGN KEY (purchase_id)
        REFERENCES coupon_purchases(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB;


INSERT INTO reviews
    (id, user_id, coupon_id, purchase_id, rating, comment)
VALUES
(1, 1, 1, 1, 5, 'Great coupon and easy to use.'),
(2, 2, 2, 2, 4, 'Good discount and useful offer.');


-- ============================================================
-- INDEXES
-- ============================================================

CREATE INDEX idx_users_rank ON users(rank_id);
CREATE INDEX idx_cart_user ON cart(user_id);
CREATE INDEX idx_cart_coupon ON cart(coupon_id);
CREATE INDEX idx_purchase_user ON coupon_purchases(user_id);
CREATE INDEX idx_purchase_coupon ON coupon_purchases(coupon_id);
CREATE INDEX idx_listing_seller ON coupon_listings(seller_id);
CREATE INDEX idx_listing_coupon ON coupon_listings(coupon_id);
CREATE INDEX idx_listing_status ON coupon_listings(status);
CREATE INDEX idx_transaction_user ON transactions(user_id);
CREATE INDEX idx_redemption_user ON redemptions(user_id);
CREATE INDEX idx_redemption_coupon ON redemptions(coupon_id);
CREATE INDEX idx_review_user ON reviews(user_id);
CREATE INDEX idx_review_coupon ON reviews(coupon_id);
CREATE INDEX idx_coupons_category ON coupons(category);
CREATE INDEX idx_coupons_retailer ON coupons(retailer_id);
CREATE INDEX idx_listing_verification ON coupon_listings(verification_status);
CREATE INDEX idx_retailers_email ON retailers(email);

-- ============================================================
-- END OF DEALRANK DATABASE (v2)
-- ============================================================
