<?php

namespace App\Services;

use App\Models\ToirMaintenancePlan;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderStep;
use Illuminate\Validation\ValidationException;

final class ToirChecklistService
{
    /**
     * Snapshot the current approved template into one issued order.
     * This must run in the same transaction as order creation.
     * Editing a future plan never rewrites historical execution evidence.
     */
    public function snapshot(ToirMaintenancePlan $plan, ToirWorkOrder $order): void
    {
        // A single template read per plan avoids repeated queries during mass scheduling.
        $plan->loadMissing('steps');
        foreach ($plan->steps as $step) {
            $exists = ToirWorkOrderStep::where('company_id', $order->company_id)
                ->where('work_order_id', $order->id)->where('position', $step->position)
                ->exists();
            if ($exists) {
                continue;
            }
            ToirWorkOrderStep::forceCreate([
                'company_id' => $order->company_id,
                'work_order_id' => $order->id,
                'position' => $step->position,
                'maintenance_step_id' => $step->id,
                'title' => $step->title, 'instructions' => $step->instructions,
                'kind' => $step->kind, 'is_required' => $step->is_required,
                'unit' => $step->unit, 'status' => 'pending',
            ]);
        }
    }

    public function assertCompletable(ToirWorkOrder $order): void
    {
        $unresolved = $order->steps()->where('is_required', true)
            ->where('status', '!=', 'done')->orderBy('position')->get(['position', 'title']);
        if ($unresolved->isNotEmpty()) {
            $first = $unresolved->first();
            throw ValidationException::withMessages([
                'checklist' => 'Нельзя закрыть наряд: не выполнены обязательные операции ('
                    .$unresolved->count().'). Первая: '.$first->title.'.',
            ]);
        }
    }
}
