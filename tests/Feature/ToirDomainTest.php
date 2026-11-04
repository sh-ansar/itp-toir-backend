<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToirDomainTest extends TestCase
{
    use RefreshDatabase;

    private function asMember(string $code, string $role = 'owner'): Company
    {
        $user = User::factory()->create();
        $company = Company::create(['name' => $code, 'code' => $code]);
        $company->users()->attach($user->id, ['role' => $role, 'is_active' => true]);
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);

        return $company;
    }

    public function test_unauthenticated_requests_cannot_view_domain(): void
    {
        $this->getJson('/api/v1/resources/assets')->assertUnauthorized();
        $this->postJson('/api/v1/resources/assets', ['name' => 'Test'])->assertUnauthorized();
        $this->getJson('/api/v1/overview')->assertUnauthorized();
    }

    public function test_asset_and_work_order_roundtrip_with_company_scope(): void
    {
        $company = $this->asMember('mining');
        $asset = $this->postJson('/api/v1/resources/assets', [
            'name' => 'Compressor 42',
            'code' => 'EQ-42',
            'category' => 'equipment',
            'status' => 'active',
        ])->assertCreated()->json('data');
        $this->assertSame($company->id, $asset['company_id']);

        $employee = ToirEmployee::forceCreate([
            'company_id' => $company->id, 'name' => 'Maintenance technician',
            'status' => 'active',
        ]);
        $order = $this->postJson('/api/v1/resources/work-orders', [
            'title' => 'Replace compressor seal',
            'asset_id' => $asset['id'],
            'employee_id' => $employee->id,
            'type' => 'planned',
            'status' => 'waiting',
        ])->assertCreated()->json('data');

        $this->assertStringStartsWith('НЗ-', $order['number']);
        $this->getJson('/api/v1/resources/work-orders/'.$order['id'])
            ->assertOk()
            ->assertJsonPath('data.asset.code', 'EQ-42')
            ->assertJsonCount(1, 'data.events');

        $this->postJson('/api/v1/work-orders/'.$order['id'].'/transition', [
            'action' => 'start',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->getJson('/api/v1/resources/work-orders/'.$order['id'])
            ->assertJsonCount(2, 'data.events');

        $this->getJson('/api/v1/overview')->assertOk()
            ->assertJsonPath('data.assets', 1)
            ->assertJsonPath('data.in_progress', 1)
            ->assertJsonPath('data.work_orders', 1);

        $this->deleteJson('/api/v1/resources/assets/'.$asset['id'])->assertStatus(409);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->asMember('mining');
        $this->postJson('/api/v1/resources/assets', [])->assertUnprocessable();
        $this->postJson('/api/v1/resources/work-orders', [])->assertUnprocessable();
        $this->postJson('/api/v1/resources/plans', [])->assertUnprocessable();
        $this->postJson('/api/v1/resources/tenders', [])->assertUnprocessable();
    }

    public function test_duplicate_asset_code_is_rejected_in_company(): void
    {
        $this->asMember('mining');
        $request = ['name' => 'Compressor', 'code' => 'EQ1', 'category' => 'equipment'];
        $this->postJson('/api/v1/resources/assets', $request)->assertCreated();
        $this->postJson('/api/v1/resources/assets', $request)->assertUnprocessable();
    }

    public function test_cross_company_asset_reference_is_rejected(): void
    {
        $first = $this->asMember('mining');
        $asset = ToirAsset::forceCreate([
            'company_id' => $first->id,
            'name' => 'Mining asset',
            'code' => 'MIN-01',
            'category' => 'equipment',
        ]);
        $other = $this->asMember('solutions');

        $this->getJson('/api/v1/resources/assets/'.$asset->id)->assertNotFound();
        $this->patchJson('/api/v1/resources/assets/'.$asset->id, ['name' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/v1/resources/assets/'.$asset->id)->assertNotFound();

        $this->postJson('/api/v1/resources/work-orders', [
            'title' => 'Sneaky order',
            'asset_id' => $asset->id,
        ])->assertUnprocessable();

        $this->getJson('/api/v1/resources/assets')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_viewer_is_read_only(): void
    {
        $this->asMember('viewer-company', 'viewer');
        $this->getJson('/api/v1/resources/assets')->assertOk();
        $this->postJson('/api/v1/resources/assets', [
            'name' => 'Not allowed', 'code' => 'X', 'category' => 'tools',
        ])->assertForbidden();
        $this->postJson('/api/v1/resources/work-orders', ['title' => 'Nope'])->assertForbidden();
    }

    public function test_engineer_can_create_orders_but_not_delete_assets(): void
    {
        $company = $this->asMember('mining', 'engineer');
        $this->postJson('/api/v1/resources/work-orders', [
            'title' => 'Check motors',
        ])->assertCreated();

        $asset = ToirAsset::forceCreate([
            'company_id' => $company->id, 'name' => 'Motor', 'code' => 'M1', 'category' => 'equipment',
        ]);
        $this->deleteJson('/api/v1/resources/assets/'.$asset->id)->assertForbidden();
    }

    public function test_search_filters_and_pagination_are_scoped(): void
    {
        $company = $this->asMember('mining');
        foreach (['Pump', 'Generator', 'Pump motor'] as $i => $name) {
            ToirAsset::forceCreate([
                'company_id' => $company->id, 'name' => $name, 'code' => 'EQ'.($i + 1), 'category' => 'equipment',
            ]);
        }
        $this->getJson('/api/v1/resources/assets?search=Pump&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_maintenance_plan_and_schedule_are_tenant_scoped(): void
    {
        $company = $this->asMember('mining');
        $asset = ToirAsset::forceCreate([
            'company_id' => $company->id, 'name' => 'Crane', 'code' => 'EQ-001', 'category' => 'equipment',
        ]);
        $this->postJson('/api/v1/resources/plans', [
            'title' => 'Quarterly inspection', 'asset_id' => $asset->id,
            'periodicity_days' => 90, 'next_due_date' => '2026-12-15',
        ])->assertCreated();

        $this->getJson('/api/v1/schedule')
            ->assertOk()->assertJsonCount(1, 'data.plans')->assertJsonCount(0, 'data.orders');
    }
}
