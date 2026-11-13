-- =====================================================================
-- Minimal seed for a REAL installation (Session 10). Run after schema.sql and migrations 001-008.
-- Contains reference data and settings only: no sample items, orders, customers or users.
-- Create the first administrator with:   php tools/create_admin.php admin "Full Name"
-- Replace the values below with the shop's real data (education levels and categories can also
-- be changed later in phpMyAdmin; there is no screen for courses yet).
-- =====================================================================
USE wmsu_garment_shop;

INSERT IGNORE INTO education_levels (education_level_id, code, name, sort_order) VALUES
  (1, 'primary',     'Primary',     1),
  (2, 'junior_high', 'Junior High', 2),
  (3, 'senior_high', 'Senior High', 3),
  (4, 'tertiary',    'Tertiary',    4);

INSERT IGNORE INTO categories (category_id, name, description) VALUES
  (1, 'School Uniforms', 'Regular school uniforms, primary to tertiary'),
  (2, 'P.E. Uniforms',   'Physical education uniforms'),
  (3, 'Togas',           'Graduation togas (rental)'),
  (4, 'Alterations',     'Alteration and tailoring services');

-- Courses: REPLACE with the real list (code must be unique). Samples only.
INSERT IGNORE INTO courses (code, name, college) VALUES
  ('BSCS', 'BS Computer Science', 'College of Computing Studies'),
  ('BSIT', 'BS Information Technology', 'College of Computing Studies');

-- Current academic period: edit in Settings after the first sign-in.
INSERT IGNORE INTO academic_periods (school_year, semester, start_date, end_date, is_current)
VALUES ('2026-2027', '1st', '2026-08-17', '2026-12-18', 1);

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, description) VALUES
  ('hold_unpaid_hours',               '168', 'int',    'Hours an approved, unpaid reservation is held (168 = 7 days)'),
  ('claim_period_days',               '14',  'int',    'Days after payment before a paid, unclaimed order is flagged'),
  ('recycle_purge_days',              '30',  'int',    'Days an expired order stays in the recycle bin before purge'),
  ('default_max_qty_per_request',     '3',   'int',    'Default online quantity cap per request'),
  ('max_active_requests_per_contact', '3',   'int',    'Pending online requests per contact number (0 = no limit)'),
  ('public_orders_per_ip_hour',       '10',  'int',    'Online reservations per device per hour (0 = no limit)'),
  ('toga_rental_term',                'academic_period_end', 'string', 'Toga due date rule'),
  ('order_no_prefix',                 'OS',  'string', 'Prefix of the Order Slip No.'),
  ('student_no_pattern', '/^([0-9]{4}-[0-9]{5}|[0-9]{12})$/', 'string', 'Regular expression for student ID or LRN');
