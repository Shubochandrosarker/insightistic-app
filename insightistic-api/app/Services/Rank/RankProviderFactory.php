<?php

namespace App\Services\Rank;

use InvalidArgumentException;

/**
 * Resolves the configured SERP backend for the rank tracker.
 *
 * config('insightistic.rank.provider') / env RANK_PROVIDER picks the
 * adapter; DataForSEO is the default and the only one shipped today. A new
 * provider is one class implementing RankProvider plus one line in PROVIDERS.
 * Nothing else (RankChecker, the schedule, the API) knows which vendor runs.
 */
final class RankProviderFactory
{
    /** @var array<string, class-string<RankProvider>> */
    public const PROVIDERS = [
        DataForSeoProvider::PROVIDER_NAME => DataForSeoProvider::class,
    ];

    public static function make(?string $name = null): RankProvider
    {
        $name = mb_strtolower(trim((string) ($name ?? config('insightistic.rank.provider', DataForSeoProvider::PROVIDER_NAME))));
        if ($name === '') {
            $name = DataForSeoProvider::PROVIDER_NAME;
        }

        $class = self::PROVIDERS[$name] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown rank provider "%s" (RANK_PROVIDER). Supported: %s.',
                $name,
                implode(', ', array_keys(self::PROVIDERS)),
            ));
        }

        return app($class);
    }
}
