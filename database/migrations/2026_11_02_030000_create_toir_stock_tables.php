<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toir_stock_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('sku', 80);
            $t->string('name', 255);
            $t->string('unit', 24)->default('шт.');
            $t->decimal('quantity_on_hand', 14, 3)->default(0);
            $t->decimal('quantity_reserved', 14, 3)->default(0);
            $t->decimal('unit_cost', 14, 2)->default(0);
            $t->decimal('minimum_quantity', 14, 3)->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'sku']);
            $t->index(['company_id', 'name']);
        });

        Schema::create('toir_work_order_materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('work_order_id')->constrained('toir_work_orders')->restrictOnDelete();
            $t->foreignId('stock_item_id')->constrained('toir_stock_items')->restrictOnDelete();
            $t->decimal('quantity_reserved', 14, 3)->default(0);
            $t->decimal('quantity_used', 14, 3)->default(0);
            $t->decimal('actual_cost', 16, 2)->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'work_order_id', 'stock_item_id'], 'toir_material_unique');
        });

        Schema::create('toir_stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained('toir_stock_items')->restrictOnDelete();
            $t->foreignId('work_order_id')->nullable()->constrained('toir_work_orders')->restrictOnDelete();
            $t->foreignId('supply_id')->nullable()->constrained('toir_supplies')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 32); // receipt / reserve / consume / release
            $t->decimal('quantity', 14, 3);
            $t->decimal('delta_on_hand', 14, 3);
            $t->decimal('delta_reserved', 14, 3);
            $t->decimal('unit_cost', 14, 2);
            $t->string('note', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'stock_item_id', 'id'], 'toir_stock_history_idx');
            $t->index(['company_id', 'work_order_id', 'id'], 'toir_stock_order_idx');
            $t->unique(['company_id', 'supply_id'], 'toir_supply_receipt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toir_stock_movements');
        Schema::dropIfExists('toir_work_order_materials');
        Schema::dropIfExists('toir_stock_items');
    }
};
