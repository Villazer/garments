-- Migration 007 (Session 9): settings rows used by the admin settings page,
-- the auto-expiry job and the dashboard. Data only; no table or column changes.
-- Safe to run twice: INSERT IGNORE never overwrites a value the shop already set.
-- Shop details (shop_name, shop_address, shop_hours, shop_phone, shop_email) are
-- NOT inserted here: until an admin saves them, the public pages keep using the
-- values in config/config.php.

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, description) VALUES
  ('reservations_open',            '1',   'bool',   '1 = accept online reservations and toga requests, 0 = closed'),
  ('hold_unpaid_hours',            '168', 'int',    'Hours an approved, unpaid reservation is held before it expires (168 = 7 days)'),
  ('default_max_qty_per_request',  '3',   'int',    'Default online quantity cap suggested for new items'),
  ('max_qty_per_request_limit',    '50',  'int',    'Highest online quantity cap any item may use (also applied to existing items)'),
  ('low_stock_default',            '5',   'int',    'Low-stock threshold used when a size has none'),
  ('toga_rental_days',             '0',   'int',    'Default toga rental length in days (0 = until the end of the academic period)'),
  ('recycle_purge_days',           '30',  'int',    'Days an expired order stays in the recycle bin before it is purged'),
  ('expiring_soon_hours',          '24',  'int',    'Hours before a hold ends that an order is flagged as expiring soon'),
  ('auto_expiry_enabled',          '1',   'bool',   'Run the expiry job when staff pages load (throttled)'),
  ('auto_expiry_interval_minutes', '5',   'int',    'Minimum minutes between two lazy expiry runs'),
  ('auto_expiry_last_run',         '',    'string', 'When the auto-expiry job last started (maintained by the system)'),
  ('auto_expiry_last_result',      '',    'string', 'Result of the last auto-expiry run (maintained by the system)'),
  ('announcement_enabled',         '0',   'bool',   'Show the announcement banner on the home page'),
  ('announcement_text',            '',    'string', 'Announcement banner text'),
  ('announcement_style',           'info','string', 'Announcement banner style: info, warning or success'),
  ('order_slip_format',            '{prefix}-{year}-{seq:4}', 'string', 'Order Slip No. format'),
  ('order_no_prefix',              'OS',  'string', 'Prefix of the Order Slip No.');

-- The PHP job (cron/expire_orders.php, also run lazily and from the Run now
-- button) replaces this MySQL event. The event expired orders without writing a
-- history row, and two expiry mechanisms would race. The other three events
-- (unclaimed flag, recycle purge, overdue rentals) are left as they are.
DROP EVENT IF EXISTS ev_expire_unpaid_orders;
