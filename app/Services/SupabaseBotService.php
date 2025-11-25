<?php

namespace App\Services;

use RuntimeException;

class SupabaseBotService
{
    private SupabaseClient $client;

    public function __construct(SupabaseClient $client)
    {
        $this->client = $client;
    }

    /**
     * Send a message to a specific room.
     *
     * @param string $roomId The room ID (UUID)
     * @param string $content Message content
     * @param string|null $type Message type: 'text', 'image', 'video', 'system' (defaults to config value)
     * @param array $options Optional fields: mentions, reply_to, media_url, media_thumbnail_url, encrypted_content, key_version, signature
     * @return array Supabase API response
     */
    public function sendMessageToRoom(
        string $roomId,
        string $content,
        ?string $type = null,
        array $options = []
    ): array {
        $type = $type ?? config('supabase.default_message_type', 'text');
        $senderId = config('supabase.bot_sender_id');

        if (!$senderId) {
            throw new RuntimeException('SUPABASE_BOT_SENDER_ID is not configured');
        }

        // Build payload according to Supabase schema
        $payload = [
            [
                'room_id' => $roomId,
                'sender_id' => $senderId,
                'type' => $type,
            ]
        ];

        // Add content (required for text messages, optional for media)
        if (!empty($content)) {
            $payload[0]['content'] = $content;
        }

        // For system messages, sender_id should be null
        if ($type === 'system') {
            $payload[0]['sender_id'] = null;
        }

        // Add optional fields
        if (isset($options['mentions']) && is_array($options['mentions'])) {
            $payload[0]['mentions'] = $options['mentions'];
        }

        if (isset($options['reply_to'])) {
            $payload[0]['reply_to'] = $options['reply_to'];
        }

        if (isset($options['media_url'])) {
            $payload[0]['media_url'] = $options['media_url'];
        }

        if (isset($options['media_thumbnail_url'])) {
            $payload[0]['media_thumbnail_url'] = $options['media_thumbnail_url'];
        }

        if (isset($options['encrypted_content'])) {
            $payload[0]['encrypted_content'] = $options['encrypted_content'];
        }

        if (isset($options['key_version'])) {
            $payload[0]['key_version'] = $options['key_version'];
        }

        if (isset($options['signature'])) {
            $payload[0]['signature'] = $options['signature'];
        }

        $messagesTable = config('supabase.messages_table', 'messages');
        $schema = config('supabase.schema', 'public');
        
        try {
            return $this->client->request('POST', $messagesTable, [], $payload);
        } catch (RuntimeException $e) {
            // Provide more context about which table was used
            throw new RuntimeException(
                "Failed to send message to table '{$schema}.{$messagesTable}': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Send a text message to a room.
     *
     * @param string $roomId The room ID (UUID)
     * @param string $content Message content
     * @param array $mentionUserIds Optional array of user IDs to mention
     * @param string|null $replyTo Optional message ID to reply to
     * @return array Supabase API response
     */
    public function sendTextMessage(
        string $roomId,
        string $content,
        array $mentionUserIds = [],
        ?string $replyTo = null
    ): array {
        $options = [];
        if (!empty($mentionUserIds)) {
            $options['mentions'] = $mentionUserIds;
        }
        if ($replyTo) {
            $options['reply_to'] = $replyTo;
        }

        return $this->sendMessageToRoom($roomId, $content, 'text', $options);
    }

    /**
     * Send an image message to a room.
     *
     * @param string $roomId The room ID (UUID)
     * @param string $mediaUrl Image URL
     * @param string|null $thumbnailUrl Optional thumbnail URL
     * @param string|null $caption Optional image caption
     * @return array Supabase API response
     */
    public function sendImageMessage(
        string $roomId,
        string $mediaUrl,
        ?string $thumbnailUrl = null,
        ?string $caption = null
    ): array {
        $options = [
            'media_url' => $mediaUrl,
        ];

        if ($thumbnailUrl) {
            $options['media_thumbnail_url'] = $thumbnailUrl;
        }

        return $this->sendMessageToRoom($roomId, $caption ?? '', 'image', $options);
    }

    /**
     * Send a video message to a room.
     *
     * @param string $roomId The room ID (UUID)
     * @param string $mediaUrl Video URL
     * @param string|null $thumbnailUrl Optional thumbnail URL
     * @param string|null $caption Optional video caption
     * @return array Supabase API response
     */
    public function sendVideoMessage(
        string $roomId,
        string $mediaUrl,
        ?string $thumbnailUrl = null,
        ?string $caption = null
    ): array {
        $options = [
            'media_url' => $mediaUrl,
        ];

        if ($thumbnailUrl) {
            $options['media_thumbnail_url'] = $thumbnailUrl;
        }

        return $this->sendMessageToRoom($roomId, $caption ?? '', 'video', $options);
    }

    /**
     * Send a system message to a room.
     * Note: System messages require admin permissions (RLS policy).
     *
     * @param string $roomId The room ID (UUID)
     * @param string $content System message content
     * @return array Supabase API response
     */
    public function sendSystemMessage(string $roomId, string $content): array
    {
        return $this->sendMessageToRoom($roomId, $content, 'system');
    }

    /**
     * Get all room IDs from Supabase.
     *
     * @return array Array of room IDs (UUIDs)
     * @throws RuntimeException If table not found or API error
     */
    public function getAllRoomIds(): array
    {
        $roomsTable = config('supabase.rooms_table', 'rooms');
        $schema = config('supabase.schema', 'public');
        
        try {
            $rows = $this->client->request('GET', $roomsTable, [
                'select' => 'id',
            ]);

            return array_column($rows, 'id');
        } catch (RuntimeException $e) {
            // Provide more context about which table was queried
            throw new RuntimeException(
                "Failed to fetch rooms from table '{$schema}.{$roomsTable}': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Broadcast a message to all rooms.
     *
     * @param string $content Message content
     * @param string|null $type Message type (defaults to config value)
     * @param array $options Optional fields
     * @return array Associative array: [roomId => result or error message]
     */
    public function broadcastMessage(string $content, ?string $type = null, array $options = []): array
    {
        $roomIds = $this->getAllRoomIds();
        $results = [];

        foreach ($roomIds as $roomId) {
            try {
                $result = $this->sendMessageToRoom($roomId, $content, $type, $options);
                $results[$roomId] = [
                    'success' => true,
                    'data' => $result,
                ];
            } catch (\Exception $e) {
                $results[$roomId] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Send a message to a room or broadcast to all.
     * 
     * If $roomId is null, send to all rooms.
     * If not null, send only to that room.
     *
     * @param string $content Message content
     * @param string|null $roomId Optional room ID (UUID, null = broadcast)
     * @param string|null $type Message type (defaults to config value)
     * @param array $options Optional fields
     * @return array Response data or broadcast results
     */
    public function send(string $content, ?string $roomId = null, ?string $type = null, array $options = []): array
    {
        if ($roomId) {
            return $this->sendMessageToRoom($roomId, $content, $type, $options);
        }

        return $this->broadcastMessage($content, $type, $options);
    }

    /**
     * @deprecated Use sendMessageToRoom() instead
     */
    public function sendMessageToConversation(string $conversationId, string $content, ?string $type = null): array
    {
        return $this->sendMessageToRoom($conversationId, $content, $type);
    }

    /**
     * @deprecated Use getAllRoomIds() instead
     */
    public function getAllConversationIds(): array
    {
        return $this->getAllRoomIds();
    }
}

