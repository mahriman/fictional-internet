<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\MySqlTestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();
        $mysqlProfileRequired = getenv('FICTIONAL_INTERNET_MYSQL_TEST') === '1';

        if ($mysqlProfileRequired && $connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException('The MySQL integration profile must run with the MySQL driver.');
        }

        if ($connection->getDriverName() === 'mysql') {
            $databaseName = $connection->selectOne('SELECT DATABASE() AS database_name')->database_name ?? null;
            MySqlTestDatabaseGuard::assertDatabaseName($databaseName);
        }

        return $app;
    }
}
