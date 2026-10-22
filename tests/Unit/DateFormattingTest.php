<?php

use Crater\Models\CompanySetting;
use Illuminate\Support\Facades\DB;

test('formatted dates tolerate missing settings and respect the record company', function ($modelClass, $source, $formatted) {
    DB::table('companies')->insert(['id' => 1, 'name' => 'Record company']);
    DB::table('companies')->insert(['id' => 2, 'name' => 'Other company']);
    $model = (new $modelClass())->forceFill(['company_id' => 1, $source => '2020-05-06 07:08:09']);

    expect(request()->header('company'))->toBeNull();
    expect($model->$formatted)->toBe('2020/05/06');

    CompanySetting::setSettings(['carbon_date_format' => 'd.m.Y'], 1);
    CompanySetting::setSettings(['carbon_date_format' => 'm-d-Y'], 2);
    $this->withHeader('company', 2);
    request()->headers->set('company', 2);
    expect($model->$formatted)->toBe('06.05.2020');

    CompanySetting::setSettings(['carbon_date_format' => ''], 1);
    expect($model->$formatted)->toBe('2020/05/06');
})->with([
    [Crater\Models\User::class, 'created_at', 'formattedCreatedAt'],
    [Crater\Models\Customer::class, 'created_at', 'formattedCreatedAt'],
    [Crater\Models\Item::class, 'created_at', 'formattedCreatedAt'],
    [Crater\Models\ExpenseCategory::class, 'created_at', 'formattedCreatedAt'],
    [Crater\Models\Invoice::class, 'created_at', 'formattedCreatedAt'],
    [Crater\Models\Invoice::class, 'invoice_date', 'formattedInvoiceDate'],
    [Crater\Models\Invoice::class, 'due_date', 'formattedDueDate'],
    [Crater\Models\Estimate::class, 'estimate_date', 'formattedEstimateDate'],
    [Crater\Models\Estimate::class, 'expiry_date', 'formattedExpiryDate'],
    [Crater\Models\Payment::class, 'created_at', 'formattedCreatedAt'],
    [Crater\Models\Payment::class, 'payment_date', 'formattedPaymentDate'],
    [Crater\Models\Expense::class, 'created_at', 'formattedCreatedAt'],
    [Crater\Models\Expense::class, 'expense_date', 'formattedExpenseDate'],
    [Crater\Models\RecurringInvoice::class, 'created_at', 'formattedCreatedAt'],
    [Crater\Models\RecurringInvoice::class, 'starts_at', 'formattedStartsAt'],
    [Crater\Models\RecurringInvoice::class, 'next_invoice_at', 'formattedNextInvoiceAt'],
    [Crater\Models\RecurringInvoice::class, 'limit_date', 'formattedLimitDate'],
]);

test('users without a legacy company column use the selected company date format', function () {
    DB::table('companies')->insert(['id' => 1, 'name' => 'Selected company']);
    CompanySetting::setSettings(['carbon_date_format' => 'd.m.Y'], 1);
    request()->headers->set('company', 1);

    expect((new Crater\Models\User(['created_at' => '2020-05-06']))->formattedCreatedAt)
        ->toBe('06.05.2020');
});
