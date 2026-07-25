<?php

namespace App\Services\Observability;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Queue health across both drivers this platform ships with (spec Phase 2.3).
 *
 * Horizon is the obvious answer for a Redis-only deployment, but the Hostinger
 * compose stacks run `QUEUE_CONNECTION=database` while the main production
 * stack runs Redis. A dashboard that only works under one of those is worse
 * than none, because the deployments most likely to be neglected are exactly
 * the ones it would not cover. This is the "or equivalent" the spec allows,
 * and it reads whichever driver is actually configured.
 *
 * The metric that matters is **oldest pending age**, not depth. A queue 5,000
 * jobs deep that drains in a minute is healthy; a queue three jobs deep whose
 * oldest has been waiting an hour means no worker is running, and depth alone
 * cannot tell those apart.
 */
class QueueHealth
{
    /** Worker heartbeat key; written by the queue-heartbeat listener. */
    public const HEARTBEAT_KEY = 'insightistic:queue:heartbeat';

    /** A worker silent for longer than this is treated as gone. */
    public const HEARTBEAT_STALE_SECONDS = 300;

    /** Pending longer than this and nothing is draining the queue. */
    public const BACKLOG_WARN_SECONDS = 300;

    public const BACKLOG_CRITICAL_SECONDS = 1800;

    public function snapshot(): array
    {
        $driver = config('queue.default');

        $pending = match ($driver) {
            'redis'    => $this->redisPending(),
            'database' => $this->databasePending(),
            // sync runs inline, so a backlog is impossible by construction.
            'sync'     => ['queues' => [], 'total' => 0, 'oldest_pending_at' => null],
            default    => null,
        };

        $failed = $this->failedSummary();
        $heartbeat = $this->heartbeat();

        $oldestAge = $pending && $pending['oldest_pending_at']
            ? (int) Carbon::parse($pending['oldest_pending_at'])->diffInSeconds(now())
            : 0;

        return [
            'driver'            => $driver,
            'supported'         => $pending !== null,
            'pending'           => $pending['total'] ?? null,
            'queues'            => $pending['queues'] ?? [],
            'oldest_pending_age_seconds' => $pending ? $oldestAge : null,
            'failed'            => $failed,
            'worker'            => $heartbeat,
            'status'            => $this->status($pending, $oldestAge, $heartbeat, $driver),
        ];
    }

    /**
     * Overall verdict.
     *
     * A stale heartbeat with an empty queue is only a warning: an idle worker
     * that has not picked anything up recently looks identical to a dead one,
     * and paging someone because nothing needed doing is how alerts get muted.
     * A stale heartbeat *with* a backlog is unambiguous.
     */
    private function status(?array $pending, int $oldestAge, array $heartbeat, string $driver): array
    {
        if ($driver === 'sync') {
            return ['level' => 'ok', 'detail' => 'Queue runs inline (sync driver); there is no backlog to measure.'];
        }

        if ($pending === null) {
            return ['level' => 'unknown', 'detail' => "Queue driver '{$driver}' is not one this dashboard can read."];
        }

        $backlog = $pending['total'] > 0;

        if ($backlog && $heartbeat['stale']) {
            return ['level' => 'critical', 'detail' => 'Jobs are waiting and no worker has reported in. Check the queue worker process.'];
        }

        if ($oldestAge >= self::BACKLOG_CRITICAL_SECONDS) {
            return ['level' => 'critical', 'detail' => 'The oldest job has been waiting over 30 minutes.'];
        }

        if ($oldestAge >= self::BACKLOG_WARN_SECONDS) {
            return ['level' => 'warning', 'detail' => 'The oldest job has been waiting over 5 minutes.'];
        }

        if ($heartbeat['stale'] && ! $backlog) {
            return ['level' => 'warning', 'detail' => 'No worker has reported in recently, but nothing is queued — it may simply be idle.'];
        }

        return ['level' => 'ok', 'detail' => 'Jobs are being picked up promptly.'];
    }

    private function databasePending(): ?array
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        $rows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as pending, MIN(available_at) as oldest')
            ->groupBy('queue')
            ->get();

        return [
            'queues' => $rows->map(fn ($r) => [
                'queue'   => $r->queue,
                'pending' => (int) $r->pending,
                // available_at is a unix timestamp on the database driver.
                'oldest_pending_at' => $r->oldest ? Carbon::createFromTimestamp((int) $r->oldest)->toIso8601String() : null,
            ])->all(),
            'total'  => (int) $rows->sum('pending'),
            'oldest_pending_at' => $rows->min('oldest')
                ? Carbon::createFromTimestamp((int) $rows->min('oldest'))->toIso8601String()
                : null,
        ];
    }

    /**
     * Redis stores each queue as a list, so depth is a LLEN per known queue.
     *
     * Redis carries no enqueue timestamp on the list itself, so the oldest-age
     * signal comes from the payload of the job at the head of the list — the
     * next one due to run, and therefore the one that has waited longest.
     */
    private function redisPending(): ?array
    {
        try {
            $connection = Redis::connection(config('queue.connections.redis.connection', 'default'));
            $prefix = (string) config('database.redis.options.prefix', '');
            $names = $this->knownQueues();

            $queues = [];
            $total = 0;
            $oldest = null;

            foreach ($names as $name) {
                $key = "queues:{$name}";
                $depth = (int) $connection->llen($key);
                $delayed = (int) $connection->zcard("{$key}:delayed");
                $reserved = (int) $connection->zcard("{$key}:reserved");

                $pushedAt = null;
                if ($depth > 0) {
                    $head = $connection->lindex($key, -1); // tail = next to pop
                    $payload = is_string($head) ? json_decode($head, true) : null;
                    if (is_array($payload) && isset($payload['pushedAt'])) {
                        $pushedAt = Carbon::createFromTimestamp((int) $payload['pushedAt']);
                    }
                }

                if ($depth === 0 && $delayed === 0 && $reserved === 0) {
                    continue;
                }

                $queues[] = [
                    'queue'    => $name,
                    'pending'  => $depth,
                    'delayed'  => $delayed,
                    'reserved' => $reserved,
                    'oldest_pending_at' => $pushedAt?->toIso8601String(),
                ];

                $total += $depth;

                if ($pushedAt && (! $oldest || $pushedAt->lessThan($oldest))) {
                    $oldest = $pushedAt;
                }
            }

            return [
                'queues' => $queues,
                'total'  => $total,
                'oldest_pending_at' => $oldest?->toIso8601String(),
                'prefix' => $prefix !== '' ? $prefix : null,
            ];
        } catch (\Throwable $e) {
            // Redis being unreachable is itself the finding, and it must not
            // take the whole health page down with it.
            report($e);

            return null;
        }
    }

    /**
     * Queue names to inspect.
     *
     * Redis cannot enumerate them without a SCAN over the keyspace, which is
     * not something to run on every dashboard load. The configured default
     * plus the names this application actually dispatches to is enough.
     */
    private function knownQueues(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('queue.connections.redis.queue', 'default'),
            'default',
        ])));
    }

    private function failedSummary(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['total' => null, 'last_24h' => null, 'recent' => []];
        }

        $recent = DB::table('failed_jobs')
            ->latest('failed_at')
            ->limit(10)
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);

        return [
            'total'    => DB::table('failed_jobs')->count(),
            'last_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            'recent'   => $recent->map(fn ($r) => [
                'id'        => $r->id,
                'uuid'      => $r->uuid,
                'queue'     => $r->queue,
                'job'       => $this->jobName($r->payload),
                'failed_at' => $r->failed_at,
                // First line only: the full trace is megabytes and contains
                // job payloads, which can carry tenant data.
                'error'     => Str::limit(strtok((string) $r->exception, "\n") ?: '', 200),
            ])->all(),
        ];
    }

    private function jobName(?string $payload): ?string
    {
        $decoded = json_decode((string) $payload, true);

        return $decoded['displayName'] ?? ($decoded['job'] ?? null);
    }

    /**
     * When a worker last reported in.
     *
     * Written by QueueHeartbeat on every job it processes, which means the
     * signal costs one cache write per job rather than a separate process.
     */
    public function heartbeat(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);
        $at = $last ? Carbon::parse($last) : null;
        $age = $at ? (int) $at->diffInSeconds(now()) : null;

        return [
            'last_seen_at'  => $at?->toIso8601String(),
            'age_seconds'   => $age,
            'stale'         => $age === null || $age > self::HEARTBEAT_STALE_SECONDS,
            'stale_after_seconds' => self::HEARTBEAT_STALE_SECONDS,
        ];
    }

    public static function recordHeartbeat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay());
    }
}
