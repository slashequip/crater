<?php

use Carbon\Carbon;
use Crater\Jobs\CreateBackupJob;
use Crater\Mail\SendInvoiceMail;
use Crater\Models\Company;
use Crater\Models\CompanySetting;
use Crater\Models\Customer;
use Crater\Models\FileDisk;
use Crater\Models\Invoice;
use Crater\Models\RecurringInvoice;
use Crater\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Vinkla\Hashids\Facades\Hashids;

beforeEach(function () {
    $this->seed([Database\Seeders\DatabaseSeeder::class, Database\Seeders\DemoSeeder::class]);
    $this->user = User::first();
    $this->company = $this->user->companies()->first();
    $this->withHeader('company', $this->company->id);
});

test('existing administrator credentials still authenticate with a session', function () {
    $this->postJson('/login', [
        'email' => $this->user->email,
        'password' => 'crater@123',
    ])->assertNoContent();

    $this->assertAuthenticatedAs($this->user, 'web');
    $this->getJson('/api/v1/customers')->assertOk();
});

test('existing bearer tokens without an expiry still authenticate', function () {
    $tokenId = DB::table('personal_access_tokens')->insertGetId([
        'tokenable_type' => User::class,
        'tokenable_id' => $this->user->id,
        'name' => 'legacy token',
        'token' => hash('sha256', 'existing-token'),
        'abilities' => '["*"]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withToken($tokenId.'|existing-token')
        ->getJson('/api/v1/customers')->assertOk();
});

test('self update endpoints and the update command are removed', function () {
    Sanctum::actingAs($this->user, ['*']);

    $this->getJson('/api/v1/check/update')->assertNotFound();

    foreach (['download', 'unzip', 'copy', 'delete', 'migrate', 'finish'] as $step) {
        $this->postJson('/api/v1/update/'.$step)->assertNotFound();
    }

    expect(Artisan::all())->not->toHaveKey('crater:update');
    expect(collect(config('crater.setting_menu'))->pluck('link')->all())
        ->not->toContain('/admin/settings/update-app');
});

test('date attributes keep their existing Carbon behaviour', function () {
    $invoice = Invoice::factory()->create([
        'invoice_date' => '2026-10-01',
        'due_date' => '2026-10-31',
    ]);

    expect($invoice->fresh()->invoice_date)->toBeInstanceOf(Carbon::class);
    expect($invoice->fresh()->due_date->format('Y-m-d'))->toBe('2026-10-31');
});

test('PDF templates render with the upgraded PDF engine', function () {
    $invoice = Invoice::factory()->create();

    foreach (Invoice::invoiceTemplates() as $template) {
        $invoice->update(['template_name' => $template['name']]);
        $pdf = $invoice->fresh()->getPDFData()->output();

        expect($pdf)->toStartWith('%PDF-');
        expect(strlen($pdf))->toBeGreaterThan(1000);
    }
});

test('PDF endpoints return PDF bytes without an embedded HTTP response', function () {
    Sanctum::actingAs($this->user, ['*']);
    $invoice = Invoice::factory()->create();
    $response = $this->get('/invoices/pdf/'.$invoice->unique_hash);

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('invoice mail renders and attaches a PDF through Symfony Mailer', function () {
    $invoice = Invoice::factory()->create();
    $mail = new SendInvoiceMail([
        'from' => 'billing@example.test',
        'to' => 'customer@example.test',
        'subject' => 'Your invoice',
        'body' => '<p>Please find your invoice attached.</p>',
        'invoice' => $invoice,
        'company' => ['logo' => null, 'name' => $this->company->name],
        'attach' => ['data' => $invoice->getPDFData()],
    ]);

    Mail::to('customer@example.test')->send($mail);

    $message = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage();
    expect($message->getSubject())->toBe('Your invoice');
    expect($message->getAttachments())->toHaveCount(1);
    expect($message->getHtmlBody())->toContain('Please find your invoice attached.');
    $this->assertDatabaseHas('email_logs', ['mailable_id' => $invoice->id, 'subject' => 'Your invoice']);
});

test('attachments retain the existing disk and path layout', function () {
    Storage::fake('public');
    $media = $this->company->addMedia(UploadedFile::fake()->image('logo.png'))
        ->toMediaCollection('logo', 'public');

    Storage::disk('public')->assertExists($media->id.'/logo.png');
    expect($media->fresh()->generated_conversions)->toBeArray();
    expect(config('filesystems.disks.local.root'))->toBe(storage_path('app'));
});

test('legacy conversion metadata survives the package migration', function () {
    $connection = DB::getDefaultConnection();
    config(['database.connections.legacy_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::setDefaultConnection('legacy_upgrade');

    try {
        Schema::create('media', function ($table) {
            $table->id();
            $table->string('file_name');
            $table->text('custom_properties');
        });
        Schema::create('personal_access_tokens', fn ($table) => $table->id());
        DB::table('media')->insert([
            'id' => 42,
            'file_name' => 'existing-logo.png',
            'custom_properties' => json_encode(['generated_conversions' => ['thumbnail' => true], 'keep' => 'unchanged']),
        ]);
        $migration = require database_path('migrations/2026_10_22_000001_add_modern_package_columns.php');
        $migration->up();

        $existing = DB::table('media')->find(42);
        expect(json_decode($existing->generated_conversions, true))->toBe(['thumbnail' => true]);
        expect(json_decode($existing->custom_properties, true)['keep'])->toBe('unchanged');
        expect($existing->file_name)->toBe('existing-logo.png');
        expect(Schema::hasColumn('personal_access_tokens', 'expires_at'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($connection);
        DB::purge('legacy_upgrade');
    }
});

test('Dropbox provides the Laravel filesystem adapter expected by Flysystem 3', function () {
    config(['filesystems.disks.dropbox.token' => 'test-token']);

    expect(Storage::disk('dropbox'))->toBeInstanceOf(FilesystemAdapter::class);
});

test('a file backup produces a readable archive using the updated backup API', function () {
    $root = storage_path('framework/testing/backup-compatibility');
    $source = $root.'/source';
    $destination = $root.'/destination';
    File::ensureDirectoryExists($source);
    File::put($source.'/sample.txt', 'existing attachment');

    config([
        'backup.backup.name' => 'compatibility',
        'backup.backup.source.files.include' => [$source],
        'backup.backup.source.databases' => [],
        'backup.backup.temporary_directory' => $root.'/temporary',
    ]);
    $disk = FileDisk::factory()->create([
        'driver' => 'local',
        'credentials' => ['root' => $destination],
    ]);

    try {
        (new CreateBackupJob(['file_disk_id' => $disk->id, 'option' => 'only-files']))->handle();
        $archives = File::glob($destination.'/compatibility/*.zip');
        expect($archives)->toHaveCount(1);
        $zip = new ZipArchive();
        expect($zip->open($archives[0]))->toBeTrue();
        expect($zip->numFiles)->toBeGreaterThan(0);
        expect($zip->getFromIndex(0))->toBe('existing attachment');
        $zip->close();
    } finally {
        File::deleteDirectory($root);
    }
});


test('existing customer credentials still authenticate with a session', function () {
    $customer = Customer::factory()->create(['password' => 'secret']);
    $this->postJson('/'.$this->company->slug.'/customer/login', [
        'email' => $customer->email,
        'password' => 'secret',
    ])->assertOk();

    $this->assertAuthenticatedAs($customer, 'customer');
});

test('Hashids preserves identifiers generated by the original dependency', function () {
    expect(Hashids::connection(Company::class)->encode(1))->toBe('EKVBD0mWlxA6xOA1nPLY');
    expect(Hashids::connection(Company::class)->decode('EKVBD0mWlxA6xOA1nPLY'))->toBe([1]);
});

test('recurring invoices preserve totals and string-based due date settings', function () {
    $this->travelTo(Carbon::parse('2026-10-22 12:00:00'));
    CompanySetting::setSettings(['invoice_due_date_days' => '14'], $this->company->id);
    $recurring = RecurringInvoice::factory()->create([
        'starts_at' => '2026-10-01',
        'frequency' => '0 0 1 * *',
        'limit_by' => 'NONE',
        'template_name' => 'invoice1',
        'creator_id' => $this->user->id,
        'status' => 'ACTIVE',
        'discount_type' => 'fixed',
        'sub_total' => 10000,
        'discount' => 0,
        'discount_val' => 0,
        'tax' => 2000,
        'total' => 12000,
        'due_amount' => 12000,
        'exchange_rate' => 1,
    ]);

    $recurring->generateInvoice();

    $invoice = $recurring->invoices()->sole();
    expect($invoice->total)->toBe(12000);
    expect($invoice->due_date->format('Y-m-d'))->toBe('2026-11-05');
    expect($invoice->invoice_number)->toBe('INV-000001');
    expect($recurring->fresh()->next_invoice_at)->toBe('2026-11-01 00:00:00');
});
