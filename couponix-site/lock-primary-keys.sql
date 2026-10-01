-- ============================================================
-- Make primary keys immutable (users + retailers)
-- Run once on the dealrank database.
--
-- Why: every child table uses "ON UPDATE CASCADE", so changing a
-- users.id / retailers.id would silently rewrite the id across
-- coupons, cart, purchases, listings, etc. These triggers reject
-- any UPDATE that tries to change the id.
-- ============================================================

DROP TRIGGER IF EXISTS trg_users_lock_id;
DROP TRIGGER IF EXISTS trg_retailers_lock_id;

DELIMITER $$

CREATE TRIGGER trg_users_lock_id
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF NEW.id <> OLD.id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'users.id is a primary key and cannot be changed';
    END IF;
END$$

CREATE TRIGGER trg_retailers_lock_id
BEFORE UPDATE ON retailers
FOR EACH ROW
BEGIN
    IF NEW.id <> OLD.id THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'retailers.id is a primary key and cannot be changed';
    END IF;
END$$

DELIMITER ;
