<?php

use App\Services\Rank\RankKpis;

/**
 * Rank KPI math (Phase 10, doc 13) — pure functions, no database: the
 * visibility band table, the cumulative top-N buckets, the improved /
 * declined noise floor, and the history row shape.
 */

/** Build a kpis() input entry: ['position' => ?, 'checked_at' => ?]. */
function obs(?int $position, string $checkedAt = '2026-09-20 10:00:00'): array
{
    return ['position' => $position, 'checked_at' => $checkedAt];
}

it('buckets positions into cumulative top-N bands and counts not_found', function () {
    // #2 is inside top3 AND top10 AND top20 (cumulative); null = checked
    // but absent from the SERP.
    $latest = [
        1 => obs(2),
        2 => obs(7),
        3 => obs(15),
        4 => obs(21),
        5 => obs(null),
    ];

    $kpis = RankKpis::kpis($latest, []);

    expect($kpis['tracked'])->toBe(5)
        ->and($kpis['active'])->toBe(5)
        ->and($kpis['top3'])->toBe(1)
        ->and($kpis['top10'])->toBe(2)
        ->and($kpis['top20'])->toBe(3)
        ->and($kpis['not_found'])->toBe(1)
        ->and($kpis['improved'])->toBe(0)
        ->and($kpis['declined'])->toBe(0);
});

it('computes visibility from the band weights and rounds to one decimal', function () {
    // 2 -> 1.0 | 7 -> 0.5 | 15 -> 0.25 | 21 -> 0.1 | null -> 0.0
    // (1.0 + 0.5 + 0.25 + 0.1) / 5 * 100 = 37.0
    $latest = [
        1 => obs(2),
        2 => obs(7),
        3 => obs(15),
        4 => obs(21),
        5 => obs(null),
    ];

    expect(RankKpis::kpis($latest, [])['visibility'])->toBe(37.0);

    // Position 3 (1.0) + 15 (0.25): (1.25 / 2) * 100 = 62.5.
    expect(RankKpis::kpis([1 => obs(3), 2 => obs(15)], [])['visibility'])->toBe(62.5);
});

it('counts any non-null improvement but applies a 3-position noise floor to declines', function () {
    $latest = [
        1 => obs(3),  // prev 6  -> improved (any move toward #1 counts)
        2 => obs(7),  // prev 5  -> 2-place drop is noise, NOT declined
        3 => obs(8),  // prev 5  -> exactly 3 places -> declined
        4 => obs(12), // prev 4  -> 8-place drop -> declined
        5 => obs(5),  // prev 5  -> unchanged -> neither
        6 => obs(2),  // prev null -> no comparison -> neither
    ];
    $previous = [
        1 => obs(6),
        2 => obs(5),
        3 => obs(5),
        4 => obs(4),
        5 => obs(5),
    ];

    $kpis = RankKpis::kpis($latest, $previous);

    expect($kpis['improved'])->toBe(1)
        ->and($kpis['declined'])->toBe(2);
});

it('treats not-found follow-ups without inventing movements', function () {
    // prev 8 -> latest null: the keyword left the SERP; that is not an
    // "improvement" or a "decline", it shows up in not_found.
    $kpis = RankKpis::kpis(
        [1 => obs(null)],
        [1 => obs(8)],
    );

    expect($kpis['not_found'])->toBe(1)
        ->and($kpis['improved'])->toBe(0)
        ->and($kpis['declined'])->toBe(0)
        ->and($kpis['visibility'])->toBe(0.0);
});

it('returns a zeroed payload for an empty keyword set', function () {
    $kpis = RankKpis::kpis([], []);

    expect($kpis)->toBe([
        'tracked'    => 0,
        'active'     => 0,
        'top3'       => 0,
        'top10'      => 0,
        'top20'      => 0,
        'not_found'  => 0,
        'improved'   => 0,
        'declined'   => 0,
        'visibility' => 0.0,
    ]);
});

it('maps observations into oldest-first chart rows for the keyword', function () {
    $keyword = (object) ['id' => 7];

    $observations = [
        (object) ['keyword_id' => 7, 'checked_at' => '2026-09-19 09:00:00', 'position' => 12, 'ranking_url' => 'https://x.test/older'],
        (object) ['keyword_id' => 7, 'checked_at' => '2026-09-20 09:00:00', 'position' => 4, 'ranking_url' => 'https://x.test/newer'],
        // A row for a different keyword must be dropped.
        (object) ['keyword_id' => 8, 'checked_at' => '2026-09-20 09:30:00', 'position' => 1, 'ranking_url' => 'https://x.test/other'],
    ];

    $history = RankKpis::history($keyword, $observations);

    expect($history)->toHaveCount(2)
        ->and($history[0]['position'])->toBe(12)
        ->and($history[0]['url'])->toBe('https://x.test/older')
        ->and($history[1]['position'])->toBe(4)
        ->and($history[1]['url'])->toBe('https://x.test/newer');
});
