# Supabase Bot Service Setup Guide

This guide explains how to configure and use the Supabase Bot service for sending IM messages.

## 📋 Environment Configuration

Add the following variables to your `.env` file:

```env
# Supabase Configuration
SUPABASE_URL="https://<PROJECT>.supabase.co"
SUPABASE_SERVICE_ROLE_KEY="<SERVICE_ROLE_KEY>"
SUPABASE_BOT_SENDER_ID="<BOT_USER_UUID>"
SUPABASE_SCHEMA="public"
SUPABASE_MESSAGES_TABLE="messages"
SUPABASE_ROOMS_TABLE="rooms"
SUPABASE_MESSAGE_TYPE_DEFAULT="text"
```

### Configuration Details

- **SUPABASE_URL**: Your Supabase project URL (e.g., `https://abcdefghijklmnop.supabase.co`)
- **SUPABASE_SERVICE_ROLE_KEY**: Your Supabase service role key (found in Project Settings > API)
- **SUPABASE_BOT_SENDER_ID**: Bot user UUID (must exist in `profiles` table, e.g., `123e4567-e89b-12d3-a456-426614174000`)
- **SUPABASE_SCHEMA**: Database schema name (default: `public`)
- **SUPABASE_MESSAGES_TABLE**: Name of your messages table (default: `messages`)
- **SUPABASE_ROOMS_TABLE**: Name of your rooms table (default: `rooms`)
- **SUPABASE_MESSAGE_TYPE_DEFAULT**: Default message type (default: `text`)

## 🏗️ Architecture

### Service Classes

1. **`SupabaseClient`** (`app/Services/SupabaseClient.php`)
   - HTTP client wrapper for Supabase REST API
   - Handles authentication headers and request/response processing
   - Throws exceptions on API errors

2. **`SupabaseBotService`** (`app/Services/SupabaseBotService.php`)
   - Business logic for sending messages
   - Supports single conversation and broadcast modes
   - Handles error recovery for broadcast operations

### Database Schema Requirements

Your Supabase database should have:

**`messages` table:**
- `room_id` (UUID, foreign key to rooms)
- `sender_id` (UUID, foreign key to profiles, null for system messages)
- `content` (text, message content)
- `type` (enum: `'text'`, `'image'`, `'video'`, `'system'`)
- `mentions` (uuid[], optional array of mentioned user IDs)
- `reply_to` (UUID, optional message ID being replied to)
- `media_url` (text, optional media file URL)
- `media_thumbnail_url` (text, optional thumbnail URL)
- `encrypted_content` (text, optional E2EE encrypted content)
- `key_version` (int, optional E2EE key version)
- `signature` (text, optional Ed25519 signature)

**`rooms` table:**
- `id` (UUID, primary key)

## 🚀 Usage

### Artisan Command

Send a message via command line:

```bash
# Broadcast to all rooms
php artisan supabase-bot:send "Hello from Laravel bot"

# Send to a specific room
php artisan supabase-bot:send "Hello user" 123e4567-e89b-12d3-a456-426614174000

# Send with options
php artisan supabase-bot:send "Hello @user" 123e4567-e89b-12d3-a456-426614174000 \
    --type=text \
    --mentions="user-id-1,user-id-2" \
    --reply-to="message-id-to-reply"

# Send system message
php artisan supabase-bot:send "System announcement" 123e4567-e89b-12d3-a456-426614174000 --type=system
```

### From Laravel Code

Inject `SupabaseBotService` into your controllers, jobs, or scheduled commands:

```php
use App\Services\SupabaseBotService;

class YourController extends Controller
{
    public function sendNotification(SupabaseBotService $bot)
    {
        $content = "Your dynamic message content here";
        
        // Send to specific room
        $bot->send($content, 'room-id-here');
        
        // Or broadcast to all rooms
        $bot->send($content);
        
        // Send text message with mentions
        $bot->sendTextMessage('room-id', 'Hello @user', ['user-id-1', 'user-id-2']);
        
        // Send image message
        $bot->sendImageMessage('room-id', 'https://example.com/image.jpg', 'https://example.com/thumb.jpg', 'Image caption');
        
        // Send system message
        $bot->sendSystemMessage('room-id', 'System announcement');
    }
}
```

### Service Methods

#### `send(string $content, ?string $roomId = null, ?string $type = null, array $options = []): array`

Main entry point:
- If `$roomId` is provided → sends to that room only
- If `$roomId` is `null` → broadcasts to all rooms
- `$options` can include: `mentions`, `reply_to`, `media_url`, `media_thumbnail_url`, etc.

#### `sendMessageToRoom(string $roomId, string $content, ?string $type = null, array $options = []): array`

Sends a message to a specific room with full options support.

#### `sendTextMessage(string $roomId, string $content, array $mentionUserIds = [], ?string $replyTo = null): array`

Sends a text message with optional mentions and reply.

#### `sendImageMessage(string $roomId, string $mediaUrl, ?string $thumbnailUrl = null, ?string $caption = null): array`

Sends an image message.

#### `sendVideoMessage(string $roomId, string $mediaUrl, ?string $thumbnailUrl = null, ?string $caption = null): array`

Sends a video message.

#### `sendSystemMessage(string $roomId, string $content): array`

Sends a system message (requires admin permissions).

#### `broadcastMessage(string $content, ?string $type = null, array $options = []): array`

Sends a message to all rooms. Returns an associative array:
```php
[
    'room-id-1' => ['success' => true, 'data' => [...]],
    'room-id-2' => ['success' => false, 'error' => 'Error message'],
    // ...
]
```

#### `getAllRoomIds(): array`

Retrieves all room IDs from Supabase.

## 📝 Example: Scheduled Task

```php
// In routes/console.php or app/Console/Kernel.php

use App\Services\SupabaseBotService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    $bot = app(SupabaseBotService::class);
    $content = "Daily reminder: Check your account!";
    $bot->send($content); // Broadcast to all rooms
})->dailyAt('09:00');
```

## 📝 Example: Job

```php
namespace App\Jobs;

use App\Services\SupabaseBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBotNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $content,
        private ?string $conversationId = null
    ) {}

    public function handle(SupabaseBotService $bot): void
    {
        $bot->send($this->content, $this->roomId);
    }
}
```

## 🔧 Configuration File

The configuration is stored in `config/supabase.php`:

```php
return [
    'url' => env('SUPABASE_URL'),
    'service_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
    'bot_sender_id' => env('SUPABASE_BOT_SENDER_ID'),
    'schema' => env('SUPABASE_SCHEMA', 'public'),
    'messages_table' => env('SUPABASE_MESSAGES_TABLE', 'messages'),
    'rooms_table' => env('SUPABASE_ROOMS_TABLE', 'rooms'),
    'default_message_type' => env('SUPABASE_MESSAGE_TYPE_DEFAULT', 'text'),
];
```

## ⚠️ Error Handling

The service throws `RuntimeException` on configuration or API errors:

```php
try {
    $bot->send($content, $roomId);
} catch (\RuntimeException $e) {
    // Handle error
    logger()->error('Supabase bot error: ' . $e->getMessage());
}
```

For broadcast operations, individual room errors are caught and included in the result array, so the entire operation doesn't fail if one room fails.

## 🧪 Testing

Test the service manually:

```bash
# Test broadcast
php artisan supabase-bot:send "Test message"

# Test single room
php artisan supabase-bot:send "Test message" your-room-id

# Test with options
php artisan supabase-bot:send "Test @user" your-room-id --mentions="user-id-1,user-id-2" --reply-to="msg-id"
```

## 🔍 Troubleshooting

### Error: "Could not find the table 'public.rooms'"

This error means Supabase cannot find the specified table. Here's how to fix it:

1. **Check your actual table names in Supabase:**
   - Go to your Supabase dashboard
   - Navigate to Table Editor
   - Note the exact table names (they might be different, e.g., `chats`, `conversations`, etc.)

2. **Update your `.env` file with the correct table names:**
   ```env
   SUPABASE_ROOMS_TABLE="your_actual_table_name"
   SUPABASE_MESSAGES_TABLE="your_actual_messages_table"
   ```

3. **If your tables are in a different schema:**
   ```env
   SUPABASE_SCHEMA="your_schema_name"
   ```

4. **Verify table structure:**
   - Ensure your `rooms` table has an `id` column (UUID)
   - Ensure your `messages` table has: `room_id` (UUID), `sender_id` (UUID), `content`, `type`

5. **Check API permissions:**
   - Ensure your Service Role Key has access to these tables
   - Check Row Level Security (RLS) policies if enabled

6. **Run with verbose mode for debugging:**
   ```bash
   php artisan supabase-bot:send "Test" -v
   ```

### Error: "SUPABASE_BOT_SENDER_ID is not configured"

Add to your `.env`:
```env
SUPABASE_BOT_SENDER_ID="123e4567-e89b-12d3-a456-426614174000"
```

**Important**: The bot sender ID must be a valid UUID that exists in your `profiles` table. The bot user must be a member of the rooms you want to send messages to (or use Service Role Key to bypass RLS).

### Error: "SUPABASE_URL is not configured"

Add to your `.env`:
```env
SUPABASE_URL="https://your-project.supabase.co"
```

### Error: "SUPABASE_SERVICE_ROLE_KEY is not configured"

1. Go to Supabase Dashboard > Project Settings > API
2. Copy the `service_role` key (not the `anon` key)
3. Add to your `.env`:
   ```env
   SUPABASE_SERVICE_ROLE_KEY="your_service_role_key_here"
   ```

## 📚 Related Files

- `config/supabase.php` - Configuration
- `app/Services/SupabaseClient.php` - HTTP client wrapper
- `app/Services/SupabaseBotService.php` - Business logic
- `app/Console/Commands/SendSupabaseBotMessage.php` - Artisan command

