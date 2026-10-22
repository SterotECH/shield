<?php

declare(strict_types=1);

namespace Shield\Laravel\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Shield\Laravel\ShieldServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ShieldServiceProvider::class];
    }
}
