-- =====================================================================
--  WMSU Garment Shop - Online Uniform and Toga Ordering/Reserving System
--  Database script for XAMPP (MySQL / MariaDB 10.4+, InnoDB, utf8mb4)
--  Backend: PHP (PDO / mysqli)
--
--  HOW TO IMPORT
--    phpMyAdmin : http://localhost/phpmyadmin -> Import -> choose this file -> Go
--    Command line: C:\xampp\mysql\bin\mysql.exe -u root < wmsu_garment_shop.sql
--
--  NOTES
--    * Creates database `wmsu_garment_shop` (existing database of the same
--      name is NOT dropped; tables use IF NOT EXISTS-safe order but the
--      seed section assumes an empty database).
--    * Stored procedures use DELIMITER $$ (supported by phpMyAdmin import).
--    * The scheduled jobs are MySQL EVENTS. XAMPP starts with the event
--      scheduler OFF. Enable it with:  SET GLOBAL event_scheduler = ON;
--      (add  event_scheduler=ON  under [mysqld] in my.ini to keep it on),
--      OR call the procedures from a Windows Task Scheduler / cron PHP script.
--    * All prices, sizes, stock figures and names in the seed data are
--      SAMPLE values for testing only. Verify real values with the shop.
--    * Seed users have a placeholder password hash. Replace it with a hash
--      from PHP:  password_hash('your-password', PASSWORD_DEFAULT)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS wmsu_garment_shop
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wmsu_garment_shop;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. STAFF / ADMIN LOGINS (customers never log in)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(50)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(120) NOT NULL,
  email          VARCHAR(120) NULL,
  role           ENUM('staff','manager','admin') NOT NULL DEFAULT 'staff',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. REFERENCE DATA
-- ---------------------------------------------------------------------
CREATE TABLE education_levels (
  education_level_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20) NOT NULL,
  name        VARCHAR(60) NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (education_level_id),
  UNIQUE KEY uq_education_levels_code (code)
) ENGINE=InnoDB;

CREATE TABLE courses (
  course_id  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(150) NOT NULL,
  college    VARCHAR(150) NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (course_id),
  UNIQUE KEY uq_courses_code (code)
) ENGINE=InnoDB;

CREATE TABLE categories (
  category_id  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(80)  NOT NULL,
  description  VARCHAR(255) NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

CREATE TABLE academic_periods (
  academic_period_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_year  VARCHAR(9) NOT NULL,                    -- e.g. 2026-2027
  semester     ENUM('1st','2nd','summer') NOT NULL,
  start_date   DATE NOT NULL,
  end_date     DATE NOT NULL,
  is_current   TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (academic_period_id),
  UNIQUE KEY uq_academic_periods (school_year, semester),
  CONSTRAINT chk_period_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. GUEST CUSTOMERS AND STUDENTS (no accounts)
--    customers = the person ordering (student, parent or guardian)
--    students  = the student the garments are for (ID no. or LRN)
-- ---------------------------------------------------------------------
CREATE TABLE customers (
  customer_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(120) NOT NULL,
  contact_no    VARCHAR(20)  NOT NULL,
  email         VARCHAR(120) NULL,
  address       VARCHAR(255) NULL,
  relationship  ENUM('self','parent','guardian','other') NOT NULL DEFAULT 'self',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id),
  KEY idx_customers_contact_no (contact_no),
  KEY idx_customers_email (email)
) ENGINE=InnoDB;

CREATE TABLE students (
  student_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_no          VARCHAR(30)  NOT NULL,           -- student ID or LRN
  full_name           VARCHAR(120) NOT NULL,
  education_level_id  TINYINT UNSIGNED NULL,
  course_id           SMALLINT UNSIGNED NULL,
  year_level          VARCHAR(20)  NULL,               -- e.g. "3rd Year", "Grade 5"
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  UNIQUE KEY uq_students_student_no (student_no),
  KEY idx_students_level (education_level_id),
  KEY idx_students_course (course_id),
  CONSTRAINT fk_students_level  FOREIGN KEY (education_level_id) REFERENCES education_levels (education_level_id),
  CONSTRAINT fk_students_course FOREIGN KEY (course_id) REFERENCES courses (course_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. CATALOG AND STOCK
-- ---------------------------------------------------------------------
CREATE TABLE items (
  item_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id          SMALLINT UNSIGNED NOT NULL,
  education_level_id   TINYINT UNSIGNED NULL,
  course_id            SMALLINT UNSIGNED NULL,
  item_code            VARCHAR(30)  NOT NULL,
  name                 VARCHAR(150) NOT NULL,
  description          VARCHAR(500) NULL,
  item_type            ENUM('ready_made','made_to_order','rental','alteration') NOT NULL,
  reservation_mode     ENUM('auto','approval','none') NOT NULL DEFAULT 'approval',
  max_qty_per_request  TINYINT UNSIGNED NOT NULL DEFAULT 3,   -- online safety cap; staff may override
  tracks_stock         TINYINT(1) NOT NULL DEFAULT 1,         -- 0 for made-to-order / alteration
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id),
  UNIQUE KEY uq_items_code (item_code),
  KEY idx_items_category (category_id),
  KEY idx_items_level (education_level_id),
  KEY idx_items_course (course_id),
  CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories (category_id),
  CONSTRAINT fk_items_level    FOREIGN KEY (education_level_id) REFERENCES education_levels (education_level_id),
  CONSTRAINT fk_items_course   FOREIGN KEY (course_id) REFERENCES courses (course_id),
  CONSTRAINT chk_items_max_qty CHECK (max_qty_per_request >= 1)
) ENGINE=InnoDB;

CREATE TABLE item_variants (
  variant_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id             INT UNSIGNED NOT NULL,
  size_label          VARCHAR(20)  NOT NULL,
  sku                 VARCHAR(40)  NOT NULL,
  unit_price          DECIMAL(10,2) NOT NULL,
  quantity_on_hand    INT NOT NULL DEFAULT 0,
  quantity_reserved   INT NOT NULL DEFAULT 0,
  quantity_available  INT AS (quantity_on_hand - quantity_reserved) VIRTUAL,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (variant_id),
  UNIQUE KEY uq_variants_sku (sku),
  UNIQUE KEY uq_variants_item_size (item_id, size_label),
  CONSTRAINT fk_variants_item FOREIGN KEY (item_id) REFERENCES items (item_id),
  CONSTRAINT chk_variant_price     CHECK (unit_price >= 0),
  CONSTRAINT chk_variant_on_hand   CHECK (quantity_on_hand >= 0),
  CONSTRAINT chk_variant_reserved  CHECK (quantity_reserved >= 0),
  CONSTRAINT chk_variant_res_le_oh CHECK (quantity_reserved <= quantity_on_hand)
) ENGINE=InnoDB;

-- OPTIONAL EXTENSION: per-unit toga tracking (quantity-based stock works without it)
CREATE TABLE toga_units (
  toga_unit_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  variant_id       INT UNSIGNED NOT NULL,
  unit_code        VARCHAR(30) NOT NULL,
  status           ENUM('available','reserved','rented_out','maintenance','retired') NOT NULL DEFAULT 'available',
  condition_notes  VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (toga_unit_id),
  UNIQUE KEY uq_toga_units_code (unit_code),
  KEY idx_toga_units_variant (variant_id),
  CONSTRAINT fk_toga_units_variant FOREIGN KEY (variant_id) REFERENCES item_variants (variant_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. ORDERS (the digital Order Slip)
-- ---------------------------------------------------------------------
CREATE TABLE orders (
  order_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no             VARCHAR(20) NOT NULL,           -- Order Slip No. (staff-facing)
  order_ref            VARCHAR(16) NOT NULL,           -- public tracking reference (customer-facing)
  order_type           ENUM('purchase','reservation','rental') NOT NULL,
  status               ENUM('requested','approved','slip_issued','paid','claimed',
                            'declined','expired','cancelled','unclaimed') NOT NULL DEFAULT 'requested',
  source               ENUM('online','walk_in') NOT NULL DEFAULT 'online',
  customer_id          BIGINT UNSIGNED NOT NULL,
  student_id           BIGINT UNSIGNED NULL,
  academic_period_id   INT UNSIGNED NULL,
  created_by           INT UNSIGNED NULL,              -- staff who encoded a walk-in order
  measured_by          INT UNSIGNED NULL,              -- "Measure/Cutter" on the slip
  checked_by           INT UNSIGNED NULL,              -- "Checked by"
  oic_manager_id       INT UNSIGNED NULL,              -- "OIC-Manager" sign-off
  received_by          VARCHAR(120) NULL,              -- "Received by" (person who claims the items)
  approved_by          INT UNSIGNED NULL,              -- NULL when auto-approved
  approved_at          DATETIME NULL,
  declined_by          INT UNSIGNED NULL,
  declined_at          DATETIME NULL,
  decline_reason       VARCHAR(255) NULL,
  slip_issued_at       DATETIME NULL,
  paid_at              DATETIME NULL,
  claimed_at           DATETIME NULL,
  pickup_date          DATE NULL,
  expires_at           DATETIME NULL,                  -- end of the unpaid hold
  claim_deadline       DATETIME NULL,                  -- end of claim period after payment
  unclaimed_flagged_at DATETIME NULL,
  total_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  remarks              VARCHAR(500) NULL,
  deleted_at           DATETIME NULL,                  -- recycle bin (soft delete)
  deleted_by           INT UNSIGNED NULL,
  purge_after          DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (order_id),
  UNIQUE KEY uq_orders_order_no (order_no),
  UNIQUE KEY uq_orders_order_ref (order_ref),
  KEY idx_orders_status_expires (status, expires_at),
  KEY idx_orders_status_claim (status, claim_deadline),
  KEY idx_orders_recycle (deleted_at, purge_after),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_student (student_id),
  KEY idx_orders_period (academic_period_id),
  KEY idx_orders_created_at (created_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id),
  CONSTRAINT fk_orders_student  FOREIGN KEY (student_id) REFERENCES students (student_id),
  CONSTRAINT fk_orders_period   FOREIGN KEY (academic_period_id) REFERENCES academic_periods (academic_period_id),
  CONSTRAINT fk_orders_created_by  FOREIGN KEY (created_by)  REFERENCES users (user_id),
  CONSTRAINT fk_orders_measured_by FOREIGN KEY (measured_by) REFERENCES users (user_id),
  CONSTRAINT fk_orders_checked_by  FOREIGN KEY (checked_by)  REFERENCES users (user_id),
  CONSTRAINT fk_orders_oic         FOREIGN KEY (oic_manager_id) REFERENCES users (user_id),
  CONSTRAINT fk_orders_approved_by FOREIGN KEY (approved_by) REFERENCES users (user_id),
  CONSTRAINT fk_orders_declined_by FOREIGN KEY (declined_by) REFERENCES users (user_id),
  CONSTRAINT fk_orders_deleted_by  FOREIGN KEY (deleted_by)  REFERENCES users (user_id),
  CONSTRAINT chk_orders_total CHECK (total_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE order_items (
  order_item_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id       BIGINT UNSIGNED NOT NULL,
  variant_id     INT UNSIGNED NOT NULL,
  item_name      VARCHAR(150) NOT NULL,               -- snapshot
  size_label     VARCHAR(20)  NOT NULL,               -- snapshot
  quantity       INT NOT NULL,
  unit_price     DECIMAL(10,2) NOT NULL,              -- price snapshot at order time
  line_total     DECIMAL(12,2) NOT NULL,              -- snapshot: quantity * unit_price
  stock_held     TINYINT(1) NOT NULL DEFAULT 0,       -- 1 while this line holds reserved stock
  remarks        VARCHAR(255) NULL,
  PRIMARY KEY (order_item_id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_variant (variant_id),
  CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id)   REFERENCES orders (order_id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES item_variants (variant_id),
  CONSTRAINT chk_order_items_qty   CHECK (quantity > 0),
  CONSTRAINT chk_order_items_price CHECK (unit_price >= 0),
  CONSTRAINT chk_order_items_total CHECK (line_total = quantity * unit_price)
) ENGINE=InnoDB;

-- Made-to-order only: one row per measurement taken for an order line
CREATE TABLE measurements (
  measurement_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_item_id   BIGINT UNSIGNED NOT NULL,
  measure_name    VARCHAR(40) NOT NULL,               -- e.g. waist, hip, length
  measure_value   DECIMAL(6,2) NOT NULL,
  unit            ENUM('cm','in') NOT NULL DEFAULT 'in',
  measured_by     INT UNSIGNED NULL,
  measured_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (measurement_id),
  UNIQUE KEY uq_measurements_item_name (order_item_id, measure_name),
  CONSTRAINT fk_measurements_item FOREIGN KEY (order_item_id) REFERENCES order_items (order_item_id) ON DELETE CASCADE,
  CONSTRAINT fk_measurements_user FOREIGN KEY (measured_by) REFERENCES users (user_id),
  CONSTRAINT chk_measure_value CHECK (measure_value > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. PAYMENT RECORDS (entered by staff from the cashier's proof of payment)
--    amount_paid   = total amount on the OR
--    deposit_amount = the part of amount_paid that is a refundable deposit
-- ---------------------------------------------------------------------
CREATE TABLE payments (
  payment_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id        BIGINT UNSIGNED NOT NULL,
  or_no           VARCHAR(30) NOT NULL,
  amount_paid     DECIMAL(10,2) NOT NULL,
  deposit_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  paid_at         DATETIME NOT NULL,
  verified_by     INT UNSIGNED NOT NULL,
  remarks         VARCHAR(255) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (payment_id),
  UNIQUE KEY uq_payments_or_no (or_no),
  KEY idx_payments_order (order_id),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (order_id),
  CONSTRAINT fk_payments_user  FOREIGN KEY (verified_by) REFERENCES users (user_id),
  CONSTRAINT chk_payments_amount  CHECK (amount_paid > 0),
  CONSTRAINT chk_payments_deposit CHECK (deposit_amount >= 0 AND deposit_amount <= amount_paid)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. TOGA RENTALS
--    MySQL/MariaDB have no partial unique index, so a generated column
--    `active_key` is 1 while the rental is active and NULL otherwise.
--    UNIQUE (student_id, academic_period_id, active_key) then allows only
--    ONE active rental per student per academic period (NULLs never clash).
-- ---------------------------------------------------------------------
CREATE TABLE rentals (
  rental_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_item_id       BIGINT UNSIGNED NOT NULL,
  student_id          BIGINT UNSIGNED NOT NULL,
  academic_period_id  INT UNSIGNED NOT NULL,
  variant_id          INT UNSIGNED NOT NULL,
  toga_unit_id        INT UNSIGNED NULL,                -- optional per-unit tracking
  status              ENUM('reserved','released','returned','overdue','cancelled') NOT NULL DEFAULT 'reserved',
  released_at         DATETIME NULL,
  due_date            DATE NULL,                        -- defaults to end of the academic period
  returned_at         DATETIME NULL,
  condition_on_return ENUM('good','needs_cleaning','damaged','lost') NULL,
  rental_fee          DECIMAL(10,2) NULL,               -- UNCONFIRMED pricing model
  deposit_amount      DECIMAL(10,2) NULL,               -- UNCONFIRMED
  penalty_amount      DECIMAL(10,2) NULL,               -- UNCONFIRMED
  deposit_returned    TINYINT(1) NULL,                  -- UNCONFIRMED
  remarks             VARCHAR(255) NULL,
  active_key          TINYINT AS (CASE WHEN status IN ('reserved','released','overdue') THEN 1 ELSE NULL END) STORED,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rental_id),
  UNIQUE KEY uq_rentals_order_item (order_item_id),
  UNIQUE KEY uq_rentals_one_active (student_id, academic_period_id, active_key),
  KEY idx_rentals_status_due (status, due_date),
  KEY idx_rentals_variant (variant_id),
  CONSTRAINT fk_rentals_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (order_item_id) ON DELETE CASCADE,
  CONSTRAINT fk_rentals_student    FOREIGN KEY (student_id) REFERENCES students (student_id),
  CONSTRAINT fk_rentals_period     FOREIGN KEY (academic_period_id) REFERENCES academic_periods (academic_period_id),
  CONSTRAINT fk_rentals_variant    FOREIGN KEY (variant_id) REFERENCES item_variants (variant_id),
  CONSTRAINT fk_rentals_unit       FOREIGN KEY (toga_unit_id) REFERENCES toga_units (toga_unit_id),
  CONSTRAINT chk_rentals_amounts CHECK (COALESCE(rental_fee,0) >= 0 AND COALESCE(deposit_amount,0) >= 0 AND COALESCE(penalty_amount,0) >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. INVENTORY LOG, RESTOCK SCHEDULE, WAITLIST
--    on_hand_delta / reserved_delta are signed changes applied to the variant.
--    user_id NULL = done by the system (auto-approval, scheduled job).
-- ---------------------------------------------------------------------
CREATE TABLE inventory_movements (
  movement_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  variant_id     INT UNSIGNED NOT NULL,
  movement_type  ENUM('restock','reserved','released','sold','rented_out','returned','adjustment') NOT NULL,
  on_hand_delta  INT NOT NULL DEFAULT 0,
  reserved_delta INT NOT NULL DEFAULT 0,
  order_id       BIGINT UNSIGNED NULL,
  user_id        INT UNSIGNED NULL,
  reason         VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (movement_id),
  KEY idx_movements_variant (variant_id, created_at),
  KEY idx_movements_order (order_id),
  KEY idx_movements_user (user_id),
  CONSTRAINT fk_movements_variant FOREIGN KEY (variant_id) REFERENCES item_variants (variant_id),
  CONSTRAINT fk_movements_order   FOREIGN KEY (order_id) REFERENCES orders (order_id) ON DELETE SET NULL,
  CONSTRAINT fk_movements_user    FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE restock_schedule (
  restock_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  variant_id     INT UNSIGNED NULL,
  item_id        INT UNSIGNED NULL,
  expected_date  DATE NOT NULL,
  expected_qty   INT NULL,
  note           VARCHAR(255) NULL,
  status         ENUM('scheduled','delivered','delayed') NOT NULL DEFAULT 'scheduled',
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (restock_id),
  KEY idx_restock_variant (variant_id),
  KEY idx_restock_item (item_id),
  KEY idx_restock_status_date (status, expected_date),
  CONSTRAINT fk_restock_variant FOREIGN KEY (variant_id) REFERENCES item_variants (variant_id),
  CONSTRAINT fk_restock_item    FOREIGN KEY (item_id) REFERENCES items (item_id),
  CONSTRAINT fk_restock_user    FOREIGN KEY (created_by) REFERENCES users (user_id),
  CONSTRAINT chk_restock_target CHECK (variant_id IS NOT NULL OR item_id IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE waitlist (
  waitlist_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  variant_id    INT UNSIGNED NOT NULL,
  contact_name  VARCHAR(120) NOT NULL,
  contact_no    VARCHAR(20)  NOT NULL,
  email         VARCHAR(120) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notified_at   DATETIME NULL,
  PRIMARY KEY (waitlist_id),
  KEY idx_waitlist_variant (variant_id, notified_at),
  KEY idx_waitlist_contact (contact_no),
  CONSTRAINT fk_waitlist_variant FOREIGN KEY (variant_id) REFERENCES item_variants (variant_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. SETTINGS AND AUDIT
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  setting_key    VARCHAR(60)  NOT NULL,
  setting_value  VARCHAR(255) NOT NULL,
  value_type     ENUM('int','decimal','string','bool') NOT NULL DEFAULT 'string',
  description    VARCHAR(255) NULL,
  updated_by     INT UNSIGNED NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  audit_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(60)  NOT NULL,
  entity_type  VARCHAR(40)  NOT NULL,
  entity_id    VARCHAR(40)  NULL,
  old_values   LONGTEXT NULL,
  new_values   LONGTEXT NULL,
  ip_address   VARCHAR(45) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 10. VIEW FOR THE PUBLIC CATALOG (stock per size)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_catalog_stock AS
SELECT i.item_id, i.item_code, i.name AS item_name, i.item_type, i.reservation_mode,
       c.name AS category, el.name AS education_level, co.code AS course_code,
       v.variant_id, v.size_label, v.unit_price,
       v.quantity_on_hand, v.quantity_reserved,
       (v.quantity_on_hand - v.quantity_reserved) AS quantity_available
FROM items i
JOIN categories c        ON c.category_id = i.category_id
JOIN item_variants v     ON v.item_id = i.item_id
LEFT JOIN education_levels el ON el.education_level_id = i.education_level_id
LEFT JOIN courses co     ON co.course_id = i.course_id
WHERE i.is_active = 1 AND v.is_active = 1;

-- =====================================================================
--  STORED PROGRAMS
-- =====================================================================
DELIMITER $$

-- Read an integer setting with a fallback default
CREATE FUNCTION fn_setting_int(p_key VARCHAR(60), p_default INT) RETURNS INT
READS SQL DATA
BEGIN
  DECLARE v VARCHAR(255);
  SELECT setting_value INTO v FROM settings WHERE setting_key = p_key;
  IF v IS NULL OR v NOT REGEXP '^-?[0-9]+$' THEN
    RETURN p_default;
  END IF;
  RETURN CAST(v AS SIGNED);
END$$

-- Add a line to a REQUESTED order: enforces the per-request quantity cap,
-- snapshots the price, and refreshes orders.total_amount.
-- p_staff_override = 1 lets counter staff exceed the cap / use counter-only items.
CREATE PROCEDURE sp_add_order_item(
  IN p_order_id BIGINT UNSIGNED, IN p_variant_id INT UNSIGNED,
  IN p_quantity INT, IN p_staff_override TINYINT)
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_item_id INT UNSIGNED;
  DECLARE v_name VARCHAR(150);
  DECLARE v_type VARCHAR(20);
  DECLARE v_mode VARCHAR(10);
  DECLARE v_max INT;
  DECLARE v_size VARCHAR(20);
  DECLARE v_price DECIMAL(10,2);
  DECLARE v_active INT DEFAULT 0;
  DECLARE v_existing INT DEFAULT 0;

  SELECT status INTO v_status FROM orders WHERE order_id = p_order_id AND deleted_at IS NULL;
  IF v_status IS NULL OR v_status <> 'requested' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Items can only be added while the order is in requested status';
  END IF;

  SELECT i.item_id, i.name, i.item_type, i.reservation_mode, i.max_qty_per_request,
         v.size_label, v.unit_price, (i.is_active AND v.is_active)
    INTO v_item_id, v_name, v_type, v_mode, v_max, v_size, v_price, v_active
    FROM item_variants v JOIN items i ON i.item_id = v.item_id
   WHERE v.variant_id = p_variant_id;

  IF v_item_id IS NULL OR v_active = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Item or size is not available';
  END IF;
  IF p_quantity IS NULL OR p_quantity < 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantity must be at least 1';
  END IF;
  IF v_mode = 'none' AND p_staff_override = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This item cannot be reserved online (counter only)';
  END IF;
  IF v_type = 'rental' AND p_quantity <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only one toga can be rented per order line';
  END IF;

  SELECT COALESCE(SUM(oi.quantity), 0) INTO v_existing
    FROM order_items oi JOIN item_variants v2 ON v2.variant_id = oi.variant_id
   WHERE oi.order_id = p_order_id AND v2.item_id = v_item_id;

  IF p_staff_override = 0 AND (v_existing + p_quantity) > v_max THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantity exceeds the online request limit for this item';
  END IF;

  INSERT INTO order_items (order_id, variant_id, item_name, size_label, quantity, unit_price, line_total)
  VALUES (p_order_id, p_variant_id, v_name, v_size, p_quantity, v_price, p_quantity * v_price);

  UPDATE orders SET total_amount = (SELECT COALESCE(SUM(line_total), 0) FROM order_items WHERE order_id = p_order_id)
   WHERE order_id = p_order_id;
END$$

-- ATOMIC RESERVATION: approve a requested order and hold its stock.
-- All-or-nothing: if any line lacks available stock the whole approval rolls back.
-- Also creates the toga rental row (the unique index blocks a 2nd active rental).
-- p_user_id = approving staff, or NULL for auto-approval (reservation_mode = auto).
-- Call it OUTSIDE an open transaction (it manages its own).
CREATE PROCEDURE sp_approve_order(IN p_order_id BIGINT UNSIGNED, IN p_user_id INT UNSIGNED)
BEGIN
  DECLARE v_done TINYINT DEFAULT 0;
  DECLARE v_status VARCHAR(20);
  DECLARE v_student BIGINT UNSIGNED;
  DECLARE v_period INT UNSIGNED;
  DECLARE v_due DATE;
  DECLARE v_oi BIGINT UNSIGNED;
  DECLARE v_var INT UNSIGNED;
  DECLARE v_qty INT;
  DECLARE v_tracks TINYINT;
  DECLARE v_type VARCHAR(20);
  DECLARE cur CURSOR FOR SELECT order_item_id, variant_id, quantity, tracks_stock, item_type FROM tmp_approve_lines;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  DROP TEMPORARY TABLE IF EXISTS tmp_approve_lines;
  START TRANSACTION;

  SELECT status, student_id, academic_period_id INTO v_status, v_student, v_period
    FROM orders WHERE order_id = p_order_id AND deleted_at IS NULL FOR UPDATE;

  IF v_status IS NULL OR v_status <> 'requested' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only an order in requested status can be approved';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM order_items WHERE order_id = p_order_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order has no items';
  END IF;

  IF v_period IS NULL THEN
    SELECT academic_period_id INTO v_period FROM academic_periods WHERE is_current = 1 LIMIT 1;
    IF v_period IS NOT NULL THEN
      UPDATE orders SET academic_period_id = v_period WHERE order_id = p_order_id;
    END IF;
  END IF;
  SELECT end_date INTO v_due FROM academic_periods WHERE academic_period_id = v_period;

  CREATE TEMPORARY TABLE tmp_approve_lines AS
    SELECT oi.order_item_id, oi.variant_id, oi.quantity, i.tracks_stock, i.item_type
      FROM order_items oi
      JOIN item_variants v ON v.variant_id = oi.variant_id
      JOIN items i ON i.item_id = v.item_id
     WHERE oi.order_id = p_order_id;

  SET v_done = 0;
  OPEN cur;
  line_loop: LOOP
    FETCH cur INTO v_oi, v_var, v_qty, v_tracks, v_type;
    IF v_done = 1 THEN LEAVE line_loop; END IF;

    IF v_tracks = 1 THEN
      -- the atomic check-and-update: succeeds only if enough is still available
      UPDATE item_variants
         SET quantity_reserved = quantity_reserved + v_qty
       WHERE variant_id = v_var
         AND is_active = 1
         AND (quantity_on_hand - quantity_reserved) >= v_qty;
      IF ROW_COUNT() = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Not enough available stock for one or more items';
      END IF;
      INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
      VALUES (v_var, 'reserved', 0, v_qty, p_order_id, p_user_id, 'Reservation approved');
    END IF;

    IF v_type = 'rental' THEN
      IF v_student IS NULL OR v_period IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A rental needs a student and an academic period';
      END IF;
      INSERT INTO rentals (order_item_id, student_id, academic_period_id, variant_id, status, due_date)
      VALUES (v_oi, v_student, v_period, v_var, 'reserved', v_due);   -- duplicate active rental => error 1062
    END IF;
  END LOOP;
  CLOSE cur;
  DROP TEMPORARY TABLE IF EXISTS tmp_approve_lines;

  UPDATE order_items oi
    JOIN item_variants v ON v.variant_id = oi.variant_id
    JOIN items i ON i.item_id = v.item_id
     SET oi.stock_held = 1
   WHERE oi.order_id = p_order_id AND i.tracks_stock = 1;

  UPDATE orders
     SET status = 'approved', approved_by = p_user_id, approved_at = NOW(),
         expires_at = DATE_ADD(NOW(), INTERVAL fn_setting_int('hold_unpaid_hours', 48) HOUR)
   WHERE order_id = p_order_id;

  COMMIT;
END$$

-- Release the held stock of an approved / slip_issued order and set its end status
-- (used for cancel and for expiry). 'expired' orders go to the recycle bin.
CREATE PROCEDURE sp_release_order(
  IN p_order_id BIGINT UNSIGNED, IN p_new_status VARCHAR(20),
  IN p_user_id INT UNSIGNED, IN p_reason VARCHAR(255))
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF p_new_status NOT IN ('expired','cancelled') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New status must be expired or cancelled';
  END IF;

  START TRANSACTION;
  SELECT status INTO v_status FROM orders WHERE order_id = p_order_id FOR UPDATE;
  IF v_status IS NULL OR v_status NOT IN ('approved','slip_issued') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only approved or slip_issued orders can release stock';
  END IF;

  UPDATE item_variants v
    JOIN (SELECT variant_id, SUM(quantity) AS qty
            FROM order_items WHERE order_id = p_order_id AND stock_held = 1
           GROUP BY variant_id) x ON x.variant_id = v.variant_id
     SET v.quantity_reserved = v.quantity_reserved - x.qty;

  INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
  SELECT variant_id, 'released', 0, -quantity, p_order_id, p_user_id, COALESCE(p_reason, 'Stock released')
    FROM order_items WHERE order_id = p_order_id AND stock_held = 1;

  UPDATE order_items SET stock_held = 0 WHERE order_id = p_order_id;

  UPDATE rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
     SET r.status = 'cancelled'
   WHERE oi.order_id = p_order_id AND r.status = 'reserved';

  UPDATE orders
     SET status = p_new_status,
         deleted_at  = IF(p_new_status = 'expired', NOW(), deleted_at),
         purge_after = IF(p_new_status = 'expired',
                          DATE_ADD(NOW(), INTERVAL fn_setting_int('recycle_purge_days', 30) DAY), purge_after)
   WHERE order_id = p_order_id;
  COMMIT;
END$$

-- SCHEDULED JOB: expire unpaid reservations whose hold ended and return the stock.
-- Orders that already have a (partial) payment are never auto-expired.
CREATE PROCEDURE sp_expire_unpaid_orders()
BEGIN
  DECLARE v_done TINYINT DEFAULT 0;
  DECLARE v_id BIGINT UNSIGNED;
  DECLARE v_ok INT DEFAULT 0;
  DECLARE v_failed INT DEFAULT 0;
  DECLARE v_before INT;
  DECLARE cur CURSOR FOR SELECT order_id FROM tmp_expiring;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
  DECLARE CONTINUE HANDLER FOR SQLEXCEPTION SET v_failed = v_failed + 1;

  DROP TEMPORARY TABLE IF EXISTS tmp_expiring;
  CREATE TEMPORARY TABLE tmp_expiring AS
    SELECT o.order_id FROM orders o
     WHERE o.status IN ('approved','slip_issued')
       AND o.expires_at IS NOT NULL AND o.expires_at <= NOW()
       AND o.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.order_id);

  SET v_done = 0;
  OPEN cur;
  expire_loop: LOOP
    FETCH cur INTO v_id;
    IF v_done = 1 THEN LEAVE expire_loop; END IF;
    SET v_before = v_failed;
    CALL sp_release_order(v_id, 'expired', NULL, 'Unpaid hold expired');
    IF v_failed = v_before THEN SET v_ok = v_ok + 1; END IF;
  END LOOP;
  CLOSE cur;
  DROP TEMPORARY TABLE IF EXISTS tmp_expiring;
  SELECT v_ok AS orders_expired, v_failed AS orders_failed;
END$$

-- SCHEDULED JOB: paid but not claimed after the claim period -> flag for staff (never deleted)
CREATE PROCEDURE sp_flag_unclaimed_orders()
BEGIN
  UPDATE orders
     SET status = 'unclaimed', unclaimed_flagged_at = NOW()
   WHERE status = 'paid' AND deleted_at IS NULL
     AND claim_deadline IS NOT NULL AND claim_deadline <= NOW();
  SELECT ROW_COUNT() AS orders_flagged;
END$$

-- SCHEDULED JOB: permanently delete recycle-bin orders past their purge date
CREATE PROCEDURE sp_purge_recycle_bin()
BEGIN
  DELETE FROM orders
   WHERE deleted_at IS NOT NULL AND purge_after IS NOT NULL
     AND purge_after <= NOW() AND status = 'expired';
  SELECT ROW_COUNT() AS orders_purged;
END$$

-- Staff restore from the recycle bin: order goes back to 'requested'
-- (stock is NOT held; staff must approve it again so availability is re-checked)
CREATE PROCEDURE sp_restore_order(IN p_order_id BIGINT UNSIGNED, IN p_user_id INT UNSIGNED)
BEGIN
  UPDATE orders
     SET status = 'requested', deleted_at = NULL, deleted_by = NULL, purge_after = NULL,
         expires_at = NULL, approved_by = NULL, approved_at = NULL
   WHERE order_id = p_order_id AND status = 'expired' AND deleted_at IS NOT NULL;
  IF ROW_COUNT() = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order is not in the recycle bin';
  END IF;
  INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values)
  VALUES (p_user_id, 'restore_order', 'orders', p_order_id, '{"status":"requested"}');
END$$

-- Record the cashier's proof of payment (OR no.). Fully paid => status 'paid'
-- and the claim period starts. Paid-in-full test:
--   SUM(amount_paid - deposit_amount) >= orders.total_amount
CREATE PROCEDURE sp_record_payment(
  IN p_order_id BIGINT UNSIGNED, IN p_or_no VARCHAR(30), IN p_amount_paid DECIMAL(10,2),
  IN p_deposit DECIMAL(10,2), IN p_paid_at DATETIME, IN p_user_id INT UNSIGNED)
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_total DECIMAL(12,2);
  DECLARE v_paid DECIMAL(12,2);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT status, total_amount INTO v_status, v_total FROM orders WHERE order_id = p_order_id FOR UPDATE;
  IF v_status IS NULL OR v_status NOT IN ('approved','slip_issued') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment can only be recorded for an approved order';
  END IF;

  INSERT INTO payments (order_id, or_no, amount_paid, deposit_amount, paid_at, verified_by)
  VALUES (p_order_id, p_or_no, p_amount_paid, COALESCE(p_deposit, 0), COALESCE(p_paid_at, NOW()), p_user_id);

  SELECT COALESCE(SUM(amount_paid - deposit_amount), 0) INTO v_paid FROM payments WHERE order_id = p_order_id;

  IF v_paid >= v_total THEN
    UPDATE orders
       SET status = 'paid', paid_at = COALESCE(p_paid_at, NOW()), expires_at = NULL,
           claim_deadline = DATE_ADD(COALESCE(p_paid_at, NOW()), INTERVAL fn_setting_int('claim_period_days', 14) DAY)
     WHERE order_id = p_order_id;
  END IF;
  COMMIT;
END$$

-- Claim at the counter: moves stock out (sold / rented_out), activates the rental
CREATE PROCEDURE sp_claim_order(IN p_order_id BIGINT UNSIGNED, IN p_user_id INT UNSIGNED, IN p_received_by VARCHAR(120))
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT status INTO v_status FROM orders WHERE order_id = p_order_id FOR UPDATE;
  IF v_status IS NULL OR v_status NOT IN ('paid','unclaimed') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a paid order can be claimed';
  END IF;

  UPDATE item_variants v
    JOIN (SELECT variant_id, SUM(quantity) AS qty
            FROM order_items WHERE order_id = p_order_id AND stock_held = 1
           GROUP BY variant_id) x ON x.variant_id = v.variant_id
     SET v.quantity_on_hand = v.quantity_on_hand - x.qty,
         v.quantity_reserved = v.quantity_reserved - x.qty;

  INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
  SELECT oi.variant_id, IF(i.item_type = 'rental', 'rented_out', 'sold'),
         -oi.quantity, -oi.quantity, p_order_id, p_user_id, 'Claimed at counter'
    FROM order_items oi
    JOIN item_variants v ON v.variant_id = oi.variant_id
    JOIN items i ON i.item_id = v.item_id
   WHERE oi.order_id = p_order_id AND oi.stock_held = 1;

  UPDATE order_items SET stock_held = 0 WHERE order_id = p_order_id;

  UPDATE rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
     SET r.status = 'released', r.released_at = NOW()
   WHERE oi.order_id = p_order_id AND r.status = 'reserved';

  UPDATE orders
     SET status = 'claimed', claimed_at = NOW(), received_by = p_received_by,
         checked_by = COALESCE(checked_by, p_user_id)
   WHERE order_id = p_order_id;
  COMMIT;
END$$

-- Toga return: back into stock, record condition / penalty (all optional)
CREATE PROCEDURE sp_return_rental(
  IN p_rental_id BIGINT UNSIGNED, IN p_user_id INT UNSIGNED,
  IN p_condition VARCHAR(20), IN p_penalty DECIMAL(10,2), IN p_deposit_returned TINYINT)
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_variant INT UNSIGNED;
  DECLARE v_order BIGINT UNSIGNED;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
  SELECT r.status, r.variant_id, oi.order_id INTO v_status, v_variant, v_order
    FROM rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
   WHERE r.rental_id = p_rental_id FOR UPDATE;
  IF v_status IS NULL OR v_status NOT IN ('released','overdue') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a released or overdue rental can be returned';
  END IF;

  UPDATE rentals
     SET status = 'returned', returned_at = NOW(), condition_on_return = p_condition,
         penalty_amount = p_penalty, deposit_returned = p_deposit_returned
   WHERE rental_id = p_rental_id;

  IF p_condition IS NULL OR p_condition IN ('good','needs_cleaning') THEN
    UPDATE item_variants SET quantity_on_hand = quantity_on_hand + 1 WHERE variant_id = v_variant;
    INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
    VALUES (v_variant, 'returned', 1, 0, v_order, p_user_id, CONCAT('Toga returned (', COALESCE(p_condition, 'good'), ')'));
  ELSE
    INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
    VALUES (v_variant, 'adjustment', 0, 0, v_order, p_user_id, CONCAT('Toga returned ', p_condition, '; not restocked'));
  END IF;
  COMMIT;
END$$

-- SCHEDULED JOB: flag toga rentals past their due date
CREATE PROCEDURE sp_mark_overdue_rentals()
BEGIN
  UPDATE rentals SET status = 'overdue'
   WHERE status = 'released' AND due_date IS NOT NULL AND due_date < CURDATE();
  SELECT ROW_COUNT() AS rentals_overdue;
END$$

DELIMITER ;

-- ---------------------------------------------------------------------
-- Scheduled jobs (need: SET GLOBAL event_scheduler = ON;)
-- ---------------------------------------------------------------------
CREATE EVENT IF NOT EXISTS ev_expire_unpaid_orders
  ON SCHEDULE EVERY 15 MINUTE DO CALL sp_expire_unpaid_orders();
CREATE EVENT IF NOT EXISTS ev_flag_unclaimed_orders
  ON SCHEDULE EVERY 1 DAY DO CALL sp_flag_unclaimed_orders();
CREATE EVENT IF NOT EXISTS ev_purge_recycle_bin
  ON SCHEDULE EVERY 1 DAY DO CALL sp_purge_recycle_bin();
CREATE EVENT IF NOT EXISTS ev_mark_overdue_rentals
  ON SCHEDULE EVERY 1 DAY DO CALL sp_mark_overdue_rentals();

-- =====================================================================
--  SEED DATA (SAMPLE VALUES - replace with real shop data)
-- =====================================================================

INSERT INTO users (user_id, username, password_hash, full_name, email, role) VALUES
  (1, 'admin',   'REPLACE_WITH_password_hash()', 'System Administrator',   'admin@example.com',   'admin'),
  (2, 'staff01', 'REPLACE_WITH_password_hash()', 'Garment Shop Staff',     'staff01@example.com', 'staff'),
  (3, 'manager', 'REPLACE_WITH_password_hash()', 'OIC - Garment Manager',  'manager@example.com', 'manager');

INSERT INTO education_levels (education_level_id, code, name, sort_order) VALUES
  (1, 'primary',     'Primary',      1),
  (2, 'junior_high', 'Junior High',  2),
  (3, 'senior_high', 'Senior High',  3),
  (4, 'tertiary',    'Tertiary',     4);

INSERT INTO courses (course_id, code, name, college) VALUES
  (1, 'BSCS', 'BS Computer Science',     'College of Computing Studies'),
  (2, 'BSIT', 'BS Information Technology','College of Computing Studies'),
  (3, 'BSN',  'BS Nursing',              'College of Nursing');

INSERT INTO categories (category_id, name, description) VALUES
  (1, 'School Uniforms', 'Regular school uniforms, primary to tertiary'),
  (2, 'P.E. Uniforms',   'Physical education uniforms'),
  (3, 'Togas',           'Graduation togas (rental)'),
  (4, 'Alterations',     'Alteration and tailoring services');

INSERT INTO academic_periods (academic_period_id, school_year, semester, start_date, end_date, is_current) VALUES
  (1, '2026-2027', '1st', '2026-08-17', '2026-12-18', 1);

INSERT INTO settings (setting_key, setting_value, value_type, description) VALUES
  ('hold_unpaid_hours',              '48', 'int', 'Hours an approved, unpaid reservation is held (1-2 days) before it expires'),
  ('claim_period_days',              '14', 'int', 'Days after payment before a paid, unclaimed order is flagged (14-30)'),
  ('recycle_purge_days',             '30', 'int', 'Days an expired order stays in the recycle bin before purge'),
  ('default_max_qty_per_request',    '3',  'int', 'Default online quantity cap per request (items.max_qty_per_request overrides)'),
  ('max_active_requests_per_contact','0',  'int', 'Max pending online requests per contact no./student no. (0 = no limit)'),
  ('toga_rental_term',               'academic_period_end', 'string', 'Toga due date rule: end of the academic period'),
  ('order_no_prefix',                'OS', 'string', 'Prefix of the Order Slip No.');

INSERT INTO items (item_id, category_id, education_level_id, course_id, item_code, name, description,
                   item_type, reservation_mode, max_qty_per_request, tracks_stock) VALUES
  (1, 1, 1, NULL, 'PRI-BOY-POLO',   'Primary Boys Polo',           'Primary school boys uniform polo',  'ready_made',    'auto',     5, 1),
  (2, 1, 1, NULL, 'PRI-GIRL-BLSE',  'Primary Girls Blouse',        'Primary school girls uniform blouse','ready_made',   'auto',     5, 1),
  (3, 2, 2, NULL, 'JHS-PE-SHIRT',   'Junior High P.E. Shirt',      'Junior high P.E. shirt',            'ready_made',    'approval', 3, 1),
  (4, 1, 4, 1,    'TER-CS-POLO',    'Tertiary Polo - BS Computer Science', 'College of Computing Studies polo', 'ready_made', 'approval', 3, 1),
  (5, 1, 3, NULL, 'SHS-SLACKS',     'Senior High Slacks (made to order)', 'Tailored to measurements',    'made_to_order', 'approval', 2, 0),
  (6, 3, 4, NULL, 'TOGA-BACH',      'Bachelor Toga Set',           'Graduation toga, cap and hood (rental)', 'rental',     'approval', 1, 1),
  (7, 4, NULL, NULL, 'ALT-HEM',     'Alteration - Hemming',        'Hemming of pants or skirt',         'alteration',    'approval', 3, 0);

INSERT INTO item_variants (item_id, size_label, sku, unit_price, quantity_on_hand, low_stock_threshold) VALUES
  (1, '6',  'PRI-BOY-POLO-6',  245.00, 20, 5), (1, '8',  'PRI-BOY-POLO-8',  255.00, 15, 5),
  (1, '10', 'PRI-BOY-POLO-10', 265.00, 12, 5), (1, '12', 'PRI-BOY-POLO-12', 275.00, 10, 5),
  (2, '6',  'PRI-GIRL-BLSE-6', 245.00, 18, 5), (2, '8',  'PRI-GIRL-BLSE-8', 255.00, 14, 5),
  (2, '10', 'PRI-GIRL-BLSE-10',265.00, 10, 5), (2, '12', 'PRI-GIRL-BLSE-12',275.00,  0, 5),
  (3, 'S',  'JHS-PE-SHIRT-S',  220.00, 10, 3), (3, 'M',  'JHS-PE-SHIRT-M',  220.00, 10, 3),
  (3, 'L',  'JHS-PE-SHIRT-L',  230.00,  8, 3), (3, 'XL', 'JHS-PE-SHIRT-XL', 230.00,  5, 3),
  (4, 'S',  'TER-CS-POLO-S',   320.00,  8, 3), (4, 'M',  'TER-CS-POLO-M',   320.00, 12, 3),
  (4, 'L',  'TER-CS-POLO-L',   330.00, 10, 3), (4, 'XL', 'TER-CS-POLO-XL',  330.00,  6, 3),
  (5, 'Custom', 'SHS-SLACKS-CUSTOM', 450.00, 0, 0),
  (6, 'S',  'TOGA-BACH-S',     350.00, 15, 3), (6, 'M',  'TOGA-BACH-M',     350.00, 25, 3),
  (6, 'L',  'TOGA-BACH-L',     350.00, 20, 3), (6, 'XL', 'TOGA-BACH-XL',    350.00, 10, 3),
  (7, 'Standard', 'ALT-HEM-STD', 80.00, 0, 0);

-- opening stock goes into the movement log
INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, user_id, reason)
SELECT variant_id, 'restock', quantity_on_hand, 0, 2, 'Opening stock (seed data)'
  FROM item_variants WHERE quantity_on_hand > 0;

INSERT INTO restock_schedule (variant_id, item_id, expected_date, expected_qty, note, status, created_by) VALUES
  ((SELECT variant_id FROM item_variants WHERE sku = 'PRI-GIRL-BLSE-12'), NULL, '2026-11-20', 15, 'Next supplier delivery', 'scheduled', 2),
  (NULL, 6, '2026-11-25', NULL, 'Additional toga sets before graduation season', 'scheduled', 2);

INSERT INTO waitlist (variant_id, contact_name, contact_no, email) VALUES
  ((SELECT variant_id FROM item_variants WHERE sku = 'PRI-GIRL-BLSE-12'), 'Rosa Dela Cruz', '09171234567', 'rosa@example.com');

-- guests and students
INSERT INTO customers (customer_id, full_name, contact_no, email, address, relationship) VALUES
  (1, 'Maria Santos',    '09171110001', 'maria.santos@example.com', 'Tetuan, Zamboanga City',   'parent'),
  (2, 'Ana Reyes',       '09171110002', NULL,                       'Putik, Zamboanga City',    'self'),
  (3, 'Carlo Mendoza',   '09171110003', 'carlo.m@example.com',      'Sta. Maria, Zamboanga City','self'),
  (4, 'Liza Aquino',     '09171110004', NULL,                       'Canelar, Zamboanga City',  'parent'),
  (5, 'Mark Villanueva', '09171110005', 'mark.v@example.com',       'Tumaga, Zamboanga City',   'self'),
  (6, 'Grace Lim',       '09171110006', NULL,                       'Guiwan, Zamboanga City',   'self'),
  (7, 'Pedro Ramos',     '09171110007', NULL,                       'Divisoria, Zamboanga City','self');

INSERT INTO students (student_id, student_no, full_name, education_level_id, course_id, year_level) VALUES
  (1, '123456789012', 'Juan Santos',    1, NULL, 'Grade 3'),
  (2, '2023-00123',   'Ana Reyes',      2, NULL, 'Grade 8'),
  (3, '2022-01001',   'Carlo Mendoza',  4, 1,    '4th Year'),
  (4, '987654321098', 'Ella Aquino',    1, NULL, 'Grade 1'),
  (5, '2022-01002',   'Mark Villanueva',4, 1,    '4th Year'),
  (6, '2021-00555',   'Grace Lim',      4, 1,    '4th Year'),
  (7, '2023-00777',   'Pedro Ramos',    3, NULL, 'Grade 11');

-- helper variables for variant ids
SET @pbp8  := (SELECT variant_id FROM item_variants WHERE sku = 'PRI-BOY-POLO-8');
SET @pgb8  := (SELECT variant_id FROM item_variants WHERE sku = 'PRI-GIRL-BLSE-8');
SET @pe_s  := (SELECT variant_id FROM item_variants WHERE sku = 'JHS-PE-SHIRT-S');
SET @cs_l  := (SELECT variant_id FROM item_variants WHERE sku = 'TER-CS-POLO-L');
SET @cs_m  := (SELECT variant_id FROM item_variants WHERE sku = 'TER-CS-POLO-M');
SET @slx   := (SELECT variant_id FROM item_variants WHERE sku = 'SHS-SLACKS-CUSTOM');
SET @toga_m:= (SELECT variant_id FROM item_variants WHERE sku = 'TOGA-BACH-M');
SET @toga_l:= (SELECT variant_id FROM item_variants WHERE sku = 'TOGA-BACH-L');

-- O1: online reservation request, still 'requested' (no stock held yet)
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id, remarks)
VALUES ('OS-2026-0001', 'GS-7K4Q9M2X', 'reservation', 'requested', 'online', 1, 1, 1, 'Online request');
SET @o1 := LAST_INSERT_ID();
CALL sp_add_order_item(@o1, @pbp8, 2, 0);

-- O2: online request, auto-approved (stock held, unpaid)
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id, remarks)
VALUES ('OS-2026-0002', 'GS-3F8D2LQ5', 'reservation', 'requested', 'online', 4, 4, 1, 'Auto-approved item');
SET @o2 := LAST_INSERT_ID();
CALL sp_add_order_item(@o2, @pgb8, 2, 0);
CALL sp_approve_order(@o2, NULL);

-- O3: approved by staff, slip issued, waiting for payment at cashier
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id)
VALUES ('OS-2026-0003', 'GS-9H2R6TW1', 'reservation', 'requested', 'online', 2, 2, 1);
SET @o3 := LAST_INSERT_ID();
CALL sp_add_order_item(@o3, @pe_s, 2, 0);
CALL sp_approve_order(@o3, 2);
UPDATE orders SET status = 'slip_issued', slip_issued_at = NOW(), pickup_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY),
                  measured_by = 2, oic_manager_id = 3 WHERE order_id = @o3;

-- O4: paid, waiting to be claimed
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id)
VALUES ('OS-2026-0004', 'GS-5B7N1VC8', 'reservation', 'requested', 'online', 3, 3, 1);
SET @o4 := LAST_INSERT_ID();
CALL sp_add_order_item(@o4, @cs_l, 1, 0);
CALL sp_approve_order(@o4, 2);
CALL sp_record_payment(@o4, 'OR-0001001', 330.00, 0.00, NOW(), 2);

-- O5: walk-in purchase, paid and claimed the same day
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id, created_by)
VALUES ('OS-2026-0005', 'GS-2C4M8ZK6', 'purchase', 'requested', 'walk_in', 5, 5, 1, 2);
SET @o5 := LAST_INSERT_ID();
CALL sp_add_order_item(@o5, @cs_m, 4, 1);          -- staff override above the online cap
CALL sp_approve_order(@o5, 2);
CALL sp_record_payment(@o5, 'OR-0001002', 1280.00, 0.00, NOW(), 2);
CALL sp_claim_order(@o5, 2, 'Mark Villanueva');

-- O6: approved but never paid -> hold ended -> expired and moved to the recycle bin
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id)
VALUES ('OS-2026-0006', 'GS-6P1X9JD3', 'reservation', 'requested', 'online', 7, 7, 1);
SET @o6 := LAST_INSERT_ID();
CALL sp_add_order_item(@o6, @pe_s, 1, 0);
CALL sp_approve_order(@o6, 2);
UPDATE orders SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE order_id = @o6;
CALL sp_expire_unpaid_orders();

-- O7: toga rental, paid with a deposit, released to the student
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id)
VALUES ('OS-2026-0007', 'GS-8W3Y5HE2', 'rental', 'requested', 'online', 6, 6, 1);
SET @o7 := LAST_INSERT_ID();
CALL sp_add_order_item(@o7, @toga_m, 1, 0);
CALL sp_approve_order(@o7, 2);
CALL sp_record_payment(@o7, 'OR-0001003', 850.00, 500.00, NOW(), 2);   -- 350 fee + 500 deposit (sample)
CALL sp_claim_order(@o7, 2, 'Grace Lim');
UPDATE rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
   SET r.rental_fee = 350.00, r.deposit_amount = 500.00 WHERE oi.order_id = @o7;

-- O8: declined request
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id,
                    declined_by, declined_at, decline_reason)
VALUES ('OS-2026-0008', 'GS-4T6G0RB7', 'reservation', 'declined', 'online', 4, 4, 1,
        2, NOW(), 'Requested size is out of stock until the next delivery');
SET @o8 := LAST_INSERT_ID();
INSERT INTO order_items (order_id, variant_id, item_name, size_label, quantity, unit_price, line_total)
SELECT @o8, variant_id, 'Primary Girls Blouse', '12', 2, 275.00, 550.00 FROM item_variants WHERE sku = 'PRI-GIRL-BLSE-12';
UPDATE orders SET total_amount = 550.00 WHERE order_id = @o8;

-- O9: made-to-order (no stock tracking) with measurements, approved
INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id, measured_by)
VALUES ('OS-2026-0009', 'GS-1A9S7QN4', 'purchase', 'requested', 'walk_in', 7, 7, 1, 2);
SET @o9 := LAST_INSERT_ID();
CALL sp_add_order_item(@o9, @slx, 1, 1);
CALL sp_approve_order(@o9, 2);
SET @o9i := (SELECT order_item_id FROM order_items WHERE order_id = @o9 LIMIT 1);
INSERT INTO measurements (order_item_id, measure_name, measure_value, unit, measured_by) VALUES
  (@o9i, 'waist',  30.00, 'in', 2),
  (@o9i, 'hip',    37.00, 'in', 2),
  (@o9i, 'length', 40.00, 'in', 2);

INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values) VALUES
  (1, 'seed_data_loaded', 'system', NULL, '{"note":"sample data"}');

-- ---------------------------------------------------------------------
-- USEFUL QUERIES FOR THE PHP BACKEND (reference)
-- ---------------------------------------------------------------------
-- Public tracking (reference no. + contact no. OR email):
--   SELECT o.order_ref, o.status, o.total_amount, o.expires_at, o.claim_deadline
--     FROM orders o JOIN customers c ON c.customer_id = o.customer_id
--    WHERE o.order_ref = :ref AND o.deleted_at IS NULL
--      AND (c.contact_no = :contact OR c.email = :contact);
--
-- Optional cap on active pending requests per contact number:
--   SELECT COUNT(*) FROM orders o JOIN customers c ON c.customer_id = o.customer_id
--    WHERE c.contact_no = :contact AND o.status IN ('requested','approved','slip_issued') AND o.deleted_at IS NULL;
--   (compare with fn_setting_int('max_active_requests_per_contact', 0); 0 = no limit)
--
-- The single-statement atomic reservation used inside sp_approve_order:
--   UPDATE item_variants SET quantity_reserved = quantity_reserved + :qty
--    WHERE variant_id = :variant AND is_active = 1
--      AND (quantity_on_hand - quantity_reserved) >= :qty;
--   -- ROW_COUNT() = 1 => reserved; 0 => not enough stock (roll back)
