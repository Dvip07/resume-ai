# Queue workers in production

Everything interesting in this app happens on the queue: job discovery, JD
enrichment, scoring, resume and cover-letter tailoring, and apply runs are all
queued jobs. A deployment with no worker running looks healthy — the API
answers, rows get created — and then nothing ever progresses past `queued`.
So a supervised, persistent worker is part of the deployment, not an
afterthought (Requirement 11.4).

## Supervisor, not Horizon

The queue driver is `database` (`backend/config/queue.php`, and the stack
diagram in the spec's design doc). Laravel Horizon only works with the Redis
driver, so adopting it means first adding Redis as a production dependency and
migrating the queue — a bigger change than the dashboard is worth right now,
for a workload that is a handful of jobs per user per day rather than thousands
per second.

What Horizon would buy us is mostly observability, and the parts we need are
covered elsewhere: `failed_jobs` is surfaced in the frontend dashboard (task
16.2), and per-stage progress is already modelled on the listing/application
rows rather than inferred from queue state.

If Redis arrives later for other reasons (cache, rate limiting at scale), the
switch is cheap: point `QUEUE_CONNECTION` at `redis`, install Horizon, and
replace the Supervisor program with `horizon` — the job classes don't change.
Keep the same timeout reasoning when you do; Horizon's `timeout` and
`retry_after` have the same ordering constraint described below.

## Config files

| File | Used by |
| --- | --- |
| `backend/deploy/supervisor/resume-ai-worker.conf` | Bare-metal / VM hosts. Copy into `/etc/supervisor/conf.d/`. |
| `docker/supervisor/supervisord.conf` | The `queue-worker` compose service. Same program, container logging. |

Both run the same command:

```
php artisan queue:work --queue=default --tries=3 --timeout=600 \
    --max-time=3600 --max-jobs=200 --sleep=3 --rest=1
```

The flag values are explained inline in the bare-metal conf. Three of them are
load-bearing and worth repeating:

- **`--timeout=600`** has to clear the longest single job. An apply run gives
  the automation worker 180s (`services.automation_worker.apply_timeout`); a
  tailoring run can spend 120s per model call (`openrouter.timeout`) plus a
  120s LaTeX compile (`LATEX_TIMEOUT`), over several internal attempts.
- **`retry_after` (660s) must exceed `--timeout`.** It lives in
  `config/queue.php` and is overridable with `QUEUE_RETRY_AFTER`. If it drops
  below the timeout, the queue decides a still-running job was abandoned and
  gives it to a second worker — which means two tailoring runs, or two
  submitted applications, for one dispatch.
- **`stopwaitsecs=630` must exceed `--timeout` too.** `queue:work` finishes the
  job in hand on SIGTERM. Too short a wait and Supervisor SIGKILLs a worker
  mid-apply, leaving a listing stuck mid-pipeline.

`numprocs=2` is the starting point. The work is mostly IO-bound, so more
processes help, but each tailoring run also spawns a LaTeX compile, and the
automation worker's own `AUTOMATION_WORKER_MAX_CONCURRENCY` (default 2) caps
how many apply/render calls can be in flight before it starts answering `503`.
Scale the two together.

## Running them

Bare metal:

```bash
sudo cp backend/deploy/supervisor/resume-ai-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status resume-ai-worker:*
```

Docker Compose (from the repo root):

```bash
docker compose up -d queue-worker
docker compose logs -f queue-worker
```

Before starting workers on a fresh host, confirm the environment the *worker*
sees — not your shell — can find the TeX engine:

```bash
php artisan latex:check
```

A worker's `PATH` comes from Supervisor, not your login shell, which is why the
conf sets `PATH` explicitly. Setting `LATEX_BINARY` to an absolute path
sidesteps the problem entirely.

## After a deploy

PHP workers hold the old code and the old config in memory. Every deploy must
tell them to exit so Supervisor starts them on the new release:

```bash
cd backend
php artisan config:clear      # or config:cache, if you cache config
php artisan queue:restart
```

`queue:restart` sets a restart flag the workers notice after their current job
finishes — graceful, no job lost. Supervisor's `autorestart=true` then brings
them straight back. Never `kill -9` a worker as part of a deploy; that abandons
whatever job it was holding until `retry_after` expires.

With Compose, `docker compose up -d --build queue-worker` replaces the
container, which has the same effect.

## Watching for failures

A job that exhausts its `$tries` lands in `failed_jobs`, and the owning
pipeline row is marked `failed` by the job's `failed()` hook, so a failure is
visible in the app and not only in the table.

```bash
php artisan queue:failed            # list failed jobs
php artisan queue:retry <uuid>      # retry one
php artisan queue:retry all         # retry everything
php artisan queue:forget <uuid>     # drop one
php artisan queue:flush             # clear the table
```

Worth putting on a dashboard or an alert: a non-zero and *growing*
`failed_jobs` count. Task 16.2 surfaces it in the frontend. Worker stdout/stderr
goes to `storage/logs/queue-worker.log` (bare metal) or `docker compose logs
queue-worker`; application-level detail is in `storage/logs/laravel.log`, where
each `failed()` hook writes a readable companion to the `failed_jobs` row.

Two failure shapes that look the same from the outside and aren't:

- **`failed_jobs` grows** — jobs are running and dying. Read the logs.
- **`jobs` grows and `failed_jobs` is empty** — nothing is consuming. The
  worker is down, or `QUEUE_CONNECTION` isn't `database` on the worker's
  environment.

## The scheduler is the other half

Workers only run work that was dispatched. The thing that dispatches without a
user clicking anything is the scheduler: `jobs:discover` fans out one
`DiscoverJobsForUser` per candidate user on a cadence (Requirements 3.1, 11.1),
and the rest of the pipeline follows from the listings it finds. Workers with
no scheduler means a system that only ever reacts; a scheduler with no workers
means a `jobs` table that grows and nothing else.

Laravel needs exactly **one** cron entry, ever — it runs the in-app schedule,
not individual commands:

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

In a container there is no crond, so run the long-lived equivalent instead. The
`scheduler` compose service does this:

```bash
docker compose up -d scheduler
docker compose logs -f scheduler
```

`schedule:work` is a foreground process that ticks every minute, so Compose's
`restart: unless-stopped` is all the supervision it needs. On a bare-metal host
prefer the cron entry; a missed minute there is self-healing.

Run only one of them. The schedule entry is marked `onOneServer`, but that
guard needs a shared cache store (`CACHE_STORE=database` qualifies within one
host, not across hosts) — two independent schedulers against the `file`/`array`
cache will each fan out the same users on the same tick.

### Cadence

The schedule itself is defined in `backend/app/Console/ScheduleDefinition.php`
(wired up from `routes/console.php`) — not in `app/Console/Kernel.php`, which
this app's bootstrap never resolves. Cadence is config-driven, in
`backend/config/pipeline.php` under `discovery`:

| Env | Default | Meaning |
| --- | --- | --- |
| `PIPELINE_DISCOVERY_CRON` | `0 * * * *` | When the fan-out fires. Any cron expression. |
| `PIPELINE_DISCOVERY_TIMEZONE` | `APP_TIMEZONE` | Timezone the expression is read in. |
| `PIPELINE_DISCOVERY_ENABLED` | `true` | `false` stops it firing; the command stays usable by hand. |

Hourly is safe because the command is cheap — it queues one job per user and
returns, running no provider calls itself. What actually bounds API spend is the
per-source daily budget (`ProviderDailyLimiter`), not this number, so raising
the cadence costs queue churn rather than quota. Lower it (`0 */6 * * *`) on a
demo box, or align it with a provider's quota reset.

Changing these values requires the scheduler process to restart, same as the
workers:

```bash
php artisan config:clear
docker compose restart scheduler    # or restart the cron host's process
```

### Checking it

```bash
php artisan schedule:list           # what is registered, and next due time
php artisan jobs:discover           # dispatch a fan-out right now
```

`schedule:list` is the first thing to run when discovery has gone quiet — it
reads the same config the scheduler does, so it will show an entry missing
entirely (`PIPELINE_DISCOVERY_ENABLED=false`) or firing at an unexpected hour
(timezone). Scheduler output goes to `docker compose logs scheduler`; a failed
dispatch also writes a `Scheduled job discovery failed to dispatch` line to
`storage/logs/laravel.log`.

A stalled run holds `withoutOverlapping`'s lock, so the next tick is skipped
rather than doubled. That is the intended behaviour, but it means a wedged run
looks identical to a disabled schedule from the outside — check `schedule:list`
before assuming config.

## The automation worker is a different process

`automation-worker/` (Node + Playwright) is a separate service with its own
lifecycle, and nothing in this page supervises it. Queue workers call it over
internal HTTP for JD rendering and apply runs; see
[`automation-worker/README.md`](../../automation-worker/README.md). In Compose
it is the `automation-worker` service, restarted independently.

Consequence for operations: restarting queue workers does not restart the
browser sidecar, and a dead sidecar does not stop the queue — enrichment
degrades to `no_content` and apply jobs retry. Check both when the pipeline
stalls.
