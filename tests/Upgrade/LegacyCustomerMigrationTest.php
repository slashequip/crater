<?php

namespace Tests\Upgrade;

use Crater\Models\Customer;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\CreatesApplication;

class LegacyCustomerMigrationTest extends TestCase
{
    use CreatesApplication;

    public function test_populated_legacy_database_upgrades_without_an_http_company_header(): void
    {
        // This suite deliberately rebuilds only the database forced by phpunit.xml.
        $this->assertContains(DB::connection()->getDatabaseName(), [':memory:', 'crater_test']);
        $paths = array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn ($path) =>
            basename($path) < '2021_06_30_062411_update_customer_id_in_all_tables.php'
        ));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]), Artisan::output());

        DB::table('companies')->insert([
            ['id' => 1, 'name' => 'Legacy company'],
            ['id' => 2, 'name' => 'Second company'],
        ]);
        DB::table('currencies')->insert([
            'id' => 1, 'name' => 'British Pound', 'code' => 'GBP', 'symbol' => '£',
            'precision' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.',
        ]);
        DB::table('company_settings')->insert(['company_id' => 1, 'option' => 'currency', 'value' => '1']);
        foreach ([1, 2] as $companyId) {
            foreach (['invoice' => 'INV', 'estimate' => 'EST', 'payment' => 'PAY'] as $type => $prefix) {
                DB::table('company_settings')->insert([
                    ['company_id' => $companyId, 'option' => $type.'_prefix', 'value' => $prefix.$companyId],
                    ['company_id' => $companyId, 'option' => $type.'_number_length', 'value' => '6'],
                ]);
            }
        }
        $hash = Hash::make('original-password');
        $createdAt = '2020-05-06 07:08:09';
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'role' => 'super admin', 'company_id' => 1, 'currency_id' => 1, 'creator_id' => null, 'password' => $hash, 'remember_token' => null, 'created_at' => $createdAt, 'updated_at' => $createdAt],
            ['id' => 42, 'name' => 'Legacy customer', 'email' => 'customer@example.test', 'role' => 'customer', 'company_id' => 1, 'currency_id' => 1, 'creator_id' => 1, 'password' => $hash, 'remember_token' => 'original-token', 'created_at' => $createdAt, 'updated_at' => $createdAt],
        ]);
        $document = [
            'id' => 7, 'user_id' => 42, 'company_id' => 1, 'creator_id' => 1,
            'status' => 'DRAFT', 'tax_per_item' => 'NO', 'discount_per_item' => 'NO',
            'sub_total' => 10000, 'total' => 12000, 'tax' => 2000, 'discount_val' => 0,
            'unique_hash' => 'existing-public-link', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ];
        DB::table('invoices')->insert($document + ['invoice_date' => '2020-05-06', 'due_date' => '2020-06-06', 'invoice_number' => 'INV-000007', 'paid_status' => 'UNPAID', 'due_amount' => 12000]);
        DB::table('estimates')->insert($document + ['estimate_date' => '2020-05-06', 'expiry_date' => '2020-06-06', 'estimate_number' => 'EST-000007']);
        DB::table('payments')->insert(['id' => 7, 'user_id' => 42, 'company_id' => 1, 'creator_id' => 1, 'invoice_id' => 7, 'payment_number' => 'PAY-000007', 'payment_date' => '2020-05-06', 'amount' => 1000]);
        DB::table('expense_categories')->insert(['id' => 1, 'name' => 'Travel', 'company_id' => 1]);
        DB::table('expenses')->insert(['id' => 7, 'user_id' => 42, 'company_id' => 1, 'creator_id' => 1, 'expense_category_id' => 1, 'expense_date' => '2020-05-06', 'amount' => 500]);
        DB::table('addresses')->insert(['id' => 7, 'user_id' => 42, 'type' => 'billing', 'address_street_1' => 'Original address']);
        DB::table('custom_fields')->insert(['id' => 1, 'name' => 'Reference', 'label' => 'Reference', 'slug' => 'CUSTOM_USER_REFERENCE', 'model_type' => 'User', 'type' => 'Input', 'company_id' => 1]);
        DB::table('custom_field_values')->insert(['id' => 1, 'custom_field_id' => 1, 'custom_field_valuable_type' => 'Crater\Models\User', 'custom_field_valuable_id' => 42, 'type' => 'Input', 'string_answer' => 'Original reference', 'company_id' => 1]);
        DB::table('media')->insert(['id' => 7, 'model_type' => 'Crater\Models\User', 'model_id' => 42, 'collection_name' => 'customer_avatar', 'name' => 'Avatar', 'file_name' => 'avatar.png', 'disk' => 'public', 'size' => 100, 'manipulations' => '[]', 'custom_properties' => '{}', 'responsive_images' => '[]']);

        $this->assertNull(request()->header('company'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());

        $customer = DB::table('customers')->where('email', 'customer@example.test')->sole();
        $this->assertSame($hash, $customer->password);
        $this->assertTrue(Hash::check('original-password', $customer->password));
        $this->assertSame('original-token', $customer->remember_token);
        $this->assertSame($createdAt, $customer->created_at);
        $this->assertSame(1, (int) $customer->creator_id);
        $this->assertSame(1, DB::table('users')->count());
        foreach (['invoices', 'estimates', 'payments', 'expenses', 'addresses'] as $table) {
            $this->assertSame((int) $customer->id, (int) DB::table($table)->where('id', 7)->value('customer_id'), $table);
        }
        foreach (['invoices', 'estimates'] as $table) {
            $this->assertSame(12000, (int) DB::table($table)->where('id', 7)->value('total'));
            $this->assertSame('existing-public-link', DB::table($table)->where('id', 7)->value('unique_hash'));
        }
        $this->assertSame('Original address', DB::table('addresses')->where('id', 7)->value('address_street_1'));
        $this->assertDatabaseHas('media', ['id' => 7, 'model_type' => 'Crater\Models\Customer', 'model_id' => $customer->id, 'file_name' => 'avatar.png']);
        $this->assertDatabaseHas('custom_field_values', ['id' => 1, 'custom_field_valuable_type' => 'Crater\Models\Customer', 'custom_field_valuable_id' => $customer->id, 'string_answer' => 'Original reference']);
        $this->assertDatabaseHas('custom_fields', ['id' => 1, 'model_type' => 'Customer', 'slug' => 'CUSTOM_CUSTOMER_REFERENCE']);
        $this->assertDatabaseHas('user_company', ['user_id' => 1, 'company_id' => 1]);
        foreach ([1, 2] as $companyId) {
            $this->assertDatabaseHas('company_settings', ['company_id' => $companyId, 'option' => 'invoice_number_format', 'value' => '{{SERIES:INV'.$companyId.'}}{{DELIMITER:-}}{{SEQUENCE:6}}']);
        }
        $this->assertTrue(Schema::hasColumn('media', 'generated_conversions'));
        $this->assertSame('2020/05/06', Customer::find($customer->id)->formattedCreatedAt);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $this->assertSame(1, DB::table('customers')->count());
    }
}
