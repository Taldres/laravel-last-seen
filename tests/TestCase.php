<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Taldres\LastSeen\LastSeenServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LastSeenServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The test container uses a self-signed certificate, which ODBC Driver 18 rejects by default.
        $app['config']->set('database.connections.sqlsrv.trust_server_certificate', true);
    }
}
