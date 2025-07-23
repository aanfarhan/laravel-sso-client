<?php

namespace Omniglies\LaravelSsoClient\Tests;

use Omniglies\LaravelSsoClient\SsoClientServiceProvider;
use Omniglies\LaravelSsoClient\SsoClient;

class SsoClientServiceProviderTest extends TestCase
{
    /** @test */
    public function it_registers_the_service_provider()
    {
        $this->assertTrue($this->app->providerIsLoaded(SsoClientServiceProvider::class));
    }

    /** @test */
    public function it_registers_the_sso_client_singleton()
    {
        $this->assertTrue($this->app->bound('sso-client'));
        
        $ssoClient = $this->app->make('sso-client');
        $this->assertInstanceOf(SsoClient::class, $ssoClient);
    }

    /** @test */
    public function it_merges_configuration()
    {
        $this->assertNotNull(config('sso-client.user_model'));
        $this->assertEquals('App\Models\User', config('sso-client.user_model'));
    }

    /** @test */
    public function it_registers_routes()
    {
        $routes = $this->app['router']->getRoutes();
        
        // Check if SSO routes are registered
        $routeNames = [];
        foreach ($routes as $route) {
            if ($route->getName()) {
                $routeNames[] = $route->getName();
            }
        }
        
        $this->assertTrue(in_array('sso.redirect', $routeNames));
        $this->assertTrue(in_array('sso.callback', $routeNames));
        $this->assertTrue(in_array('sso.logout', $routeNames));
        $this->assertTrue(in_array('sso.local-logout', $routeNames));
    }
}