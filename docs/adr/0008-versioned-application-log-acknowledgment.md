# Acknowledge an investigated application-log version

The Super Administrator may acknowledge a specific application-log version after recording a result and verification note. Preserve both the log file and a bounded recent excerpt in Protected Audit Records. This separates reviewed diagnostic history from later log writes without clearing current database, storage, or other service failures.

Identify append-only logs using file statistics and bounded start/end samples so large logs do not require full hashing on every dashboard refresh. New writes or rotation require another review; custom edits confined to the middle of a file are outside this identification model and would require stronger version tracking. Logs remain diagnostic evidence, while acknowledgment records a human assessment rather than proving a service is working.
