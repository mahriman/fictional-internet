<?php

namespace Tests\Support;

class MySqlTestDatabaseGuard
{
    public static function assertDatabaseName(?string $databaseName): void
    {
        if ($databaseName !== 'fictional_internet_test') {
            throw new \RuntimeException('MySQL tests may only run against the fictional_internet_test database.');
        }
    }
}
