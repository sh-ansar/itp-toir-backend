<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirAssetReading;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirWorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ToirAssetPassportController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $company = $request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company, 403, 'Нет доступа к компании.');
        $asset = ToirAsset::query()->where('company_id', $company->id)
            ->with('parent:id,name,code')->findOrFail($id);

        $orders = ToirWorkOrder::query()->where('company_id', $company->id)
            ->where('asset_id', $asset->id)
            ->with(['employee:id,name', 'maintenancePlan:id,title'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        $plans = ToirMaintenancePlan::query()->where('company_id', $company->id)
            ->where('asset_id', $asset->id)
            ->orderBy('next_due_date')->limit(100)->get();

        $readings = ToirAssetReading::query()->where('company_id', $company->id)
            ->where('asset_id', $asset->id)
            ->orderByDesc('recorded_at')->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => [
            'asset' => $asset,
            'summary' => [
                'total_orders' => ToirWorkOrder::where('company_id', $company->id)
                    ->where('asset_id', $asset->id)->count(),
                'completed_orders' => ToirWorkOrder::where('company_id', $company->id)
                    ->where('asset_id', $asset->id)->where('status', 'closed')->count(),
                'open_orders' => ToirWorkOrder::where('company_id', $company->id)
                    ->where('asset_id', $asset->id)->whereIn('status', ['waiting','in_progress','overdue','awaiting_acceptance'])->count(),
                'plan_count' => ToirMaintenancePlan::where('company_id', $company->id)
                    ->where('asset_id', $asset->id)->count(),
                'reading_count' => ToirAssetReading::where('company_id', $company->id)
                    ->where('asset_id', $asset->id)->count(),
            ],
            'orders' => $orders,
            'plans' => $plans,
            'readings' => $readings,
        ]]);
    }
}
