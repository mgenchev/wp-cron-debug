# Changelog

## 0.1.0 - 2026-09-16

### Added

- Initial `wp cron-debug` interactive developer tool.
- WordPress Core, Plugins, Theme, and Other event categories based on callback source files.
- Exact scheduled-instance selection for hooks with multiple argument/timestamp combinations.
- Schedule-preserving debug execution in a fresh WP-CLI child process with `DOING_CRON` defined before WordPress loads.
- Capture of callback stdout and stderr, including common `var_dump()`, `print_r()`, `echo`, PHP error, exception, and fatal-debug output.
- Single `cron-debug.log` in the command working directory, overwritten on every cron run.
- Bounded 10 MB captured output and configurable execution timeout.
- `Run again`, `Back`, and global `Exit` navigation, with `[x] Exit` available in every internal menu.
- Revalidation of the exact scheduled event before execution and before a re-run.
- Direct hook mode via `wp cron-debug <hook>` with exact-instance selection when a hook has multiple scheduled events.
- Conservative event diagnostics for missing/non-callable callbacks, overdue events, unknown/changed recurrence schedules, invalid intervals, and inconsistent event signatures, with explicit OK/WARN/ERROR status reporting in event lists.
- Profile runs with worker-scoped `SAVEQUERIES`, query count/time, memory delta, and up to 10 query details sorted by duration.
- Explicit real-cron lifecycle execution with reschedule/unschedule result reporting and schedule-changing behavior isolated from normal debug runs.
- ANSI-aware screen replacement, status color coding, aligned event columns, and structured EVENT/CALLBACK/DIAGNOSTICS detail sections.

### Changed

- Profile query details are labeled `QUERIES BY DURATION` rather than implying an absolute slow-query threshold.
- Added extra spacing between profile query numbers and timings for easier scanning.
- Reformatted the `PROFILE` log section into a compact `SUMMARY` block and separate numbered `QUERIES BY DURATION` blocks with aligned caller/query labels.
- Simplified log section formatting: each heading has a single separator below it, and `RESULT` appears immediately after the main `WP CRON DEBUG` metadata section.
- Real-cron execution does not ask for an extra confirmation and returns directly to a freshly loaded event list after completion.
- Removed duration from console run summaries; duration remains available in `cron-debug.log`.
- Captured stdout is preserved exactly as emitted by the callback; no automatic `<pre>` removal or `var_dump()` string compaction is applied.
- Callbacks that invoke `exit`/`die` are reported as `EXITED` instead of the ambiguous `TERMINATED` status.

### Fixed

- Fixed stale event lists after `Run as real cron` by reading the current cron option directly from the database instead of reusing the long-lived parent process option cache.
