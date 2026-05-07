@echo off
REM ============================================================
REM  KGS Landslide Monitoring Network — Cache Refresh
REM  Zentra Cloud 2.0 / API v5
REM
REM  RATE LIMIT REALITY:
REM  Zentra v5 GCRA rate limit = burst of 5, then 1 req/min.
REM  24 stations = ~25 minutes minimum per full run.
REM  A full run MUST complete before the next one starts to
REM  avoid two instances competing for the rate limit budget
REM  (which causes 429 errors and slows everything down).
REM
REM  RECOMMENDED TASK SCHEDULER SETTINGS:
REM  ─────────────────────────────────────
REM  1. Open Task Scheduler → Create Task (not Basic Task)
REM
REM  GENERAL tab:
REM    Name:        KGS Landslide Cache Refresh
REM    Description: Refreshes Zentra soil moisture data cache
REM    Run As:      [domain account with access to \\kgsgarnet\webshare\]
REM                 NOT "SYSTEM" or "Local Service" — they can't
REM                 access UNC network shares
REM    [x] Run whether user is logged on or not
REM    [x] Run with highest privileges
REM
REM  TRIGGERS tab:
REM    New Trigger → Daily
REM    Start: today at 00:00
REM    [x] Repeat task every: 30 minutes  ← KEY: 30 not 15
REM        for a duration of: Indefinitely
REM
REM  ACTIONS tab:
REM    Action:    Start a program
REM    Program:   C:\php\php.exe
REM    Arguments: "[PATH TO]\slope-monitoring\api\refresh_cache.php"
REM
REM  CONDITIONS tab:
REM    [ ] uncheck "Start only if computer is on AC power" if on a server
REM
REM  SETTINGS tab:
REM    [x] If the task is already running, do not start a new instance
REM         ^^^^^ THIS IS THE CRITICAL SETTING ^^^^^
REM         Prevents overlapping runs from competing for the API
REM         rate limit. Run 1 finishes (~25 min), then run 2 starts.
REM    [x] Stop the task if it runs longer than: 1 hour
REM        (safety net in case of a hang)
REM
REM  WHY 30 MINUTES:
REM  A full 24-station run takes ~25 min. With "do not start new
REM  instance" set, a 15-min trigger just queues up a backlog.
REM  30 minutes gives the run time to finish cleanly, then the
REM  next run starts fresh. Worst-case data age = ~30 minutes.
REM ============================================================

SET PHP_EXE=C:\php\php.exe
SET SCRIPT=[PATH TO]\slope-monitoring\api\refresh_cache.php
SET LOG=[PATH TO]\slope-monitoring\cache\refresh.log

echo [%DATE% %TIME%] Starting cache refresh >> "%LOG%"
"%PHP_EXE%" "%SCRIPT%" >> "%LOG%" 2>&1
echo [%DATE% %TIME%] Done >> "%LOG%"
