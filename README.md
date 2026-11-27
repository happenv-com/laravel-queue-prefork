# Laravel Queue Prefork

[![Latest Version](https://img.shields.io/github/v/release/happenv-com/laravel-queue-prefork?style=flat-square&label=version)](https://github.com/happenv-com/laravel-queue-prefork/releases)
[![Tests](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-queue-prefork/tests.yml?label=tests&style=flat-square)](https://github.com/happenv-com/laravel-queue-prefork/actions/workflows/tests.yml)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-queue-prefork/phpstan.yml?label=phpstan&style=flat-square)](https://github.com/happenv-com/laravel-queue-prefork/actions/workflows/phpstan.yml)
[![Quality](https://img.shields.io/github/actions/workflow/status/happenv-com/laravel-queue-prefork/quality.yml?label=code%20quality&style=flat-square)](https://github.com/happenv-com/laravel-queue-prefork/actions/workflows/quality.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/happenv-com/laravel-queue-prefork.svg?style=flat-square)](https://packagist.org/packages/happenv-com/laravel-queue-prefork)
[![License](https://img.shields.io/github/license/happenv-com/laravel-queue-prefork.svg?style=flat-square)](LICENSE.md)

Run a pool of Laravel queue workers as forks of one small parent that never loads your app, so every worker shares a single opcache copy of the compiled code.

```bash
vendor/bin/queue-prefork --workers=8 redis --queue=high,default --tries=3 --max-time=3600
```

That one process replaces eight `php artisan queue:work redis --queue=high,default --tries=3 --max-time=3600` processes. Each worker is a stock `queue:work` that boots your app itself. Only the compiled code is shared, so the workers keep the isolation of separate processes:
- their own database and Redis connections;
- their own container, singletons and random seed;
- nothing inherited from a booted parent.

## Who it is for

**Large applications that run many workers.** Every `queue:work` process compiles your whole app into its own private opcache. In a big app that copy is 150–250 MB, and it is identical in every worker. Ten workers hold ten copies.

- **The saving grows with each worker.** In the benchmark below, each worker beyond the first saves about 200 MB.
- **4 workers:** 1,020 MB → 398 MB (−61 %).
- **16 workers:** about 4.1 GB → 1.0 GB (−75 %).

**Small apps gain less.** If your app compiles to 30 MB, each extra worker saves about 30 MB. With a single worker there is nothing to share, and the pool parent costs a little more than stock.

## Key features

- **About 200 MB less per extra worker** in a large app, measured as PSS (see [Benchmarks](#benchmarks)).
- **Workers ready in 0.2 s instead of 1.1–1.5 s** after `--max-time`, `--max-jobs`, `--memory`, a crash or `queue:restart`. A new worker finds the code already compiled.
- **The same throughput and the same `queue:work`.** All its options, signals, `queue:restart`, `queue:pause` and maintenance mode behave as in stock.
- **No FFI, no sockets between unrelated processes, no stubs.** The parent forks its own children, and they inherit its stdout and stderr.
- **Readable logs.** Laravel writes each job line in several pieces; the workers hold their stdout until the end of a line, so lines from different workers never cut into each other. In the benchmark app, 4 workers on 2,000 jobs cut 1,695 of 4,004 lines without it, and none with it.
- **Safe failure modes:**
  - workers that keep dying before their first loop stop the pool with exit code 1, so your process manager restarts it with its own backoff;
  - workers left without their parent stop after their current job.
- **Tested against a real process tree** on Linux and macOS: start, replacement, graceful stop, process-group stops, signal forwarding, crash breaker, orphaned workers and a hanging warm-up.

## How it works

```
queue-prefork (parent, ~16 MB, never loads your app)
│   owns opcache's shared memory
│
├── warm-up: php artisan list --quiet      boots the app once, fills opcache, exits
│
├── worker 1: php artisan queue:work …     boots the app itself, on a warm opcache
├── worker 2: php artisan queue:work …
└── worker N: php artisan queue:work …
```

1. **The parent starts.** It loads only this package's five classes, without Composer's autoloader. It owns opcache's shared memory segment, which every fork inherits, the way PHP-FPM workers share their master's.
2. **One warm-up child runs.** It runs `php artisan list --quiet`, which boots your app and loads every command, then exits. Opcache now holds the compiled app.
3. **N workers start.** Each worker is a fork of the parent that boots your app from scratch, as `artisan` does, and runs `queue:work` with your arguments. It compiles almost nothing, because the code is already in the shared segment. That is why its private heap is small.
4. **The parent supervises.** It replaces workers that exit and forwards signals. On TERM, INT or QUIT it waits for every worker to finish its job, then exits 0.

**Why not fork a booted app?** Forking a parent that has already booted Laravel would also share the boot heap, and it would start workers in about 1 ms. We measured that design on the same app, and it lost on every count:
- **It used more memory.** The parent had compiled the app, so every child inherited the compiler's leftovers, and copy-on-write slowly duplicated them. The pool used 461 MB against 398 MB here.
- **Every bug of shared state comes with it:** connections opened at boot, singletons, random seeds, process IDs captured at boot, and stale code after `queue:restart`.

## Requirements

| | |
|---|---|
| PHP | 8.3 – 8.5, CLI, with `pcntl`, `posix` and opcache |
| Laravel | 12, 13 |
| OS | Linux for production. macOS works for development. Windows is not supported (no `fork()`). |
| Queue | `queue:work`. Horizon is not supported: it starts its own processes. |

## Installation

```bash
composer require happenv-com/laravel-queue-prefork
```

There is no service provider and no config file. The package adds one script, `vendor/bin/queue-prefork`.

## Usage

```bash
vendor/bin/queue-prefork [--workers=N] [--base-path=DIR] [--warmup="COMMAND" | --no-warmup] [--warmup-timeout=SECONDS] [--] [queue:work arguments]
```

| Option | Default | Meaning |
|---|---|---|
| `--workers=N` | 1 | Workers in the pool. |
| `--base-path=DIR` | current directory | The Laravel application. |
| `--warmup="COMMAND"` | `list --quiet` | Artisan command that fills opcache before the workers start. It must not change anything. |
| `--no-warmup` | | Start the workers on an empty opcache. They then compile the code themselves, and each keeps more private memory. |
| `--warmup-timeout=SECONDS` | 60 | Kill a warm-up still running after this long, and start the workers anyway. |
| `--` | | Ends the pool's options. Everything after it goes to `queue:work`, even `--workers`. |

Every other argument goes, unchanged, to each worker's `queue:work`. `--once` and `--stop-when-empty` are refused: a pool would restart such a worker after every job.

### Docker

Run one pool per container instead of one `queue:work` per container:

```yaml
services:
  queue:
    image: your-app
    command: ['php', 'vendor/bin/queue-prefork', '--workers=8', 'redis', '--queue=default', '--tries=3', '--max-time=3600', '--memory=256']
    stop_signal: SIGTERM
    stop_grace_period: 90s # longer than your longest job
    deploy:
      resources:
        limits:
          memory: 1G # see "Sizing memory" below
```

The pool can run as PID 1: it handles TERM, INT and QUIT itself and reaps any orphaned process. `init: true` is not needed.

### supervisord

```ini
[program:queue]
command=php /var/www/app/vendor/bin/queue-prefork --workers=8 redis --queue=default --tries=3 --max-time=3600
numprocs=1
stopsignal=TERM
stopwaitsecs=3600
stopasgroup=true
```

Use `numprocs=1` and set the worker count with `--workers`. With `stopasgroup` the workers get TERM twice, once from supervisord and once from the pool. That is harmless: `queue:work` stops once.

### systemd

```ini
[Service]
ExecStart=/usr/bin/php /var/www/app/vendor/bin/queue-prefork --workers=8 redis --queue=default --max-time=3600
KillSignal=SIGTERM
KillMode=mixed
TimeoutStopSec=3600
Restart=always
```

`KillMode=mixed` sends TERM to the pool only and lets it stop the workers. The workers finish their jobs, and SIGKILL reaches the whole group only after the timeout.

### PHP settings

```ini
; php.ini (or php-cli.ini) for the CLI
opcache.enable_cli=1                 ; the pool turns it on by itself if it is off
opcache.memory_consumption=256       ; more than your compiled app: the warm-up log prints its size
opcache.interned_strings_buffer=32   ; package-heavy apps overflow the default 8 MB
opcache.max_accelerated_files=100000
```

The pool's log tells you whether opcache is big enough:

```
[queue-prefork 2026-11-14 13:55:56] warm-up took 1.76s; opcache holds 6390 scripts in 158 of 256 MB
[queue-prefork 2026-11-14 13:55:56] started 4 workers: 4997, 4998, 4999, 5000
```

### Sizing memory

A pool needs roughly:

```
shared opcache + ~16 MB for the parent + N × (a worker's private heap)
```

The private heap is what `memory_get_usage(true)` reports in a job, plus a few MB. Size the container limit for the pool, not for one worker. Each worker's `--memory` still guards its own heap, as in stock.

## Benchmarks

Measured on a large modular Laravel 13 application, on a Linux container (10 CPUs) with PHP 8.5:
- **App size:** about 5,600 PHP files compiled at boot, 6,390 after the warm-up, 348 Eloquent models and about 158 MB of opcache.
- **Opcache settings, the same in every variant:** 256 MB, 32 MB of interned strings, JIT off.
- **Job mix:** read-only jobs that load and serialise 25–500 rows of each of the 348 models, run with production `queue:work` options.

**Memory** is the PSS of every process in the pool, summed from `/proc/<pid>/smaps_rollup`. PSS splits each shared page between the processes that map it, so it adds up to the real footprint. RSS would count the shared opcache once per process.

### Memory, 4 workers

| | Idle | After 40 jobs | After 340 jobs |
|---|---:|---:|---:|
| 4 × `queue:work`, opcache with `opcache.file_cache` | 838 MB | 975 MB | 1,020 MB |
| 4 × `queue:work`, opcache without `file_cache` | 973 MB | 1,098 MB | 1,131 MB |
| `queue-prefork --workers=4` | **287 MB** | **352 MB** | **398 MB (−61 %)** |
| `queue-prefork --workers=4`, after `queue:restart` | 287 MB | | |

Where it goes after 340 jobs:

| | Shared opcache | Private heap | Files | Total |
|---|---:|---:|---:|---:|
| 4 × `queue:work` | 4 × 175 MB | 4 × 73 MB | 24 MB | 1,020 MB |
| `queue-prefork` | 1 × 169 MB | 4 × 51 MB + 5 MB (parent) | 16 MB | 398 MB |

The workers' private heap is smaller too, 51 MB instead of 73 MB. A stock worker keeps the compiler's working memory after compiling the app; a pool worker finds the code compiled and never needs it.

### Memory by number of workers

Worked out from the measurement above: stock ≈ 255 MB per worker; pool ≈ 185 MB + 53 MB per worker.

| Workers | 1 × `queue:work` each | `queue-prefork` | Saved |
|---:|---:|---:|---:|
| 1 | 255 MB | 238 MB | −7 % |
| 2 | 510 MB | 291 MB | −43 % |
| 4 | 1.0 GB | 398 MB (measured) | −61 % |
| 8 | 2.0 GB | 609 MB | −70 % |
| 16 | 4.1 GB | 1.0 GB | −75 % |
| 32 | 8.2 GB | 1.9 GB | −77 % |

The saving per extra worker is about the size of your compiled code plus the compiler's leftovers. A smaller app saves proportionally less. The private heap grows with the jobs a worker runs, in both modes. Recycling workers with `--max-jobs` or `--max-time` resets it, and it costs a pool worker only 0.2 s.

### Time

| | 4 × `queue:work` | `queue-prefork` |
|---|---:|---:|
| A new worker is ready (start, recycle, crash, `queue:restart`) | 1.1 s with `file_cache`, 1.5 s without | **0.2 s** |
| CPU to boot a worker | 0.9–1.2 s | under 0.2 s (not measured separately) |
| Pool start (warm-up, once) | | 1.5–1.8 s |
| 100 heavy jobs on 4 workers | 21.9–22.4 s | 19.6–20.5 s |
| Median job | 0.74–0.81 s | 0.70–0.73 s |

**Throughput is the same within noise.** The first job of a fresh stock worker is slower (1.1–1.2 s against 0.63–0.67 s), because it compiles the code its job touches.

### Other designs, same benchmark

| Design | After 340 jobs | A new worker is ready in |
|---|---:|---:|
| 4 × `queue:work` (`file_cache`) | 1,020 MB | 1.1 s |
| Fork of a booted app | 461 MB | ~1 ms |
| [tevkert/queue-prefork](https://github.com/tevkert/queue-prefork) 1.0.5 | 417 MB | 0.3–0.46 s |
| **This package** | **398 MB** | **0.2 s** |

tevkert/queue-prefork takes a different route:
- it is a drop-in for existing `queue:work` programs and Horizon;
- it needs FFI to hand each program's output over to the forked worker.

This package is an explicit pool command instead:
- you run one pool instead of N programs;
- it needs no FFI;
- it does not support Horizon.

## Side effects and differences from stock `queue:work`

Read this before you switch a production pool. Most of these points also apply to any process manager that shares memory between workers, PHP-FPM included.

### Deployment shape

- **One process tree per pool, not one process per program.** All workers in a pool run the same `queue:work` arguments. A different queue or different options need a separate pool.
- **Limits apply to the whole pool:**
  - a container's memory limit covers the pool (see [Sizing memory](#sizing-memory));
  - its CPU limit is shared by all workers. Moving from N containers with one CPU each to one pool container needs the CPU limit raised.
- **Your monitoring sees different numbers.**
  - `docker stats`, `top` and RSS count the shared opcache once per worker, so their sum overstates the pool. Use PSS.
  - Health checks that read `/proc/1/cmdline` or look for `queue:work` as PID 1 must change: PID 1 is now the pool.
- **Process titles change.** `ps` shows `queue-prefork: pool of N (php artisan queue:work …)` and `queue-prefork: worker (php artisan queue:work …)`.
- **A single worker gains nothing.** There is nothing to share, and the parent costs about 16 MB.

### Processes and signals

- **The parent is the pool's single point of failure.** If it is killed with SIGKILL or by the OOM killer, the workers notice on their next loop, finish their current job and stop. The process manager must then restart the pool. In a container where the pool is PID 1, the container ends at once.
- **Signals:**
  - TERM, INT and QUIT stop the pool: they are forwarded to every worker, and the parent waits for their jobs;
  - USR2 (pause) and CONT (resume) are forwarded to every worker that has reached its first loop. The pool remembers the latest one, and a worker still booting, or started later (a replacement after `--max-time`, say), gets it at its first loop. Until then it has no handler, and USR2 would kill it. A worker that is still booting gets TERM instead of QUIT, whose default action dumps core;
  - other signals keep their default effect on the parent. HUP, for one, ends it, and the workers then stop as orphans after their job. Under `nohup`, an ignored HUP stays ignored.
- **Workers are replaced at once.** The parent replaces a worker whatever the reason it exited: `--max-time`, `--max-jobs`, `--memory` (exit code 12), a `--timeout` kill, a crash or `queue:restart`.
- **The crash breaker:** when 5 workers die before their first loop within 60 seconds, the pool stops with exit code 1. It assumes the app cannot boot (a fatal error in a service provider, a missing extension). A worker that dies in a job after its first loop is never counted.
- **Workers start and recycle together.** With `--max-time`, all workers of a pool reach it at the same moment and are replaced together, which takes about 0.2 s of capacity.
- **Output is shared, one line at a time.** Workers write to the pool's stdout and stderr, and there is no per-worker log file.
  - **Stdout is line-buffered.** Each worker holds its stdout until a newline, then writes whole lines, at most 4 KB per write, which a pipe keeps in one piece. A line longer than that is written in parts. A line without a newline appears once the next newline comes, or when the worker exits.
  - **What bypasses the buffer:** output that does not go through PHP's `STDOUT` stream, such as `echo`, `print`, `dump()` without a console and subprocesses writing to the inherited descriptor, is not held, so it can land in the middle of another worker's line.
  - **Stderr is not buffered,** so a fatal error (memory or time limit) is still reported in full. Lines on stderr from different workers can still interleave.
  - **A worker killed with SIGKILL loses a line it had not finished.**
  - A process that a job forks with `pcntl_fork()` does not repeat the half line its worker was holding.
- **`--once` and `--stop-when-empty` are refused,** because the pool would restart such a worker immediately.

### Opcache

- **One segment must hold the code of every worker.** When it fills up, workers compile into private memory and the saving disappears. The warm-up log line shows how full it is, and says `FULL` when it is.
- **The pool sets `opcache.validate_timestamps=1` and `opcache.revalidate_freq=0` for its workers.** A worker replaced after `queue:restart` therefore compiles the files a deploy changed. The cost is one `stat()` per file a worker includes.
- **Old versions of changed files stay in the segment as wasted memory until opcache restarts.** After many in-place deploys, restart the pool itself, not only its workers. With immutable images, every deploy starts a new pool anyway.
- **Opcache skips files modified less than 2 seconds before they are loaded** (`opcache.file_update_protection`). Finish `config:cache`, `view:cache` and similar steps before you restart workers.
- **`opcache.enable_cli` is turned on for the pool.** If the CLI has it off, the script re-executes itself, in the same process, with `-d opcache.enable_cli=1`. Your other CLI commands are not affected.
- **`opcache.file_cache` is optional** and no longer needed for fast worker starts.

### Process-wide state

- **Extensions start once, in the parent, and every worker inherits them.** Extensions that start threads or open sockets when PHP starts can break after a fork. Examples: gRPC without `grpc.enable_fork_support=1`, some APM agents and profilers (New Relic, the Datadog profiler, Blackfire). Test the pool with your CLI extensions before production.
- **APCu with `apc.enable_cli=1` becomes one cache shared by the pool,** as under PHP-FPM, instead of one per worker.
- **`auto_prepend_file` runs once, in the parent, not in each worker.**
- **Each worker gets its own state, set when it is forked:**
  - an `mt_rand()` seed;
  - an empty realpath cache;
  - a reset peak memory;
  - `REQUEST_TIME`, `REQUEST_TIME_FLOAT` and `LARAVEL_START`.

  Everything else in the app (container, config, connections) is created by the worker's own boot.
- **The warm-up boots your app once more at pool start.** Your service providers' `register()` and `boot()` run in it, as they do for any Artisan command.

## Testing

```bash
composer test     # unit tests and real process-tree tests (Linux or macOS)
composer ci       # everything CI checks
```

The feature tests start `bin/queue-prefork` against a fixture application and check the pool from outside:
- the workers' process IDs and parents;
- their arguments;
- the signals they receive;
- the pool's exit codes.

## Changelog

See [CHANGELOG](CHANGELOG.md) and [GitHub releases](https://github.com/happenv-com/laravel-queue-prefork/releases) for what has changed recently.

## Contributing

See [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Happenv sp. z o.o.](https://happenv.com)
- [webard](https://github.com/webard)
- [All contributors](../../contributors)

## License

The MIT License (MIT). See [License File](LICENSE.md) for more information.

---

<p align="center">
    <a href="https://happenv.com">
        <img src="art/happenv-logo.png" alt="Happenv" width="400">
    </a>
</p>
