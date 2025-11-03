<?php

use App\Models\Symbol;
use App\Models\SymbolTick;
use App\Services\Market\MarketService;
use App\Support\Decimal;

test('market service can generate fake ticks for enabled symbols', function () {
    Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    Symbol::factory()->create(['base' => 'ETH', 'quote' => 'USDT', 'enabled' => true]);
    Symbol::factory()->create(['base' => 'BNB', 'quote' => 'USDT', 'enabled' => false]);

    $marketService = new MarketService();
    $count = $marketService->generateFakeTicks();

    expect($count)->toBe(2); // Only enabled symbols

    // Verify ticks were created
    $ticks = SymbolTick::all();
    expect($ticks)->toHaveCount(2);
    
    foreach ($ticks as $tick) {
        expect($tick->last_price)->toBeInstanceOf(Decimal::class);
        expect($tick->change_percent)->toBeString();
        expect($tick->tick_at)->toBeInstanceOf(\Carbon\Carbon::class);
    }
});

test('market service generates price based on latest tick if exists', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    // Create initial tick
    SymbolTick::create([
        'symbol_id' => $symbol->id,
        'last_price' => Decimal::of('45000'),
        'change_percent' => '0',
        'tick_at' => now()->subMinute(),
    ]);

    $marketService = new MarketService();
    $marketService->generateFakeTicks();

    $latestTick = SymbolTick::where('symbol_id', $symbol->id)
        ->latest('tick_at')
        ->first();

    expect($latestTick)->not->toBeNull();
    // New price should be within -5% to +5% of base (45000)
    expect($latestTick->last_price->toFloat())->toBeGreaterThan(42750); // 45000 * 0.95
    expect($latestTick->last_price->toFloat())->toBeLessThan(47250); // 45000 * 1.05
});

test('market service uses base price for first tick', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $marketService = new MarketService();
    $marketService->generateFakeTicks();

    $tick = SymbolTick::where('symbol_id', $symbol->id)->first();
    
    expect($tick)->not->toBeNull();
    // BTC base price is 45000, with -1% to +1% variation
    expect($tick->last_price->toFloat())->toBeGreaterThan(44550); // 45000 * 0.99
    expect($tick->last_price->toFloat())->toBeLessThan(45450); // 45000 * 1.01
});

test('market service calculates change percent correctly', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $marketService = new MarketService();
    $marketService->generateFakeTicks();

    $tick = SymbolTick::where('symbol_id', $symbol->id)->first();
    
    expect($tick)->not->toBeNull();
    // Change percent should be calculated from base price (45000)
    $basePrice = 45000;
    $expectedChangePercent = ($tick->last_price->toFloat() - $basePrice) / $basePrice * 100;
    
    expect((float)$tick->change_percent)->toBeGreaterThanOrEqual($expectedChangePercent - 0.01);
    expect((float)$tick->change_percent)->toBeLessThanOrEqual($expectedChangePercent + 0.01);
});

test('market service can get latest tick for a symbol', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    // Create older tick
    SymbolTick::create([
        'symbol_id' => $symbol->id,
        'last_price' => Decimal::of('44000'),
        'change_percent' => '-2.22',
        'tick_at' => now()->subMinutes(10),
    ]);

    // Create newer tick
    SymbolTick::create([
        'symbol_id' => $symbol->id,
        'last_price' => Decimal::of('45000'),
        'change_percent' => '0',
        'tick_at' => now()->subMinute(),
    ]);

    $marketService = new MarketService();
    $latestTick = $marketService->getLatestTick($symbol->id);

    expect($latestTick)->not->toBeNull();
    expect($latestTick->last_price->toString())->toBe('45000.000000');
});

test('market service returns null when no tick exists', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $marketService = new MarketService();
    $latestTick = $marketService->getLatestTick($symbol->id);

    expect($latestTick)->toBeNull();
});

test('market service can get tick history', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    // Create 5 ticks - older ticks first
    for ($i = 4; $i >= 0; $i--) {
        SymbolTick::create([
            'symbol_id' => $symbol->id,
            'last_price' => Decimal::of('45000')->add($i * 100),
            'change_percent' => (string)(2.5 + $i),
            'tick_at' => now()->subMinutes($i),
        ]);
    }

    $marketService = new MarketService();
    $history = $marketService->getTickHistory($symbol->id, 10);

    expect($history)->toHaveCount(5);
    // History is ordered by tick_at desc, so latest (i=0, now) is first
    expect($history[0]['last_price'])->toBe('45000.000000'); // Latest (i=0)
    expect($history[0]['change_percent'])->toBe(2.5);
    // Oldest (i=4) should be last
    expect($history[4]['last_price'])->toBe('45400.000000');
    expect($history[4]['change_percent'])->toBe(6.5);
});

test('market service limits tick history to specified limit', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    
    // Create 20 ticks
    for ($i = 0; $i < 20; $i++) {
        SymbolTick::create([
            'symbol_id' => $symbol->id,
            'last_price' => Decimal::of('45000'),
            'change_percent' => '0',
            'tick_at' => now()->subMinutes($i),
        ]);
    }

    $marketService = new MarketService();
    $history = $marketService->getTickHistory($symbol->id, 10);

    expect($history)->toHaveCount(10);
});

test('market service returns empty array when no history exists', function () {
    $symbol = Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $marketService = new MarketService();
    $history = $marketService->getTickHistory($symbol->id);

    expect($history)->toBeArray();
    expect($history)->toHaveCount(0);
});

