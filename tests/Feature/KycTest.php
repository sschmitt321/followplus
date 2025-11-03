<?php

use App\Models\User;
use App\Services\Kyc\KycService;

test('user can submit basic KYC', function () {
    $user = User::factory()->create();
    $kycService = new KycService();

    $kyc = $kycService->submitBasic($user->id, 'John Doe');

    expect($kyc->level)->toBe('basic');
    expect($kyc->status)->toBe('pending');
    expect($user->fresh()->profile->name)->toBe('John Doe');
});

test('user can submit advanced KYC', function () {
    $user = User::factory()->create();
    $kycService = new KycService();

    $kyc = $kycService->submitAdvanced(
        $user->id,
        'https://example.com/front.jpg',
        'https://example.com/back.jpg'
    );

    expect($kyc->level)->toBe('advanced');
    expect($kyc->status)->toBe('pending');
    expect($kyc->front_image_url)->toBe('https://example.com/front.jpg');
    expect($kyc->back_image_url)->toBe('https://example.com/back.jpg');
});

test('admin can review and approve KYC', function () {
    $user = User::factory()->create();
    $kycService = new KycService();

    $kyc = $kycService->submitAdvanced(
        $user->id,
        'https://example.com/front.jpg',
        'https://example.com/back.jpg'
    );

    $reviewedKyc = $kycService->review($kyc->id, 'approved', 'All documents verified');

    expect($reviewedKyc->status)->toBe('approved');
    expect($reviewedKyc->review_reason)->toBe('All documents verified');
});

test('admin can review and reject KYC', function () {
    $user = User::factory()->create();
    $kycService = new KycService();

    $kyc = $kycService->submitAdvanced(
        $user->id,
        'https://example.com/front.jpg',
        'https://example.com/back.jpg'
    );

    $reviewedKyc = $kycService->review($kyc->id, 'rejected', 'Documents unclear');

    expect($reviewedKyc->status)->toBe('rejected');
    expect($reviewedKyc->review_reason)->toBe('Documents unclear');
});

test('KYC review throws exception for invalid status', function () {
    $user = User::factory()->create();
    $kycService = new KycService();

    $kyc = $kycService->submitAdvanced(
        $user->id,
        'https://example.com/front.jpg',
        'https://example.com/back.jpg'
    );

    expect(fn() => $kycService->review($kyc->id, 'invalid_status'))
        ->toThrow(\InvalidArgumentException::class);
});

