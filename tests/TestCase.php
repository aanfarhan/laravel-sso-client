<?php

namespace Mdigi\LaravelSsoClient\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Mdigi\LaravelSsoClient\SsoClientServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [
            SsoClientServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set up basic SSO client configuration for testing
        $app['config']->set('sso-client.user_model', 'App\Models\User');
        $app['config']->set('sso-client.route_prefix', 'sso');
        $app['config']->set('sso-client.middleware', ['web']);
    }
}