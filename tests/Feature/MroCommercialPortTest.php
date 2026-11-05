<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\MroCommercial\{InvoiceService,LegalDocumentService,PlanCatalogService,PlatformAdminAccess};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MroCommercialPortTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): array
    {
        $user = User::factory()->create(['email' => 'owner@mro.test', 'is_active' => true, 'email_verified_at' => now()]);
        $company = Company::create(['name' => 'QA Mining', 'code' => 'qa-mining', 'is_active' => true]);
        $company->users()->attach($user->id, ['role' => 'owner', 'is_active' => true]);

        return [$user, $company];
    }

    private function manager(User $user): void
    {
        DB::table('mro_admin_grants')->insert([
            'user_id' => $user->id, 'permission' => 'platform.manage',
            'granted_by' => null, 'created_at' => now(),
        ]);
    }

    private function plan(): array
    {
        return [
            'code' => 'mro-base',
            'names' => ['ru' => 'Старт ТОиР', 'en' => 'MRO Starter', 'ge' => 'საწყისი ტარიფი'],
            'description' => 'Equipment, work orders and maintenance planning',
            'price_minor' => 125000, 'currency' => 'KZT', 'period_months' => 1,
            'is_public' => true, 'display_order' => 1,
            'limits' => ['users' => 20, 'companies' => 1, 'storage_bytes' => 1073741824, 'max_file_bytes' => 36700160],
            'modules' => array_fill_keys(app(PlanCatalogService::class)->modules(), true),
        ];
    }

    public function test_public_catalog_and_legal_are_accessible_anonymously(): void
    {
        $this->getJson('/api/v1/public/plans')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/public/legal?locale=ru&jurisdiction=KZ')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/platform-admin/overview')->assertUnauthorized();
    }

    public function test_admin_is_denied_by_default_and_plans_publish_by_grant(): void
    {
        [$user, $company] = $this->owner();
        $this->actingAs($user)->withSession(['toir_company_id' => $company->id]);
        $this->getJson('/api/v1/platform-admin/overview')->assertForbidden();
        $this->postJson('/api/v1/platform-admin/plans', $this->plan())->assertForbidden();

        $this->manager($user);
        $this->assertTrue(PlatformAdminAccess::allowed($user, 'plans.write'));
        $this->getJson('/api/v1/platform-admin/overview')->assertOk();
        $this->getJson('/api/v1/platform-admin/organizations')->assertOk()->assertJsonPath('total', 1);
        $draft = $this->postJson('/api/v1/platform-admin/plans', $this->plan())
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->getJson('/api/v1/public/plans')->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/platform-admin/plans/'.$draft.'/status', ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson('/api/v1/public/plans')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.terms.names.ru', 'Старт ТОиР');
    }

    public function test_invoice_preview_uses_mro_tables_and_issuance_is_guarded(): void
    {
        [$user, $company] = $this->owner();
        $this->manager($user);
        $service = app(InvoiceService::class);
        $service->saveProfile([
            'legal_name' => 'ITP Mining', 'registration_number' => '010101010101',
            'address' => 'Astana, Kazakhstan', 'bank' => 'QA Bank', 'iban' => 'KZ000000000000000000',
            'bic' => 'QATESTXX', 'signatory' => 'QA Manager', 'currency' => 'KZT',
            'tax_basis_points' => 0, 'tax_label' => 'No VAT', 'valid_days' => 15,
            'prefix' => 'MRO', 'service_name' => 'Maintenance platform',
        ], null, $user->id);
        $draft = $service->create([
            'company_id' => $company->id,
            'items' => [['description' => 'MRO subscription test', 'quantity' => 1, 'unit_minor' => 123450]],
            'period_from' => today()->toDateString(), 'period_to' => today()->toDateString(),
            'due_date' => today()->addDays(15)->toDateString(), 'basis' => 'Internal QA',
        ], $user->id);
        $this->assertSame('draft', $draft->status);
        $this->assertStringStartsWith('%PDF', $service->preview($draft->id));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->issue($draft->id, $user->id);
    }

    public function test_start_registration_does_not_create_an_account(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/public/register/start', [
            'name' => 'New Owner', 'email' => 'fresh@mro.test', 'company_name' => 'Fresh Co', 'consent' => true,
        ])->assertStatus(202);
        $this->assertDatabaseMissing('users', ['email' => 'fresh@mro.test']);
        $this->assertDatabaseHas('mro_signup_requests', ['email' => 'fresh@mro.test']);
    }

    public function test_verify_registration_creates_just_one_company_and_owner(): void
    {
        $token = str_repeat('a', 80);
        DB::table('mro_signup_requests')->insert([
            'email' => 'new@mro.test', 'name' => 'New Owner', 'company_name' => 'New QA Company',
            'plan_code' => 'mro-base', 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(20), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/public/register/verify', [
            'token' => $token, 'password' => 'Strong!123456789', 'password_confirmation' => 'Strong!123456789',
        ])->assertCreated()->assertJsonPath('redirect', '/dashboard');
        $owner = User::where('email', 'new@mro.test')->firstOrFail();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertSame('owner', $owner->companies()->firstOrFail()->pivot->role);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $owner->id);
        $this->assertDatabaseCount('mro_signup_requests', 0);
    }

    public function test_invalid_token_never_creates_tenant(): void
    {
        $this->postJson('/api/v1/public/register/verify', [
            'token' => str_repeat('z', 80), 'password' => 'Strong!123456789',
            'password_confirmation' => 'Strong!123456789',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('companies', 0);
    }
}
