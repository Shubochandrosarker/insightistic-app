<?php

use App\Models\User;

it('lists, revokes one and revokes other sessions', function () {
    [$user] = makeOrgUser('sessions@example.com');

    $tokenA = $user->createToken('spa')->plainTextToken;
    $user->createToken('spa'); // second device
    $user->createToken('oauth'); // third

    $list = $this->withToken($tokenA)->getJson('/api/auth/sessions');
    $list->assertOk();
    expect($list->json('sessions'))->toHaveCount(3);
    expect(collect($list->json('sessions'))->where('current', true))->toHaveCount(1);

    // Revoke a specific other session.
    $otherId = collect($list->json('sessions'))->firstWhere('current', false)['id'];
    $this->withToken($tokenA)->deleteJson("/api/auth/sessions/{$otherId}")->assertOk();

    // Revoke everything else.
    $this->withToken($tokenA)->postJson('/api/auth/sessions/revoke-others')->assertOk();
    expect($user->tokens()->count())->toBe(1);
});

it('cannot revoke another user\'s session', function () {
    [$userA] = makeOrgUser('sess-a@example.com');
    [$userB] = makeOrgUser('sess-b@example.com');

    $tokenA = $userA->createToken('spa')->plainTextToken;
    $tokenBId = $userB->createToken('spa')->accessToken->id;

    $this->withToken($tokenA)->deleteJson("/api/auth/sessions/{$tokenBId}")->assertNotFound();
    expect($userB->tokens()->count())->toBe(1);
});
