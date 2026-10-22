<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The legacy factories expect seeded IDs to start at 1. MySQL does not roll
// auto-increment counters back with a transaction, so truncate between tests.
$databaseIsolation = getenv('CRATER_TEST_MYSQL') === '1'
    ? DatabaseTruncation::class
    : RefreshDatabase::class;

uses(TestCase::class, $databaseIsolation)->in('Feature', 'Unit');
