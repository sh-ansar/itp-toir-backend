<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toir_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('toir_assets')->nullOnDelete();
            $t->string('name');
            $t->string('code', 80);
            $t->string('category', 30); // premises/equipment/tools/materials
            $t->string('status', 30)->default('active');
            $t->string('location')->nullable();
            $t->string('manufacturer')->nullable();
            $t->string('model')->nullable();
            $t->string('serial_number')->nullable();
            $t->date('commissioned_at')->nullable();
            $t->decimal('purchase_value', 14, 2)->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
            $t->index(['company_id', 'category', 'status']);
        });

        Schema::create('toir_employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('position')->nullable();
            $t->string('department')->nullable();
            $t->string('phone')->nullable();
            $t->string('status', 30)->default('active');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status']);
        });

        Schema::create('toir_projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('code', 80);
            $t->string('manager_name')->nullable();
            $t->string('status', 30)->default('planned');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('toir_work_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('number', 40)->nullable();
            $t->foreignId('asset_id')->nullable()->constrained('toir_assets')->nullOnDelete();
            $t->foreignId('project_id')->nullable()->constrained('toir_projects')->nullOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained('toir_employees')->nullOnDelete();
            $t->foreignId('initiator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('type', 30)->default('planned');
            $t->string('status', 30)->default('waiting');
            $t->dateTime('planned_start')->nullable();
            $t->dateTime('planned_end')->nullable();
            $t->dateTime('actual_start')->nullable();
            $t->dateTime('actual_end')->nullable();
            $t->decimal('estimated_cost', 14, 2)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'number']);
            $t->index(['company_id', 'status', 'planned_start']);
        });

        Schema::create('toir_maintenance_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained('toir_assets')->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained('toir_employees')->nullOnDelete();
            $t->string('title');
            $t->string('status', 30)->default('planned');
            $t->unsignedInteger('periodicity_days')->default(30);
            $t->date('start_date')->nullable();
            $t->date('next_due_date');
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'next_due_date', 'status']);
        });

        Schema::create('toir_supplies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained('toir_assets')->nullOnDelete();
            $t->string('name');
            $t->string('category', 30)->default('materials');
            $t->decimal('quantity', 12, 3)->default(1);
            $t->string('unit', 30)->default('шт.');
            $t->decimal('unit_price', 14, 2)->nullable();
            $t->string('status', 30)->default('requested');
            $t->date('requested_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status']);
        });

        Schema::create('toir_tenders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('number', 80);
            $t->string('title');
            $t->string('status', 30)->default('draft');
            $t->decimal('budget', 14, 2)->nullable();
            $t->date('deadline_at')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'number']);
        });

        Schema::create('toir_work_order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('work_order_id')->constrained('toir_work_orders')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 70);
            $t->text('message')->nullable();
            $t->json('changes')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'work_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['toir_work_order_events','toir_tenders','toir_supplies','toir_maintenance_plans',
            'toir_work_orders','toir_projects','toir_employees','toir_assets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
