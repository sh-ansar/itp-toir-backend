<?php

namespace App\Services;

use App\Models\ToirChat;
use App\Models\ToirChatMessage;
use Illuminate\Support\Facades\DB;

final class ToirChatDeliveryService
{
    public static function changed(
        ToirChat $chat, string $kind, ?ToirChatMessage $message = null, array $metadata = []
    ): void {
        DB::table('toir_chat_outbox')->insert([
            'company_id' => $chat->company_id,
            'chat_id' => $chat->id,
            'message_id' => $message?->id,
            'kind' => $kind,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }
}
