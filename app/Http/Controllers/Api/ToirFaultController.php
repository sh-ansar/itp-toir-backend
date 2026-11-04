<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ToirAsset;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ToirFaultController extends Controller
{
    public function report(Request $request, int $assetId): JsonResponse
    {
        $company = $request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company, 403, 'Нет доступа к компании.');

        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => ['required', 'in:normal,high,critical'],
        ]);

        $asset = ToirAsset::query()->where('company_id', $company->id)->findOrFail($assetId);
        if (in_array($asset->status, ['retired', 'disposed'], true)) {
            throw ValidationException::withMessages(['asset_id' => 'Нельзя зарегистрировать неисправность для списанного оборудования.']);
        }

        $order = DB::transaction(function () use ($request, $company, $asset, $data) {
            $order = ToirWorkOrder::forceCreate([
                'company_id' => $company->id,
                'asset_id' => $asset->id,
                'initiator_id' => $request->user()->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'type' => 'emergency',
                'priority' => ['critical' => 1, 'high' => 2, 'normal' => 3][$data['priority']],
                'status' => 'waiting',
            ]);
            $order->number = 'НЗ-АВ-'.$order->id;
            $order->save();
            ToirWorkOrderEvent::forceCreate([
                'company_id' => $company->id,
                'work_order_id' => $order->id,
                'user_id' => $request->user()->id,
                'action' => 'fault_reported',
                'message' => $data['description'],
                'changes' => ['asset_id' => $asset->id, 'priority' => $data['priority'], 'to' => 'waiting'],
            ]);

            return $order;
        }, 3);

        return response()->json(['data' => $order], 201);
    }
}
