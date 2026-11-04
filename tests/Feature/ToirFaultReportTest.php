<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ToirAsset;
use App\Models\ToirWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ToirFaultReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_member_can_report_fault_and_create_audited_emergency_order(): void
    {
        $company = Company::create(['name' => 'Mine A', 'code' => 'mine-a']);
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => 'viewer', 'is_active' => true]);
        $asset = ToirAsset::forceCreate(['company_id' => $company->id, 'name' => 'Pump', 'code' => 'P1', 'category' => 'equipment']);
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);
        $response = $this->postJson('/api/v1/assets/'.$asset->id.'/faults', [
            'title' => 'Pressure loss', 'description' => 'Pressure keeps falling during operation', 'priority' => 'critical',
        ])->assertCreated()->assertJsonPath('data.type', 'emergency')
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.asset_id', $asset->id);
        $order = ToirWorkOrder::findOrFail($response->json('data.id'));
        $this->assertSame('НЗ-АВ-'.$order->id, $order->number);
        $this->assertSame($user->id, $order->initiator_id);
        $event = $order->events()->firstOrFail();
        $this->assertSame('fault_reported', $event->action);
        $this->assertSame('critical', $event->changes['priority']);
    }

    public function test_cross_company_asset_and_retired_asset_are_rejected(): void
    {
        $first = Company::create(['name' => 'A', 'code' => 'a']);
        $second = Company::create(['name' => 'B', 'code' => 'b']);
        $user = User::factory()->create();
        $first->users()->attach($user->id, ['role' => 'viewer', 'is_active' => true]);
        $asset = ToirAsset::forceCreate(['company_id' => $second->id, 'name' => 'Other', 'code' => 'X', 'category' => 'equipment']);
        $retired = ToirAsset::forceCreate(['company_id' => $first->id, 'name' => 'Old', 'code' => 'Y', 'category' => 'equipment', 'status' => 'retired']);
        $this->actingAs($user)->withSession(['toir_company_id' => $first->id]);
        $payload = ['title' => 'Fault description', 'description' => 'Something definitely failed here', 'priority' => 'high'];
        $this->postJson('/api/v1/assets/'.$asset->id.'/faults', $payload)->assertNotFound();
        $this->postJson('/api/v1/assets/'.$retired->id.'/faults', $payload)->assertUnprocessable();
        $this->assertSame(0, ToirWorkOrder::count());
    }

    public function test_fault_requires_real_description_and_login(): void
    {
        $company = Company::create(['name' => 'A', 'code' => 'a']);
        $asset = ToirAsset::forceCreate(['company_id' => $company->id, 'name' => 'Pump', 'code' => 'P', 'category' => 'equipment']);
        $url = '/api/v1/assets/'.$asset->id.'/faults';
        $this->postJson($url, ['title' => 'Broken'])->assertUnauthorized();
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);
        $this->postJson($url, ['title' => 'No', 'description' => 'tiny', 'priority' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'priority']);
    }
}
