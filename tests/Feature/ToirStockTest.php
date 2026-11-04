<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirEmployee;
use App\Models\ToirStockItem;
use App\Models\ToirWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirStockTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $code, string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => $code, 'code' => $code]);
        $company->users()->attach($user->id, ['role' => $role, 'is_active' => true]);
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);

        return [$company, $user];
    }

    private function sku(): array
    {
        return ['sku' => 'BEARING-6205', 'name' => 'Подшипник 6205',
            'unit' => 'шт.', 'unit_cost' => '250.50', 'minimum_quantity' => '2.000'];
    }

    private function order(Company $company, User $user): ToirWorkOrder
    {
        $asset = ToirAsset::forceCreate(['company_id' => $company->id, 'name' => 'Pump',
            'code' => 'EQ-22', 'category' => 'equipment']);
        $employee = ToirEmployee::forceCreate(['company_id' => $company->id, 'name' => 'Technician',
            'status' => 'active', 'user_id' => $user->id]);

        return ToirWorkOrder::forceCreate(['company_id' => $company->id, 'title' => 'Replace bearings',
            'status' => 'waiting', 'asset_id' => $asset->id,
            'employee_id' => $employee->id, 'initiator_id' => $user->id, 'number' => 'НЗ-000122']);
    }

    public function test_receipt_reserve_consumption_release_and_actual_cost_are_audited(): void
    {
        [$company,$user] = $this->member('mining');
        $order = $this->order($company, $user);
        $item = $this->postJson('/api/v1/stock/items', $this->sku())->assertCreated()->json('data');
        $url = '/api/v1/stock/items/'.$item['id'];
        $this->postJson($url.'/receive', ['quantity' => '10.000', 'note' => 'Opening'])->assertOk()
            ->assertJsonPath('data.quantity_on_hand', '10.000');
        $this->patchJson($url, ['quantity_on_hand' => '999'])->assertUnprocessable();

        $material = '/api/v1/work-orders/'.$order->id.'/materials';
        $this->postJson($material, ['action' => 'reserve', 'stock_item_id' => $item['id'], 'quantity' => '6.000'])
            ->assertOk()->assertJsonPath('data.item.quantity_reserved', '6.000');
        $this->postJson($material, ['action' => 'reserve', 'stock_item_id' => $item['id'], 'quantity' => '5.000'])
            ->assertStatus(409);
        $this->postJson($material, ['action' => 'consume', 'stock_item_id' => $item['id'], 'quantity' => '2.000'])
            ->assertStatus(409);
        $this->postJson('/api/v1/work-orders/'.$order->id.'/transition', ['action' => 'start'])->assertOk();
        $this->postJson($material, ['action' => 'consume', 'stock_item_id' => $item['id'], 'quantity' => '2.500'])
            ->assertOk()->assertJsonPath('data.line.actual_cost', '626.25')
            ->assertJsonPath('data.item.quantity_on_hand', '7.500')
            ->assertJsonPath('data.item.quantity_reserved', '3.500');
        $this->postJson($material, ['action' => 'release', 'stock_item_id' => $item['id'], 'quantity' => '3.500'])
            ->assertOk()->assertJsonPath('data.item.quantity_reserved', '0.000');
        $this->getJson($material)->assertOk()
            ->assertJsonPath('actual_material_cost', '626.25')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity_used', '2.500');

        $this->postJson('/api/v1/work-orders/'.$order->id.'/transition', [
            'action' => 'complete', 'comment' => 'Replaced bearings',
        ])->assertOk();
        $this->postJson($material, ['action' => 'reserve', 'stock_item_id' => $item['id'], 'quantity' => '1.000'])
            ->assertStatus(409);
        $this->getJson($url.'/movements')->assertOk()->assertJsonCount(4, 'data');
        $this->assertDatabaseCount('toir_stock_movements', 4);
    }

    public function test_in_progress_order_cannot_bypass_dispatch_permissions_via_generic_edit(): void
    {
        [$company, $user] = $this->member('active-edit-qa');
        $order = $this->order($company, $user);
        $alternate = ToirEmployee::forceCreate([
            'company_id' => $company->id,
            'name' => 'Alternate technician',
            'status' => 'active',
        ]);

        $url = '/api/v1/resources/work-orders/'.$order->id;
        $this->postJson('/api/v1/work-orders/'.$order->id.'/transition', [
            'action' => 'start',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->patchJson($url, ['employee_id' => $alternate->id])->assertStatus(409);
        $this->patchJson($url, ['asset_id' => null])->assertStatus(409);
        $this->patchJson($url, ['type' => 'emergency'])->assertStatus(409);
        $this->patchJson($url, [
            'description' => 'Progress notes may still be updated',
        ])->assertOk();

        $this->assertDatabaseHas('toir_work_orders', [
            'id' => $order->id,
            'employee_id' => $order->employee_id,
            'asset_id' => $order->asset_id,
            'description' => 'Progress notes may still be updated',
        ]);
    }

    public function test_archive_requires_releasing_stock_and_preserves_consumption_history(): void
    {
        [$company, $user] = $this->member('stock-archive-qa');
        $order = $this->order($company, $user);
        $item = $this->postJson('/api/v1/stock/items', $this->sku())
            ->assertCreated()->json('data');

        $this->postJson('/api/v1/stock/items/'.$item['id'].'/receive', [
            'quantity' => '5.000',
        ])->assertOk();

        $path = '/api/v1/work-orders/'.$order->id.'/materials';
        $archive = '/api/v1/resources/work-orders/'.$order->id;
        $this->postJson($path, [
            'stock_item_id' => $item['id'], 'action' => 'reserve', 'quantity' => '2.000',
        ])->assertOk();
        $this->deleteJson($archive)->assertStatus(409);
        $this->assertDatabaseHas('toir_stock_items', [
            'id' => $item['id'], 'quantity_reserved' => '2.000',
        ]);

        $this->postJson($path, [
            'stock_item_id' => $item['id'], 'action' => 'release', 'quantity' => '2.000',
        ])->assertOk();
        $this->deleteJson($archive)->assertOk();
        $this->assertDatabaseHas('toir_stock_items', [
            'id' => $item['id'], 'quantity_reserved' => '0.000',
        ]);

        // The material ledger must remain discoverable after a completed repair.
        $finished = ToirWorkOrder::forceCreate([
            'company_id' => $company->id,
            'asset_id' => $order->asset_id,
            'employee_id' => $order->employee_id,
            'initiator_id' => $user->id,
            'title' => 'Completed repair with parts',
            'number' => 'QA-CONSUMED-001',
            'type' => 'planned',
            'status' => 'waiting',
        ]);
        $finishedUrl = '/api/v1/work-orders/'.$finished->id;
        $this->postJson($finishedUrl.'/transition', ['action' => 'start'])->assertOk();
        $this->postJson($finishedUrl.'/materials', [
            'stock_item_id' => $item['id'], 'action' => 'consume', 'quantity' => '1.000',
        ])->assertOk();
        $this->postJson($finishedUrl.'/transition', [
            'action' => 'complete', 'comment' => 'Maintenance finished',
        ])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->deleteJson('/api/v1/resources/work-orders/'.$finished->id)->assertStatus(409);
        $this->assertDatabaseHas('toir_stock_items', [
            'id' => $item['id'], 'quantity_on_hand' => '4.000',
            'quantity_reserved' => '0.000',
        ]);
        $this->assertDatabaseHas('toir_work_order_materials', [
            'work_order_id' => $finished->id, 'quantity_used' => '1.000',
        ]);
    }

    public function test_reserved_stock_blocks_close_until_consumed_or_released(): void
    {
        [$company,$user] = $this->member('mining');
        $order = $this->order($company, $user);
        $item = $this->postJson('/api/v1/stock/items', $this->sku())->assertCreated()->json('data');
        $this->postJson('/api/v1/stock/items/'.$item['id'].'/receive', ['quantity' => '3'])->assertOk();
        $material = '/api/v1/work-orders/'.$order->id.'/materials';
        $this->postJson($material, ['action' => 'reserve', 'stock_item_id' => $item['id'], 'quantity' => '2'])->assertOk();
        $transition = '/api/v1/work-orders/'.$order->id.'/transition';
        $this->postJson($transition, ['action' => 'start'])->assertOk();
        $this->postJson($transition, ['action' => 'complete', 'comment' => 'Done'])->assertStatus(409);
        $this->postJson($material, ['action' => 'consume', 'stock_item_id' => $item['id'], 'quantity' => '2'])->assertOk();
        $this->postJson($transition, ['action' => 'complete', 'comment' => 'Done'])->assertOk();
    }

    public function test_no_negative_stock_cross_tenant_or_non_admin_receipts(): void
    {
        [$company,$owner] = $this->member('mining');
        $item = $this->postJson('/api/v1/stock/items', $this->sku())->assertCreated()->json('data');
        $order = $this->order($company, $owner);
        $material = '/api/v1/work-orders/'.$order->id.'/materials';
        $this->postJson($material, ['action' => 'reserve', 'stock_item_id' => $item['id'], 'quantity' => '1'])->assertStatus(409);

        [$other] = $this->member('solutions');
        $this->getJson('/api/v1/stock/items')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stock/items/'.$item['id'].'/movements')->assertNotFound();
        $this->postJson('/api/v1/stock/items/'.$item['id'].'/receive', ['quantity' => '1'])->assertNotFound();

        $technician = User::factory()->create();
        $company->users()->attach($technician->id, ['role' => 'technician', 'is_active' => true]);
        $this->actingAs($technician)->withSession(['toir_company_id' => $company->id]);
        $this->postJson('/api/v1/stock/items', $this->sku())->assertForbidden();
        $this->postJson('/api/v1/stock/items/'.$item['id'].'/receive', ['quantity' => '1'])->assertForbidden();
        $this->postJson($material, ['action' => 'reserve', 'stock_item_id' => $item['id'], 'quantity' => '1'])->assertForbidden();
    }

    public function test_supply_request_can_be_received_once_into_stock_with_weighted_cost(): void
    {
        [$company] = $this->member('mining');
        $item = $this->postJson('/api/v1/stock/items', $this->sku())->assertCreated()->json('data');
        $this->postJson('/api/v1/stock/items/'.$item['id'].'/receive', [
            'quantity' => '10.000',
        ])->assertOk();

        $supply = $this->postJson('/api/v1/resources/supplies', [
            'name' => 'Подшипник 6205', 'category' => 'spares', 'quantity' => '10.000',
            'unit' => 'шт.', 'unit_price' => '200.00', 'status' => 'ordered',
        ])->assertCreated()->json('data');
        $url = '/api/v1/supplies/'.$supply['id'].'/receive';
        $this->postJson($url, ['stock_item_id' => $item['id']])
            ->assertCreated()->assertJsonPath('created', true)
            ->assertJsonPath('data.quantity_on_hand', '20.000')
            ->assertJsonPath('data.unit_cost', '225.25');
        $this->postJson($url, ['stock_item_id' => $item['id']])
            ->assertOk()->assertJsonPath('created', false);
        $this->getJson('/api/v1/resources/supplies/'.$supply['id'])
            ->assertJsonPath('data.status', 'delivered');
        $this->assertDatabaseCount('toir_stock_movements', 2);
        $this->patchJson('/api/v1/resources/supplies/'.$supply['id'], [
            'status' => 'delivered',
        ])->assertUnprocessable();

        [$other] = $this->member('solutions');
        $this->postJson($url, ['stock_item_id' => $item['id']])->assertUnprocessable();
        $this->assertEquals(20, (float) ToirStockItem::where('company_id', $company->id)->first()->quantity_on_hand);
    }

    public function test_item_price_change_affects_only_future_consumption_not_past_cost(): void
    {
        [$company,$user] = $this->member('mining');
        $item = $this->postJson('/api/v1/stock/items', $this->sku())->assertCreated()->json('data');
        $order = $this->order($company, $user);
        $this->postJson('/api/v1/stock/items/'.$item['id'].'/receive', ['quantity' => '10'])->assertOk();
        $this->postJson('/api/v1/work-orders/'.$order->id.'/transition', ['action' => 'start'])->assertOk();
        $material = '/api/v1/work-orders/'.$order->id.'/materials';
        $this->postJson($material, ['action' => 'consume', 'stock_item_id' => $item['id'], 'quantity' => '1'])->assertOk()
            ->assertJsonPath('data.line.actual_cost', '250.50');
        $this->patchJson('/api/v1/stock/items/'.$item['id'], ['unit_cost' => '300.00'])->assertOk();
        $this->postJson($material, ['action' => 'consume', 'stock_item_id' => $item['id'], 'quantity' => '1'])->assertOk()
            ->assertJsonPath('data.line.actual_cost', '550.50');
    }
}
