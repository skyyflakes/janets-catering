@echo off
rem Creates the hourly task that deletes archived bookings and reports after 30 days.
rem Run once. Running it again replaces the task. php-win.exe runs without opening a window.
set PHP_EXE=C:\xampp\php\php-win.exe
set SCRIPT=%~dp0maintenance_cli.php
schtasks /Create /F /SC HOURLY /TN "JanetsCateringArchiveCleanup" /TR "\"%PHP_EXE%\" \"%SCRIPT%\" purge"
