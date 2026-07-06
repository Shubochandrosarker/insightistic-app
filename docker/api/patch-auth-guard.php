<?php
// See docker/api/Dockerfile for why this runs during the image build.
//
// `composer require laravel/sanctum ... --no-scripts` (used above so the build
// doesn't need a live DB connection) skips Laravel's package discovery/install
// steps, which normally register the `sanctum` auth guard. Without it, every
// `auth:sanctum` route -- including all of /api/admin/* -- throws:
//   "Auth guard [sanctum] is not defined."
$f = 'config/auth.php';
$c = file_get_contents($f);

if (strpos($c, "'sanctum' =>") !== false) {
    echo "auth.php already has a sanctum guard, skipping\n";
    exit(0);
}

$needle = "'web' => [\n            'driver' => 'session',\n            'provider' => 'users',\n        ],\n    ],";
$replacement = "'web' => [\n            'driver' => 'session',\n            'provider' => 'users',\n        ],\n\n        'sanctum' => [\n            'driver' => 'sanctum',\n            'provider' => null,\n        ],\n    ],";

if (strpos($c, $needle) === false) {
    fwrite(STDERR, "patch-auth-guard.php: expected guards block not found in config/auth.php - Laravel skeleton format may have changed, patch manually\n");
    exit(1);
}

file_put_contents($f, str_replace($needle, $replacement, $c));
echo "config/auth.php patched with sanctum guard\n";
