Scheduled PHP scripts (Session 9)

expire_orders.php  Expires unpaid reservations whose hold ended, releases their stock,
                   writes the history and moves them to the recycle bin.

Windows Task Scheduler (XAMPP):
  1. Task Scheduler > Create Basic Task > trigger: repeat every 5 minutes.
  2. Action "Start a program":
       Program/script: C:\xampp\php\php.exe
       Add arguments:  C:\xampp\htdocs\garments\cron\expire_orders.php
  3. Test it once in a command prompt:
       C:\xampp\php\php.exe C:\xampp\htdocs\garments\cron\expire_orders.php

No scheduler? The same job also runs by itself (at most once every few minutes, see
Settings) whenever a staff page is opened, and admins have a Run now button.
Importing database/migrations/007_admin_settings.sql removes the old MySQL event
ev_expire_unpaid_orders so only one expiry mechanism remains.
