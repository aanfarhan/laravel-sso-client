# Laravel SSO Client

A Laravel package for OAuth client implementation using Laravel Socialite with LaravelPassport driver.

## Features

- Easy SSO integration with LaravelPassport OAuth server
- Automatic user synchronization from OAuth server
- Configurable user model and field preservation
- Support for Spatie Laravel Permission roles
- Artisan command for easy installation and setup
- Local and SSO logout options

## Version Compatibility

| Laravel Version | Package Version | PHP Version | Branch | Status |
|----------------|-----------------|-------------|--------|--------|
| 9.x | ^9.0 | ^8.0 | 9.x | Current Release |

## Installation

### Via Composer

```bash
composer require omniglies/laravel-sso-client:^9.0
```

### Quick Setup

Run the installation command to automatically configure everything:

```bash
php artisan sso:install
```

This command will:
- Publish configuration files
- Publish database migrations
- Configure required services
- Update EventServiceProvider
- Add environment variables template

### Manual Installation

1. Publish the configuration:
```bash
php artisan vendor:publish --tag=sso-client-config
```

2. Publish migrations:
```bash
php artisan vendor:publish --tag=sso-client-migrations
```

3. Run migrations:
```bash
php artisan migrate
```

4. Add to your `.env` file:
```env
LARAVELPASSPORT_CLIENT_ID=your_client_id
LARAVELPASSPORT_CLIENT_SECRET=your_client_secret
LARAVELPASSPORT_REDIRECT_URI=http://your-app.com/sso/callback
LARAVELPASSPORT_HOST=http://your-oauth-server.com
```

## Configuration

### config/sso-client.php

```php
return [
    // User model to use for authentication
    'user_model' => 'App\\Models\\User',
    
    // Default role for new SSO users (requires Spatie Permission)
    'default_role' => null,
    
    // Fields that won't be overwritten during sync
    'preserved_fields' => [
        'id_role', 'nik', 'address', 'nip_pbb', 
        'kd_propinsi', 'kd_dati2', 'kd_kecamatan', 'kd_kelurahan'
    ],
    
    // Redirect path after successful login
    'redirect_after_login' => '/home',
    
    // Route configuration
    'route_prefix' => 'sso',
    'middleware' => ['web'],
];
```

## Usage

### Routes

The package automatically registers these routes:

- `GET /sso/redirect` - Redirect to OAuth server
- `GET /sso/callback` - OAuth callback handler
- `POST /sso/logout` - SSO logout (logs out from both local and OAuth server)
- `POST /sso/local-logout` - Local logout only

### In your views

```blade
{{-- SSO Login Button --}}
<a href="{{ route('sso.redirect') }}" class="btn btn-primary">
    Login with SSO
</a>

{{-- SSO Logout Button --}}
<form method="POST" action="{{ route('sso.logout') }}">
    @csrf
    <button type="submit" class="btn btn-secondary">
        Logout (SSO)
    </button>
</form>

{{-- Local Logout Only --}}
<form method="POST" action="{{ route('sso.local-logout') }}">
    @csrf
    <button type="submit" class="btn btn-secondary">
        Logout (Local Only)
    </button>
</form>
```

### User Service

Access the SSO user service for advanced operations:

```php
use Omniglies\LaravelSsoClient\Services\SsoUserService;

$ssoService = new SsoUserService();

// Search users (requires admin token)
$users = $ssoService->withToken($adminToken)->searchUsers(['email' => 'user@example.com']);

// Create user on OAuth server
$newUser = $ssoService->withToken($adminToken)->createUser([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'username' => 'johndoe',
    'password' => 'password'
]);

// Sync local user with OAuth server
$ssoService->syncLocalUser($user);
```

## Database Schema

The package adds these fields to your users table:

- `oauth_id` - Unique OAuth user ID
- `username` - Username from OAuth server
- `oauth_data` - JSON field storing all OAuth user data
- `synced_at` - Last sync timestamp
- `is_active` - User active status

## Requirements

- PHP ^8.0
- Laravel ^9.0
- Laravel Socialite ^5.0
- SocialiteProviders LaravelPassport ^4.0

## License

MIT License