-- =====================================================================
-- Migration 008 (Session 10): indexes for the list pages, the rate limits and the login throttle.
-- No table or column is changed. Safe to run twice and on both MySQL and MariaDB: an index that
-- already exists, or one whose columns are missing (migration not applied yet), is skipped.
-- Import in phpMyAdmin: select the database, Import, choose this file.
-- =====================================================================
USE wmsu_garment_shop;

DROP PROCEDURE IF EXISTS s10_add_index;
DELIMITER $$
CREATE PROCEDURE s10_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255))
BEGIN
  DECLARE v_wanted INT;
  DECLARE v_have_cols INT;
  DECLARE v_exists INT;
  SET v_wanted = 1 + LENGTH(p_columns) - LENGTH(REPLACE(p_columns, ',', ''));
  SELECT COUNT(*) INTO v_have_cols FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND FIND_IN_SET(COLUMN_NAME, p_columns) > 0;
  SELECT COUNT(*) INTO v_exists FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index;
  IF v_exists = 0 AND v_have_cols = v_wanted THEN
    SET @s10_sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
    PREPARE s10_stmt FROM @s10_sql;
    EXECUTE s10_stmt;
    DEALLOCATE PREPARE s10_stmt;
  END IF;
END$$
DELIMITER ;

-- Staff order list: WHERE deleted_at IS NULL [AND status = ?] ORDER BY created_at DESC
CALL s10_add_index('orders', 'idx_orders_list', 'deleted_at,status,created_at');
-- Movement history and reports filter and sort by date and type
CALL s10_add_index('inventory_movements', 'idx_movements_created', 'created_at');
CALL s10_add_index('inventory_movements', 'idx_movements_type_created', 'movement_type,created_at');
-- Login lock-out and public rate limits count recent rows by action, key and IP
CALL s10_add_index('audit_logs', 'idx_audit_action_created', 'action,created_at');
CALL s10_add_index('audit_logs', 'idx_audit_ip_created', 'ip_address,created_at');
CALL s10_add_index('audit_logs', 'idx_audit_rate', 'entity_type,entity_id,action,created_at');
-- Waitlist counts per size and status (needs migration 005 for the status column)
CALL s10_add_index('waitlist', 'idx_waitlist_variant_status', 'variant_id,status');
CALL s10_add_index('waitlist', 'idx_waitlist_status_created', 'status,created_at');
-- Checkout looks up a returning customer by phone and name
CALL s10_add_index('customers', 'idx_customers_contact_name', 'contact_no,full_name');

DROP PROCEDURE s10_add_index;

-- Setting read by checkout.php (Session 10): orders one device may place per hour (0 = no limit)
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, description)
VALUES ('public_orders_per_ip_hour', '10', 'int',
        'Online reservations one device (IP address) may submit per hour; 0 = no limit. Stops one person holding all stock.');
