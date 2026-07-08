<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VendorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/portal',
        ])->assertSuccessful();
    }

    public function test_user_with_vendor_create_permission_can_register_a_vendor(): void
    {
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'vendors.create']);
        $user->givePermissionTo($permission);

        $response = $this->actingAs($user)->post(route('vendors.store'), [
            'name' => 'Acme Supplies Inc.',
            'email' => 'vendor@example.com',
            'phone' => '+63 912 345 6789',
            'vendor_type' => null,
            'company_id' => null,
            'password' => 'Vendor1234',
            'password_confirmation' => 'Vendor1234',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('portal_vendors', [
            'name' => 'Acme Supplies Inc.',
            'email' => 'vendor@example.com',
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        $vendorId = (int) DB::table('portal_vendors')
            ->where('email', 'vendor@example.com')
            ->value('id');

        $this->assertDatabaseHas('portal_vendor_profiles', [
            'vendor_id' => $vendorId,
            'approval_status' => 'draft',
        ]);
    }

    public function test_user_without_vendor_create_permission_cannot_register_a_vendor(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('vendors.store'), [
            'name' => 'Blocked Vendor',
            'email' => 'blocked@example.com',
            'password' => 'Vendor1234',
            'password_confirmation' => 'Vendor1234',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('portal_vendors', [
            'email' => 'blocked@example.com',
        ]);
    }

    public function test_user_with_vendor_edit_permission_can_update_vendor_account_details(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('vendors.edit'));

        $vendor = Vendor::create([
            'code' => 'VND-TEST-001',
            'name' => 'Original Vendor',
            'email' => 'original@example.com',
            'password' => 'Vendor1234',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->put(route('vendors.update', $vendor), [
            'name' => 'Updated Vendor',
            'email' => 'updated@example.com',
            'phone' => '+63 917 000 0000',
            'company_id' => null,
            'vendor_type' => null,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('portal_vendors', [
            'id' => $vendor->id,
            'code' => 'VND-TEST-001',
            'name' => 'Updated Vendor',
            'email' => 'updated@example.com',
            'status' => 'active',
            'updated_by' => $user->id,
        ]);
    }

    public function test_user_without_vendor_edit_permission_cannot_update_a_vendor(): void
    {
        $user = User::factory()->create();
        $vendor = Vendor::create([
            'code' => 'VND-TEST-002',
            'name' => 'Protected Vendor',
            'email' => 'protected@example.com',
            'password' => 'Vendor1234',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->put(route('vendors.update', $vendor), [
            'name' => 'Unauthorized Change',
            'email' => 'changed@example.com',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('portal_vendors', [
            'id' => $vendor->id,
            'name' => 'Protected Vendor',
            'email' => 'protected@example.com',
        ]);
    }
}
