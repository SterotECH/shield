<?php

declare(strict_types=1);

namespace Stero\Shield\Laravel\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Stero\Shield\Laravel\ShieldServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ShieldServiceProvider::class];
    }
}
