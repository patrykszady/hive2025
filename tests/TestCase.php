<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['scout.driver' => null]);
        $this->registerSqliteRegexp();
    }

    /**
     * MySQL's REGEXP and CHAR_LENGTH for the in-memory SQLite test database,
     * which has neither: task de-duplication queries with both, and its tests
     * failed with "no such function". REGEXP is case-insensitive, like MySQL
     * on the app's non-binary collations; SQLite calls regexp(pattern,
     * subject) for "subject REGEXP pattern". CONCAT is built in since 3.44.
     */
    protected function registerSqliteRegexp(): void
    {
        $connection = $this->app['db']->connection();

        if ($connection->getDriverName() !== 'sqlite') {
            return;
        }

        $connection->getPdo()->sqliteCreateFunction('REGEXP', function (?string $pattern, ?string $subject): int {
            if ($pattern === null || $subject === null) {
                return 0;
            }

            return (int) (@preg_match('~'.str_replace('~', '\~', $pattern).'~iu', $subject) === 1);
        }, 2);

        $connection->getPdo()->sqliteCreateFunction('CHAR_LENGTH', fn (?string $value): ?int => $value === null ? null : mb_strlen($value), 1);
    }
}
