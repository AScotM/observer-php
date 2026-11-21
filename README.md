# observer-php

A single-file, PHP 8.2+ Linux CLI observer for system manifests, configuration drift, historical snapshots, and runtime metrics.

## Requirements

- Linux with `/proc` and `/sys` mounted
- PHP 8.2 or newer, CLI SAPI
- `iproute2` for interface address discovery
- `pdo_sqlite` for optional SQLite history
- Optional `pcntl` for graceful watch signal handling

## Installation

```bash
mkdir -p ~/path/observer-php
cp observer-php-improved.php ~/path/observer-php/main.php
cd ~/path/observer-php
php -l main.php
```

## Usage

Legacy manifest mode remains available:

```bash
php main.php
php main.php --format table
php main.php --output snapshots/baseline.json
php main.php --compare snapshots/baseline.json --format table
```

Extended observation:

```bash
php main.php --extended --format table
php main.php --extended --format json
php main.php --extended --format csv
php main.php --extended --output snapshots/extended.json
php main.php --extended --compare snapshots/extended.json --format table
```

SQLite history:

```bash
php main.php --extended --database state/observer.sqlite --retention 100 --format table
php main.php --extended --database state/observer.sqlite --history 10
```

Watch mode:

```bash
php main.php --watch --interval 5 --count 12 --format table
php main.php --watch --interval 2 --count 0 --database state/observer.sqlite --retention 500
```

Thresholds:

```bash
php main.php --extended --memory-threshold 85 --swap-threshold 70 --disk-threshold 90
```

## Observed data

The extended snapshot includes CPU topology and counters, load and memory metrics, pressure stall information, block-device counters, mount points and capacity, network addresses and counters, kernel modules and limits, process statistics, security settings, and cgroup v2 root statistics when available.

## Accounting semantics

CPU utilization is computed from differences in `/proc/stat` user, nice, system, idle, iowait, irq, softirq and steal counters. Guest and guest_nice are not added to the denominator because they are already included in user and nice. Iowait is treated as idle for the busy-percent metric. CPU utilization is unavailable if required counters reset or do not advance. A changed boot ID invalidates all runtime deltas for that comparison.

Network error findings are emitted for increasing counters when a baseline exists. Absolute error counters remain in snapshots.

SQLite retention keeps the most recent N snapshots and N events independently per hostname, not N total records across all hosts.

## Limitations

- System snapshots are not atomic; each collector samples at a slightly different instant.
- `/proc` and `/sys` visibility may be limited inside containers.
- The cgroup v2 collector reports root cgroup data, not a recursive inventory of all cgroups.
- The command executor invokes a shell and is intended only for internal fixed commands; it must not receive untrusted command strings.
- Mount statistics are derived from the current mount namespace; some pseudo-filesystems are excluded from usage warnings but remain visible in snapshots.
- Existing extended snapshot schema version remains 2, with an additive `cgroups` section.
- Filesystem `free_bytes` follows `disk_free_space` semantics; use care when interpreting reserved blocks.

## Verification

```bash
php -l main.php
php main.php --extended --format json | python3 -m json.tool >/dev/null
php main.php --watch --count 2 --interval 0.3 --format json > /tmp/observer-watch.json
```

## License

GPL-3.0-or-later. Include the full GPLv3 license text in `LICENSE` when publishing the repository.
