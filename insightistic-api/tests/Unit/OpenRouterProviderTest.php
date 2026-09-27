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

    Http::assertSent(fn ($request) =>
        $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
        && $request['model'] === 'openai/gpt-6-luna'
        && $request['models'] === ['z-ai/glm-5.3-flash']
        && $request->hasHeader('Authorization', 'Bearer test-key')
    );
});
