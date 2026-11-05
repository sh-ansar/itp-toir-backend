<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\MroCommercial\InvoiceService;
use App\Services\MroCommercial\PlanCatalogService;
use App\Services\MroCommercial\PlatformAdminAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    private function legalSignupVersions(): array
    {
        $items = [];
        foreach (['offer', 'privacy'] as $type) {
            $hash = hash('sha256', 'QA-mro-'.$type);
            $id = DB::table('mro_legal_versions')->insertGetId([
                'type' => $type, 'locale' => 'ru', 'jurisdiction' => 'KZ',
                'version' => 1, 'status' => 'published',
                'title' => 'QA '.ucfirst($type),
                'body' => str_repeat('Only synthetic QA legal content. ', 5),
                'operator_snapshot' => '{}', 'sha256' => $hash,
                'acceptance' => 'accepted', 'required' => true,
                'effective_at' => now()->subDay(),
                'published_at' => now()->subDay(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $items[] = ['id' => $id, 'sha256' => $hash, 'type' => $type];
        }

        return $items;
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
        $this->expectException(HttpException::class);
        $service->issue($draft->id, $user->id);
    }

    public function test_start_registration_does_not_create_an_account(): void
    {
        config(['mro_commercial.public_registration_enabled' => true]);
        $this->legalSignupVersions();
        Mail::fake();
        $this->postJson('/api/v1/public/register/start', [
            'name' => 'New Owner', 'email' => 'fresh@mro.test', 'company_name' => 'Fresh Co', 'consent' => true, 'locale' => 'en',
            'legal_choices' => ['offer' => true, 'privacy' => true],
        ])->assertStatus(202);
        $this->assertDatabaseMissing('users', ['email' => 'fresh@mro.test']);
        $this->assertDatabaseHas('mro_signup_requests', ['email' => 'fresh@mro.test']);
    }

    public function test_verify_registration_creates_just_one_company_and_owner(): void
    {
        config(['mro_commercial.public_registration_enabled' => true]);
        $versions = $this->legalSignupVersions();
        $token = str_repeat('a', 80);
        DB::table('mro_signup_requests')->insert([
            'email' => 'new@mro.test', 'name' => 'New Owner', 'company_name' => 'New QA Company',
            'plan_code' => 'mro-base', 'token_hash' => hash('sha256', $token),
            'legal_snapshot' => json_encode(['locale' => 'ru', 'versions' => $versions, 'choices' => ['offer' => true, 'privacy' => true]]),
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
        $this->assertDatabaseCount('mro_legal_consents', 2);
    }

    public function test_public_registration_is_gated_on_legal_publication(): void
    {
        Mail::fake();
        $payload = [
            'name' => 'New Person', 'email' => 'newperson@mro.test',
            'company_name' => 'Legal QA Co', 'consent' => true, 'locale' => 'ru',
            'legal_choices' => ['offer' => true, 'privacy' => true],
        ];
        $this->postJson('/api/v1/public/register/start', $payload)->assertStatus(503);
        config(['mro_commercial.public_registration_enabled' => true]);
        $this->postJson('/api/v1/public/register/start', $payload)->assertStatus(409);
        $this->legalSignupVersions();
        $this->postJson('/api/v1/public/register/start', $payload)->assertStatus(202);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_invalid_token_never_creates_tenant(): void
    {
        config(['mro_commercial.public_registration_enabled' => true]);
        $this->postJson('/api/v1/public/register/verify', [
            'token' => str_repeat('z', 80), 'password' => 'Strong!123456789',
            'password_confirmation' => 'Strong!123456789',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_mro_grant_management_isolated_from_portal_admin_tables(): void
    {
        [$admin, $company] = $this->owner();
        $this->manager($admin);
        $member = User::factory()->create([
            'email' => 'technician@mro.test', 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $company->users()->attach($member->id, ['role' => 'technician', 'is_active' => true]);
        $this->actingAs($admin)->withSession(['toir_company_id' => $company->id]);
        $this->postJson('/api/v1/platform-admin/grants', [
            'user_id' => $member->id, 'permission' => 'billing.read',
        ])->assertOk()->assertJsonPath('status', 'granted');
        $this->getJson('/api/v1/platform-admin/grants')->assertOk()->assertJsonFragment([
            'user_id' => $member->id, 'permission' => 'billing.read',
        ]);
        $grant = DB::table('mro_admin_grants')
            ->where('user_id', $member->id)->where('permission', 'billing.read')->firstOrFail();
        $this->deleteJson('/api/v1/platform-admin/grants/'.$grant->id)
            ->assertOk()->assertJsonPath('status', 'revoked');
        $this->assertDatabaseMissing('mro_admin_grants', ['id' => $grant->id]);
        $managerGrant = DB::table('mro_admin_grants')->where('user_id', $admin->id)
            ->where('permission', 'platform.manage')->firstOrFail();
        $this->deleteJson('/api/v1/platform-admin/grants/'.$managerGrant->id)
            ->assertStatus(409);
        $outsideUser = User::factory()->create([
            'email' => 'outside@mro.test', 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->postJson('/api/v1/platform-admin/grants', [
            'user_id' => $outsideUser->id, 'permission' => 'billing.read',
        ])->assertStatus(422);
        DB::table('company_user')->where('company_id', $company->id)
            ->where('user_id', $admin->id)->update(['is_active' => false]);
        $this->getJson('/api/v1/platform-admin/overview')->assertForbidden();

    }

    public function test_super_admin_invoice_draft_accepts_mro_company_id(): void
    {
        [$admin, $company] = $this->owner();
        $this->manager($admin);
        app(InvoiceService::class)->saveProfile([
            'legal_name' => 'QA Maintenance', 'registration_number' => '990001',
            'address' => 'QA Astana', 'bank' => 'Test bank', 'iban' => 'KZ00000',
            'bic' => 'QADEMO01', 'signatory' => 'QA Director', 'currency' => 'KZT',
            'tax_basis_points' => 0, 'tax_label' => 'Tax excluded',
            'valid_days' => 30, 'prefix' => 'MRO', 'service_name' => 'MRO services',
        ], null, $admin->id);
        $this->actingAs($admin)->withSession(['toir_company_id' => $company->id]);
        $this->postJson('/api/v1/platform-admin/invoices', [
            'company_id' => $company->id,
            'items' => [['description' => 'Maintenance subscription', 'quantity' => 1, 'unit_minor' => 125000]],
            'period_from' => today()->toDateString(),
            'period_to' => today()->addMonth()->toDateString(),
            'due_date' => today()->addDays(15)->toDateString(),
            'basis' => 'QA invoice draft',
        ])->assertCreated()->assertJsonPath('data.company_id', $company->id)
            ->assertJsonPath('data.status', 'draft');
    }
}
