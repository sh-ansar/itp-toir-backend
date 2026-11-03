<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('toir_assets', function(Blueprint $t) {
            $t->string('node_type',24)->default('equipment'); // object/system/aggregate/assembly/component/equipment
            $t->unsignedTinyInteger('criticality')->default(3);
            $t->unsignedTinyInteger('condition_score')->nullable(); // 0 bad, 100 healthy
            $t->decimal('operating_hours', 12, 2)->default(0);
            $t->dateTime('condition_recorded_at')->nullable();
            $t->index(['company_id','parent_id'], 'toir_asset_tree_idx');
        });

        Schema::create('toir_crews',function(Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code',64);
            $t->string('name',200);
            $t->string('status',20)->default('active');
            $t->foreignId('lead_employee_id')->nullable()->constrained('toir_employees')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id','code']);
            $t->index(['company_id','status']);
        });

        Schema::create('toir_crew_members',function(Blueprint $t){
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('crew_id')->constrained('toir_crews')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained('toir_employees')->restrictOnDelete();
            $t->string('role',24)->default('member');
            $t->timestamps();
            $t->unique(['crew_id','employee_id']);
            $t->index(['company_id','employee_id']);
        });

        Schema::create('toir_crew_shifts',function(Blueprint $t){
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('crew_id')->constrained('toir_crews')->cascadeOnDelete();
            $t->date('work_date');
            $t->time('starts_at');
            $t->time('ends_at');
            $t->string('status',20)->default('confirmed');
            $t->string('note',255)->nullable();
            $t->timestamps();
            $t->unique(['crew_id','work_date','starts_at'],'toir_shift_unique');
            $t->index(['company_id','work_date']);
        });

        Schema::create('toir_asset_readings',function(Blueprint $t){
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained('toir_assets')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('meter',24); // operating_hours / condition_score
            $t->decimal('value',12,2);
            $t->dateTime('recorded_at');
            $t->string('source',24)->default('manual');
            $t->string('note',255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','asset_id','meter','recorded_at','id'],'toir_readings_lookup');
        });

        Schema::table('toir_maintenance_plans',function(Blueprint $t){
            $t->foreignId('crew_id')->nullable()->constrained('toir_crews')->nullOnDelete();
            $t->unsignedInteger('duration_minutes')->default(120);
            $t->unsignedTinyInteger('priority')->default(3);
            $t->decimal('interval_hours',12,2)->nullable();
            $t->decimal('next_due_hours',12,2)->nullable();
            $t->text('work_instructions')->nullable();
            $t->text('safety_instructions')->nullable();
        });

        Schema::table('toir_work_orders',function(Blueprint $t){
            $t->foreignId('crew_id')->nullable()->constrained('toir_crews')->nullOnDelete();
            $t->unsignedInteger('duration_minutes')->nullable();
            $t->string('trigger_source',24)->default('manual'); // manual / calendar / operating_hours / combined
            $t->decimal('trigger_hours',12,2)->nullable(); // immutable threshold snapshot for idempotency
            $t->index(['company_id','crew_id','planned_start','planned_end'],'toir_crew_allocations_idx');
        });
    }

    public function down(): void
    {
        Schema::table('toir_work_orders',function(Blueprint $t){
            $t->dropIndex('toir_crew_allocations_idx');
            $t->dropConstrainedForeignId('crew_id');
            $t->dropColumn(['duration_minutes','trigger_source','trigger_hours']);
        });
        Schema::table('toir_maintenance_plans',function(Blueprint $t){
            $t->dropConstrainedForeignId('crew_id');
            $t->dropColumn(['duration_minutes','priority','interval_hours','next_due_hours',
                'work_instructions','safety_instructions']);
        });
        Schema::dropIfExists('toir_asset_readings');
        Schema::dropIfExists('toir_crew_shifts');
        Schema::dropIfExists('toir_crew_members');
        Schema::dropIfExists('toir_crews');
        Schema::table('toir_assets',function(Blueprint $t){
            $t->dropIndex('toir_asset_tree_idx');
            $t->dropColumn(['node_type','criticality','condition_score',
                'operating_hours','condition_recorded_at']);
        });
    }
};
