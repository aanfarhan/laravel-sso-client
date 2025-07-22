<?php

namespace Mdigi\LaravelSsoClient;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Mdigi\LaravelSsoClient\Commands\SsoInstallCommand;

class SsoClientServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/sso-client.php', 'sso-client'
        );

        $this->app->singleton('sso-client', function ($app) {
            return new SsoClient();
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sso-client.php' => config_path('sso-client.php'),
            ], 'sso-client-config');

            $this->publishes([
                __DIR__.'/../database/migrations/add_oauth_fields_to_users_table.php.stub' => 
                database_path('migrations/'.date('Y_m_d_His').'_add_oauth_fields_to_users_table.php'),
            ], 'sso-client-migrations');

            $this->commands([
                SsoInstallCommand::class,
                \Mdigi\LaravelSsoClient\Commands\SsoSyncCommand::class,
            ]);
        }

        $this->registerRoutes();
        $this->registerEventServiceProviderConfiguration();
    }

    protected function registerRoutes()
    {
        if (config('sso-client.route_prefix')) {
            Route::group([
                'prefix' => config('sso-client.route_prefix', 'sso'),
                'as' => 'sso.',
                'middleware' => config('sso-client.middleware', ['web']),
            ], function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/sso.php');
            });
        }
    }

    protected function registerEventServiceProviderConfiguration()
    {
        // Register Socialite provider
        $this->app->booted(function () {
            if (class_exists('\SocialiteProviders\Manager\SocialiteWasCalled')) {
                \Event::listen('\SocialiteProviders\Manager\SocialiteWasCalled', [
                    '\SocialiteProviders\LaravelPassport\LaravelPassportExtendSocialite@handle'
                ]);
            }
        });
    }
}