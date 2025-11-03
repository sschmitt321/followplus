<?php

use App\Models\Currency;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Deposit\DepositService;
use App\Services\Ledger\LedgerService;
use App\Services\System\ConfigService;
use App\Services\Withdraw\WithdrawService;
use App\Support\Decimal;

test('withdraw service calculates withdrawable for newbie correctly', function () {
    $user = User::factory()->create([
        'first_joined_at' => now()->subDays(3), // 3 days ago (newbie)
    ]);
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    
    // Deposit 1000 USDT
    $depositService->manualApply($user->id, 'USDT', '1000');

    $withdrawService = new WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new ConfigService()
    );

    $calc = $withdrawService->calcWithdrawable($user->id);

    expect($calc['policy'])->toBe('newbie');
    expect($calc['total_balance'])->toBe('1000.000000');
    // Newbie: 扣除10%手续费
    expect($calc['fee'])->toBe('100.000000');
    expect($calc['withdrawable'])->toBe('900.000000');
});

test('withdraw service calculates withdrawable for old user correctly', function () {
    $user = User::factory()->create([
        'first_joined_at' => now()->subDays(10), // 10 days ago (old user)
    ]);
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    
    // Deposit 1000 USDT
    $depositService->manualApply($user->id, 'USDT', '1000');

    $withdrawService = new WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new ConfigService()
    );

    $calc = $withdrawService->calcWithdrawable($user->id);

    expect($calc['policy'])->toBe('old');
    expect($calc['total_balance'])->toBe('1000.000000');
    // Old user: 10% fee
    expect($calc['fee'])->toBe('100.000000');
    expect($calc['withdrawable'])->toBe('900.000000');
});

test('withdraw service can apply withdrawal', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $withdrawService = new WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new ConfigService()
    );

    $withdrawal = $withdrawService->apply(
        $user->id,
        '100',
        'Txxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'USDT'
    );

    expect($withdrawal)->toBeInstanceOf(Withdrawal::class);
    expect($withdrawal->status)->toBe('pending');
    expect($withdrawal->amount_request->toString())->toBe('100.000000');
    expect($withdrawal->fee->greaterThan(Decimal::zero()))->toBeTrue();

    // Verify balance is frozen
    $account = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account->frozen->toString())->toBe('100.000000');
    expect($account->available->toString())->toBe('900.000000');
});

test('withdraw service can approve and mark paid', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $withdrawService = new WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new ConfigService()
    );

    $withdrawal = $withdrawService->apply($user->id, '100', 'Txxx', 'USDT');
    
    // Approve
    $approved = $withdrawService->approve($withdrawal->id);
    expect($approved->status)->toBe('approved');

    // Mark as paid
    $paid = $withdrawService->markPaid($withdrawal->id, 'txid123');
    expect($paid->status)->toBe('paid');
    expect($paid->txid)->toBe('txid123');

    // Verify balance is debited
    $account = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account->available->toString())->toBe('900.000000');
    expect($account->frozen->toString())->toBe('0.000000');
});

test('withdraw service can reject withdrawal and unfreeze balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '1000');

    $withdrawService = new WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new ConfigService()
    );

    $withdrawal = $withdrawService->apply($user->id, '100', 'Txxx', 'USDT');
    
    // Reject
    $rejected = $withdrawService->reject($withdrawal->id);
    expect($rejected->status)->toBe('rejected');

    // Verify balance is unfrozen
    $account = \App\Models\Account::where([
        'user_id' => $user->id,
        'type' => 'spot',
        'currency' => 'USDT',
    ])->first();

    expect($account->available->toString())->toBe('1000.000000');
    expect($account->frozen->toString())->toBe('0.000000');
});

test('withdraw service throws exception on insufficient balance', function () {
    $user = User::factory()->create();
    Currency::factory()->create(['name' => 'USDT']);

    $ledgerService = new LedgerService();
    $depositService = new DepositService($ledgerService);
    $depositService->manualApply($user->id, 'USDT', '100');

    $withdrawService = new WithdrawService(
        $ledgerService,
        new \App\Services\Assets\AssetsService(),
        new ConfigService()
    );

    expect(fn() => $withdrawService->apply($user->id, '200', 'Txxx', 'USDT'))
        ->toThrow(\Exception::class, 'Insufficient balance');
});

