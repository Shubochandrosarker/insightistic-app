<?php

/**
 * Docker compose passes unset vars through as EMPTY strings (`${VAR:-}`),
 * and env('X', $default) returns '' for those — which produced an empty
 * `redirect_uri` on the GitHub authorize URL. Config must fall back.
 */
function loadServicesConfigWith(array $vars): array
{
    $keys = ['OAUTH_REDIRECT_BASE', 'INSIGHTISTIC_APP_URL', 'GITHUB_REDIRECT_URI', 'GOOGLE_REDIRECT_URI', 'MICROSOFT_REDIRECT_URI'];
    $saved = [];
    foreach ($keys as $k) {
        $saved[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
        $v = $vars[$k] ?? '';
        putenv("$k=$v");
        $_ENV[$k] = $_SERVER[$k] = $v;
    }
    try {
        return require base_path('config/services.php');
    } finally {
        foreach ($saved as $k => [$e, $s, $g]) {
            $g === false ? putenv($k) : putenv("$k=$g");
            if ($e === null) { unset($_ENV[$k]); } else { $_ENV[$k] = $e; }
            if ($s === null) { unset($_SERVER[$k]); } else { $_SERVER[$k] = $s; }
        }
    }
}

it('falls back to the app callback when redirect env vars are empty strings', function () {
    $cfg = loadServicesConfigWith([]);

    expect($cfg['github']['redirect'])->toBe('https://app.insightistic.com/api/auth/oauth/github/callback')
        ->and($cfg['google']['redirect'])->toBe('https://app.insightistic.com/api/auth/oauth/google/callback')
        ->and($cfg['microsoft']['redirect'])->toBe('https://app.insightistic.com/api/auth/oauth/microsoft/callback');
});

it('honours OAUTH_REDIRECT_BASE and explicit per-provider overrides', function () {
    $cfg = loadServicesConfigWith([
        'OAUTH_REDIRECT_BASE' => 'https://api.insightistic.com/',
        'GOOGLE_REDIRECT_URI' => 'https://example.test/cb',
    ]);

    expect($cfg['github']['redirect'])->toBe('https://api.insightistic.com/api/auth/oauth/github/callback')
        ->and($cfg['google']['redirect'])->toBe('https://example.test/cb');
});
