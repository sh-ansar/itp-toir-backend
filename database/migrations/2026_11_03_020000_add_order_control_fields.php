<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('toir_work_orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(3);
            $table->unsignedInteger('actual_labor_minutes')->nullable();
            $table->index(['company_id', 'status', 'priority'], 'toir_order_priority_index');
        });
    }

    public function down(): void
    {
        Schema::table('toir_work_orders', function (Blueprint $table) {
            $table->dropIndex('toir_order_priority_index');
            $table->dropColumn(['priority', 'actual_labor_minutes']);
        });
    }
};
