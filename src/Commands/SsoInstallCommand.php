<?php

namespace Mdigi\LaravelSsoClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SsoInstallCommand extends Command
{
    protected $signature = 'sso:install {--force : Force the operation to run when in production}';
    protected $description = 'Install and configure SSO client package';

    public function handle()
    {
        $this->info('Installing Laravel SSO Client...');

        // Step 1: Publish config
        $this->publishConfig();

        // Step 2: Publish and run migrations
        $this->publishMigrations();

        // Step 3: Install required packages
        $this->installDependencies();

        // Step 4: Configure services
        $this->configureServices();

        // Step 5: Update EventServiceProvider
        $this->updateEventServiceProvider();

        // Step 6: Add environment variables
        $this->addEnvironmentVariables();

        $this->info('✅ SSO Client installation completed!');
        $this->newLine();
        $this->warn('Next steps:');
        $this->line('1. Update your .env file with OAuth server credentials:');
        $this->line('   LARAVELPASSPORT_CLIENT_ID=your_client_id');
        $this->line('   LARAVELPASSPORT_CLIENT_SECRET=your_client_secret');
        $this->line('   LARAVELPASSPORT_REDIRECT_URI=http://your-app.com/sso/callback');
        $this->line('   LARAVELPASSPORT_HOST=http://your-oauth-server.com');
        $this->newLine();
        $this->line('2. Run migrations: php artisan migrate');
        $this->line('3. Configure your User model if needed in config/sso-client.php');
        $this->line('4. Add SSO login links to your views using route("sso.redirect")');
    }

    protected function publishConfig()
    {
        $this->info('📝 Publishing configuration...');
        
        $this->call('vendor:publish', [
            '--tag' => 'sso-client-config',
            '--force' => $this->option('force'),
        ]);
    }

    protected function publishMigrations()
    {
        $this->info('📁 Publishing migrations...');
        
        $this->call('vendor:publish', [
            '--tag' => 'sso-client-migrations',
            '--force' => $this->option('force'),
        ]);
    }

    protected function installDependencies()
    {
        $this->info('📦 Checking dependencies...');

        $composer = json_decode(File::get(base_path('composer.json')), true);
        $dependencies = [
            'laravel/socialite' => '^5.0',
            'socialiteproviders/laravelpassport' => '^4.0'
        ];

        $missingDeps = [];
        foreach ($dependencies as $package => $version) {
            if (!isset($composer['require'][$package])) {
                $missingDeps[] = $package . ':' . $version;
            }
        }

        if (!empty($missingDeps)) {
            $this->warn('Installing missing dependencies: ' . implode(', ', $missingDeps));
            $this->info('Run: composer require ' . implode(' ', $missingDeps));
        } else {
            $this->info('✅ All dependencies are already installed');
        }
    }

    protected function configureServices()
    {
        $this->info('⚙️ Configuring services...');

        $servicesPath = config_path('services.php');
        $servicesContent = File::get($servicesPath);

        // Check if laravelpassport config already exists
        if (!Str::contains($servicesContent, "'laravelpassport' =>")) {
            $laravelPassportConfig = "
    'laravelpassport' => [
        'client_id' => env('LARAVELPASSPORT_CLIENT_ID'),
        'client_secret' => env('LARAVELPASSPORT_CLIENT_SECRET'),
        'redirect' => env('LARAVELPASSPORT_REDIRECT_URI'),
        'host' => env('LARAVELPASSPORT_HOST'),
    ],";

            // Add before the closing bracket
            $servicesContent = Str::replaceLast('];', $laravelPassportConfig . "\n];", $servicesContent);
            File::put($servicesPath, $servicesContent);
            
            $this->info('✅ Added LaravelPassport configuration to services.php');
        } else {
            $this->info('✅ LaravelPassport configuration already exists in services.php');
        }
    }

    protected function updateEventServiceProvider()
    {
        $this->info('🔧 Updating EventServiceProvider...');

        $eventServiceProviderPath = app_path('Providers/EventServiceProvider.php');
        
        if (!File::exists($eventServiceProviderPath)) {
            $this->warn('EventServiceProvider not found, skipping...');
            return;
        }

        $content = File::get($eventServiceProviderPath);

        // Check if listener already exists
        if (Str::contains($content, 'SocialiteWasCalled')) {
            $this->info('✅ Socialite event listener already configured');
            return;
        }

        // Add the listener
        $listenerConfig = "
        \\SocialiteProviders\\Manager\\SocialiteWasCalled::class => [
            '\\SocialiteProviders\\LaravelPassport\\LaravelPassportExtendSocialite@handle',
        ],";

        if (Str::contains($content, 'protected $listen = [')) {
            // Add to existing $listen array
            $content = Str::replaceFirst(
                'protected $listen = [',
                'protected $listen = [' . $listenerConfig,
                $content
            );
        } else {
            // Add new $listen array
            $content = Str::replaceFirst(
                'use Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider as ServiceProvider;',
                "use Illuminate\\Foundation\\Support\\Providers\\EventServiceProvider as ServiceProvider;\n\nclass EventServiceProvider extends ServiceProvider\n{\n    protected \$listen = [{$listenerConfig}\n    ];",
                $content
            );
        }

        File::put($eventServiceProviderPath, $content);
        $this->info('✅ Updated EventServiceProvider with Socialite listener');
    }

    protected function addEnvironmentVariables()
    {
        $this->info('🔐 Adding environment variables template...');

        $envPath = base_path('.env');
        $envExamplePath = base_path('.env.example');

        $envVars = "
# Laravel SSO Client Configuration
LARAVELPASSPORT_CLIENT_ID=
LARAVELPASSPORT_CLIENT_SECRET=
LARAVELPASSPORT_REDIRECT_URI=\${APP_URL}/sso/callback
LARAVELPASSPORT_HOST=";

        // Add to .env.example
        if (File::exists($envExamplePath)) {
            $envExampleContent = File::get($envExamplePath);
            if (!Str::contains($envExampleContent, 'LARAVELPASSPORT_CLIENT_ID')) {
                File::append($envExamplePath, $envVars);
                $this->info('✅ Added SSO variables to .env.example');
            }
        }

        // Add to .env if it exists and doesn't have the vars
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
            if (!Str::contains($envContent, 'LARAVELPASSPORT_CLIENT_ID')) {
                File::append($envPath, $envVars);
                $this->info('✅ Added SSO variables to .env');
            }
        }
    }
}