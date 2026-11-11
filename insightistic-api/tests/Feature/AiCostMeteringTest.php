<?php

use App\Services\Ai\OpenRouterProvider;
use App\Services\Ai\RuleProvider;
use App\Services\Observability\MeteredAiProvider;
use App\Services\Observability\Telemetry;
use App\Services\Observability\Tracing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * AppSumo AI allowance must come from MEASURED provider cost. Until now no
 * path recorded cost: telemetry_ai_usage had 0 tokens / $0 for 1,772 calls
 * and ai_runs.cost_usd was always null. OpenRouter returns usage.cost on
 * every completion; these tests pin that it now lands in the cost tables,
 * and that nothing is estimated when a provider reports nothing.
 */

function meteredOpenRouter(): MeteredAiProvider
{
    return new MeteredAiProvider(
        new OpenRouterProvider(['api_key' => 'sk-test', 'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'test/paid-model']),
        app(Telemetry::class),
        app(Tracing::class),
        'openrouter',
    );
}

it('records OpenRouter-reported tokens and cost in telemetry_ai_usage', function () {
    Http::fake(['openrouter.ai/*' => Http::response([
        'model'   => 'test/paid-model',
        'choices' => [['message' => ['content' => '{"title":"t","summary":"s"}']]],
        'usage'   => ['prompt_tokens' => 1200, 'completion_tokens' => 300, 'cost' => 0.00042],
    ])]);

    $provider = meteredOpenRouter();
    expect($provider->generate('sys', ['a' => 1], null))->toBeArray()
        ->and($provider->lastUsage())->toBe(['tokens_in' => 1200, 'tokens_out' => 300, 'cost_usd' => 0.00042]);

    $row = DB::table('telemetry_ai_usage')->first();
    expect((int) $row->tokens_in)->toBe(1200)
        ->and((int) $row->tokens_out)->toBe(300)
        ->and((float) $row->cost_usd)->toBe(0.00042);
});

it('records zero cost (not an estimate) when the provider reports no usage', function () {
    Http::fake(['openrouter.ai/*' => Http::response([
        'model'   => 'test/paid-model',
        'choices' => [['message' => ['content' => '{"title":"t"}']]],
    ])]);

    $provider = meteredOpenRouter();
    $provider->generate('sys', [], null);

    expect($provider->lastUsage())->toBeNull();
    $row = DB::table('telemetry_ai_usage')->first();
    expect((int) $row->tokens_in)->toBe(0)->and((float) $row->cost_usd)->toBe(0.0);
});

it('clears usage after a failed call so a stale cost is never re-recorded', function () {
    Http::fakeSequence('openrouter.ai/*')
        ->push(['model' => 'm', 'choices' => [['message' => ['content' => '{"x":1}']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'cost' => 0.01]])
        ->push(['error' => 'boom'], 500);

    $provider = meteredOpenRouter();
    $provider->generate('sys', [], null);
    $provider->generate('sys', [], null);

    expect($provider->lastUsage())->toBeNull()
        ->and((float) DB::table('telemetry_ai_usage')->sum('cost_usd'))->toBe(0.01);
});

it('reports no usage for the rule-based $0 fallback', function () {
    $metered = new MeteredAiProvider(new RuleProvider, app(Telemetry::class), app(Tracing::class), 'rule');

    expect($metered->lastUsage())->toBeNull();
});
