<?php

namespace Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use JMac\Testing\Traits\AdditionalAssertions;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use AdditionalAssertions;

    private static ?array $migrationSeedData = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CRATER_TEST_MYSQL') === '1') {
            if (self::$migrationSeedData !== null) {
                $this->restoreMigrationSeedData();
            } else {
                self::$migrationSeedData = [];
                foreach (['file_disks', 'settings', 'exchange_rate_providers'] as $table) {
                    self::$migrationSeedData[$table] = \DB::table($table)->get()
                        ->map(fn ($row) => (array) $row)->all();
                }
            }
        }

        Factory::guessFactoryNamesUsing(function (string $modelName) {
            // We can also customise where our factories live too if we want:
            $namespace = 'Database\\Factories\\';

            // Here we are getting the model name from the class namespace
            $modelName = Str::afterLast($modelName, '\\');

            // Finally we'll build up the full class path where
            // Laravel will find our model factory
            return $namespace.$modelName.'Factory';
        });
    }

    private function restoreMigrationSeedData(): void
    {
        // These records are installed by historical migrations, not the seeders.
        foreach (self::$migrationSeedData ?? [] as $table => $rows) {
            if ($rows) {
                \DB::table($table)->insert($rows);
            }
        }
    }

    protected function assertMonetaryDatabaseHas(string $table, array $attributes): void
    {
        // MySQL rounds values assigned to the existing integer minor-unit columns.
        // SQLite's permissive integer affinity retains fractional fixture values.
        if (\DB::connection()->getDriverName() === 'mysql') {
            foreach ($attributes as $key => $value) {
                if (str_starts_with($key, 'base_') && is_numeric($value)) {
                    $attributes[$key] = (int) round($value);
                }
            }
        }

        $this->assertDatabaseHas($table, $attributes);
    }
}
