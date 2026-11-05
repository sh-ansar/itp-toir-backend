<?php

namespace App\Services\MroCommercial;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PlanCatalogService
{
    public function modules(): array
    {
        return ['assets', 'work_orders', 'plans', 'schedule', 'crews', 'risk', 'warehouse', 'reports', 'chat'];
    }

    public function save(array $data, ?int $actor): object
    {
        $modules = $this->modules();
        $d = Validator::make($data, [
            'code' => ['required', 'regex:/^[a-z][a-z0-9_-]{1,39}$/'], 'names' => 'required|array:ru,en,ge', 'names.ru' => 'required|string|max:120', 'names.en' => 'required|string|max:120', 'names.ge' => 'required|string|max:120',
            'description' => 'nullable|string|max:4000', 'price_minor' => 'required|integer|min:0|max:100000000000', 'currency' => ['required', Rule::in(['KZT', 'GEL', 'USD', 'EUR'])], 'period_months' => 'required|integer|min:1|max:120', 'is_public' => 'required|boolean', 'display_order' => 'integer|min:-10000|max:10000',
            'limits' => 'required|array:users,companies,storage_bytes,max_file_bytes', 'limits.users' => 'present|nullable|integer|min:1|max:1000000', 'limits.companies' => 'present|nullable|integer|min:1|max:1000000', 'limits.storage_bytes' => 'present|nullable|integer|min:1|max:1000000000000000', 'limits.max_file_bytes' => 'present|nullable|integer|min:1|max:1000000000000',
            'modules' => ['required', 'array:'.implode(',', $modules)], 'modules.*' => 'boolean',
        ])->validate();

        return DB::transaction(function () use ($d, $actor, $modules) {
            DB::table('mro_plans')->insertOrIgnore(['code' => $d['code'], 'created_at' => now(), 'updated_at' => now()]);
            $plan = DB::table('mro_plans')->where('code', $d['code'])->lockForUpdate()->first();
            $terms = ['names' => $d['names'], 'description' => $d['description'] ?? '', 'price_minor' => (int) $d['price_minor'], 'currency' => $d['currency'], 'period_months' => (int) $d['period_months'], 'limits' => $d['limits'], 'modules' => array_replace(array_fill_keys($modules, false), $d['modules'])];
            $json = json_encode($terms, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $version = 1 + (int) DB::table('mro_plan_versions')->where('plan_id', $plan->id)->max('version');
            $id = DB::table('mro_plan_versions')->insertGetId(['plan_id' => $plan->id, 'version' => $version, 'status' => 'draft', 'is_public' => $d['is_public'], 'display_order' => $d['display_order'] ?? 0, 'terms' => $json, 'sha256' => hash('sha256', $json), 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            PortalAudit::record($actor, null, 'plan.draft', "plan:$id", ['code' => $d['code'], 'version' => $version]);

            return $this->find($id);
        }, 3);
    }

    public function find(int $id): object
    {
        $p = DB::table('mro_plan_versions as v')->join('mro_plans as p', 'p.id', '=', 'v.plan_id')->where('v.id', $id)->select('v.*', 'p.code')->first();
        abort_unless($p, 404);
        $p->terms = json_decode($p->terms, true, 512, JSON_THROW_ON_ERROR);

        return $p;
    }

    public function transition(int $id, string $state, int $actor): object
    {
        abort_unless(in_array($state, ['published', 'archived'], true), 422);
        DB::transaction(function () use ($id, $state, $actor) {
            $v = DB::table('mro_plan_versions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($v, 404);
            abort_unless($v->status === ($state === 'published' ? 'draft' : 'published'), 409);
            DB::table('mro_plan_versions')->where('id', $id)->update(['status' => $state, 'updated_at' => now()]);
            PortalAudit::record($actor, null, 'plan.'.$state, "plan:$id");
        });

        return $this->find($id);
    }

    public function catalog(bool $public = true): array
    {
        $q = DB::table('mro_plan_versions as v')->join('mro_plans as p', 'p.id', '=', 'v.plan_id');
        if ($public) {
            $q->where('p.code', '!=', 'legacy')->where('v.status', 'published')->where('v.is_public', true)->whereNotExists(fn ($n) => $n->selectRaw('1')->from('mro_plan_versions as newer')->whereColumn('newer.plan_id', 'v.plan_id')->whereIn('newer.status', ['published', 'archived'])->whereColumn('newer.version', '>', 'v.version'));
        }

        return $q->select('v.*', 'p.code')->orderBy('v.display_order')->orderByDesc('v.id')->get()->map(function ($p) {
            $p->terms = json_decode($p->terms, true);

            return $p;
        })->all();
    }
}
