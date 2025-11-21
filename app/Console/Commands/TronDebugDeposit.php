<?php

namespace App\Console\Commands;

use App\Models\UserTronWallet;
use App\Services\Tron\TronNodeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TronDebugDeposit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tron:debug-deposit {address : Tron address to debug} {--hours=24 : Hours to look back}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug why a deposit was not detected';

    /**
     * Execute the console command.
     */
    public function handle(TronNodeClient $nodeClient): int
    {
        $address = $this->argument('address');
        $hours = (int) $this->option('hours');

        $this->info("🔍 Debugging deposit detection for address: {$address}");
        $this->newLine();

        // Step 1: Verify address is in our system
        $this->info('Step 1: Checking if address is in our system...');
        $wallet = UserTronWallet::where('tron_address', $address)->first();
        if ($wallet) {
            $this->info("✅ Address found for User ID: {$wallet->user_id}");
        } else {
            $this->error("❌ Address NOT found in user_tron_wallets table!");
            $this->warn("   This address needs to be registered in the system first.");
            return Command::FAILURE;
        }
        $this->newLine();

        // Step 2: Check USDT balance
        $this->info('Step 2: Checking USDT balance...');
        $usdtContract = app(\App\Services\Tron\TronUsdtContract::class);
        $balance = $usdtContract->getBalance($address);
        $this->info("USDT Balance: {$balance} USDT");
        if ($balance == 0) {
            $this->warn("⚠️  Balance is 0. Make sure you sent USDT to this address.");
        }
        $this->newLine();

        // Step 3: Get transfer events directly from API
        $this->info("Step 3: Fetching Transfer events from last {$hours} hours...");
        $contractAddress = config('services.tron.usdt_contract');
        $baseUrl = config('services.tron.node_url', 'https://api.trongrid.io');
        $apiKey = config('services.tron.api_key', '');
        
        $this->info("Contract Address: {$contractAddress}");
        $this->info("Node URL: {$baseUrl}");
        $this->newLine();

        // Convert addresses to hex for filtering
        $tronHelper = app(\App\Support\TronHelper::class);
        $contractAddressHex = $tronHelper::addressToHex($contractAddress);
        $addressHex = $tronHelper::addressToHex($address);

        $this->info("Contract Address (base58): {$contractAddress}");
        $this->info("Contract Address (hex): {$contractAddressHex}");
        $this->info("Target Address (hex): {$addressHex}");
        $this->newLine();

        // Calculate timestamp
        $minTimestamp = (time() - ($hours * 3600)) * 1000; // milliseconds
        $this->info("Min Timestamp: {$minTimestamp} (" . date('Y-m-d H:i:s', $minTimestamp / 1000) . ")");
        $this->newLine();

        // Fetch events - TronGrid API expects base58 format for contract address in URL
        $url = "{$baseUrl}/v1/contracts/{$contractAddress}/events";
        $params = [
            'event_name' => 'Transfer',
            'only_confirmed' => 'true',
            'limit' => 200,
            'min_block_timestamp' => $minTimestamp,
        ];

        $this->info("Fetching from: {$url}");
        $this->info("Params: " . json_encode($params, JSON_PRETTY_PRINT));
        $this->newLine();

        try {
            $headers = [];
            if ($apiKey) {
                $headers['TRON-PRO-API-KEY'] = $apiKey;
            }

            $response = Http::withHeaders($headers)->get($url, $params);

            $this->info("Response Status: {$response->status()}");
            
            if (!$response->successful()) {
                $this->error("❌ API request failed!");
                $this->error("Response: " . $response->body());
                return Command::FAILURE;
            }

            $data = $response->json();
            $events = $data['data'] ?? [];
            $totalEvents = count($events);

            $this->info("✅ Found {$totalEvents} total Transfer events");
            $this->newLine();

            // Filter events for our address
            $this->info("Step 4: Filtering events for target address...");
            $matchingEvents = [];
            
            foreach ($events as $event) {
                $result = $event['result'] ?? [];
                $toHex = $result['to'] ?? '';
                $fromHex = $result['from'] ?? '';
                
                // Convert hex to address for comparison
                $toAddress = $tronHelper::hexToAddress($toHex);
                $fromAddress = $tronHelper::hexToAddress($fromHex);
                
                // Normalize hex addresses for comparison
                // API returns 0x... format, convert to 41... format
                $toHexNormalized = $toHex;
                if (str_starts_with($toHex, '0x') || str_starts_with($toHex, '0X')) {
                    $toHexNormalized = '41' . substr($toHex, 2);
                } elseif (!str_starts_with($toHex, '41')) {
                    $toHexNormalized = '41' . $toHex;
                }
                
                // Check if this is a transfer TO our address
                if (strtolower($toHexNormalized) === strtolower($addressHex)) {
                    $amount = $tronHelper::hexToUsdtAmount($result['value'] ?? '0');
                    $txid = $event['transaction'] ?? '';
                    $blockTimestamp = $event['block_timestamp'] ?? 0;
                    
                    $matchingEvents[] = [
                        'txid' => $txid,
                        'from' => $fromAddress,
                        'to' => $toAddress,
                        'amount' => $amount,
                        'block_timestamp' => $blockTimestamp,
                        'block_number' => $event['block_number'] ?? 0,
                        'raw_to_hex' => $toHex,
                        'raw_from_hex' => $fromHex,
                    ];
                }
            }

            $this->info("Found " . count($matchingEvents) . " event(s) TO this address");
            $this->newLine();

            // Also check for events FROM this address (outgoing)
            $outgoingEvents = [];
            foreach ($events as $event) {
                $result = $event['result'] ?? [];
                $fromHex = $result['from'] ?? '';
                
                // Normalize hex address for comparison
                $fromHexNormalized = $fromHex;
                if (str_starts_with($fromHex, '0x') || str_starts_with($fromHex, '0X')) {
                    $fromHexNormalized = '41' . substr($fromHex, 2);
                } elseif (!str_starts_with($fromHex, '41')) {
                    $fromHexNormalized = '41' . $fromHex;
                }
                
                if (strtolower($fromHexNormalized) === strtolower($addressHex)) {
                    $toAddress = $tronHelper::hexToAddress($result['to'] ?? '');
                    $amount = $tronHelper::hexToUsdtAmount($result['value'] ?? '0');
                    $outgoingEvents[] = [
                        'txid' => $event['transaction'] ?? '',
                        'to' => $toAddress,
                        'amount' => $amount,
                        'block_timestamp' => $event['block_timestamp'] ?? 0,
                    ];
                }
            }
            
            if (!empty($outgoingEvents)) {
                $this->info("Found " . count($outgoingEvents) . " event(s) FROM this address (outgoing)");
                $this->newLine();
            }

            if (empty($matchingEvents)) {
                $this->warn("⚠️  No transfers TO this address found in the last {$hours} hours");
                $this->info("   Possible reasons:");
                $this->info("   1. Transfer happened more than {$hours} hours ago");
                $this->info("   2. Transfer was not confirmed yet");
                $this->info("   3. Transfer was to a different address");
                $this->info("   4. API filtering issue");
                $this->newLine();
                
                // Show some sample events for debugging
                if ($totalEvents > 0) {
                    $this->info("Sample events (first 3):");
                    foreach (array_slice($events, 0, 3) as $event) {
                        $result = $event['result'] ?? [];
                        $toHex = $result['to'] ?? '';
                        $toAddress = $tronHelper::hexToAddress($toHex);
                        $this->info("  - To: {$toAddress} (hex: {$toHex})");
                    }
                }
            } else {
                $this->info("✅ Found matching deposit(s):");
                $this->newLine();
                
                $tableData = [];
                foreach ($matchingEvents as $event) {
                    $tableData[] = [
                        substr($event['txid'], 0, 20) . '...',
                        $event['from'],
                        $event['amount'] . ' USDT',
                        date('Y-m-d H:i:s', $event['block_timestamp'] / 1000),
                        $event['block_number'],
                    ];
                }
                
                $this->table(
                    ['TXID', 'From', 'Amount', 'Time', 'Block'],
                    $tableData
                );
                $this->newLine();

                // Check if deposits are already in database
                $this->info("Step 5: Checking if deposits are in database...");
                foreach ($matchingEvents as $event) {
                    $exists = \App\Models\TronDeposit::where('txid', $event['txid'])
                        ->where('tron_address', $address)
                        ->exists();
                    
                    if ($exists) {
                        $this->info("✅ TXID {$event['txid']} already exists in database");
                    } else {
                        $this->warn("⚠️  TXID {$event['txid']} NOT found in database!");
                        $this->info("   This deposit should have been detected by scanNewDeposits()");
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error("❌ Exception occurred: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info("Step 6: Testing scanNewDeposits() method...");
        
        // Test the actual scan method
        $depositService = app(\App\Services\Tron\TronDepositService::class);
        $scannedCount = $depositService->scanNewDeposits();
        $this->info("scanNewDeposits() found {$scannedCount} new deposit(s)");
        
        // Check if our deposit is now in database
        if (!empty($matchingEvents)) {
            foreach ($matchingEvents as $event) {
                $exists = \App\Models\TronDeposit::where('txid', $event['txid'])
                    ->where('tron_address', $address)
                    ->exists();
                
                if ($exists) {
                    $this->info("✅ After scan, TXID {$event['txid']} is now in database");
                } else {
                    $this->error("❌ After scan, TXID {$event['txid']} is still NOT in database!");
                }
            }
        }

        $this->newLine();
        $this->info('✅ Debug completed!');

        return Command::SUCCESS;
    }
}

