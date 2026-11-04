<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toir_maintenance_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('maintenance_plan_id')
                ->constrained('toir_maintenance_plans')->cascadeOnDelete();
            $t->unsignedInteger('position');
            $t->string('title', 200);
            $t->text('instructions')->nullable();
            $t->string('kind', 24)->default('inspection');
            $t->boolean('is_required')->default(true);
            $t->string('unit', 30)->nullable();
            $t->timestamps();
            $t->unique(['maintenance_plan_id', 'position'], 'toir_plan_step_position_unique');
            $t->index(['company_id', 'maintenance_plan_id'], 'toir_plan_steps_company');
        });
        Schema::create('toir_work_order_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('work_order_id')->constrained('toir_work_orders')->cascadeOnDelete();
            $t->foreignId('maintenance_step_id')->nullable()
                ->constrained('toir_maintenance_steps')->nullOnDelete();
            $t->unsignedInteger('position');
            $t->string('title', 200);
            $t->text('instructions')->nullable();
            $t->string('kind', 24)->default('inspection');
            $t->boolean('is_required')->default(true);
            $t->string('unit', 30)->nullable();
            $t->string('status', 20)->default('pending');
            $t->decimal('numeric_value', 14, 3)->nullable();
            $t->text('comment')->nullable();
            $t->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('performed_at')->nullable();
            $t->timestamps();
            $t->unique(['work_order_id', 'position'], 'toir_order_step_position_unique');
            $t->index(['company_id', 'work_order_id', 'status'], 'toir_order_steps_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toir_work_order_steps');
        Schema::dropIfExists('toir_maintenance_steps');
    }
};
