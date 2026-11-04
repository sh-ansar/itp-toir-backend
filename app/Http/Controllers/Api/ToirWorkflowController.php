<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\ToirWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ToirWorkflowController extends Controller
{
    public function __construct(private readonly ToirWorkflowService $workflow) {}

    private function company(Request $request): Company
    {
        $companyId = $request->session()->get('toir_company_id');
        $company = $request->user()->activeCompanies()
            ->whereKey($companyId)
            ->first();
        abort_unless($company, 403, 'Доступ к компании не предоставлен.');

        return $company;
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'complete', 'reopen'])],
            'comment' => ['nullable', 'string', 'max:2000'],
            'actual_labor_minutes' => ['prohibited_unless:action,complete', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $order = $this->workflow->transition(
            $request, $company, $id, $data['action'], $data['comment'] ?? null, $data['actual_labor_minutes'] ?? null
        );

        return response()->json(['data' => $order]);
    }

    public function issuePlan(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $result = $this->workflow->issuePlan($request, $company, $id);

        return response()->json([
            'data' => $result['order'],
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200);
    }
}
