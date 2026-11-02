<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirEmployee;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirProject;
use App\Models\ToirSupply;
use App\Models\ToirTender;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

class ToirResourceController extends Controller
{
    private const RESOURCES = [
        'assets' => ToirAsset::class,
        'employees' => ToirEmployee::class,
        'projects' => ToirProject::class,
        'work-orders' => ToirWorkOrder::class,
        'plans' => ToirMaintenancePlan::class,
        'supplies' => ToirSupply::class,
        'tenders' => ToirTender::class,
    ];

    private const SEARCH_FIELDS = [
        'assets' => ['name', 'code', 'location', 'serial_number'],
        'employees' => ['name', 'email', 'department', 'position'],
        'projects' => ['name', 'code', 'manager_name'],
        'work-orders' => ['number', 'title', 'description'],
        'plans' => ['title', 'description'],
        'supplies' => ['name', 'unit'],
        'tenders' => ['number', 'title'],
    ];

    private const RELATIONS = [
        'work-orders' => ['asset', 'employee', 'project', 'maintenancePlan'],
        'plans' => ['asset', 'employee'],
        'supplies' => ['asset'],
        'assets' => ['parent'],
    ];

    private function company(Request $request): Company
    {
        $companyId = $request->session()->get('toir_company_id');

        $company = $request->user()->activeCompanies()
            ->whereKey($companyId)
            ->first();

        abort_unless($company, 403, 'Доступ к компании не предоставлен.');

        return $company;
    }

    private function model(string $resource): string
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);

        return self::RESOURCES[$resource];
    }

    private function authorizeWrite(Company $company, string $resource, string $operation): void
    {
        $role = $company->pivot->role;
        if (in_array($role, ['owner', 'admin', 'manager'], true)) {
            return;
        }

        if ($role === 'engineer' && $operation !== 'delete'
            && in_array($resource, ['work-orders', 'plans', 'supplies'], true)) {
            return;
        }

        abort(403, 'Недостаточно прав для изменения данных.');
    }

    private function rules(string $resource, Company $company, ?Model $record = null): array
    {
        $id = $record?->getKey();
        $belongs = fn (string $table) => Rule::exists($table, 'id')
            ->where('company_id', $company->id)->whereNull('deleted_at');
        $unique = fn (string $table, string $field) => Rule::unique($table, $field)
            ->where('company_id', $company->id)->ignore($id);

        return match ($resource) {
            'assets' => [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'code' => ['sometimes', 'required', 'string', 'max:80', $unique('toir_assets', 'code')],
                'category' => ['sometimes', 'required', Rule::in(['premises','equipment','tools','materials'])],
                'status' => ['sometimes', Rule::in(['active','maintenance','inactive','retired'])],
                'location' => ['nullable','string','max:255'],
                'parent_id' => ['nullable','integer',$belongs('toir_assets'), Rule::notIn($id ? [$id] : [])],
                'manufacturer' => ['nullable','string','max:255'],
                'model' => ['nullable','string','max:255'],
                'serial_number' => ['nullable','string','max:255'],
                'commissioned_at' => ['nullable','date'],
                'purchase_value' => ['nullable','numeric','min:0'],
                'description' => ['nullable','string','max:5000'],
            ],
            'employees' => [
                'name' => ['sometimes','required','string','max:255'],
                'email' => ['nullable','email:rfc','max:255'],
                'position' => ['nullable','string','max:255'],
                'department' => ['nullable','string','max:255'],
                'phone' => ['nullable','string','max:60'],
                'status' => ['sometimes', Rule::in(['active','inactive','vacation'])],
                'user_id' => ['nullable', Rule::exists('users','id')->whereIn(
                    'id', DB::table('company_user')->where('company_id',$company->id)->where('is_active',true)->pluck('user_id')->all()
                )],
            ],
            'projects' => [
                'name' => ['sometimes','required','string','max:255'],
                'code' => ['sometimes','required','string','max:80',$unique('toir_projects','code')],
                'manager_name' => ['nullable','string','max:255'],
                'status' => ['sometimes', Rule::in(['planned','active','paused','completed'])],
                'start_date' => ['nullable','date'],
                'end_date' => ['nullable','date','after_or_equal:start_date'],
                'description' => ['nullable','string','max:5000'],
            ],
            'work-orders' => [
                'title' => ['sometimes','required','string','max:255'],
                'description' => ['nullable','string','max:10000'],
                'asset_id' => ['nullable','integer',$belongs('toir_assets')],
                'project_id' => ['nullable','integer',$belongs('toir_projects')],
                'employee_id' => ['nullable','integer',$belongs('toir_employees')],
                'type' => ['sometimes', Rule::in(['planned','emergency'])],
                'status' => $record ? ['prohibited'] : ['sometimes', Rule::in(['waiting'])],
                'planned_start' => ['nullable','date'],
                'planned_end' => ['nullable','date','after_or_equal:planned_start'],
                'actual_start' => ['prohibited'],
                'actual_end' => ['prohibited'],
                'estimated_cost' => ['nullable','numeric','min:0'],
            ],
            'plans' => [
                'title' => ['sometimes','required','string','max:255'],
                'asset_id' => ['sometimes','required','integer',$belongs('toir_assets')],
                'employee_id' => ['nullable','integer',$belongs('toir_employees')],
                'periodicity_days' => ['sometimes','required','integer','min:1','max:3650'],
                'next_due_date' => ['sometimes','required','date'],
                'start_date' => ['nullable','date'],
                'status' => ['sometimes', Rule::in(['planned','active','paused','completed'])],
                'description' => ['nullable','string','max:5000'],
            ],
            'supplies' => [
                'name' => ['sometimes','required','string','max:255'],
                'asset_id' => ['nullable','integer',$belongs('toir_assets')],
                'category' => ['sometimes', Rule::in(['spares','materials','tools'])],
                'quantity' => ['sometimes','numeric','min:0.001'],
                'unit' => ['sometimes','string','max:30'],
                'unit_price' => ['nullable','numeric','min:0'],
                'status' => ['sometimes',Rule::in(['requested','ordered','delivered','cancelled'])],
                'requested_at' => ['nullable','date'],
            ],
            'tenders' => [
                'number' => ['sometimes','required','string','max:80',$unique('toir_tenders','number')],
                'title' => ['sometimes','required','string','max:255'],
                'status' => ['sometimes',Rule::in(['draft','published','evaluation','completed','cancelled'])],
                'budget' => ['nullable','numeric','min:0'],
                'deadline_at' => ['nullable','date'],
                'description' => ['nullable','string','max:5000'],
            ],
            default => abort(404),
        };
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        $company = $this->company($request);
        $model = $this->model($resource);

        $query = $model::query()->where('company_id', $company->id)
            ->with(self::RELATIONS[$resource] ?? []);

        if ($search = trim((string) $request->query('search', ''))) {
            $search = mb_substr($search, 0, 120);
            $query->where(function ($q) use ($resource, $search) {
                foreach (self::SEARCH_FIELDS[$resource] as $field) {
                    $q->orWhere($field, 'like', '%'.addcslashes($search, '%_\\').'%');
                }
            });
        }

        foreach (['status','category','asset_id','employee_id','maintenance_plan_id'] as $field) {
            $value = $request->query($field);
            if ($value !== null && $value !== '' && in_array($field, (new $model)->getConnection()->getSchemaBuilder()->getColumnListing((new $model)->getTable()), true)) {
                $query->where($field, $value);
            }
        }

        $size = min(100, max(1, (int) $request->query('per_page', 15)));
        $page = $query->orderByDesc('id')->paginate($size);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function show(Request $request, string $resource, int $id): JsonResponse
    {
        $company = $this->company($request);
        $model = $this->model($resource);
        $with = self::RELATIONS[$resource] ?? [];
        if ($resource === 'work-orders') $with[] = 'events';

        $record = $model::query()->where('company_id', $company->id)
            ->with($with)->findOrFail($id);

        return response()->json(['data' => $record]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $company = $this->company($request);
        $this->authorizeWrite($company, $resource, 'create');
        $model = $this->model($resource);
        $rules = $this->rules($resource, $company);

        foreach (['name','code','category','title','number','asset_id','next_due_date'] as $required) {
            $mandatory = [
                'assets' => ['name','code','category'],
                'employees' => ['name'],
                'projects' => ['name','code'],
                'work-orders' => ['title'],
                'plans' => ['title','asset_id','next_due_date'],
                'supplies' => ['name'],
                'tenders' => ['title','number'],
            ][$resource];
            if (in_array($required,$mandatory,true)) {
                $rules[$required] = array_values(array_filter($rules[$required],
                    fn ($rule) => $rule !== 'sometimes'));
                array_unshift($rules[$required], 'required');
            }
        }
        $values = $request->validate($rules);

        $record = DB::transaction(function () use ($model, $values, $company, $request, $resource): Model {
            $record = new $model();
            $record->fill($values);
            $record->company_id = $company->id;
            if ($resource === 'work-orders') {
                $record->initiator_id = $request->user()->id;
            }
            $record->save();

            if ($resource === 'work-orders') {
                $record->number = 'НЗ-'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT);
                $record->save();
                $this->recordEvent($company, $record, $request, 'created', null, ['status' => $record->status]);
            }

            return $record;
        });

        return response()->json(['data' => $record->load(self::RELATIONS[$resource] ?? [])], 201);
    }

    private function assertAssetParentIsValid(Company $company, ?ToirAsset $record, array $values): void
    {
        if (!array_key_exists('parent_id', $values) || $values['parent_id'] === null) {
            return;
        }

        $next = (int) $values['parent_id'];
        $seen = [];
        while ($next > 0) {
            if (isset($seen[$next]) || ($record && $next === $record->id)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Вложенность активов не может образовывать цикл.',
                ]);
            }

            $seen[$next] = true;
            $parent = ToirAsset::where('company_id', $company->id)->find($next);
            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Родительский актив не найден в компании.',
                ]);
            }
            $next = (int) ($parent->parent_id ?? 0);
        }
    }

    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        $company = $this->company($request);
        $this->authorizeWrite($company, $resource, 'update');
        $model = $this->model($resource);
        $record = $model::query()->where('company_id',$company->id)->findOrFail($id);
        if ($resource === 'work-orders' && $record->status === 'closed') {
            return response()->json([
                'message' => 'Закрытый наряд нельзя редактировать. Для исправления откройте новый наряд.',
            ], 409);
        }
        $values = $request->validate($this->rules($resource, $company, $record));
        if ($resource === 'assets') {
            $this->assertAssetParentIsValid($company, $record, $values);
        }
        if ($resource === 'work-orders') {
            $start = $values['planned_start'] ?? $record->planned_start;
            $end = $values['planned_end'] ?? $record->planned_end;
            if ($start && $end && Carbon::parse($end)->lessThan(Carbon::parse($start))) {
                throw ValidationException::withMessages([
                    'planned_end' => 'Завершение работ не может быть раньше планового начала.',
                ]);
            }
        }
        if ($resource === 'plans' && $record->workOrders()->where('status', '!=', 'closed')->exists()) {
            if (($values['status'] ?? null) === 'completed') {
                return response()->json(['message'=>'Нельзя завершить план, пока есть незакрытый наряд.'],409);
            }
            foreach (['asset_id','employee_id','next_due_date','periodicity_days'] as $field) {
                if (array_key_exists($field, $values) && (string) ($record->{$field} ?? '') !== (string) ($values[$field] ?? '')) {
                    return response()->json([
                        'message' => 'Нельзя менять актив, исполнителя или график, пока наряд по плану не закрыт.',
                    ], 409);
                }
            }
        }
        $before = $record->only(array_keys($values));

        DB::transaction(function () use ($record, $values, $before, $resource, $company, $request) {
            $record->fill($values)->save();
            if ($resource === 'work-orders' && $record->wasChanged()) {
                $this->recordEvent($company, $record, $request, 'updated', null, [
                    'before' => $before, 'after' => $record->only(array_keys($values)),
                ]);
            }
        });

        return response()->json(['data' => $record->refresh()->load(self::RELATIONS[$resource] ?? [])]);
    }

    public function destroy(Request $request, string $resource, int $id): JsonResponse
    {
        $company = $this->company($request);
        $this->authorizeWrite($company, $resource, 'delete');
        $model = $this->model($resource);
        $record = $model::query()->where('company_id',$company->id)->findOrFail($id);

        // Do not leave child equipment with a broken structural chain.
        if ($resource === 'assets' && ToirAsset::where('parent_id',$id)->exists()) {
            return response()->json(['message'=>'Сначала удалите или переместите вложенные активы.'],409);
        }
        if ($resource === 'assets' && (ToirMaintenancePlan::where('asset_id',$id)->exists() || ToirWorkOrder::where('asset_id',$id)->exists() || ToirSupply::where('asset_id',$id)->exists())) {
            return response()->json(['message'=>'Актив используется в планах или нарядах.'],409);
        }
        if ($resource === 'employees' && (
            ToirWorkOrder::where('employee_id',$id)->where('status','!=','closed')->exists()
            || ToirMaintenancePlan::where('employee_id',$id)->exists()
        )) {
            return response()->json(['message'=>'Исполнитель используется в незакрытых нарядах или планах ТО.'],409);
        }
        if ($resource === 'projects' && ToirWorkOrder::where('project_id',$id)->exists()) {
            return response()->json(['message'=>'Проект используется в нарядах.'],409);
        }
        if ($resource === 'plans' && $record->workOrders()->exists()) {
            return response()->json(['message'=>'План ТО связан с нарядами; его нельзя архивировать.'],409);
        }
        if ($resource === 'work-orders' && (
            $record->maintenance_plan_id !== null || $record->status === 'in_progress'
        )) {
            return response()->json(['message'=>'Активный наряд или наряд из плана ТО нельзя архивировать.'],409);
        }
        if ($resource === 'work-orders') {
            DB::transaction(function () use ($record, $company, $request) {
                $this->recordEvent($company, $record, $request, 'deleted', null);
                $record->delete();
            });
        } else {
            $record->delete();
        }

        return response()->json(['message'=>'Запись перенесена в архив.']);
    }

    public function overview(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $id = $company->id;

        return response()->json(['data' => [
            'assets' => ToirAsset::where('company_id',$id)->count(),
            'employees' => ToirEmployee::where('company_id',$id)->count(),
            'projects' => ToirProject::where('company_id',$id)->count(),
            'work_orders' => ToirWorkOrder::where('company_id',$id)->count(),
            'waiting' => ToirWorkOrder::where('company_id',$id)->where('status','waiting')->count(),
            'in_progress' => ToirWorkOrder::where('company_id',$id)->where('status','in_progress')->count(),
            'overdue' => ToirWorkOrder::where('company_id',$id)->where('status','overdue')->count(),
            'closed' => ToirWorkOrder::where('company_id',$id)->where('status','closed')->count(),
            'plans' => ToirMaintenancePlan::where('company_id',$id)->count(),
            'plans_overdue' => ToirMaintenancePlan::where('company_id',$id)->whereDate('next_due_date','<',now()->toDateString())->where('status','!=','completed')->count(),
            'supplies_requested' => ToirSupply::where('company_id',$id)->where('status','requested')->count(),
            'tenders' => ToirTender::where('company_id',$id)->count(),
        ]]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $orders = ToirWorkOrder::where('company_id',$company->id)
            ->whereNotNull('planned_start')
            ->with(['asset','employee'])->orderBy('planned_start')->limit(150)->get();
        $plans = ToirMaintenancePlan::where('company_id',$company->id)
            ->with(['asset','employee'])->orderBy('next_due_date')->limit(150)->get();

        return response()->json(['data' => ['orders'=>$orders,'plans'=>$plans]]);
    }

    private function recordEvent(Company $company, ToirWorkOrder $record, Request $request,
        string $action, ?string $message, array $changes = []): void
    {
        $event = new ToirWorkOrderEvent();
        $event->company_id = $company->id;
        $event->work_order_id = $record->id;
        $event->user_id = $request->user()->id;
        $event->action = $action;
        $event->message = $message;
        $event->changes = $changes;
        $event->save();
    }
}
