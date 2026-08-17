<?php

namespace Tests\Feature;

use App\Http\Middleware\SetTenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshDatabaseAsOwner;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $resolve = fn (Request $request) => [
            'tenant_id' => app(SetTenantContext::class)->resolveTenantId($request),
        ];

        Route::middleware('web')->get('_test/web-tenant', $resolve);
        Route::middleware(['api', 'auth:sanctum'])->get('_test/api-tenant', $resolve);
    }

    public function test_a_user_belongs_to_a_tenant()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();

        $this->assertSame($tenant->id, $user->tenant->id);
    }

    public function test_a_user_may_have_no_tenant_yet()
    {
        $user = User::factory()->create();

        $this->assertNull($user->tenant_id);
        $this->assertNull($user->tenant);
    }

    public function test_a_guest_resolves_to_no_tenant()
    {
        $this->get('_test/web-tenant')->assertOk()->assertJsonPath('tenant_id', null);
    }

    public function test_the_suite_runs_against_postgres_as_the_dml_only_role()
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('faturly_app', DB::scalar('select current_user'));
    }

    public function test_a_request_binds_the_tenant_to_the_connection()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();

        $this->actingAs($user)->get('_test/web-tenant')->assertOk();

        $this->assertSame($tenant->id, DB::scalar("select current_setting('app.tenant_id', true)"));
    }

    public function test_a_guest_request_leaves_no_tenant_bound()
    {
        $this->get('_test/web-tenant')->assertOk();

        $this->assertSame('', DB::scalar("select current_setting('app.tenant_id', true)"));
        $this->assertNull(DB::scalar("select nullif(current_setting('app.tenant_id', true), '')::uuid"));
    }

    public function test_a_session_authenticated_user_resolves_to_their_tenant()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();

        $this->actingAs($user)
            ->get('_test/web-tenant')
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id);
    }

    public function test_a_bearer_token_resolves_to_the_users_tenant()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('_test/api-tenant')
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id);
    }
}
