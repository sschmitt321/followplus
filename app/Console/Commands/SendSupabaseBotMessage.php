<?php

namespace App\Console\Commands;

use App\Services\SupabaseBotService;
use Illuminate\Console\Command;

class SendSupabaseBotMessage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'supabase-bot:send 
                            {content : Message content} 
                            {room_id? : Optional room ID (UUID); if empty, broadcast to all}
                            {--type=text : Message type (text/image/video/system)}
                            {--reply-to= : Reply to message ID}
                            {--mentions= : Comma-separated user IDs to mention}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a bot message via Supabase (single room or broadcast)';

    /**
     * Execute the console command.
     */
    public function handle(SupabaseBotService $bot): int
    {
        $content = $this->argument('content');
        $roomId = $this->argument('room_id');
        $type = $this->option('type');
        $replyTo = $this->option('reply-to');
        $mentions = $this->option('mentions');

        if (empty($content)) {
            $this->error('Content cannot be empty');
            return Command::FAILURE;
        }

        try {
            // Show configuration info for debugging
            if ($this->option('verbose')) {
                $this->line('Configuration:');
                $this->line('  Messages table: ' . config('supabase.messages_table', 'messages'));
                $this->line('  Rooms table: ' . config('supabase.rooms_table', 'rooms'));
                $this->line('  Schema: ' . config('supabase.schema', 'public'));
                $this->line('  Bot sender ID: ' . config('supabase.bot_sender_id', 'not set'));
                $this->newLine();
            }

            // Build options array
            $options = [];
            if ($replyTo) {
                $options['reply_to'] = $replyTo;
            }
            if ($mentions) {
                $options['mentions'] = array_filter(array_map('trim', explode(',', $mentions)));
            }

            $result = $bot->send($content, $roomId, $type, $options);

            if ($roomId) {
                // Single room
                $this->info("Message sent successfully to room: {$roomId}");
                $this->line('Response:');
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                // Broadcast
                $this->info('Broadcasting message to all rooms...');
                $this->newLine();

                $successCount = 0;
                $errorCount = 0;

                foreach ($result as $rId => $item) {
                    if ($item['success'] ?? false) {
                        $successCount++;
                        $this->info("✓ Room {$rId}: Success");
                    } else {
                        $errorCount++;
                        $error = $item['error'] ?? 'Unknown error';
                        $this->error("✗ Room {$rId}: {$error}");
                    }
                }

                $this->newLine();
                $this->info("Summary: {$successCount} successful, {$errorCount} failed");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to send message: ' . $e->getMessage());
            $this->newLine();
            $this->warn('Troubleshooting:');
            $this->line('1. Check if the tables exist in Supabase:');
            $this->line('   - ' . config('supabase.schema', 'public') . '.' . config('supabase.rooms_table', 'rooms'));
            $this->line('   - ' . config('supabase.schema', 'public') . '.' . config('supabase.messages_table', 'messages'));
            $this->line('2. Verify table names in .env:');
            $this->line('   SUPABASE_ROOMS_TABLE=' . config('supabase.rooms_table', 'rooms'));
            $this->line('   SUPABASE_MESSAGES_TABLE=' . config('supabase.messages_table', 'messages'));
            $this->line('3. Run with -v flag to see detailed configuration');
            return Command::FAILURE;
        }
    }
}
