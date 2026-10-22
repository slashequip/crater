<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UpdateCustomerIdInAllTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Copy stored columns only. Serializing today's User model invokes HTTP
        // date/avatar accessors and includes attributes absent from this schema.
        // Query builder inserts also preserve password hashes and timestamps.
        $columns = array_diff(Schema::getColumnListing('customers'), ['id']);

        DB::transaction(function () use ($columns) {
            DB::table('users')->where('role', 'customer')->orderBy('id')
                ->chunkById(100, function ($users) use ($columns) {
                    foreach ($users as $user) {
                        $attributes = array_intersect_key((array) $user, array_flip($columns));
                        $customerId = DB::table('customers')->insertGetId($attributes);

                        foreach (['addresses', 'expenses', 'estimates', 'invoices', 'payments'] as $table) {
                            DB::table($table)->where('user_id', $user->id)->update([
                                'customer_id' => $customerId,
                                'user_id' => null,
                            ]);
                        }

                        DB::table('custom_field_values')
                            ->where('custom_field_valuable_id', $user->id)
                            ->where('custom_field_valuable_type', 'Crater\\Models\\User')
                            ->update([
                                'custom_field_valuable_type' => 'Crater\\Models\\Customer',
                                'custom_field_valuable_id' => $customerId,
                            ]);

                        DB::table('media')->where('model_id', $user->id)
                            ->where('model_type', 'Crater\\Models\\User')
                            ->update(['model_type' => 'Crater\\Models\\Customer', 'model_id' => $customerId]);
                    }
                });

            foreach (DB::table('custom_fields')->where('model_type', 'User')->get() as $field) {
                DB::table('custom_fields')->where('id', $field->id)->update([
                    'model_type' => 'Customer',
                    'slug' => Str::upper('CUSTOM_Customer_'.Str::slug($field->label, '_')),
                ]);
            }
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('unit');
        });

        DB::table('users')->where('role', 'customer')->delete();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
