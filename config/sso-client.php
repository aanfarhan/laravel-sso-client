<?php

return [
    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | Specify the User model class that should be used for SSO authentication.
    | This model should have oauth_id, username, email, name, oauth_data, 
    | synced_at, and is_active columns.
    |
    */
    'user_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Default Role
    |--------------------------------------------------------------------------
    |
    | The default role to assign to new users created via SSO.
    | Set to null if you don't want to assign any default role.
    | This requires Spatie Laravel Permission package.
    |
    */
    'default_role' => null,

    /*
    |--------------------------------------------------------------------------
    | Field Preservation (Auto-Detected)
    |--------------------------------------------------------------------------
    |
    | The package now automatically detects which fields to preserve during
    | user synchronization by comparing local user model fields with OAuth
    | server user fields. Fields that exist locally but not on the server
    | are automatically preserved to prevent local data loss.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Redirect After Login
    |--------------------------------------------------------------------------
    |
    | Where to redirect users after successful SSO login.
    |
    */
    'redirect_after_login' => '/dashboard',

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware to apply to SSO routes.
    |
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for SSO routes.
    |
    */
    'route_prefix' => 'sso',

    /*
    |--------------------------------------------------------------------------
    | Route Names
    |--------------------------------------------------------------------------
    |
    | Custom route names for SSO endpoints.
    |
    */
    'route_names' => [
        'redirect' => 'sso.redirect',
        'callback' => 'sso.callback',
        'logout' => 'sso.logout',
        'local_logout' => 'sso.local-logout',
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Credentials Authentication
    |--------------------------------------------------------------------------
    |
    | The package now uses OAuth 2.0 Client Credentials grant for admin
    | operations like user sync. This is more secure and doesn't require
    | managing long-lived admin tokens. Authentication is handled automatically
    | using the client_id and client_secret from services.laravelpassport config.
    |
    */
];