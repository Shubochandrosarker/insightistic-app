<?php

use App\Services\Ai\WpisticGatewayClient;
use App\Services\Ai\WpisticGatewayProvider;
use Illuminate\Support\Facades\Http;

it('calls the current WPistic product gateway and normalizes its completion', function () {
    config()->set('insightistic.wpistic_gateway', [
        'base_url'    => 'https://ai.wpistic.com',
        'chat_path'   => '/compat/chat/completions',
        'product_key' => 'test-product-key',
        'product_id'  => 'insightistic',
        'model'       => 'glm-5.3',
        'max_tokens'  => 1200,
        'timeout'     => 30,
    ]);

    Http::fake([
        'https://ai.wpistic.com/compat/chat/completions' => Http::response([
            'model'   => 'z-ai/glm-5.3',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'A grounded answer.'],
            ]],
            'usage' => ['prompt_tokens' => 23, 'completion_tokens' => 9],
        ]),
    ]);

    $result = app(WpisticGatewayClient::class)->complete([
        ['role' => 'system', 'content' => 'Use the supplied evidence.'],
        ['role' => 'user', 'content' => 'A small test question.'],
    ], 42);

    expect($result)->toBe([
        'content'    => 'A grounded answer.',
        'model'      => 'z-ai/glm-5.3',
        'tokens_in'  => 23,
        'tokens_out' => 9,
    ]);

    Http::assertSent(fn ($request) =>
        $request->url() === 'https://ai.wpistic.com/compat/chat/completions'
        && $request->hasHeader('X-Wpistic-Product', 'insightistic')
        && $request->hasHeader('X-Wpistic-Key', 'test-product-key')
        && $request->hasHeader('X-Wpistic-User', 'org-42')
        && $request['model'] === 'glm-5.3'
        && $request['max_tokens'] === 1200
        && count($request['messages']) === 2
    );
});

it('rejects empty WPistic completions so another provider can take over', function () {
    config()->set('insightistic.wpistic_gateway', [
        'base_url'    => 'https://ai.wpistic.com',
        'chat_path'   => '/compat/chat/completions',
        'product_key' => 'test-product-key',
        'product_id'  => 'insightistic',
        'model'       => 'glm-5.3',
        'max_tokens'  => 1200,
        'timeout'     => 30,
    ]);

    Http::fake([
        'https://ai.wpistic.com/compat/chat/completions' => Http::response([
            'model'   => 'z-ai/glm-5.3',
            'choices' => [['message' => ['role' => 'assistant', 'content' => '']]],
        ]),
    ]);

    expect(fn () => app(WpisticGatewayClient::class)->complete([
        ['role' => 'user', 'content' => 'A small test question.'],
    ], 42))->toThrow(RuntimeException::class, 'empty completion');
});

it('parses structured AI insight output from the WPistic gateway', function () {
    config()->set('insightistic.wpistic_gateway', [
        'base_url'    => 'https://ai.wpistic.com',
        'chat_path'   => '/compat/chat/completions',
        'product_key' => 'test-product-key',
        'product_id'  => 'insightistic',
        'model'       => 'glm-5.3',
        'max_tokens'  => 1200,
        'timeout'     => 30,
    ]);

    Http::fake([
        'https://ai.wpistic.com/compat/chat/completions' => Http::response([
            'model' => 'z-ai/glm-5.3',
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => "```json\n{\"title\":\"Organic traffic grew\",\"confidence\":\"high\"}\n```",
            ]]],
        ]),
    ]);

    $provider = new WpisticGatewayProvider(app(WpisticGatewayClient::class));

    expect($provider->generate('Return a JSON insight.', ['sessions' => 12], 42))
        ->toBe(['title' => 'Organic traffic grew', 'confidence' => 'high'])
        ->and($provider->model())->toBe('wpistic-gateway:glm-5.3');
});
