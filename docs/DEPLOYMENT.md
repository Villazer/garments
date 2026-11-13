# Deployment notes

## Option A: local server on the shop or school network (no internet)
This fits the shop's situation: the site uses no external files, no e-mail and no SMS, so it works fully offline.
1. **Machine.** One always-on PC (not the shop's counter PC if possible), wired network, a UPS, Windows set to *Manila time*. Install XAMPP. In the XAMPP Control Panel install **Apache and MySQL as services** (start automatically).
2. **Address.** Give the PC a fixed IP (router DHCP reservation), e.g. `192.168.1.20`. Ask IT for a friendly name such as `http://garments.wmsu.local/`; otherwise use `http://192.168.1.20/garments`. Set `base_url` to `/garments` (or `''` if the site is the web root).
3. **Firewall.** Windows Defender Firewall: allow inbound TCP 80 on the private network only. Block 3306 (MySQL) and do not forward any port on the router. XAMPP's phpMyAdmin must stay `localhost` only (default in current XAMPP; check `httpd-xampp.conf`).
4. **Security.** Follow README sections 2 and 4 (dedicated DB user, real admin, `debug` false, sample data cleared). `trusted_proxies` stays empty. HTTPS is optional on a closed network; if the school can provide a certificate, use it and set `hsts` to true.
5. **Clock.** With no internet the PC clock can drift, and hold times and expiry depend on it. Check it monthly (or use the school's time server). PHP, MySQL and Windows must all be Manila.
6. **Jobs and backups.** Task Scheduler jobs from README section 6. Backups to a USB disk or another PC, weekly copy kept off-site (another office). Test a restore monthly.
7. **Customers.** Only people on the school network can reach the site. Students at home cannot reserve until the site is published (Option B or a school-approved VPN/port arrangement). Decide this with the shop and IT: it changes the "reserve in advance" goal.
8. **Updates.** Take a backup, unzip the new files, run the patcher/migrations as in README section 3.

## Option B: web hosting (public internet)
1. **Hosting needs:** PHP 8.0+, MariaDB/MySQL, `.htaccess` support (Apache or LiteSpeed), cron jobs, and the ability to create stored procedures. `EVENT` privilege is *not* required because `cron/maintenance.php` runs the daily jobs; if events are allowed, they do no harm.
2. **Upload** the release zip (`php tools/make_release.php`), create the database and user in the host's panel, import schema, migrations 001-008 and `seed_minimal.sql`, then create `config/config.php` (above the web root if the host allows; `config()` reads `../config/config.php`, so keep the folder layout).
3. **Cron jobs** in the host panel: expire every 5 minutes, maintenance daily: `/usr/bin/php /home/USER/public_html/garments/cron/expire_orders.php`.
4. **HTTPS is required.** Enable the host's free certificate, force HTTPS, then set `'hsts' => true`. Session cookies become `Secure` automatically.
5. **Proxy / CDN.** If the host or Cloudflare sits in front of the site, put its IP addresses in `trusted_proxies`, otherwise the per-IP limits will treat all visitors as one person.
6. **Hardening:** `debug` false; PHP `display_errors=Off`, `expose_php=Off`; delete `setup_check.php`, `styleguide.php`, `tests/`; check that the T15 URLs return 403. If images or pages return HTTP 500 after upload, the host does not allow `Options`/`RemoveHandler` in `.htaccess`: delete those lines in `uploads/.htaccess` and the root `.htaccess` and ask the host to disable PHP execution in `uploads/`. On Nginx, `.htaccess` is ignored: add `location ~ ^/garments/(includes|config|database|tests|tools|docs|cron)/ { deny all; }` and `location ~* ^/garments/uploads/.*\.php$ { deny all; }`.
7. **Backups:** use the host's database backup plus a daily `mysqldump` via cron to a location outside the web folder; download a copy weekly.
8. **Privacy.** The system stores names, phone numbers, e-mails, addresses and student numbers/LRNs (some belong to minors). Under the Data Privacy Act of 2012 (RA 10173) the school should have a privacy notice on the site, a stated retention period, and its Data Protection Officer should approve publishing it. Expired orders are purged after `recycle_purge_days`; there is no automatic deletion of old customer data yet.
