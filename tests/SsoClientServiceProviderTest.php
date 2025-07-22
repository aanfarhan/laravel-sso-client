<?php

namespace Mdigi\LaravelSsoClient\Tests;

use Mdigi\LaravelSsoClient\SsoClientServiceProvider;
use Mdigi\LaravelSsoClient\SsoClient;

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
        
        $this->assertContains('sso.redirect', $routeNames);
        $this->assertContains('sso.callback', $routeNames);
        $this->assertContains('sso.logout', $routeNames);
        $this->assertContains('sso.local-logout', $routeNames);
    }
}