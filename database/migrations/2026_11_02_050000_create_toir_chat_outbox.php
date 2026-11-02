<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('toir_chat_outbox', function(Blueprint $t){
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chat_id')->constrained('toir_chats')->cascadeOnDelete();
            $t->foreignId('message_id')->nullable()->constrained('toir_chat_messages')->nullOnDelete();
            $t->string('kind', 32);
            $t->json('metadata')->nullable();
            $t->timestamp('available_at')->useCurrent();
            $t->timestamp('locked_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['available_at', 'locked_until', 'id'], 'toir_outbox_delivery_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('toir_chat_outbox'); }
};
