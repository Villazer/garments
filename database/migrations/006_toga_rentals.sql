-- 006_toga_rentals.sql  (Session 8: toga rentals and walk-in orders)
-- MariaDB 10.4+ (XAMPP). Safe to run more than once.
--
-- 1. Who handed the toga out and who took it back. Both are optional so rows
--    created before this migration stay valid.
ALTER TABLE rentals
  ADD COLUMN IF NOT EXISTS issued_by   INT UNSIGNED NULL AFTER released_at,
  ADD COLUMN IF NOT EXISTS received_by INT UNSIGNED NULL AFTER returned_at;

ALTER TABLE rentals
  ADD CONSTRAINT fk_rentals_issued_by   FOREIGN KEY IF NOT EXISTS (issued_by)   REFERENCES users (user_id),
  ADD CONSTRAINT fk_rentals_received_by FOREIGN KEY IF NOT EXISTS (received_by) REFERENCES users (user_id);

-- 2. Default rental length. The shop has not confirmed the real duration, so
--    0 means "due at the end of the academic period" (the schema's
--    toga_rental_term). Set a number of days here or in Settings to change it.
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, description) VALUES
  ('toga_rental_days', '0', 'int',
   'Default toga rental length in days from the issue date. 0 = due at the end of the academic period (duration not yet confirmed with the shop)');
