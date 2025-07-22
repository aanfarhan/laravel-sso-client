# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel package for OAuth client implementation using Laravel Socialite with LaravelPassport driver. It provides SSO integration, user synchronization, and role management between OAuth client and server applications.

## Key Commands

### Installation & Setup
```bash
# Install package via composer
composer require mdigi/laravel-sso-client

# Run automated setup
php artisan sso:install

# Manual configuration publishing
php artisan vendor:publish --tag=sso-client-config
php artisan vendor:publish --tag=sso-client-migrations
php artisan migrate
```

### User Synchronization
```bash
# Basic sync
php artisan sso:sync

# Preview changes without applying
php artisan sso:sync --dry-run

# Force update server with client data
php artisan sso:sync --force-update-server

# Sync with role mapping
php artisan sso:sync --role-mapping --default-role=user

# Test password compatibility
php artisan sso:sync --test-password-sync=user@example.com
```

### Testing
```bash
# Run PHPUnit tests
./vendor/bin/phpunit

# Test with Orchestra Testbench
composer test
```

## Architecture & Structure

### Core Components
- **SsoClientServiceProvider**: Main service provider that registers routes, commands, and configurations
- **SsoController**: Handles OAuth redirect, callback, and logout endpoints
- **SsoUserService**: Manages user operations with OAuth server using Client Credentials authentication
- **ClientCredentialsService**: Handles OAuth 2.0 Client Credentials grant for secure API access
- **SsoSyncCommand**: Advanced command for bidirectional user synchronization

### Package Structure
```
src/
├── Commands/
│   ├── SsoInstallCommand.php    # Automated package setup
│   └── SsoSyncCommand.php       # User synchronization with conflict resolution
├── Controllers/
│   └── SsoController.php        # OAuth flow handlers
├── Services/
│   ├── ClientCredentialsService.php  # OAuth 2.0 Client Credentials
│   └── SsoUserService.php       # User management operations
├── SsoClient.php               # Main package class
└── SsoClientServiceProvider.php # Service provider
```

### Configuration
- **config/sso-client.php**: Package configuration including user model, preserved fields, routing
- **Environment variables**: OAuth client credentials and server host settings
- **Database migrations**: Adds OAuth-related fields to users table (oauth_id, username, oauth_data, synced_at, is_active)

### Routes & Endpoints
All routes are prefixed with `/sso` (configurable):
- `GET /sso/redirect` - OAuth authorization redirect
- `GET /sso/callback` - OAuth callback handler
- `POST /sso/logout` - SSO logout (both local and server)
- `POST /sso/local-logout` - Local logout only

### OAuth Server API Requirements
The package expects these endpoints on the OAuth server:
- `GET /api/users/search` - Search/list users
- `POST /api/users` - Create user with roles and client_scopes
- `PUT /api/users/{id}` - Update user
- `GET /api/users/{id}/sync` - Get sync data
- `GET /api/users/roles` - Available roles
- `POST /api/users/test-password-sync` - Password compatibility testing

## Development Workflow

### User Synchronization Features
The `sso:sync` command provides comprehensive user management:
- **Bidirectional sync**: Handles conflicts between client and server data
- **Role mapping**: Interactive mapping of local roles to OAuth server roles
- **Client access management**: Grant/verify access with configurable scopes
- **Password sync**: Advanced password handling with compatibility testing
- **Batch processing**: Configurable batch sizes for performance

### Security Considerations
- Uses OAuth 2.0 Client Credentials grant for API authentication
- Preserves local-only fields during sync (configured in `preserved_fields`)
- Handles password hashing compatibility between client and server
- Logs sensitive data as `[hidden]` in conflict reports

### Testing & Quality Assurance
- Package includes PHPUnit configuration for testing
- Uses Orchestra Testbench for Laravel package testing
- Supports PHP ^8.0 and Laravel ^9.0|^10.0|^11.0
- No specific linting commands configured in composer.json

## Important Notes

- This package requires Laravel Socialite and SocialiteProviders LaravelPassport
- User model must have oauth_id, username, oauth_data, synced_at, and is_active columns
- Supports optional Spatie Laravel Permission for role management
- Client Credentials authentication eliminates need for long-lived admin tokens
- Comprehensive conflict resolution for data synchronization scenarios