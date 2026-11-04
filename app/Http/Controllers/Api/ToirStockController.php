<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ToirStockItem;
use App\Models\ToirStockMovement;
use App\Models\ToirSupply;
use App\Models\ToirWorkOrder;
use App\Models\ToirWorkOrderEvent;
use App\Models\ToirWorkOrderMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ToirStockController extends Controller
{
    private const QTY = ['required', 'regex:/^\d{1,11}(\.\d{1,3})?$/', 'gt:0'];

    private const MONEY = ['required', 'regex:/^\d{1,11}(\.\d{1,2})?$/'];

    private function company(Request $request): Company
    {
        $company = $request->user()->activeCompanies()
            ->whereKey($request->session()->get('toir_company_id'))->first();
        abort_unless($company, 403, 'Нет доступа к компании.');

        return $company;
    }

    private function administrator(Company $company): void
    {
        abort_unless(in_array($company->pivot->role, ['owner', 'admin', 'manager'], true),
            403, 'Управление складом доступно руководителю или администратору.');
    }

    private function operator(Company $company, Request $request, ToirWorkOrder $order): void
    {
        if (in_array($company->pivot->role, ['owner', 'admin', 'manager', 'engineer'], true)) {
            return;
        }
        abort_unless($company->pivot->role === 'technician'
            && $order->employee_id
            && $order->employee()->where('user_id', $request->user()->id)
                ->where('company_id', $company->id)->where('status', 'active')->exists(),
            403, 'Операция разрешена только назначенному исполнителю.');
    }

    private static function milli(string|int|float $value): int
    {
        $s = (string) $value;
        $neg = str_starts_with($s, '-');
        $a = explode('.', ltrim($s, '-'), 2);
        $n = ((int) $a[0]) * 1000 + (int) str_pad(substr($a[1] ?? '', 0, 3), 3, '0');

        return $neg ? -$n : $n;
    }

    private static function formatted(int $milli): string
    {
        return ($milli < 0 ? '-' : '').intdiv(abs($milli), 1000).'.'.str_pad((string) (abs($milli) % 1000), 3, '0', STR_PAD_LEFT);
    }

    private static function cents(string $value): int
    {
        $a = explode('.', $value, 2);

        return (int) $a[0] * 100 + (int) str_pad(substr($a[1] ?? '', 0, 2), 2, '0');
    }

    private static function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function item(Company $company, int $id): ToirStockItem
    {
        return ToirStockItem::where('company_id', $company->id)->findOrFail($id);
    }

    private function order(Company $company, int $id): ToirWorkOrder
    {
        return ToirWorkOrder::where('company_id', $company->id)->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $q = ToirStockItem::where('company_id', $company->id);
        if ($term = trim((string) $request->query('search', ''))) {
            $term = mb_substr($term, 0, 100);
            $q->where(fn ($builder) => $builder->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('sku', 'like', '%'.addcslashes($term, '%_\\').'%'));
        }
        if ($request->boolean('low_stock')) {
            $q->whereRaw('quantity_on_hand - quantity_reserved <= minimum_quantity');
        }
        $page = $q->orderBy('name')->paginate(min(100, max(1, (int) $request->query('per_page', 20))));
        $rows = collect($page->items())->map(fn ($row) => array_merge($row->toArray(), [
            'available_quantity' => self::formatted(self::milli($row->quantity_on_hand) - self::milli($row->quantity_reserved)),
        ]));

        return response()->json(['data' => $rows, 'meta' => [
            'total' => $page->total(), 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $this->administrator($company);
        $v = $request->validate([
            'sku' => ['required', 'string', 'max:80', Rule::unique('toir_stock_items', 'sku')->where('company_id', $company->id)],
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:24'],
            'unit_cost' => self::MONEY,
            'minimum_quantity' => ['nullable', 'regex:/^\d{1,11}(\.\d{1,3})?$/'],
        ]);
        $item = new ToirStockItem;
        $item->fill($v);
        $item->company_id = $company->id;
        $item->save();

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $this->administrator($company);
        $item = $this->item($company, $id);
        $v = $request->validate([
            'sku' => ['sometimes', 'required', 'string', 'max:80',
                Rule::unique('toir_stock_items', 'sku')->where('company_id', $company->id)->ignore($id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit' => ['sometimes', 'required', 'string', 'max:24'],
            'unit_cost' => ['sometimes', ...self::MONEY],
            'minimum_quantity' => ['sometimes', 'regex:/^\d{1,11}(\.\d{1,3})?$/'],
            'quantity_on_hand' => ['prohibited'],
            'quantity_reserved' => ['prohibited'],
        ]);
        $item->fill($v)->save();

        return response()->json(['data' => $item->refresh()]);
    }

    public function movements(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $this->item($company, $id);
        $page = ToirStockMovement::where('company_id', $company->id)
            ->where('stock_item_id', $id)->orderByDesc('id')->paginate(40);

        return response()->json(['data' => $page->items(), 'meta' => [
            'total' => $page->total(), 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
        ]]);
    }

    private function movement(Company $company, Request $request, ToirStockItem $item,
        ?ToirWorkOrder $order, string $action, int $quantity, int $stockDelta, int $reservedDelta,
        string $note = '', ?int $supplyId = null): void
    {
        $m = new ToirStockMovement;
        $m->company_id = $company->id;
        $m->stock_item_id = $item->id;
        $m->work_order_id = $order?->id;
        $m->supply_id = $supplyId;
        $m->user_id = $request->user()->id;
        $m->action = $action;
        $m->quantity = self::formatted($quantity);
        $m->delta_on_hand = self::formatted($stockDelta);
        $m->delta_reserved = self::formatted($reservedDelta);
        $m->unit_cost = $item->unit_cost;
        $m->note = $note ?: null;
        $m->save();
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $this->administrator($company);
        $v = $request->validate(['quantity' => self::QTY, 'note' => ['nullable', 'string', 'max:500']]);
        $qty = self::milli((string) $v['quantity']);
        $item = DB::transaction(function () use ($company, $id, $qty, $v, $request) {
            $item = ToirStockItem::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            $next = self::milli($item->quantity_on_hand) + $qty;
            if ($next > 99999999999999) {
                throw ValidationException::withMessages(['quantity' => 'Превышен допустимый остаток.']);
            }
            $item->quantity_on_hand = self::formatted($next);
            $item->save();
            $this->movement($company, $request, $item, null, 'receipt', $qty, $qty, 0, $v['note'] ?? '');

            return $item->refresh();
        }, 3);

        return response()->json(['data' => $item]);
    }

    /**
     * Receive a company supply request into company stock once.
     */
    public function receiveSupply(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $this->administrator($company);
        $v = $request->validate([
            'stock_item_id' => ['required', 'integer',
                Rule::exists('toir_stock_items', 'id')->where('company_id', $company->id)],
        ]);
        $result = DB::transaction(function () use ($request, $company, $id, $v) {
            $supply = ToirSupply::where('company_id', $company->id)
                ->lockForUpdate()->findOrFail($id);
            if ($supply->status === 'delivered') {
                $movement = ToirStockMovement::where('company_id', $company->id)
                    ->where('supply_id', $supply->id)->first();
                if ($movement) {
                    return ['item' => $this->item($company, $movement->stock_item_id), 'created' => false];
                }
                abort(409, 'Заявка ранее отмечена поставленной без складского прихода. Проверьте историю.');
            }
            abort_unless(in_array($supply->status, ['requested', 'ordered'], true),
                409, 'Отменённую заявку нельзя принять на склад.');

            $item = ToirStockItem::where('company_id', $company->id)
                ->lockForUpdate()->findOrFail((int) $v['stock_item_id']);
            abort_unless($item->unit === $supply->unit, 422, 'Единицы измерения заявки и склада отличаются.');
            $qty = self::milli((string) $supply->quantity);
            abort_if($qty <= 0, 422, 'Укажите положительное количество в заявке.');
            $oldQty = self::milli($item->quantity_on_hand);
            $newQty = $oldQty + $qty;
            $oldCost = self::cents((string) $item->unit_cost);
            $newCost = $supply->unit_price !== null
                ? self::cents((string) $supply->unit_price) : $oldCost;
            $weighted = (int) round(($oldQty * $oldCost + $qty * $newCost) / $newQty, 0, PHP_ROUND_HALF_UP);
            $item->unit_cost = self::money($weighted);
            $item->quantity_on_hand = self::formatted($newQty);
            $item->save();
            $this->movement($company, $request, $item, null, 'supply_receipt', $qty, $qty, 0,
                'Приход по заявке #'.$supply->id, $supply->id);
            $supply->status = 'delivered';
            $supply->save();

            return ['item' => $item->refresh(), 'created' => true];
        }, 3);

        return response()->json(['data' => $result['item'], 'created' => $result['created']],
            $result['created'] ? 201 : 200);
    }

    public function orderMaterials(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $this->order($company, $id);
        $lines = ToirWorkOrderMaterial::where('company_id', $company->id)
            ->where('work_order_id', $id)->with('item')->orderBy('id')->get();
        $totalCents = 0;
        foreach ($lines as $line) {
            $totalCents += self::cents((string) $line->actual_cost);
        }

        return response()->json(['data' => $lines, 'actual_material_cost' => self::money($totalCents)]);
    }

    public function operate(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $v = $request->validate([
            'stock_item_id' => ['required', 'integer',
                Rule::exists('toir_stock_items', 'id')->where('company_id', $company->id)],
            'action' => ['required', Rule::in(['reserve', 'consume', 'release'])],
            'quantity' => self::QTY,
        ]);
        $qty = self::milli((string) $v['quantity']);
        $result = DB::transaction(function () use ($company, $request, $id, $v, $qty) {
            $order = ToirWorkOrder::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            $this->operator($company, $request, $order);
            abort_if(in_array($order->status, ['closed', 'awaiting_acceptance'], true), 409, 'Закрытый наряд нельзя изменять.');
            if ($v['action'] === 'consume' && $order->status !== 'in_progress') {
                abort(409, 'Списание допускается только для наряда в работе.');
            }
            if (! in_array($order->status, ['waiting', 'overdue', 'in_progress'], true)) {
                abort(409, 'Наряд недоступен для складских операций.');
            }
            $item = ToirStockItem::where('company_id', $company->id)
                ->lockForUpdate()->findOrFail((int) $v['stock_item_id']);
            $line = ToirWorkOrderMaterial::where('company_id', $company->id)
                ->where('work_order_id', $id)->where('stock_item_id', $item->id)
                ->lockForUpdate()->first();
            if (! $line) {
                $line = new ToirWorkOrderMaterial;
                $line->company_id = $company->id;
                $line->work_order_id = $id;
                $line->stock_item_id = $item->id;
                $line->quantity_reserved = '0.000';
                $line->quantity_used = '0.000';
                $line->actual_cost = '0.00';
            }
            $onHand = self::milli($item->quantity_on_hand);
            $reserved = self::milli($item->quantity_reserved);
            $held = self::milli($line->quantity_reserved);
            $used = self::milli($line->quantity_used);
            $cost = self::cents((string) $line->actual_cost);
            $stockDelta = 0;
            $reserveDelta = 0;
            if ($v['action'] === 'reserve') {
                if ($onHand - $reserved < $qty) {
                    abort(409, 'Недостаточно свободного остатка на складе.');
                }
                $held += $qty;
                $reserveDelta = $qty;
            } elseif ($v['action'] === 'release') {
                if ($held < $qty) {
                    abort(409, 'Нельзя снять резерв больше зарезервированного.');
                }
                $held -= $qty;
                $reserveDelta = -$qty;
            } else {
                // Consumption may use unreserved stock only if nobody else holds it.
                $useHeld = min($held, $qty);
                $unreserved = $qty - $useHeld;
                if ($onHand - $reserved < $unreserved || $onHand < $qty) {
                    abort(409, 'Недостаточно доступного остатка для списания.');
                }
                $held -= $useHeld;
                $reserved -= $useHeld;
                $used += $qty;
                $stockDelta = -$qty;
                $reserveDelta = -$useHeld;
                $unitCents = self::cents((string) $item->unit_cost);
                $cost += (int) round($qty * $unitCents / 1000, 0, PHP_ROUND_HALF_UP);
            }
            $nextReserved = $reserved + ($v['action'] === 'reserve' ? $qty : ($v['action'] === 'release' ? -$qty : 0));
            $nextStock = $onHand + $stockDelta;
            if ($nextReserved < 0 || $nextStock < $nextReserved || $nextStock < 0) {
                abort(409, 'Недостаточно остатка для операции.');
            }
            $item->quantity_on_hand = self::formatted($nextStock);
            $item->quantity_reserved = self::formatted($nextReserved);
            $item->save();
            $line->quantity_reserved = self::formatted($held);
            $line->quantity_used = self::formatted($used);
            $line->actual_cost = self::money($cost);
            $line->save();
            $this->movement($company, $request, $item, $order, $v['action'], $qty, $stockDelta, $reserveDelta);
            $event = new ToirWorkOrderEvent;
            $event->company_id = $company->id;
            $event->work_order_id = $id;
            $event->user_id = $request->user()->id;
            $event->action = 'material_'.$v['action'];
            $event->message = $item->name;
            $event->changes = ['sku' => $item->sku, 'quantity' => self::formatted($qty), 'unit' => $item->unit];
            $event->save();

            return ['line' => $line->load('item'), 'item' => $item->refresh()];
        }, 3);

        return response()->json(['data' => $result]);
    }
}
