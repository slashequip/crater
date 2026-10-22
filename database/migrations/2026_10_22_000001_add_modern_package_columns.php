<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('media', 'generated_conversions')) {
            Schema::table('media', function (Blueprint $table) {
                $table->json('generated_conversions')->nullable();
            });

            // Media Library 8 stored conversion flags inside custom_properties.
            // Keep those properties intact so the old application can still read them.
            DB::table('media')->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $properties = json_decode($row->custom_properties, true) ?: [];

                    DB::table('media')->where('id', $row->id)->update([
                        'generated_conversions' => json_encode($properties['generated_conversions'] ?? []),
                    ]);
                }
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('generated_conversions');
        });
    }
};
