-- ============================================================
-- Couponix: seller notifications (user bell)
-- Run in phpMyAdmin (SQL tab). Safe to re-run at any time, including
-- if you already ran an earlier version of this file.
-- The retailer bell needs nothing here: it reads pending listings
-- directly via retailer-notifications-api.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    recipient_type ENUM('user', 'retailer') NOT NULL,
    recipient_id   INT UNSIGNED NOT NULL,

    type ENUM('resale_verification_request', 'resale_verified', 'resale_rejected', 'resale_sold') NOT NULL,

    title   VARCHAR(150) NOT NULL,
    message VARCHAR(255) NOT NULL,

    listing_id INT UNSIGNED DEFAULT NULL,

    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_recipient (recipient_type, recipient_id, is_read, created_at),
    KEY idx_listing (listing_id),

    CONSTRAINT fk_notif_listing
        FOREIGN KEY (listing_id) REFERENCES coupon_listings(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If the table already existed from the earlier version, add the new 'resale_sold' type.
ALTER TABLE notifications
    MODIFY type ENUM('resale_verification_request', 'resale_verified', 'resale_rejected', 'resale_sold') NOT NULL;

DROP TRIGGER IF EXISTS trg_listing_notify_retailer;
DROP TRIGGER IF EXISTS trg_listing_notify_verification;
DELETE FROM notifications WHERE recipient_type = 'retailer';

DELIMITER $$

-- One trigger, three events, all notifying the SELLER:
--   retailer approves   -> "Your coupon is verified"
--   retailer rejects    -> "Your resale listing was rejected" (+ reason)
--   someone buys it     -> "Your coupon sold" (coupon-hut.php sets status = 'sold')
CREATE TRIGGER trg_listing_notify_verification
AFTER UPDATE ON coupon_listings
FOR EACH ROW
BEGIN
    DECLARE v_title VARCHAR(255) DEFAULT '';

    IF (OLD.verification_status <> NEW.verification_status
        AND NEW.verification_status IN ('approved', 'rejected'))
       OR (OLD.status <> 'sold' AND NEW.status = 'sold') THEN
        SELECT title INTO v_title FROM coupons WHERE id = NEW.coupon_id LIMIT 1;
    END IF;

    IF OLD.verification_status <> NEW.verification_status THEN
        IF NEW.verification_status = 'approved' THEN
            INSERT INTO notifications
                (recipient_type, recipient_id, type, title, message, listing_id)
            VALUES
                ('user', NEW.seller_id, 'resale_verified',
                 'Your coupon is verified',
                 LEFT(CONCAT('"', v_title, '" is verified and now live on Coupon Hut.'), 255),
                 NEW.id);
        ELSEIF NEW.verification_status = 'rejected' THEN
            INSERT INTO notifications
                (recipient_type, recipient_id, type, title, message, listing_id)
            VALUES
                ('user', NEW.seller_id, 'resale_rejected',
                 'Your resale listing was rejected',
                 LEFT(CONCAT('"', v_title, '" was rejected.',
                             IF(NEW.rejection_reason IS NULL OR NEW.rejection_reason = '',
                                '', CONCAT(' Reason: ', NEW.rejection_reason))), 255),
                 NEW.id);
        END IF;
    END IF;

    IF OLD.status <> 'sold' AND NEW.status = 'sold' THEN
        INSERT INTO notifications
            (recipient_type, recipient_id, type, title, message, listing_id)
        VALUES
            ('user', NEW.seller_id, 'resale_sold',
             'Your coupon sold!',
             LEFT(CONCAT('Someone bought "', v_title, '" for BDT ',
                         FORMAT(NEW.listing_price, 2), '.'), 255),
             NEW.id);
    END IF;
END$$

DELIMITER ;
