# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel package for OAuth client implementation using Laravel Socialite with LaravelPassport driver. It provides SSO integration, user synchronization, and role management between OAuth client and server applications.

## Multi-Version Support

This package supports multiple Laravel versions using a branching strategy:

### Branch Structure
- **main** - Laravel 12.x support (default branch)
- **11.x** - Laravel 11.x support 
- **10.x** - Laravel 10.x support
- **9.x** - Laravel 9.x support

### Version Matrix
| Laravel Version | Package Version | PHP Version | Branch | Status |
|----------------|-----------------|-------------|--------|--------|
| 12.x | ^12.0 | ^8.2 | main | Active Development |
| 11.x | ^11.0 | ^8.2 | 11.x | Active Maintenance |
| 10.x | ^10.0 | ^8.1 | 10.x | Active Maintenance |
| 9.x | ^9.0 | ^8.0 | 9.x | LTS / Security Fixes |

### Working with Branches
- **Feature development**: Use the main branch (Laravel 12.x)
- **Bug fixes**: Apply to appropriate version branch, then forward-merge if compatible
- **Security fixes**: Apply to all supported branches
- **Breaking changes**: Only in main branch for next major version

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

# Test specific Laravel version (GitHub Actions handles this automatically)
composer require "laravel/framework:^12.0" "orchestra/testbench:^10.0" --no-update
composer update && ./vendor/bin/phpunit
```

### Branch Management
```bash
# Switch between Laravel version branches
git checkout main      # Laravel 12.x
git checkout 11.x      # Laravel 11.x  
git checkout 10.x      # Laravel 10.x
git checkout 9.x       # Laravel 9.x

# Create tags for releases
git tag v12.1.0 -m "Release message"  # On main branch
git tag v11.1.0 -m "Release message"  # On 11.x branch
git tag v10.1.0 -m "Release message"  # On 10.x branch
git tag v9.1.0 -m "Release message"   # On 9.x branch
```

### **IMPORTANT: Release Management Policy**
**After every commit and push, you MUST create and push new patch version tags for all affected branches:**

```bash
# Standard workflow after any fix/change:
# 1. Commit and push to main branch
git add . && git commit -m "Fix description" && git push origin main

# 2. Apply to other branches (if applicable)
git checkout 11.x && git cherry-pick main && git push origin 11.x
git checkout 10.x && git cherry-pick main && git push origin 10.x  
git checkout 9.x && git cherry-pick main && git push origin 9.x

# 3. ALWAYS create new patch version tags after ANY change
git checkout main && git tag v12.0.X && git push origin v12.0.X
git checkout 11.x && git tag v11.0.X && git push origin v11.0.X
git checkout 10.x && git tag v10.0.X && git push origin v10.0.X
git checkout 9.x && git tag v9.0.X && git push origin v9.0.X

# Replace X with next patch number (e.g., v12.0.3 → v12.0.4)
```

**This ensures:**
- Package users get immediate access to fixes
- Proper semantic versioning is maintained
- Release history tracks all changes
- GitHub releases can be created for each tag

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