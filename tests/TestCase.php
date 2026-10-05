<?php

namespace Wink\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Wink\WinkServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [WinkServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('wink.database_connection', 'testing');
        $app['config']->set('wink.storage_disk', 'public');
        $app['config']->set('wink.storage_path', 'wink/images');
        $app['config']->set('wink.featured_image.driver', getenv('WINK_IMAGE_DRIVER') ?: 'auto');
    }

    protected function defineDatabaseMigrations()
    {
        // Not loadMigrationsFrom(): it rolls back after each test, and the
        // markdown migration's down() is broken. The database is in memory.
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../src/Migrations'), '--realpath' => true]);
    }
}
