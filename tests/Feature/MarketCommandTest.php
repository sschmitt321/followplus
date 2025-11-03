<?php

use App\Models\Symbol;
use App\Models\SymbolTick;
use Illuminate\Support\Facades\Artisan;

test('market generate ticks command generates ticks for enabled symbols', function () {
    Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);
    Symbol::factory()->create(['base' => 'ETH', 'quote' => 'USDT', 'enabled' => true]);
    Symbol::factory()->create(['base' => 'BNB', 'quote' => 'USDT', 'enabled' => false]);

    Artisan::call('market:generate-ticks');

    $ticks = SymbolTick::all();
    expect($ticks)->toHaveCount(2); // Only enabled symbols
    
    // Verify output
    expect(Artisan::output())->toContain('Generated');
});

test('market generate ticks command handles no symbols gracefully', function () {
    Artisan::call('market:generate-ticks');

    $ticks = SymbolTick::all();
    expect($ticks)->toHaveCount(0);
    
    $output = Artisan::output();
    expect($output)->toContain('Generated');
    // Output should contain "0" or "0 tick"
    expect($output)->toMatch('/\b0\b/');
});

test('market generate ticks command exits with success status', function () {
    Symbol::factory()->create(['base' => 'BTC', 'quote' => 'USDT', 'enabled' => true]);

    $exitCode = Artisan::call('market:generate-ticks');

    expect($exitCode)->toBe(0);
});

