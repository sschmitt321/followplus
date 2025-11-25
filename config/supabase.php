<?php

return [
    'url' => env('SUPABASE_URL'),
    
    'service_key' => env('SUPABASE_SERVICE_ROLE_KEY'),
    
    'bot_sender_id' => env('SUPABASE_BOT_SENDER_ID'),
    
    'schema' => env('SUPABASE_SCHEMA', 'public'),
    
    'messages_table' => env('SUPABASE_MESSAGES_TABLE', 'messages'),
    
    'rooms_table' => env('SUPABASE_ROOMS_TABLE', 'rooms'),
    
    'default_message_type' => env('SUPABASE_MESSAGE_TYPE_DEFAULT', 'text'),
];

