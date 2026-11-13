-- =====================================================================
-- CLEAR SAMPLE DATA before the shop goes live (Session 10).  *** DESTRUCTIVE ***
-- Empties every transaction and catalog table: orders, payments, rentals, stock, items,
-- customers, students, waitlist, restock schedule, movements, images and the activity log.
-- KEEPS: settings, categories, education levels, courses, academic periods, and user accounts.
-- Back up first (tools/backup.bat). Never run this on a database holding real orders.
-- After it: delete the sample staff accounts in Users, run tools/set_password.php for the admin,
-- then add the real items in Inventory > Items. Uploaded photos in uploads/items/ must be deleted by hand.
-- =====================================================================
USE wmsu_garment_shop;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE measurements;
TRUNCATE TABLE payments;
TRUNCATE TABLE rentals;
TRUNCATE TABLE order_items;
TRUNCATE TABLE orders;
TRUNCATE TABLE inventory_movements;
TRUNCATE TABLE waitlist;
TRUNCATE TABLE restock_schedule;
TRUNCATE TABLE toga_units;
TRUNCATE TABLE item_variants;
TRUNCATE TABLE items;
TRUNCATE TABLE customers;
TRUNCATE TABLE students;
TRUNCATE TABLE audit_logs;
-- item_images exists only when migration 001 was applied
SET @has_img := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_images');
SET @sql_img := IF(@has_img > 0, 'TRUNCATE TABLE item_images', 'SELECT 1');
PREPARE s FROM @sql_img; EXECUTE s; DEALLOCATE PREPARE s;
SET FOREIGN_KEY_CHECKS = 1;

-- The slip-number counters belong to the cleared orders.
DELETE FROM settings WHERE setting_key LIKE 'order_slip_seq\_%';
UPDATE settings SET updated_by = NULL;
UPDATE settings SET setting_value = '' WHERE setting_key IN ('auto_expiry_last_run', 'auto_expiry_last_result');

-- Sample staff accounts (their password hash is a placeholder, so they cannot sign in anyway).
DELETE FROM users WHERE username IN ('staff01', 'manager') AND password_hash LIKE 'REPLACE_WITH%';
SELECT 'Sample data cleared. Now set the admin password: php tools/set_password.php admin' AS next_step;
