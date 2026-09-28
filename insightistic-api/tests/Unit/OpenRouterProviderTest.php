<?php

use App\Services\Ai\OpenRouterProvider;
use Illuminate\Support\Facades\Http;

it('uses the ordered model fallback list and records the model that answered', function () {
    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'model' => 'z-ai/glm-5.3-flash',
            'choices' => [['message' => ['content' => '{"title":"ok"}']]],
        ]),
    ]);

    $provider = new OpenRouterProvider([
        'api_key' => 'test-key',
        'base_url' => 'https://openrouter.ai/api/v1',
        'model' => 'openai/gpt-6-luna',
        'fallback_models' => ['z-ai/glm-5.3-flash'],
        'timeout' => 10,
    ]);

    expect($provider->generate('system', ['metric' => 1]))->toBe(['title' => 'ok'])
        ->and($provider->model())->toBe('openrouter:z-ai/glm-5.3-flash');

    Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
        && $request['model'] === 'openai/gpt-6-luna'
        && $request['models'] === ['z-ai/glm-5.3-flash']
        && $request->hasHeader('Authorization', 'Bearer test-key')
    );
});

it('sends the configured zero-cost free-model failovers in order', function () {
    $fallbacks = [
        'nvidia/nemotron-3-super-120b-a12b:free',
        'google/gemma-4-31b-it:free',
        'google/gemma-4-26b-a4b-it:free',
        'z-ai/glm-5.3-flash:free',
    ];

    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'model' => 'google/gemma-4-31b-it:free',
            'choices' => [['message' => ['content' => '{"title":"free fallback"}']]],
        ]),
    ]);

    $provider = new OpenRouterProvider([
        'api_key' => 'test-key',
        'base_url' => 'https://openrouter.ai/api/v1',
        'model' => 'openrouter/free',
        'fallback_models' => $fallbacks,
        'timeout' => 10,
    ]);

    expect($provider->generate('system', ['metric' => 1]))->toBe(['title' => 'free fallback'])
        ->and($provider->model())->toBe('openrouter:google/gemma-4-31b-it:free');

    Http::assertSent(fn ($request) => $request['model'] === 'openrouter/free'
        && $request['models'] === $fallbacks
        && $request['response_format']['type'] === 'json_object'
    );
});
