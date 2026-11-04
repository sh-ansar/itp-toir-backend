<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ToirWorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ToirWorkControlController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company, 403, 'Нет доступа к компании.');
        $id = $company->id;
        $base = ToirWorkOrder::query()->where('company_id', $id);
        $open = ['waiting', 'overdue', 'in_progress', 'awaiting_acceptance'];
        $now = now();
        $overdue = (clone $base)->whereIn('status', $open)
            ->whereNotNull('planned_end')->where('planned_end', '<', $now)->count();
        $waitingAcceptance = (clone $base)->where('status', 'awaiting_acceptance')->count();
        $unassigned = (clone $base)->whereIn('status', ['waiting', 'overdue'])
            ->whereNull('employee_id')->whereNull('crew_id')->count();
        $totals = DB::table('toir_work_order_materials as m')
            ->join('toir_work_orders as o', function ($join) {
                $join->on('o.id', '=', 'm.work_order_id')->on('o.company_id', '=', 'm.company_id');
            })->where('o.company_id', $id)->whereNull('o.deleted_at')
            ->selectRaw('COALESCE(SUM(m.actual_cost),0) as material_cost')->first();
        $orders = (clone $base)->whereIn('status', $open)
            ->with(['asset:id,name,code', 'employee:id,name'])
            ->withSum('materials as actual_material_cost', 'actual_cost')
            ->orderBy('priority')
            ->orderByRaw('CASE WHEN planned_end IS NULL THEN 1 ELSE 0 END')
            ->orderBy('planned_end')
            ->orderBy('id')
            ->limit(100)->get()
            ->map(function ($order) use ($now) {
                $order->is_late = $order->planned_end !== null &&
                    Carbon::parse($order->planned_end)->lt($now);

                return $order;
            });

        return response()->json(['data' => [
            'summary' => [
                'open_orders' => (clone $base)->whereIn('status', $open)->count(),
                'late_orders' => $overdue,
                'unassigned_orders' => $unassigned,
                'awaiting_acceptance' => $waitingAcceptance,
                'recorded_material_cost' => (float) ($totals->material_cost ?? 0),
                'recorded_labor_minutes' => (int) (clone $base)->whereNotNull('actual_labor_minutes')
                    ->sum('actual_labor_minutes'),
                'orders_with_labor_recorded' => (clone $base)->whereNotNull('actual_labor_minutes')->count(),
            ],
            'orders' => $orders,
            'limit' => 100,
        ]]);
    }
}
