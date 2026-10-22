<?php

use Crater\Models\FileDisk;
use Crater\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

test('get file disks', function () {
    $response = getJson('/api/v1/disks');

    $response->assertOk();
});

test('create file disk', function () {
    $disk = FileDisk::factory()->raw();

    $response = postJson('/api/v1/disks', $disk);

    $credentials = $disk['credentials'];
    unset($disk['credentials']);
    $response->assertCreated();
    $this->assertDatabaseHas('file_disks', $disk);
    expect(json_decode(FileDisk::where('name', $disk['name'])->firstOrFail()->credentials, true))->toEqual($credentials);
});


test('update file disk', function () {
    $disk = FileDisk::factory()->create();

    $disk2 = FileDisk::factory()->raw();

    $response = putJson("/api/v1/disks/{$disk->id}", $disk2)->assertStatus(200);

    $credentials = $disk2['credentials'];
    unset($disk2['credentials']);
    $this->assertDatabaseHas('file_disks', $disk2);
    expect(json_decode($disk->fresh()->credentials, true))->toEqual($credentials);
});


test('get disk', function () {
    $disk = FileDisk::factory()->create();

    $response = getJson("/api/v1/disks/{$disk->driver}");

    $response->assertStatus(200);
});

test('get drivers', function () {
    $response = getJson("/api/v1/disk/drivers");

    $response->assertStatus(200);
});
