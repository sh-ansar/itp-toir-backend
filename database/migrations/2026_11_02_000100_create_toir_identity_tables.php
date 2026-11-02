<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('viewer');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('toir_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toir_audit_events');
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};
