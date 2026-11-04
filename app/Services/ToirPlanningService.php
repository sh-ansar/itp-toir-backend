<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirAssetReading;
use App\Models\ToirCrew;
use App\Models\ToirCrewShift;
use App\Models\ToirMaintenancePlan;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ToirPlanningService
{
    public function __construct(private readonly ToirChecklistService $checklist) {}

    /**
     * Condition-based risk triage (not a trained failure-probability model).
     * Returns scored components and an inherited maximum risk for their parents.
     */
    public function risks(int $companyId): array
    {
        $today = Carbon::today();
        $assets = ToirAsset::where('company_id', $companyId)->orderBy('id')->get();
        $plans = ToirMaintenancePlan::where('company_id', $companyId)
            ->whereIn('status', ['planned', 'active'])->get()->groupBy('asset_id');
        $orders = ToirWorkOrder::where('company_id', $companyId)
            ->whereNotIn('status', ['closed'])->get()->groupBy('asset_id');
        $children = $assets->groupBy(fn ($a) => (int) ($a->parent_id ?? 0));
        $result = [];
        foreach ($assets as $asset) {
            $risk = 7 * (max(1, min(5, (int) $asset->criticality)) - 1);
            $reasons = [];
            $quality = [];
            if ($asset->condition_score === null) {
                $quality[] = 'condition_missing';
                $reasons[] = 'Нет измерения технического состояния';
            } else {
                $risk += (int) round((100 - max(0, min(100, (int) $asset->condition_score))) / 3);
                if ((int) $asset->condition_score < 50) {
                    $reasons[] = 'Низкая оценка состояния';
                }
            }
            $assetPlans = $plans->get($asset->id, collect());
            if ($assetPlans->isEmpty() && in_array((string) $asset->node_type,
                ['aggregate', 'assembly', 'component', 'equipment'], true)) {
                $risk += 7;
                $reasons[] = 'Не назначен действующий план ТО';
            }
            foreach ($assetPlans as $plan) {
                $days = $today->diffInDays(Carbon::parse($plan->next_due_date), false);
                if ($days < 0) {
                    $risk += min(30, 12 + 2 * abs($days));
                    $reasons[] = 'Просрочен регламент «'.$plan->title.'»';
                } elseif ($days <= 7) {
                    $risk += 6;
                    $reasons[] = 'Приближается срок регламента «'.$plan->title.'»';
                }
                if ($plan->next_due_hours !== null &&
                    (float) $asset->operating_hours >= (float) $plan->next_due_hours) {
                    $risk += 24;
                    $reasons[] = 'Достигнут порог наработки по регламенту «'.$plan->title.'»';
                }
            }
            $open = $orders->get($asset->id, collect());
            $emergency = $open->where('type', 'emergency')->count();
            if ($emergency) {
                $risk += min(30, $emergency * 18);
                $reasons[] = 'Открытых аварийных нарядов: '.$emergency;
            }
            $overdue = $open->filter(fn ($o) => $o->planned_end
                && Carbon::parse($o->planned_end)->lt(now()))->count();
            if ($overdue) {
                $risk += min(16, $overdue * 8);
                $reasons[] = 'Просроченных незавершённых нарядов: '.$overdue;
            }
            if ($asset->condition_recorded_at &&
                Carbon::parse($asset->condition_recorded_at)->lt(now()->subDays(30))) {
                $quality[] = 'condition_stale';
                $reasons[] = 'Данные диагностики старше 30 дней';
            }
            if ($asset->status === 'retired') {
                $quality[] = 'retired';
            }
            $score = max(0, min(100, $risk));
            $result[$asset->id] = [
                'id' => $asset->id, 'parent_id' => $asset->parent_id, 'name' => $asset->name,
                'code' => $asset->code, 'node_type' => $asset->node_type,
                'status' => $asset->status, 'criticality' => (int) $asset->criticality,
                'condition_score' => $asset->condition_score,
                'operating_hours' => (float) $asset->operating_hours,
                'own_score' => $score, 'score' => $score, 'reasons' => array_values(array_unique($reasons)),
                'data_quality' => $quality, 'children_count' => ($children->get($asset->id) ?? collect())->count(),
                'highest_risk_descendant_id' => null,
            ];
        }
        // Children inherit risk upwards; a bad bearing raises the pump's risk.
        // Bounded recursion protects against legacy cycles in imported data.
        $visited = [];
        $apply = function (int $id, int $depth = 0) use (&$apply, &$result, &$visited, $children) {
            if (isset($visited[$id])) {
                return $result[$id]['score'];
            }
            if ($depth > 50) {
                return $result[$id]['score'];
            }
            $visited[$id] = true;
            foreach ($children->get($id, collect()) as $child) {
                if (! isset($result[$child->id]) || $child->id === $id) {
                    continue;
                }
                $sub = $apply($child->id, $depth + 1);
                $propagated = (int) round($sub * 0.9);
                if ($propagated > $result[$id]['score']) {
                    $result[$id]['score'] = $propagated;
                    $result[$id]['highest_risk_descendant_id'] = $child->id;
                }
            }

            return $result[$id]['score'];
        };
        foreach (array_keys($result) as $id) {
            $apply($id);
        }
        foreach ($result as &$item) {
            $score = $item['score'];
            $item['level'] = $score >= 80 ? 'critical' : ($score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low'));
            $item['risk_method'] = 'transparent_rules_v1';
            if ($item['highest_risk_descendant_id']) {
                $item['reasons'][] = 'Унаследованный риск дочернего узла';
            }
        }
        unset($item);
        $list = array_values($result);
        usort($list, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
        $summary = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'incomplete_data' => 0];
        foreach ($list as $r) {
            $summary[$r['level']]++;
            if ($r['data_quality']) {
                $summary['incomplete_data']++;
            }
        }

        return ['data' => $list, 'summary' => $summary, 'method' => 'transparent_rules_v1',
            'as_of' => now()->toIso8601String(),
            'disclaimer' => 'Эвристическая оценка приоритетов осмотра, не вероятность отказа и не ML-прогноз.'];
    }

    public function usageRates(int $companyId): array
    {
        $readings = ToirAssetReading::where('company_id', $companyId)
            ->where('meter', 'operating_hours')
            ->orderByDesc('recorded_at')->orderByDesc('id')->get()
            ->groupBy('asset_id');
        $rates = [];
        foreach ($readings as $id => $rows) {
            if ($rows->count() < 2) {
                continue;
            }
            $latest = $rows[0];
            $older = $rows[1];
            $days = Carbon::parse($older->recorded_at)->floatDiffInDays(Carbon::parse($latest->recorded_at));
            $delta = (float) $latest->value - (float) $older->value;
            if ($days >= 0.25 && $delta >= 0) {
                $rates[$id] = round($delta / $days, 3);
            }
        }

        return $rates;
    }

    /**
     * Future occurrences are projections; they are not work orders.
     * The meter-based estimate exists only with two valid readings.
     */
    public function forecast(int $companyId, int $days): array
    {
        $start = Carbon::today();
        $end = $start->copy()->addDays($days);
        $rates = $this->usageRates($companyId);
        $plans = ToirMaintenancePlan::where('company_id', $companyId)
            ->whereIn('status', ['planned', 'active'])
            ->with(['asset', 'crew', 'employee'])->orderBy('next_due_date')->get();
        $occurrences = [];
        foreach ($plans as $plan) {
            if (! $plan->asset || $plan->asset->status === 'retired') {
                continue;
            }
            $calendar = Carbon::parse($plan->next_due_date)->startOfDay();
            $per = max(1, (int) $plan->periodicity_days);
            $operating = (float) $plan->asset->operating_hours;
            $rate = $rates[$plan->asset_id] ?? null;
            $hourDue = null;
            if ($plan->next_due_hours !== null) {
                $remaining = (float) $plan->next_due_hours - $operating;
                if ($remaining <= 0) {
                    $hourDue = $start->copy();
                } elseif ($rate !== null && $rate > 0) {
                    $hourDue = $start->copy()->addDays((int) ceil($remaining / $rate));
                }
            }
            $shown = 0;
            $cursor = $calendar->copy();
            while ($cursor->lte($end) && $shown < 12) {
                if ($cursor->gte($start) || $shown === 0) {
                    $occurrences[] = [
                        'plan_id' => $plan->id, 'title' => $plan->title,
                        'asset_id' => $plan->asset_id, 'asset_name' => $plan->asset->name,
                        'asset_code' => $plan->asset->code,
                        'date' => $cursor->toDateString(),
                        'due' => $cursor->lt($start) ? 'overdue' : ($cursor->lte($start->copy()->addDays(7)) ? 'soon' : 'future'),
                        'trigger' => 'calendar',
                        'crew_id' => $plan->crew_id, 'crew_name' => $plan->crew?->name,
                        'employee_id' => $plan->employee_id,
                        'employee_name' => $plan->employee?->name,
                        'duration_minutes' => (int) $plan->duration_minutes,
                        'priority' => (int) $plan->priority,
                        'estimated' => false,
                    ];
                    $shown++;
                }
                $cursor->addDays($per);
                // Limit stale plans to one overdue record, not years of duplicate tasks.
                if ($cursor->lt($start)) {
                    $steps = (int) floor($cursor->diffInDays($start) / $per);
                    if ($steps > 0) {
                        $cursor->addDays($steps * $per);
                    }
                }
            }
            if ($hourDue !== null && $hourDue->lte($end)) {
                $occurrences[] = [
                    'plan_id' => $plan->id, 'title' => $plan->title,
                    'asset_id' => $plan->asset_id, 'asset_name' => $plan->asset->name,
                    'asset_code' => $plan->asset->code, 'date' => $hourDue->toDateString(),
                    'due' => $hourDue->lte($start) ? 'soon' : 'future',
                    'trigger' => 'operating_hours', 'crew_id' => $plan->crew_id,
                    'crew_name' => $plan->crew?->name, 'employee_id' => $plan->employee_id,
                    'employee_name' => $plan->employee?->name, 'priority' => (int) $plan->priority,
                    'duration_minutes' => (int) $plan->duration_minutes,
                    'estimated' => $rate !== null && $rate > 0,
                    'hours_remaining' => round(max(0, (float) $plan->next_due_hours - $operating), 2),
                    'daily_hours_estimate' => $rate,
                ];
            }
        }
        usort($occurrences, fn ($a, $b) => strcmp($a['date'], $b['date']) ?:
            $b['priority'] <=> $a['priority']);

        return ['data' => $occurrences, 'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'method' => 'calendar_and_two_reading_linear_hours_projection',
            'disclaimer' => 'Прогноз моточасов рассчитывается только по двум показаниям, без ML.'];
    }

    public function ensureCrew(int $companyId, ?int $crewId): ?ToirCrew
    {
        if (! $crewId) {
            return null;
        }
        $crew = ToirCrew::where('company_id', $companyId)->findOrFail($crewId);
        if ($crew->status !== 'active') {
            throw ValidationException::withMessages(['crew_id' => 'Бригада не активна.']);
        }

        return $crew;
    }

    public function assertNoCrewConflict(int $companyId, int $crewId, string $start, string $end, ?int $ignoreOrder = null): void
    {
        $begin = Carbon::parse($start);
        $finish = Carbon::parse($end);
        if (! $finish->gt($begin) || ! $begin->isSameDay($finish)) {
            throw ValidationException::withMessages(['planned_end' => 'Работа должна завершаться в тот же день после начала.']);
        }
        $shifts = ToirCrewShift::where('company_id', $companyId)
            ->where('crew_id', $crewId)
            ->whereDate('work_date', $begin->toDateString())
            ->where('status', 'confirmed')->get();
        $within = $shifts->contains(fn ($s) => $begin->format('H:i:s') >= $s->starts_at
            && $finish->format('H:i:s') <= $s->ends_at);
        if (! $within) {
            throw ValidationException::withMessages([
                'crew_id' => 'Период наряда должен полностью входить в подтверждённую смену бригады.',
            ]);
        }
        $members = DB::table('toir_crew_members')->where('company_id', $companyId)
            ->where('crew_id', $crewId)->pluck('employee_id')->all();
        $relatedCrews = $members ? DB::table('toir_crew_members')
            ->where('company_id', $companyId)->whereIn('employee_id', $members)->pluck('crew_id')->all() : [$crewId];
        $overlap = ToirWorkOrder::where('company_id', $companyId)
            ->where(function ($q) use ($relatedCrews, $members) {
                $q->whereIn('crew_id', $relatedCrews);
                if ($members) {
                    $q->orWhereIn('employee_id', $members);
                }
            })
            ->whereNotNull('planned_start')->whereNotNull('planned_end')
            ->where('planned_start', '<', $finish->format('Y-m-d H:i:s'))
            ->where('planned_end', '>', $begin->format('Y-m-d H:i:s'));
        if ($ignoreOrder) {
            $overlap->where('id', '!=', $ignoreOrder);
        }
        if ($overlap->exists()) {
            throw ValidationException::withMessages([
                'planned_start' => 'У бригады уже есть работа в это время. Выберите свободный интервал.',
            ]);
        }
    }

    public function assertNoEmployeeConflict(int $companyId, int $employeeId, string $start, string $end, ?int $ignoreOrder = null): void
    {
        $begin = Carbon::parse($start);
        $finish = Carbon::parse($end);
        if (! $finish->gt($begin) || ! $begin->isSameDay($finish)) {
            throw ValidationException::withMessages(['planned_end' => 'Работа должна завершаться после начала в тот же день.']);
        }
        $crews = DB::table('toir_crew_members')->where('company_id', $companyId)
            ->where('employee_id', $employeeId)->pluck('crew_id')->all();
        $orders = ToirWorkOrder::where('company_id', $companyId)
            ->where(function ($q) use ($employeeId, $crews) {
                $q->where('employee_id', $employeeId);
                if ($crews) {
                    $q->orWhereIn('crew_id', $crews);
                }
            })
            ->where('planned_start', '<', $finish->toDateTimeString())
            ->where('planned_end', '>', $begin->toDateTimeString());
        if ($ignoreOrder) {
            $orders->where('id', '!=', $ignoreOrder);
        }
        if ($orders->exists()) {
            throw ValidationException::withMessages([
                'planned_start' => 'Сотрудник уже назначен на другую работу в этот интервал.',
            ]);
        }
    }

    public function availableSlot(int $companyId, int $crewId, string $date, int $minutes, array $reservations = []): ?array
    {
        $shifts = ToirCrewShift::where('company_id', $companyId)->where('crew_id', $crewId)
            ->whereDate('work_date', $date)->where('status', 'confirmed')
            ->orderBy('starts_at')->get();
        $members = DB::table('toir_crew_members')->where('company_id', $companyId)
            ->where('crew_id', $crewId)->pluck('employee_id')->all();
        $relatedCrews = $members ? DB::table('toir_crew_members')
            ->where('company_id', $companyId)->whereIn('employee_id', $members)->pluck('crew_id')->all() : [$crewId];
        foreach ($shifts as $shift) {
            $begin = Carbon::parse($date.' '.$shift->starts_at);
            $end = Carbon::parse($date.' '.$shift->ends_at);
            for ($cursor = $begin->copy(); $cursor->copy()->addMinutes($minutes)->lte($end);
                $cursor->addMinutes(15)) {
                $finish = $cursor->copy()->addMinutes($minutes);
                $overlapsReservation = collect($reservations)->contains(function ($r) use ($members, $relatedCrews, $cursor, $finish) {
                    $same = ($r['crew_id'] !== null && in_array($r['crew_id'], $relatedCrews, true))
                        || ($r['employee_id'] !== null && in_array($r['employee_id'], $members, true));

                    return $same && $r['start'] < $finish->toDateTimeString()
                        && $r['end'] > $cursor->toDateTimeString();
                });
                if ($overlapsReservation) {
                    continue;
                }
                $collision = ToirWorkOrder::where('company_id', $companyId)
                    ->where(function ($q) use ($members, $relatedCrews) {
                        $q->whereIn('crew_id', $relatedCrews);
                        if ($members) {
                            $q->orWhereIn('employee_id', $members);
                        }
                    })
                    ->where('planned_start', '<', $finish->format('Y-m-d H:i:s'))
                    ->where('planned_end', '>', $cursor->format('Y-m-d H:i:s'))->exists();
                if (! $collision) {
                    return [$cursor->format('Y-m-d H:i:s'), $finish->format('Y-m-d H:i:s')];
                }
            }
        }

        return null;
    }

    /** @return array{created:int,existing:int,blocked:array} */
    public function generate(Request $request, Company $company, int $days, bool $dryRun): array
    {
        $today = Carbon::today();
        $end = $today->copy()->addDays($days);

        return DB::transaction(function () use ($request, $company, $today, $end, $dryRun) {
            $plans = ToirMaintenancePlan::where('company_id', $company->id)
                ->whereIn('status', ['planned', 'active'])
                ->orderBy('id')->lockForUpdate()->get();
            if ($plans->count() > 300) {
                throw new HttpException(422, 'Слишком много планов: используйте меньшие партии.');
            }
            $result = ['created' => 0, 'existing' => 0, 'blocked' => [], 'preview' => $dryRun, 'created_orders' => []];
            $generated = 0;
            $reservations = [];
            foreach ($plans as $plan) {
                if (! $plan->asset()->whereNotIn('status', ['retired', 'inactive'])->exists()) {
                    continue;
                }
                $cycle = Carbon::parse($plan->next_due_date)->startOfDay();
                $period = max(1, (int) $plan->periodicity_days);
                // An overdue cycle must not silently disappear from the forecast.
                $overdue = $cycle->lt($today);
                // For overdue plans issue only the original missed cycle. Never silently
                // shift its cycle ID or multiply years of missed orders.
                for ($n = 0; $n < ($overdue ? 1 : 60) && $cycle->lte($end); $n++,$cycle->addDays($period)) {
                    if (++$generated > 1000) {
                        throw new HttpException(422, 'Период слишком велик для одной партии.');
                    }
                    $day = $cycle->toDateString();
                    $workDay = $overdue ? $today->toDateString() : $day;
                    $existing = ToirWorkOrder::where('company_id', $company->id)
                        ->where('maintenance_plan_id', $plan->id)
                        ->whereDate('scheduled_for', $day)->exists();
                    if ($existing) {
                        $result['existing']++;

                        continue;
                    }
                    $minutes = (int) $plan->duration_minutes;
                    $starts = $workDay.' 09:00:00';
                    $ends = Carbon::parse($starts)->addMinutes($minutes)->format('Y-m-d H:i:s');
                    if ($plan->crew_id) {
                        $crew = $this->ensureCrew($company->id, (int) $plan->crew_id);
                        if (! $crew->members()->where('toir_employees.status', 'active')->exists()) {
                            $result['blocked'][] = ['plan_id' => $plan->id, 'date' => $day, 'reason' => 'no_members'];

                            continue;
                        }
                        $slot = $this->availableSlot($company->id, $crew->id, $workDay, $minutes, $reservations);
                        if (! $slot) {
                            $result['blocked'][] = ['plan_id' => $plan->id, 'date' => $day, 'reason' => 'no_capacity'];

                            continue;
                        }
                        [$starts,$ends] = $slot;
                    }
                    if ($plan->employee_id) {
                        try {
                            $this->assertNoEmployeeConflict($company->id, (int) $plan->employee_id,
                                $starts, $ends);
                        } catch (ValidationException) {
                            $result['blocked'][] = ['plan_id' => $plan->id, 'date' => $day,
                                'reason' => 'employee_busy'];

                            continue;
                        }
                    }
                    if (collect($reservations)->contains(fn ($slot) => ($slot['crew_id'] && $slot['crew_id'] === $plan->crew_id ||
                        $slot['employee_id'] && $slot['employee_id'] === $plan->employee_id) &&
                        $slot['start'] < $ends && $slot['end'] > $starts)) {
                        $result['blocked'][] = ['plan_id' => $plan->id, 'date' => $day,
                            'reason' => 'resource_busy'];

                        continue;
                    }
                    $reservations[] = ['crew_id' => $plan->crew_id, 'employee_id' => $plan->employee_id,
                        'start' => $starts, 'end' => $ends, 'plan_id' => $plan->id];
                    if ($dryRun) {
                        $result['created']++;

                        continue;
                    }
                    $order = ToirWorkOrder::forceCreate([
                        'company_id' => $company->id, 'maintenance_plan_id' => $plan->id,
                        'scheduled_for' => $day, 'trigger_source' => 'calendar', 'title' => $plan->title,
                        'description' => $plan->work_instructions ?: $plan->description,
                        'type' => 'planned', 'status' => 'waiting',
                        'asset_id' => $plan->asset_id, 'employee_id' => $plan->employee_id,
                        'crew_id' => $plan->crew_id, 'duration_minutes' => $minutes,
                        'initiator_id' => $request->user()->id,
                        'planned_start' => $starts, 'planned_end' => $ends,
                    ]);
                    $order->number = 'MRO-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
                    $order->save();
                    $this->checklist->snapshot($plan, $order);
                    ToirWorkOrderEvent::forceCreate([
                        'company_id' => $company->id, 'work_order_id' => $order->id,
                        'user_id' => $request->user()->id,
                        'action' => 'issued_from_plan', 'message' => 'Автоматически сформирован из графика ТО.',
                        'changes' => ['plan_id' => $plan->id, 'scheduled_for' => $day, 'crew_id' => $plan->crew_id],
                    ]);
                    if ($plan->status === 'planned') {
                        $plan->status = 'active';
                        $plan->save();
                    }
                    $result['created']++;
                    $result['created_orders'][] = $order->id;
                }

                // Meter-triggered service is due NOW rather than merely a forecast.
                // Store the immutable meter threshold to make repeated generation safe.
                $threshold = $plan->next_due_hours;
                $asset = ToirAsset::where('company_id', $company->id)->find($plan->asset_id);
                if ($asset && $threshold !== null && $plan->interval_hours !== null &&
                    (float) $asset->operating_hours >= (float) $threshold) {
                    $known = ToirWorkOrder::where('company_id', $company->id)
                        ->where('maintenance_plan_id', $plan->id)
                        ->where('trigger_hours', $threshold)->exists();
                    if ($known) {
                        $result['existing']++;

                        continue;
                    }
                    $workDay = $today->toDateString();
                    $pendingToday = ToirWorkOrder::where('company_id', $company->id)
                        ->where('maintenance_plan_id', $plan->id)
                        ->whereNotIn('status', ['closed'])
                        ->where('planned_start', '>=', $workDay.' 00:00:00')
                        ->where('planned_start', '<', $today->copy()->addDay()->toDateTimeString())
                        ->first();
                    $plannedToday = collect($reservations)->contains(fn ($slot) => $slot['plan_id'] === $plan->id &&
                        str_starts_with($slot['start'], $workDay));
                    if ($pendingToday || $plannedToday) {
                        if (! $dryRun && $pendingToday) {
                            $pendingToday->trigger_source = 'combined';
                            $pendingToday->trigger_hours = $threshold;
                            $pendingToday->save();
                        }
                        $result['existing']++;

                        continue;
                    }
                    if ($plan->crew_id) {
                        $crew = $this->ensureCrew($company->id, (int) $plan->crew_id);
                        if (! $crew->members()->where('toir_employees.status', 'active')->exists()) {
                            $result['blocked'][] = ['plan_id' => $plan->id, 'date' => $workDay, 'reason' => 'no_members', 'trigger' => 'operating_hours'];

                            continue;
                        }
                    }
                    $minutes = (int) $plan->duration_minutes;
                    $starts = $workDay.' 09:00:00';
                    $ends = Carbon::parse($starts)->addMinutes($minutes)->toDateTimeString();
                    if ($plan->crew_id) {
                        $slot = $this->availableSlot($company->id, (int) $plan->crew_id, $workDay, $minutes, $reservations);
                        if (! $slot) {
                            $result['blocked'][] = ['plan_id' => $plan->id, 'date' => $workDay, 'reason' => 'no_capacity', 'trigger' => 'operating_hours'];

                            continue;
                        }
                        [$starts,$ends] = $slot;
                    }
                    if ($plan->employee_id) {
                        try {
                            $this->assertNoEmployeeConflict(
                                $company->id, (int) $plan->employee_id, $starts, $ends);
                        } catch (ValidationException) {
                            $result['blocked'][] = ['plan_id' => $plan->id, 'date' => $workDay, 'reason' => 'employee_busy', 'trigger' => 'operating_hours'];

                            continue;
                        }
                    }
                    $overlap = collect($reservations)->contains(fn ($slot) => ($slot['crew_id'] && $slot['crew_id'] === $plan->crew_id ||
                         $slot['employee_id'] && $slot['employee_id'] === $plan->employee_id) &&
                        $slot['start'] < $ends && $slot['end'] > $starts);
                    if ($overlap) {
                        $result['blocked'][] = ['plan_id' => $plan->id, 'date' => $workDay, 'reason' => 'resource_busy', 'trigger' => 'operating_hours'];

                        continue;
                    }
                    $reservations[] = ['crew_id' => $plan->crew_id, 'employee_id' => $plan->employee_id,
                        'start' => $starts, 'end' => $ends, 'plan_id' => $plan->id];
                    if ($dryRun) {
                        $result['created']++;

                        continue;
                    }
                    $order = ToirWorkOrder::forceCreate([
                        'company_id' => $company->id, 'maintenance_plan_id' => $plan->id,
                        'scheduled_for' => $workDay, 'trigger_source' => 'operating_hours',
                        'trigger_hours' => $threshold, 'title' => $plan->title,
                        'description' => $plan->work_instructions ?: $plan->description,
                        'type' => 'planned', 'status' => 'waiting', 'asset_id' => $plan->asset_id,
                        'employee_id' => $plan->employee_id, 'crew_id' => $plan->crew_id,
                        'duration_minutes' => $minutes, 'initiator_id' => $request->user()->id,
                        'planned_start' => $starts, 'planned_end' => $ends,
                    ]);
                    $order->number = 'MRO-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
                    $order->save();
                    $this->checklist->snapshot($plan, $order);
                    ToirWorkOrderEvent::forceCreate([
                        'company_id' => $company->id, 'work_order_id' => $order->id,
                        'user_id' => $request->user()->id,
                        'action' => 'issued_from_meter',
                        'message' => 'Наработка достигла порога плана ТО.',
                        'changes' => ['plan_id' => $plan->id, 'trigger_hours' => $threshold],
                    ]);
                    if ($plan->status === 'planned') {
                        $plan->status = 'active';
                        $plan->save();
                    }
                    $result['created']++;
                    $result['created_orders'][] = $order->id;
                }
            }

            return $result;
        }, 3);
    }
}
