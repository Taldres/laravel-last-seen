<?php

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
}
