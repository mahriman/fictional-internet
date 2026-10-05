<?php

use Tests\Support\MySqlTestDatabaseGuard;

test('mysql test database guard accepts only the isolated test database', function () {
    expect(fn () => MySqlTestDatabaseGuard::assertDatabaseName('fictional_internet_test'))
        ->not->toThrow(Throwable::class);
});

test('mysql test database guard refuses the normal and unknown databases', function (?string $databaseName) {
    expect(fn () => MySqlTestDatabaseGuard::assertDatabaseName($databaseName))
        ->toThrow(RuntimeException::class, 'MySQL tests may only run against the fictional_internet_test database.');
})->with([
    'normal development database' => 'fictional_internet',
    'unknown database' => 'another_database',
    'no active database' => null,
]);
