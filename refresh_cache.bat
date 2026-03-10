@echo off
REM ============================================================
REM  KGS Landslide Monitoring Network — Cache Refresh
REM  Zentra Cloud 2.0 / API v5
REM
REM  Run via Windows Task Scheduler every 15 minutes.
REM
REM  IMPORTANT — v5 Rate Limit Note:
REM  Zentra v5 uses GCRA: burst of 5 requests, then 1 req/min.
REM  With 25 stations, a FULL refresh takes ~25 minutes.
REM  The refresh script handles this automatically — it prioritizes
REM  the most-stale stations first and respects the rate limit.
REM  Running every 15 min means ~15 stations will be refreshed
REM  per run. All stations will be refreshed within ~30 minutes.
REM
REM  Task Scheduler Setup:
REM  1. Open Task Scheduler → Create Basic Task
REM  2. Name: "KGS Landslide Cache Refresh"
REM  3. Trigger: Daily, repeat every 15 minutes indefinitely
REM  4. Action: Start a program
REM     Program:   C:\php\php.exe
REM     Arguments: "\\kgsgarnet\webshare\kygeode\services\landslide\api\refresh_cache.php"
REM  5. Run whether user is logged on or not
REM  6. Run with highest privileges
REM  7. IMPORTANT: Run As a domain account with access to \\kgsgarnet\webshare\
REM     (not SYSTEM or Local Service — they can't access network shares)
REM ============================================================

SET PHP_EXE=C:\php\php.exe
SET SCRIPT=\\kgsgarnet\webshare\kygeode\services\landslide\api\refresh_cache.php
SET LOG=\\kgsgarnet\webshare\kygeode\services\landslide\cache\refresh.log

echo [%DATE% %TIME%] Starting cache refresh >> "%LOG%"
"%PHP_EXE%" "%SCRIPT%" >> "%LOG%" 2>&1
echo [%DATE% %TIME%] Done >> "%LOG%"