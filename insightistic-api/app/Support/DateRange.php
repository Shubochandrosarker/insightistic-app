<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Resolves a dashboard date filter into usable bounds.
 *
 * Snapshots are keyed by LOCAL calendar date (the store's timezone), so range
 * queries on snapshots use dateFrom()/dateTo() strings. Raw order queries hit
 * created_at_store (stored UTC), so they use utcStart()/utcEnd() datetimes.
 *
 * Window is half-open: [startLocal, endExclusiveLocal).
 */
class DateRange
{
    public function __construct(
        public CarbonImmutable $startLocal,
        public CarbonImmutable $endExclusiveLocal,
        public string $tz,
        public string $label,
    ) {}

    public static function fromRequest(?string $period, ?string $from, ?string $to, string $tz): self
    {
        $tz = $tz ?: 'UTC';
        $now = CarbonImmutable::now($tz)->startOfDay();
        $period = $period ?: 'last_30_days';

        switch ($period) {
            case 'today':
                $s = $now; $e = $now->addDay(); break;
            case 'yesterday':
                $s = $now->subDay(); $e = $now; break;
            case 'last_7_days':
                $s = $now->subDays(6); $e = $now->addDay(); break;
            case 'this_month':
                $s = $now->startOfMonth(); $e = $now->addDay(); break;
            case 'last_month':
                $s = $now->subMonthNoOverflow()->startOfMonth();
                $e = $now->startOfMonth(); break;
            case 'custom':
                $s = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $now->subDays(29);
                $e = $to ? CarbonImmutable::parse($to, $tz)->startOfDay()->addDay() : $now->addDay();
                break;
            case 'last_30_days':
            default:
                $period = 'last_30_days';
                $s = $now->subDays(29); $e = $now->addDay(); break;
        }

        if ($e->lessThanOrEqualTo($s)) {
            $e = $s->addDay();
        }

        return new self($s, $e, $tz, $period);
    }

    public function days(): int
    {
        return max(1, (int) round($this->startLocal->diffInDays($this->endExclusiveLocal)));
    }

    /** Same-length window immediately before this one (for comparisons). */
    public function previous(): self
    {
        $len = $this->days();
        return new self(
            $this->startLocal->subDays($len),
            $this->startLocal,
            $this->tz,
            'previous',
        );
    }

    public function dateFrom(): string
    {
        return $this->startLocal->toDateString();
    }

    public function dateTo(): string
    {
        return $this->endExclusiveLocal->subDay()->toDateString();
    }

    public function utcStart(): CarbonImmutable
    {
        return $this->startLocal->utc();
    }

    public function utcEnd(): CarbonImmutable
    {
        return $this->endExclusiveLocal->utc();
    }
}
