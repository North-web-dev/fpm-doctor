# fpm-doctor

**Diagnose and tune PHP-FPM pools from real, measured data.** `fpm-doctor` reads
your pool configs, measures actual child process RSS (from Linux `/proc` or the
FPM status page), and turns the usual `pm.max_children` guesswork into honest,
reproducible recommendations. It also detects the shared-pool pattern that
produces silent 502 storms, and summarises your slowlog.

It is **read-only**: it never edits a config and never restarts anything. It
prints what it found and what it would change, and you apply it.

---

## The real problem it solves

A shared `php-fpm` master serves several sites from one pool (or several pools
that together outweigh the box). Under load the pool hits `pm.max_children`,
new requests pile into the listen queue, and the web server starts returning
**502 Bad Gateway** — while there may still be *free RAM*, or, worse, while the
box is one traffic burst away from the OOM killer.

The two failure modes are opposite and both common:

- **`pm.max_children` set too high** → every worker is allowed to spawn, they
  collectively exceed RAM, the OOM killer reaps `php-fpm`, every site 502s.
- **`pm.max_children` set too low** → workers saturate while RAM sits idle,
  requests queue, slow endpoints make it worse, sites 502 under load that the
  hardware could easily handle.

Almost nobody computes `pm.max_children` — they guess. The correct number is
just `RAM budget ÷ average worker RSS`, but that needs a *measured* RSS, not a
number off a blog. `fpm-doctor` measures it and does the arithmetic, and it
tells you which method it used so you can trust (or challenge) the result.

---

## Install

### Composer (global)

```bash
composer global require north-web-dev/fpm-doctor
# ensure ~/.composer/vendor/bin (or ~/.config/composer/vendor/bin) is on your PATH
fpm-doctor --version
```

### Composer (per project)

```bash
composer require --dev north-web-dev/fpm-doctor
./vendor/bin/fpm-doctor analyze
```

### Standalone PHAR

Grab `fpm-doctor.phar` from the [latest release](https://github.com/North-web-dev/fpm-doctor/releases),
drop it on the server, and run it with any PHP 8.1+:

```bash
curl -L -o fpm-doctor.phar \
  https://github.com/North-web-dev/fpm-doctor/releases/latest/download/fpm-doctor.phar
chmod +x fpm-doctor.phar
./fpm-doctor.phar analyze
# or install it on PATH:
sudo mv fpm-doctor.phar /usr/local/bin/fpm-doctor
```

The PHAR has **zero runtime dependencies** — pure PHP, ~67 KB.

**Requirements:** PHP 8.1+ with `ext-json`. RSS auto-measurement needs Linux
`/proc`; everywhere else, pass `--avg-rss` or point it at the status page.

---

## Commands

### `analyze` — size `pm.max_children` from measured RSS

```
fpm-doctor analyze [--conf=FILE] [--conf-dir=DIR] [--status=URL|TARGET]
                   [--status-file=PATH] [--ram=MB] [--ram-fraction=0..1]
                   [--avg-rss=MB] [--peak-rss=MB] [--format=conf] [--json]
```

With no `--conf`/`--conf-dir` it scans the usual locations
(`/etc/php/*/fpm/pool.d/`, `/etc/php-fpm.d`, `/usr/local/etc/php-fpm.d`,
`/opt/homebrew/etc/php/*/php-fpm.d`).

**Real live run** (real pool config, RSS measured from `/proc` across the running
workers, RAM auto-detected):

```
$ fpm-doctor analyze --conf=/www/server/php/74/etc/php-fpm.conf

fpm-doctor analyze
------------------
Pool configs : /www/server/php/74/etc/php-fpm.conf
RAM budget   : 180,138.1 MiB (70% of detected 257,340.2 MiB total RAM)
Pools found  : 1
Read-only: fpm-doctor never edits configs or restarts FPM. It prints recommendations only.

pool | configured | recommended | avg RSS   | peak RSS  | verdict
-----+------------+-------------+-----------+-----------+--------------
www  | 100        | 863         | 208.5 MiB | 312.0 MiB | UNDERUTILIZED

• pool www — UNDERUTILIZED
    - avg child RSS 208.5 MiB, peak 312.0 MiB (method: proc, 96 samples).
    - budget 180,138.1 MiB / avg 208.5 MiB = 863 children.
    - Headroom: configured 100 is well under the 863 RAM allows; raise it if you
      see "max children reached" climb under load.
```

**Oversized pool with a live status page** → OOM risk:

```
$ fpm-doctor analyze --conf=www.conf --status=http://127.0.0.1/status?json&full --ram=4096

pool | configured | recommended | avg RSS  | peak RSS | verdict
-----+------------+-------------+----------+----------+---------
www  | 120        | 58          | 70.0 MiB | 80.0 MiB | OOM RISK

• pool www — OOM RISK
    - avg child RSS 70.0 MiB, peak 80.0 MiB (method: override, 1 sample).
    - budget 4,096.0 MiB / avg 70.0 MiB = 58 children.
    - TOO HIGH: 120 children x peak 80.0 MiB = 9,600.0 MiB exceeds budget 4,096.0 MiB (OOM risk under full load).

Shared-pool contention
----------------------
! Pool "www" has hit pm.max_children 7 time(s) since start. Under a shared master
  this is where sites 502 even though each pool "looks" fine in isolation.
```

**Shared master, several pools** → oversubscription (the 502-storm pattern):

```
$ fpm-doctor analyze --conf=shared.conf --avg-rss=60 --peak-rss=90 --ram=4096

pool   | configured | recommended | avg RSS  | peak RSS | verdict
-------+------------+-------------+----------+----------+---------
site_a | 80         | 68          | 60.0 MiB | 90.0 MiB | OOM RISK
site_b | 60         | 68          | 60.0 MiB | 90.0 MiB | OOM RISK
site_c | 40         | 68          | 60.0 MiB | 90.0 MiB | OK

Shared-pool contention
----------------------
! SHARED-POOL OVERSUBSCRIPTION: 3 pools share this master. Their combined worst case
  (sum of max_children x peak RSS) is 16,200.0 MiB, over the 4,096.0 MiB budget. A
  burst on one pool can starve the others and OOM the box - the classic 502 storm.
  Split the budget per pool so the totals fit, and consider separate masters for
  noisy neighbours.
```

Add `--format=conf` to also print a paste-ready snippet (it is printed, never
written to disk), and `--json` for machine-readable output.

**Verdicts:**

| Verdict | Meaning |
|---|---|
| `OK` | Configured value fits the measured RAM/RSS. |
| `OOM_RISK` | `max_children × peak RSS` exceeds the RAM budget — too high. |
| `CONTENTION` | Status shows `max children reached` / a listen queue while RAM is free — too low. |
| `UNDERUTILIZED` | Configured well below what RAM allows; headroom to grow if you see contention. |
| `NO_DATA` | No RSS could be measured; pass `--avg-rss` or enable a data source. |

`analyze` exits `1` when it finds a problem (OOM risk, contention, or
oversubscription), `0` when everything is OK — handy in CI or a cron check.

### `slowlog` — aggregate a php-fpm slowlog

```
fpm-doctor slowlog <path/to/slow.log> [--top=N] [--json]
```

```
$ fpm-doctor slowlog /var/log/php/www.slow.log --top=5

fpm-doctor slowlog
------------------
File            : /var/log/php/www.slow.log
Slow requests   : 5
Distinct traces : 3
Skipped lines   : 2 (unparseable; counted, not fatal)
Note: php-fpm slowlogs record that request_slowlog_timeout was exceeded, not the
elapsed time. Ranking is by frequency and recency, never invented durations.

Top offenders by frequency
--------------------------
count | script                          | top frame                                            | last seen
------+---------------------------------+------------------------------------------------------+---------------------
3     | /var/www/shop/public/index.php  | curl_exec() .../Handler/CurlHandler.php:45           | 26-Oct-2026 09:18:10
1     | /var/www/shop/public/cron.php   | PDOStatement::execute() .../Report/Builder.php:210   | 26-Oct-2026 09:17:44
1     | /var/www/shop/public/search.php | sleep() /var/www/shop/src/Search/Indexer.php:77      | 26-Oct-2026 09:20:55
```

It **streams** the log (constant memory, safe on multi-GB files) and counts
malformed lines instead of crashing on them.

### `status` — pretty-print the live FPM status

```
fpm-doctor status --status=http://127.0.0.1/status?json&full [--json]
fpm-doctor status --status=unix:/run/php/php8.2-fpm.sock:/status?json&full
fpm-doctor status --status-file=/path/to/captured-status.json
```

```
$ fpm-doctor status --status=http://127.0.0.1/status?json

FPM status: pool www
--------------------
metric               | value
---------------------+--------
active processes     | 120
idle processes       | 0
listen queue         | 34
max listen queue     | 128
max children reached | 7
slow requests        | 12
accepted conn        | 184233

! "max children reached" > 0: this pool has saturated pm.max_children and queued/
  dropped requests. Run `fpm-doctor analyze` to size it against measured RSS.
```

---

## The recommendation math

```
recommended pm.max_children = floor( ram_budget / avg_child_rss )
```

- **`ram_budget`** is the memory you are willing to give FPM. Pass it with
  `--ram=<MB>`, or let `fpm-doctor` take a fraction of total RAM
  (`--ram-fraction`, default **0.7** — the remaining 30% is your headroom for
  the OS, OPcache, the database, and spikes). The budget fraction *is* the
  headroom; the division itself is not padded, so the math stays transparent.
- **`avg_child_rss`** is the measured average resident memory of a worker.
- **OOM check (too high):** if `configured × peak_rss > ram_budget`, a full pool
  can exceed RAM → `OOM_RISK`. Peak RSS, not average, because under load every
  worker trends toward its peak.
- **Contention check (too low):** if the status page reports
  `max children reached > 0` or a backed-up listen queue *and* RAM would allow
  more workers → `CONTENTION`.
- **Shared-pool oversubscription:** across all pools on one master,
  `Σ (max_children × peak_rss)` is compared to the budget. If the sum exceeds it,
  the pools are individually plausible but collectively dangerous — one busy
  site can starve the others. That is the 502 storm, and it is flagged even when
  every single pool looks fine on its own.

---

## Enabling the data sources

**FPM status page** — in your pool config:

```ini
pm.status_path = /status
```

Reload FPM, then either expose it through your web server:

```nginx
location = /status {
    access_log off;
    allow 127.0.0.1;
    deny all;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_NAME /status;
}
```

…and point `fpm-doctor` at it (`--status=http://127.0.0.1/status?json&full`), or
skip the web server entirely and query the socket directly
(`--status=unix:/run/php/php8.2-fpm.sock:/status?json&full`). Always include
`full` — it is what provides per-process data.

**Slowlog** — in your pool config:

```ini
request_slowlog_timeout = 5s
slowlog = /var/log/php/$pool.slow.log
```

---

## Honest limits

- **It is a heuristic, not a guarantee.** RSS varies by workload, request mix,
  OPcache state, and traffic. Measure under *real* load and re-check.
- **RSS method is stated every run** (`proc`, `status:last-request-memory`, or
  `override`). From the status page, the signal is FPM's *last request memory* —
  PHP request peak, which *understates* true process RSS; for real RSS, run on
  the Linux host so `/proc` can be read, or measure on a comparable host and pass
  `--avg-rss`.
- **`/proc` is Linux-only.** On macOS/BSD/remote, pass `--avg-rss` (and
  optionally `--peak-rss`); the tool will say so rather than invent a number.
- **Slowlogs carry no durations.** Standard php-fpm slowlogs record only *that*
  `request_slowlog_timeout` was exceeded, so ranking is by frequency and
  recency. `fpm-doctor` will never print an elapsed time it did not measure.
- **It never writes or restarts.** Every change is yours to review and apply,
  followed by a normal `php-fpm` reload.

---

## Development

```bash
composer install
composer test          # PHPUnit
composer cs            # php-cs-fixer (PSR-12), dry-run
php -d phar.readonly=0 scripts/build-phar.php   # builds build/fpm-doctor.phar
```

---

## Support

If `fpm-doctor` saved you a 3 a.m. 502 incident, a ⭐ on the repo is appreciated — and so is a coffee:

| Coin | Address |
|---|---|
| **BTC** | `bc1q0sfghv7yfl594gjurhyhlhdgm0dtwh4dkjha36` |
| **ETH / USDT-ERC20 / BNB-BEP20** | `0xB827b5a8B6c5111946c5D872933b2C23484E87dE` |
| **SOL** | `3ch5aHrPp3CierhiNXmqmtFH2dMHh6Gfv7a97KtLMMCh` |
| **USDT-TRC20 / TRX** | `TUAyz7TjgJdFVD2oFHWUUTNrzzAnBRgySG` |
| **TON** | `UQAJmYVuR3hh02etjaPKJF-h6jcpdSnq9ZIFvI64NEskS6tx` |

---

## License

MIT — see [LICENSE](LICENSE).
