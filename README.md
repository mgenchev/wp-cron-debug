# WP Cron Debug

Developer-focused WP-CLI package for inspecting and debugging scheduled WP-Cron events without installing a WordPress plugin or using wp-admin.

## Requirements

- PHP >= 7.4
- WP-CLI >= 2.8
- WordPress installation accessible to WP-CLI
- `proc_open()` enabled for isolated cron execution

## Installation

During development:

```bash
wp package install .
```

After publishing:

```bash
wp package install https://github.com/<owner>/<repository>.git
```

## Usage

Open the interactive debugger:

```bash
wp cron-debug
```

Run a known scheduled hook directly:

```bash
wp cron-debug app_sync_inventory
```

If the hook has one scheduled instance, its details are shown directly. If multiple instances exist, the package first asks which exact timestamp/argument combination to inspect.

Optional timeout for one isolated cron execution:

```bash
wp cron-debug --timeout=900
```

The default timeout is 300 seconds. The accepted range is 1–86400 seconds.

## Interactive flow

The main menu groups currently scheduled events by the origin of their registered callback(s):

```text
Cron Debug

[1] WordPress Core    12 events
[2] Plugins            8 events
[3] Theme              3 events
[4] Other              2 events
[x] Exit
```

`Other` intentionally includes hooks with unknown, mixed, or missing callback origins instead of guessing.

Every internal menu always includes both:

```text
[0] Back
[x] Exit
```

`Back` returns one level. `Exit` terminates the entire command and returns to the shell.

On an interactive terminal with ANSI screen control, moving between menus replaces the visible screen instead of stacking old menus above the current one. This applies when entering a category, choosing an event or exact instance, opening event details, starting a run, re-running, and navigating back. Terminals without supported screen control fall back to normal appended output. The package does not attempt to erase terminal scrollback or shell history.

Event lists keep the diagnostic status and final next-run columns aligned so long hook names and different recurrence labels remain easy to scan. Each hook receives a conservative `✓ OK`, `! WARN`, or `✗ ERROR` status based on the worst diagnostic state across its scheduled instances.

Inside a category the menu contains only scheduled cron events plus navigation:

```text
[1] app_sync_inventory       ✓ OK        app-core · hourly · in 12 minutes
[2] app_newsletter_send      ! WARN      newsletter · daily · overdue 8 minutes
[3] cleanup_import_queue     ✗ ERROR     importer · every_5_minutes · in 2 minutes
[0] Back
[x] Exit
```

Selecting an event does not run it immediately. The package first shows its details, including hook, category, source, recurrence, next-run time, exact arguments, callback name, callback file/line, priority, and callable status:

```text
Cron Event Details

EVENT
--------------------------------------------------------------------
Hook:       app_sync_inventory
Category:   Plugins
Source:     app-core
Schedule:   hourly
Next run:   2026-09-16 10:42:00 UTC (in 36 minutes)
Arguments:  {"product_id":42}

CALLBACK
--------------------------------------------------------------------
Callback:   App\Inventory\Cron::sync
File:       /path/to/wp-content/plugins/app-core/src/Cron.php:184
Priority:   10
Callable:   yes

DIAGNOSTICS
--------------------------------------------------------------------
✓ OK     No obvious issues detected.

[1] Debug run            Schedule preserved
[2] Profile run          Schedule preserved + DB profile
[3] Run as real cron     Reschedule/unschedule like WP-Cron
[0] Back
[x] Exit
```

When a hook has multiple scheduled instances, the package first asks which exact instance to inspect and shows its arguments, next-run timing, and recurrence. After an instance is selected, the same details screen is shown before execution.

After an execution:

```text
Selected: app_sync_inventory
⠋ Running cron event...
✓ Cron completed successfully.
Log: cron-debug.log

[1] Run again
[0] Back
[x] Exit
```

Before `Run again`, the exact scheduled event is revalidated. If it no longer exists, it is not executed from a stale snapshot.

## Event diagnostics

The details screen performs conservative diagnostics before execution. It can report:

- missing registered callbacks;
- callbacks that are registered but not callable;
- scheduled events overdue by at least five minutes;
- recurrence names that are no longer registered;
- recurring events with an invalid interval;
- stored recurrence intervals that differ from the currently registered schedule;
- a stored cron signature that does not match the event arguments.

Diagnostics are informational only. The package does not automatically change or repair scheduled events.

## Execution modes

### Debug run

A debug run executes the selected hook with the exact scheduled event arguments but does **not** unschedule, reschedule, or advance the event. This makes repeated developer testing predictable.

Each run is executed in a fresh WP-CLI child process. This is deliberate:

- `DOING_CRON` is defined before WordPress/plugins/themes load in the worker;
- edits to plugin/theme PHP are loaded on the next run;
- a callback fatal error or `exit` does not kill the interactive parent menu;
- stdout and stderr can be captured independently;
- the selected scheduled event is revalidated inside the worker immediately before execution.

The full WordPress runtime is required because callback registration happens while WordPress, plugins, MU plugins, and themes load. This package therefore intentionally runs after WordPress has loaded rather than using `before_wp_load`.

### Profile run

A profile run preserves the cron schedule and executes the same exact event in a fresh worker, while additionally enabling WordPress `SAVEQUERIES` before WordPress loads in that worker. This allows the log to include callback-time database query count, cumulative query time, memory delta, and up to 10 captured queries sorted by duration, with caller information where available. The `PROFILE` log section separates these into a compact `SUMMARY` block and individually numbered `TOP 10 QUERIES BY DURATION` blocks for easier scanning. This is a ranking by duration, not a claim that every listed query is objectively slow.

Profiling is opt-in because `SAVEQUERIES` adds runtime overhead. SQL text and caller strings are bounded before being written to the log.

### Run as real cron

This mode applies the selected event's WordPress schedule lifecycle before executing its callback:

1. a recurring event is rescheduled using its current recurrence;
2. the selected current instance is unscheduled;
3. the callback is executed with the exact event arguments.

This intentionally changes the cron schedule. It runs immediately after selection without an extra confirmation prompt. After the run, the cron registry is refreshed from a fresh database snapshot and the interface returns directly to the relevant event list instead of offering `Run again` for an event instance that has already been moved or removed. The log records whether the reschedule and unschedule operations succeeded.

This mode reproduces the event-level schedule lifecycle used by WordPress cron. It does **not** emulate the full `wp-cron.php` process-level lock, spawn request, or loop over all currently due events.

## Log file

Exactly one log file is written in the directory from which the command was started:

```text
./cron-debug.log
```

Every cron execution **overwrites** this file. It is not an append-only history.

The log contains execution metadata, callback/source information, event arguments, result status, duration, peak memory where available, captured stdout (`var_dump`, `print_r`, `echo`, etc.), and stderr/PHP errors. `RESULT` appears directly after the main `WP CRON DEBUG` metadata section, before `OUTPUT`. Section headings use a single separator below the heading for easier scanning. Profile runs additionally include a `PROFILE` section; real-cron runs include a `CRON LIFECYCLE` section.

Captured stdout is preserved as emitted by the callback. The debugger does not remove HTML wrappers or shorten long `var_dump()` values, because those values may be the exact data being investigated.

A callback that explicitly calls `exit` or `die` is reported as `EXITED`, rather than as a generic failure. This keeps the result distinct from fatal errors and exceptions during debugging.

Captured output is bounded to 10 MB per run. Additional output is discarded and the log is marked as truncated.

Because arbitrary debug output may contain secrets or personal data, treat `cron-debug.log` as sensitive developer output and do not commit it.

## Event categories

Callback source detection is based on the callback's reflected PHP file:

- **WordPress Core**: callbacks located under `wp-includes` or `wp-admin`.
- **Plugins**: callbacks located under the normal plugins or MU-plugins roots.
- **Theme**: callbacks located under the WordPress theme root.
- **Other**: unknown source, missing callback, or hooks whose callbacks span multiple categories.

The package does not classify events by hook-name heuristics or hard-coded plugin lists.

## Safety boundaries

Normal debug and profile runs do not modify the cron schedule. Schedule changes are isolated behind the explicit `Run as real cron` action. Real-cron execution returns to a freshly loaded event list after the run. Cron discovery and exact-event revalidation bypass the long-lived parent process option cache so rescheduled timestamps are visible immediately.

The package does not provide generic cron deletion/editing controls or automatically repair diagnostics findings.

The selected event is identified by hook, timestamp, and exact argument array. The worker validates the corresponding cron-array signature immediately before executing the hook, matching WordPress' event identity model.

## Current limitations

- Action Scheduler is intentionally out of scope for this WP-Cron-focused build and can be added as a separate job-engine layer later.
- Source classification can report `Other` for unusual callback loaders or path layouts that cannot be proven to belong to core/plugins/theme.
- PHP/bootstrap output produced before the debug callback begins may also appear in the captured child-process output, which is useful when debugging load-time problems.

## Visual status UI

Interactive event lists include an aligned diagnostic status column. Statuses use light WP-CLI color coding when color output is enabled: green for OK, yellow for warnings, and red for errors. Event details are grouped into EVENT, CALLBACK, and DIAGNOSTICS sections. `--no-color` remains supported by WP-CLI.
