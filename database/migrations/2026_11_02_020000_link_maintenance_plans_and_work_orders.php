<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('toir_work_orders', function (Blueprint $table): void {
            $table->foreignId('maintenance_plan_id')->nullable()
                ->constrained('toir_maintenance_plans')->nullOnDelete();
            $table->date('scheduled_for')->nullable();
            // Prevent duplicate work orders for the same plan and scheduled cycle.
            $table->unique(['company_id', 'maintenance_plan_id', 'scheduled_for'], 'toir_plan_cycle_unique');
            $table->index(['company_id', 'maintenance_plan_id', 'status'], 'toir_plan_orders_index');
        });
    }

    public function down(): void
    {
        Schema::table('toir_work_orders', function (Blueprint $table): void {
            $table->dropUnique('toir_plan_cycle_unique');
            $table->dropIndex('toir_plan_orders_index');
            $table->dropConstrainedForeignId('maintenance_plan_id');
            $table->dropColumn('scheduled_for');
        });
    }
};
