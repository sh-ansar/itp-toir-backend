<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ToirChatSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $companyId,
        public int $chatId,
        public string $kind,
        public array $recipients,
        public ?int $messageId = null,
        public array $metadata = [],
    ) {}

    public function broadcastAs(): string { return 'toir.chat.changed'; }

    public function broadcastOn(): array
    {
        return collect($this->recipients)->unique()
            ->map(fn($id)=>new PrivateChannel('toir.company.'.$this->companyId.'.user.'.$id))
            ->all();
    }

    public function broadcastWith(): array
    {
        return [
            'company_id'=>$this->companyId,
            'chat_id'=>$this->chatId,
            'kind'=>$this->kind,
            'message_id'=>$this->messageId,
            'metadata'=>$this->metadata,
        ];
    }
}
