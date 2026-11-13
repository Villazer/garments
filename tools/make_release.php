<?php
/**
 * Build a clean install package from your working copy:   php tools/make_release.php
 * Writes release/wmsu-garments-YYYYMMDD.zip. Leaves out everything that must not go to the real server:
 * config/config.php (secrets), tests, the dev pages, uploaded files, backups, old patch copies, logs, git data.
 * Needs the PHP zip extension (on in XAMPP).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!class_exists('ZipArchive')) { fwrite(STDERR, "Enable extension=zip in php.ini.\n"); exit(1); }
$root = dirname(__DIR__);
$skip_prefix = ['release/', 'backup/', 'tests/', '.git/', 'uploads/items/', 'database/clear_sample_data.sql', 'database/seed.sql', 'node_modules/'];
$skip_exact  = ['config/config.php', 'setup_check.php', 'styleguide.php', 'assets/css/styleguide.css', 'tools/apply_session10_patches.php', 'tools/make_release.php'];
$keep_always = ['uploads/items/.htaccess', 'uploads/items/index.html', 'database/clear_sample_data.sql'];
$skip_ext    = ['log', 'bak', 'zip', 'swp', 'tmp', 'orig'];

@mkdir($root . '/release', 0755, true);
$zip_path = $root . '/release/wmsu-garments-' . date('Ymd') . '.zip';
@unlink($zip_path);
$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE) !== true) { fwrite(STDERR, "Cannot create the zip.\n"); exit(1); }
$n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile()) { continue; }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $skip = in_array($rel, $skip_exact, true) || in_array(strtolower($file->getExtension()), $skip_ext, true);
    foreach ($skip_prefix as $p) { if (strpos($rel, $p) === 0) { $skip = true; } }
    if (in_array($rel, $keep_always, true) && $rel !== 'database/clear_sample_data.sql') { $skip = false; }
    if ($skip) { continue; }
    $zip->addFile($file->getPathname(), 'garments/' . $rel);
    $n++;
}
$zip->close();
echo "Wrote $zip_path ($n files).\nOn the server: unzip, copy config/config.sample.php to config/config.php, edit it, import the SQL, run tools/check_install.php.\n";
