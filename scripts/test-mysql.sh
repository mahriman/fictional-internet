#!/usr/bin/env bash

set -euo pipefail

expected_database='fictional_internet_test'

if [[ "${DB_DATABASE:-$expected_database}" != "$expected_database" ]]; then
    printf 'Refusing MySQL tests: DB_DATABASE must be %s.\n' "$expected_database" >&2
    exit 2
fi

export APP_ENV=testing
export DB_CONNECTION=mysql
export DB_DATABASE="$expected_database"
export DB_URL=''
export FICTIONAL_INTERNET_MYSQL_TEST=1

active_database=$(php artisan tinker --execute 'echo DB::selectOne("SELECT DATABASE() AS database_name")->database_name;')

if [[ "$active_database" != "$expected_database" ]]; then
    printf 'Refusing MySQL tests: SELECT DATABASE() returned an unexpected database.\n' >&2
    exit 2
fi

php artisan test --compact \
    tests/Feature/AccountLifecycleTest.php \
    tests/Feature/AccountOpenAiCredentialTest.php \
    tests/Feature/ContentContinuationTest.php \
    tests/Feature/GeneratedContentActionsTest.php \
    tests/Feature/GeneratedContentEditingTest.php \
    tests/Feature/GeneratedContentManagementTest.php \
    tests/Feature/GeneratedContentReferencesTest.php \
    tests/Feature/GeneratedContentUiTest.php \
    tests/Feature/GeneratedContentVersionUiTest.php \
    tests/Feature/GenerationOperationalSafetyTest.php \
    tests/Feature/GenerationAttemptTest.php \
    tests/Feature/OpenAiCredentialResolutionTest.php \
    tests/Feature/ProjectContextManagementTest.php \
    tests/Feature/ProjectExportTest.php \
    tests/Feature/ProjectManagementTest.php \
    tests/Unit/MySqlTestDatabaseGuardTest.php

# These suites deliberately run outside test transactions and refresh schema
# before each case so provider calls remain outside database transactions.
php artisan test --compact tests/Feature/ContentContinuationGenerationTest.php
exec php artisan test --compact tests/Feature/GeneratedContentContinuationUiTest.php
