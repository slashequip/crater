<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChangeEnablePortalFieldOfCustomersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Normalize legacy NULLs before making the column NOT NULL. Keep the
        // original migration's policy of disabling existing customer portals.
        DB::table('customers')->update(['enable_portal' => false]);

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('enable_portal')->default(false)->change();
        });
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
