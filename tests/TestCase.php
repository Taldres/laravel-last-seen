<?php

namespace Taldres\LastSeen\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Taldres\LastSeen\LastSeenServiceProvider;

abstract class TestCase extends Orchestra
{
protected $loadEnvironmentVariables = true;
    protected function getEnvironmentSetUp($app): void
    {
        $packageRoot = __DIR__.'/../';

        if (file_exists($packageRoot)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($packageRoot, '.env.testing');
            $dotenv->safeLoad();
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            LastSeenServiceProvider::class,
        ];
    }
}
