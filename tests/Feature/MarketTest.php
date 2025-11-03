<?php

use App\Models\Symbol;
use App\Models\SymbolTick;
use App\Models\User;
use App\Support\Decimal;

test('authenticated user can get all enabled symbols', function () {
    Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    Symbol::factory()->create(['base' => 'ETH', 'quote' => 'USDT', 'enabled' => true]);
    Symbol::factory()->create(['base' => 'BNB', 'quote' => 'USDT', 'enabled' => false]);

    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/symbols');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'symbols' => [
                '*' => [
                    'id',
                    'base',
                    'quote',
                    'name',
                ],
            ],
        ]);

    expect($response->json('symbols'))->toHaveCount(2);
    expect($response->json('symbols.0.base'))->toBe('BTC');
    expect($response->json('symbols.1.base'))->toBe('ETH');
});

test('authenticated user can get latest tick for a symbol', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    SymbolTick::create([
        'symbol_id' => $symbol->id,
        'last_price' => Decimal::of('45000'),
        'change_percent' => '2.5',
        'tick_at' => now(),
    ]);

    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/symbols/{$symbol->id}/tick");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'symbol_id',
            'symbol',
            'last_price',
            'change_percent',
            'tick_at',
        ]);

    expect($response->json('symbol_id'))->toBe($symbol->id);
    expect($response->json('symbol'))->toBe('BTC/USDT');
    expect($response->json('last_price'))->toBe('45000.000000');
    expect($response->json('change_percent'))->toBe(2.5);
});

test('authenticated user gets null tick when no tick data available', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/symbols/{$symbol->id}/tick");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'symbol_id',
            'symbol',
            'last_price',
            'change_percent',
            'tick_at',
            'message',
        ]);

    expect($response->json('last_price'))->toBeNull();
    expect($response->json('change_percent'))->toBeNull();
    expect($response->json('message'))->toBe('No tick data available');
});

test('authenticated user can get tick history for a symbol', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    // Create multiple ticks
    for ($i = 0; $i < 5; $i++) {
        SymbolTick::create([
            'symbol_id' => $symbol->id,
            'last_price' => Decimal::of('45000')->add($i * 100),
            'change_percent' => (string)(2.5 + $i),
            'tick_at' => now()->subMinutes($i),
        ]);
    }

    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/symbols/{$symbol->id}/tick-history");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'symbol_id',
            'symbol',
            'history' => [
                '*' => [
                    'last_price',
                    'change_percent',
                    'tick_at',
                ],
            ],
        ]);

    expect($response->json('history'))->toHaveCount(5);
    expect($response->json('symbol'))->toBe('BTC/USDT');
});

test('tick history returns latest 100 ticks by default', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    // Create 150 ticks
    for ($i = 0; $i < 150; $i++) {
        SymbolTick::create([
            'symbol_id' => $symbol->id,
            'last_price' => Decimal::of('45000'),
            'change_percent' => '2.5',
            'tick_at' => now()->subMinutes($i),
        ]);
    }

    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/symbols/{$symbol->id}/tick-history");

    expect($response->json('history'))->toHaveCount(100);
});

test('unauthenticated user cannot access market endpoints', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $response = $this->getJson('/api/v1/symbols');
    $response->assertStatus(401);

    $response = $this->getJson("/api/v1/symbols/{$symbol->id}/tick");
    $response->assertStatus(401);

    $response = $this->getJson("/api/v1/symbols/{$symbol->id}/tick-history");
    $response->assertStatus(401);
});

test('get tick returns 404 for non-existent symbol', function () {
    $user = User::factory()->create();
    $token = \PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::fromUser($user);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/symbols/99999/tick');

    $response->assertStatus(404);
});

