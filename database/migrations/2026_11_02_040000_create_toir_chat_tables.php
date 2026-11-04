<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toir_chats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('kind', 16); // direct/channel
            $t->string('title', 150)->nullable();
            $t->string('direct_key', 80)->nullable(); // sorted pair; one chat per company
            $t->timestamps();
            $t->unique(['company_id', 'direct_key']);
            $t->index(['company_id', 'updated_at']);
        });
        Schema::create('toir_chat_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chat_id')->constrained('toir_chats')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->boolean('is_admin')->default(false);
            $t->unsignedBigInteger('joined_after_message_id')->default(0);
            $t->timestamp('joined_at')->useCurrent();
            $t->timestamp('left_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['chat_id', 'user_id']);
            $t->index(['company_id', 'user_id', 'left_at']);
        });
        Schema::create('toir_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chat_id')->constrained('toir_chats')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reply_to_id')->nullable()->constrained('toir_chat_messages')->nullOnDelete();
            $t->string('client_token', 64)->nullable();
            $t->text('body');
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['chat_id', 'user_id', 'client_token'], 'toir_chat_message_dedupe');
            $t->index(['company_id', 'chat_id', 'id']);
        });
        Schema::create('toir_chat_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chat_id')->constrained('toir_chats')->cascadeOnDelete();
            $t->foreignId('message_id')->constrained('toir_chat_messages')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->unique(['message_id', 'user_id']);
            $t->index(['company_id', 'user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toir_chat_receipts');
        Schema::dropIfExists('toir_chat_messages');
        Schema::dropIfExists('toir_chat_members');
        Schema::dropIfExists('toir_chats');
    }
};
