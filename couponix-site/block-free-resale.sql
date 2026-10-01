-- ============================================================
-- DealRank: free (welcome bonus) coupons can't be resold.
-- Run once in phpMyAdmin (SQL tab). Safe to re-run.
-- The PHP pages already block this; this makes the DATABASE refuse it
-- too, so no page, old copy or hand-crafted request can list one.
-- ============================================================

-- 1) Clean up: cancel any free coupon that is already listed.
UPDATE coupon_listings cl
JOIN coupon_purchases cp ON cp.id = cl.purchase_id
SET cl.status = 'cancelled'
WHERE cp.total_price <= 0 AND cl.status = 'active';

-- 2) Guard: refuse new listings of free purchases.
DROP TRIGGER IF EXISTS trg_block_free_resale;

DELIMITER $$

CREATE TRIGGER trg_block_free_resale
BEFORE INSERT ON coupon_listings
FOR EACH ROW
BEGIN
    DECLARE v_total DECIMAL(10,2) DEFAULT NULL;

    IF NEW.purchase_id IS NOT NULL THEN
        SELECT total_price INTO v_total
        FROM coupon_purchases WHERE id = NEW.purchase_id LIMIT 1;

        IF v_total IS NOT NULL AND v_total <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FREE_COUPON_NOT_RESELLABLE';
        END IF;
    END IF;
END$$

DELIMITER ;
