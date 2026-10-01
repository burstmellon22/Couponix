-- ============================================================
-- Couponix: automatic stock + status handling
-- Run once in phpMyAdmin (SQL tab) or the mysql client.
-- After this, coupons.quantity means REMAINING stock.
-- ============================================================

DROP TRIGGER IF EXISTS trg_purchase_stock;
DROP TRIGGER IF EXISTS trg_coupon_stock_status;

DELIMITER $$

-- 1) When a purchase goes pending -> paid (otp.php), take the units
--    out of stock. Blocks the payment if there isn't enough left.
--    Resales (coupon-hut.php) insert 'paid' rows directly, so they
--    never fire this and never touch stock. Correct: the unit was
--    already counted when the original buyer paid.
CREATE TRIGGER trg_purchase_stock
AFTER UPDATE ON coupon_purchases
FOR EACH ROW
BEGIN
    IF OLD.purchase_status <> 'paid' AND NEW.purchase_status = 'paid' THEN

        UPDATE coupons
        SET quantity = quantity - NEW.quantity
        WHERE id = NEW.coupon_id AND quantity >= NEW.quantity;

        IF ROW_COUNT() = 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'OUT_OF_STOCK';
        END IF;

    ELSEIF OLD.purchase_status = 'paid'
       AND NEW.purchase_status IN ('cancelled', 'refunded') THEN

        -- Refunded / cancelled units go back on the shelf.
        UPDATE coupons SET quantity = quantity + NEW.quantity
        WHERE id = NEW.coupon_id;
    END IF;
END$$

-- 2) Keep status in step with quantity, for purchases AND retailer edits.
--    qty hits 0            -> 'sold_out'
--    qty raised from 0     -> back to 'active' (only if it was sold_out)
--    An 'inactive' or 'expired' coupon is never auto-changed.
CREATE TRIGGER trg_coupon_stock_status
BEFORE UPDATE ON coupons
FOR EACH ROW
BEGIN
    IF NEW.quantity = 0 AND NEW.status = 'active' THEN
        SET NEW.status = 'sold_out';
    ELSEIF NEW.quantity > 0 AND OLD.quantity = 0 AND NEW.status = 'sold_out' THEN
        SET NEW.status = 'active';
    END IF;
END$$

DELIMITER ;

-- ------------------------------------------------------------
-- OPTIONAL one-time backfill (run ONCE, never twice).
-- Only needed if real purchases were already paid before these
-- triggers existed. Removes those units from stock. Resales are
-- excluded automatically (they have 'coupon_resale' transactions).
-- The seed purchases have no transactions rows, so on a fresh
-- database this changes nothing.
-- ------------------------------------------------------------
-- UPDATE coupons c
-- JOIN (
--     SELECT cp.coupon_id, SUM(cp.quantity) AS sold
--     FROM coupon_purchases cp
--     JOIN transactions t ON t.purchase_id = cp.id AND t.transaction_type = 'coupon_purchase'
--     WHERE cp.purchase_status = 'paid'
--     GROUP BY cp.coupon_id
-- ) s ON s.coupon_id = c.id
-- SET c.quantity = GREATEST(0, c.quantity - s.sold);
