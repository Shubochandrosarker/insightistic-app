<?php

use App\Models\PlatformSetting;
use App\Models\User;

it('lets a super admin read and update non-secret AI routing after reauthentication', function () {
    $admin = User::create([
        'name'     => 'AI Owner',
        'email'    => 'ai-owner@example.com',
        'password' => 'secret-password',
        'status'   => 'active',
    ]);
    $admin->forceFill(['is_super_admin' => true])->save();
    $token = $admin->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/ai/configuration')
        ->assertOk()
        ->assertJsonPath('ai.provider_order.0', 'wpistic_gateway')
        ->assertJsonPath('ai.openrouter.model', 'z-ai/glm-5.3-flash');

    $this->withToken($token)
        ->postJson('/api/auth/reauthenticate', ['password' => 'secret-password'])
        ->assertOk()
        ->assertJson(['status' => 'confirmed']);

    $response = $this->withToken($token)
        ->patchJson('/api/admin/ai/configuration', [
            'openrouter_paid_model'       => 'z-ai/glm-5.3-flash',
            'openrouter_free_model'       => 'z-ai/glm-5.3-flash',
            'knowledge_agent_enabled'     => true,
            'knowledge_agent_url'         => 'https://agents.wpistic.example',
            'knowledge_agent_slug'        => 'analytics-pro',
            'knowledge_agent_category'    => 'analytics',
        ])
        ->assertOk()
        ->assertJsonPath('ai.openrouter.model', 'z-ai/glm-5.3-flash')
        ->assertJsonPath('ai.knowledge_agent.enabled', true)
        ->assertJsonPath('ai.knowledge_agent.base_url', 'https://agents.wpistic.example');

    expect(json_encode($response->json()))
        ->not->toContain('api_key')
        ->not->toContain('secret');

    expect(PlatformSetting::where('key', 'ai_routing')->first()?->value_json)
        ->toBeArray()
        ->toMatchArray([
            'knowledge_agent_enabled' => true,
            'knowledge_agent_slug' => 'analytics-pro',
        ]);
});
