@echo off
rem Daily database + photo backup for XAMPP (Session 10). Edit the three lines below if your paths differ.
rem Run it by double-clicking, or schedule it with Windows Task Scheduler (README section 8).
set XAMPP=C:\xampp
set SITE=%XAMPP%\htdocs\garments
set BACKUPDIR=D:\GarmentsBackup
rem BACKUPDIR should be a DIFFERENT drive or a USB disk, not the same disk as the site.

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmm"') do set STAMP=%%i
if not exist "%BACKUPDIR%" mkdir "%BACKUPDIR%"

rem Put the database password in %SITE%\tools\backup.cnf (see README) instead of typing it here:
rem   [client]
rem   user=garments_app
rem   password=YOUR-PASSWORD
"%XAMPP%\mysql\bin\mysqldump.exe" --defaults-extra-file="%SITE%\tools\backup.cnf" --single-transaction --routines --events --default-character-set=utf8mb4 wmsu_garment_shop > "%BACKUPDIR%\wmsu_garment_shop-%STAMP%.sql"
if errorlevel 1 (
  echo BACKUP FAILED. Do not ignore this.
  del "%BACKUPDIR%\wmsu_garment_shop-%STAMP%.sql" 2>nul
  pause
  exit /b 1
)
rem Item photos
xcopy "%SITE%\uploads\items" "%BACKUPDIR%\uploads-items" /E /I /Y /Q >nul

rem Keep the newest 30 database backups
powershell -NoProfile -Command "Get-ChildItem '%BACKUPDIR%\wmsu_garment_shop-*.sql' | Sort-Object LastWriteTime -Descending | Select-Object -Skip 30 | Remove-Item"
echo Backup finished: %BACKUPDIR%\wmsu_garment_shop-%STAMP%.sql
