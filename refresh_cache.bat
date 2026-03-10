@echo off
REM ============================================================
REM  KGS Soil Moisture Cache Refresh
REM  Run via Windows Task Scheduler every 15 minutes
REM
REM  Setup:
REM  1. Open Task Scheduler
REM  2. Create Basic Task: "KGS Soil Moisture Cache Refresh"
REM  3. Trigger: Daily, repeat every 15 minutes indefinitely
REM  4. Action: Start a program
REM     Program: C:\path\to\php\php.exe
REM     Arguments: "C:\inetpub\wwwroot\soilmoisture\api\refresh_cache.php"
REM  5. Run whether user is logged on or not
REM  6. Run with highest privileges
REM ============================================================

SET PHP_EXE=C:\php\php.exe
SET SCRIPT=\\kgsgarnet\webshare\kygeode\services\landslide\api\refresh_cache.php
SET LOG=\\kgsgarnet\webshare\kygeode\services\landslide\cache\refresh.log

echo [%DATE% %TIME%] Starting cache refresh >> "%LOG%"
"%PHP_EXE%" "%SCRIPT%" >> "%LOG%" 2>&1
echo [%DATE% %TIME%] Done >> "%LOG%"
