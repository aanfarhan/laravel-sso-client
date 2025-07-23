<?php

namespace Omniglies\LaravelSsoClient\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Omniglies\LaravelSsoClient\Services\SsoUserService;
use Carbon\Carbon;

class SsoSyncCommand extends Command
{
    protected $signature = 'sso:sync 
                           {--dry-run : Show what would be synced without making changes}
                           {--force : Skip conflict prompts and use server data}
                           {--client-wins : In conflicts, use client data}
                           {--server-wins : In conflicts, use server data}
                           {--batch-size=100 : Number of users to process at once}
                           {--grant-access : Grant access to this client when creating users}
                           {--verify-access : Only sync users who have access to this client}
                           {--role-mapping : Enable interactive role mapping for users}
                           {--update-roles : Update roles for existing users during sync}
                           {--default-role= : Default role to assign to all new users}
                           {--scopes= : Set specific scopes for user access (comma-separated)}
                           {--no-scopes : Set scopes to none/empty for all users}
                           {--update-scopes : Update scopes for existing users during sync}
                           {--interactive-scopes : Enable interactive scope selection}
                           {--force-update-server : Force update existing server users with client data}
                           {--update-passwords : Sync passwords for existing users}
                           {--skip-password-sync : Skip password synchronization entirely}
                           {--test-password-sync= : Test password sync for specific user email}
                           {--validate-passwords : Validate password compatibility before sync}
                           {--show-field-detection : Show auto-detected syncable and preserved fields}
                           {--test-connection : Test OAuth server connection and permissions}
                           {--debug-user-creation : Show debug info for user creation process}';
    
    protected $description = 'Sync user data between OAuth client and server';

    protected $ssoUserService;
    protected $userModel;
    protected $conflicts = [];
    protected $syncedUsers = 0;
    protected $createdUsers = 0;
    protected $skippedUsers = 0;
    protected $clientId = null;
    protected $grantAccess = false;
    protected $verifyAccess = false;
    protected $roleMapping = false;
    protected $updateRoles = false;
    protected $defaultRole = null;
    protected $availableRoles = [];
    protected $roleMappings = [];
    protected $customScopes = null;
    protected $noScopes = false;
    protected $updateScopes = false;
    protected $interactiveScopes = false;
    protected $scopeOptions = [];
    protected $forceUpdateServer = false;
    protected $updatePasswords = false;
    protected $skipPasswordSync = false;
    protected $testPasswordSync = null;
    protected $validatePasswords = false;

    public function handle()
    {
        $this->info('🔄 Starting SSO user sync...');
        $this->newLine();

        // Initialize services
        $this->ssoUserService = new SsoUserService();
        $this->userModel = config('sso-client.user_model', 'App\\Models\\User');
        
        // Get client ID from config
        $this->clientId = config('services.laravelpassport.client_id');
        if (!$this->clientId) {
            $this->error('❌ OAuth client ID not found in config. Please set LARAVELPASSPORT_CLIENT_ID in .env');
            return 1;
        }
        
        // Set options
        $this->grantAccess = $this->option('grant-access');
        $this->verifyAccess = $this->option('verify-access');
        $this->roleMapping = $this->option('role-mapping');
        $this->updateRoles = $this->option('update-roles');
        $this->defaultRole = $this->option('default-role');
        $this->customScopes = $this->option('scopes');
        $this->noScopes = $this->option('no-scopes');
        $this->updateScopes = $this->option('update-scopes');
        $this->interactiveScopes = $this->option('interactive-scopes');
        $this->forceUpdateServer = $this->option('force-update-server');
        $this->updatePasswords = $this->option('update-passwords');
        $this->skipPasswordSync = $this->option('skip-password-sync');
        $this->testPasswordSync = $this->option('test-password-sync');
        $this->validatePasswords = $this->option('validate-passwords');

        // Client credentials are now handled automatically by SsoUserService
        // No manual token configuration needed!

        // Validate connection to OAuth server
        if (!$this->validateServerConnection()) {
            return 1;
        }

        // Show field detection information if requested
        if ($this->option('show-field-detection')) {
            $this->showFieldDetectionInfo();
            return 0;
        }

        // Test connection and permissions if requested
        if ($this->option('test-connection')) {
            $this->runConnectionTest();
            return 0;
        }

        // Show user creation debug info if requested
        if ($this->option('debug-user-creation')) {
            $this->showUserCreationDebug();
            return 0;
        }

        // Setup scope management if enabled
        if ($this->customScopes || $this->noScopes || $this->updateScopes || $this->interactiveScopes) {
            if (!$this->setupScopeManagement()) {
                return 1;
            }
        }

        // Validate password sync options
        if ($this->updatePasswords && $this->skipPasswordSync) {
            $this->error('❌ Cannot use both --update-passwords and --skip-password-sync options together');
            return 1;
        }

        // Handle password testing if requested
        if ($this->testPasswordSync) {
            return $this->runPasswordTest();
        }

        // Setup role mapping if enabled
        if ($this->roleMapping || $this->defaultRole || $this->updateRoles) {
            if (!$this->setupRoleMapping()) {
                return 1;
            }
        }

        $batchSize = (int) $this->option('batch-size');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Get all local users
        $totalUsers = $this->userModel::count();
        $this->info("📊 Found {$totalUsers} users to process");
        
        $progressBar = $this->output->createProgressBar($totalUsers);
        $progressBar->start();

        // Process users in batches
        $this->userModel::chunk($batchSize, function ($users) use ($progressBar, $isDryRun) {
            foreach ($users as $user) {
                $this->processUser($user, $isDryRun);
                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        // Show summary
        $this->showSummary($isDryRun);

        // Handle conflicts if any
        if (!empty($this->conflicts) && !$isDryRun) {
            $this->handleConflicts();
        }

        return 0;
    }

    protected function validateServerConnection(): bool
    {
        try {
            $response = $this->ssoUserService->searchUsers(['limit' => 1]);
            if ($response === null) {
                $this->error('❌ Failed to connect to OAuth server');
                $this->showConnectionTroubleshooting();
                return false;
            }
            $this->info('✅ Connected to OAuth server successfully');
            return true;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Check for specific permission errors
            if (strpos($errorMessage, '403') !== false || strpos($errorMessage, 'admin user management permissions') !== false) {
                $this->error('❌ Permission Error: Client lacks user management permissions');
                $this->showPermissionTroubleshooting();
            } else {
                $this->error('❌ OAuth server connection failed: ' . $errorMessage);
                $this->showConnectionTroubleshooting();
            }
            return false;
        }
    }

    protected function showPermissionTroubleshooting(): void
    {
        $this->newLine();
        $this->warn('🔧 Permission Issue Troubleshooting:');
        $this->newLine();
        
        $this->line('The OAuth client does not have the required permissions for user management.');
        $this->line('To fix this, you need to grant admin permissions to your OAuth client.');
        $this->newLine();
        
        $this->info('📋 Required Steps on OAuth Server:');
        $this->line('1. Update your OAuth client configuration to include admin scopes');
        $this->line('2. Ensure the client has "user-management" or "admin" scope permissions');
        $this->line('3. Verify the client_id and client_secret are correct in your .env file');
        $this->newLine();
        
        $this->info('🔑 Current OAuth Configuration:');
        $this->line('   Client ID: ' . (config('services.laravelpassport.client_id') ?: '(Not Set)'));
        $this->line('   Host: ' . (config('services.laravelpassport.host') ?: '(Not Set)'));
        $this->line('   Secret: ' . (config('services.laravelpassport.client_secret') ? '(Set)' : '(Not Set)'));
        $this->newLine();
        
        $this->info('💡 Quick Fix:');
        $this->line('Contact your OAuth server administrator to grant your client the following scopes:');
        $this->line('• user-management');
        $this->line('• admin-api');
        $this->line('• client-credentials-admin');
    }

    protected function showConnectionTroubleshooting(): void
    {
        $this->newLine();
        $this->warn('🔧 Connection Troubleshooting:');
        $this->newLine();
        
        $this->info('🔍 Check these common issues:');
        $this->line('1. Verify LARAVELPASSPORT_HOST is correctly set in .env');
        $this->line('2. Ensure LARAVELPASSPORT_CLIENT_ID and CLIENT_SECRET are valid');
        $this->line('3. Check if the OAuth server is accessible');
        $this->line('4. Verify the /api/users/search endpoint exists on the server');
        $this->newLine();
        
        $this->info('🔑 Current Configuration:');
        $this->line('   Host: ' . (config('services.laravelpassport.host') ?: '(Not Set)'));
        $this->line('   Client ID: ' . (config('services.laravelpassport.client_id') ?: '(Not Set)'));
        $this->line('   Secret: ' . (config('services.laravelpassport.client_secret') ? '(Set)' : '(Not Set)'));
    }

    protected function processUser($user, bool $isDryRun): void
    {
        try {
            // Search for user on server by email and username
            $serverUser = $this->findServerUser($user);

            if (!$serverUser) {
                // User doesn't exist on server - create it
                $this->handleUserCreation($user, $isDryRun);
            } else {
                // Check client access if verification is enabled
                if ($this->verifyAccess && !$this->hasClientAccess($serverUser)) {
                    if ($isDryRun) {
                        $this->line("  ⚠️  Would skip user {$user->email} - no access to client {$this->clientId}");
                    } else {
                        $this->line("  ⚠️  Skipping user {$user->email} - no access to client {$this->clientId}");
                    }
                    $this->skippedUsers++;
                    return;
                }
                
                // User exists on both - sync data
                $this->handleUserSync($user, $serverUser, $isDryRun);
            }
        } catch (\Exception $e) {
            $this->error("❌ Error processing user {$user->email}: " . $e->getMessage());
            $this->skippedUsers++;
        }
    }

    protected function findServerUser($user): ?array
    {
        return $this->ssoUserService->findUserByEmailOrUsername(
            $user->email, 
            $user->username ?? null
        );
    }

    protected function handleUserCreation($user, bool $isDryRun): void
    {
        if ($isDryRun) {
            $this->line("  📝 Would create user: {$user->email}");
            $this->createdUsers++;
            return;
        }

        $userData = $this->prepareUserDataForServer($user);
        
        $result = $this->ssoUserService->createUser($userData);
        
        if ($result && isset($result['success']) && $result['success'] !== false) {
            $this->createdUsers++;
            
            // Update local user with server oauth_id if provided
            if (isset($result['user']['id'])) {
                $user->update(['oauth_id' => $result['user']['id']]);
            }
        } else {
            $this->error("❌ Failed to create user {$user->email} on server");
            $this->skippedUsers++;
        }
    }

    protected function handleUserSync($user, array $serverUser, bool $isDryRun): void
    {
        $conflicts = $this->detectConflicts($user, $serverUser);
        
        // Check if roles need to be synced
        $roleSync = $this->checkRoleSync($user, $serverUser, $isDryRun);
        
        // Check if scopes need to be synced
        $scopeSync = $this->checkScopeSync($user, $serverUser, $isDryRun);
        
        // Check if force update is needed
        $forceUpdate = $this->forceUpdateServer || $this->hasForceUpdateConflicts($conflicts);
        
        if (empty($conflicts) && !$roleSync['needsUpdate'] && !$scopeSync['needsUpdate'] && !$forceUpdate) {
            // No conflicts, role updates, or scope updates - just update timestamps
            if (!$isDryRun) {
                $user->update([
                    'oauth_id' => $serverUser['id'],
                    'synced_at' => now()
                ]);
            }
            $this->syncedUsers++;
            return;
        }

        // Handle role sync if needed
        if ($roleSync['needsUpdate'] && !$isDryRun) {
            $this->syncUserRole($user, $serverUser, $roleSync['mappedRole']);
        }

        // Handle scope sync if needed
        if ($scopeSync['needsUpdate'] && !$isDryRun) {
            $this->syncUserScopes($user, $serverUser, $scopeSync['scopes']);
        }

        // Handle force update if needed
        if ($forceUpdate && !$isDryRun) {
            $this->forceUpdateServerUser($user, $serverUser, $conflicts);
        } elseif ($forceUpdate && $isDryRun) {
            $this->line("  🔄 Would force update server user: {$user->email}");
        }

        // Handle conflicts based on options (if not force updating)
        $resolution = !$forceUpdate ? $this->resolveConflicts($user, $serverUser, $conflicts, $isDryRun) : 'client';
        
        if ($resolution && !$isDryRun) {
            $this->applySyncResolution($user, $serverUser, $resolution);
            $this->syncedUsers++;
        } elseif (empty($conflicts) && ($roleSync['needsUpdate'] || $scopeSync['needsUpdate'])) {
            // No conflicts but had role or scope update
            $this->syncedUsers++;
        } else {
            $this->conflicts[] = [
                'user' => $user,
                'server_user' => $serverUser,
                'conflicts' => $conflicts
            ];
        }
    }

    protected function detectConflicts($user, array $serverUser): array
    {
        $conflicts = [];
        $syncableFields = $this->getSyncableFields();

        foreach ($syncableFields as $field) {
            if (!isset($serverUser[$field])) {
                continue;
            }

            $clientValue = $user->{$field};
            $serverValue = $serverUser[$field];

            // Skip if values are the same
            if ($clientValue == $serverValue) {
                continue;
            }

            // Special handling for password
            if ($field === 'password') {
                // Skip password sync entirely if option is set
                if ($this->skipPasswordSync) {
                    continue;
                }
                
                // Compare password hash differences - both client and server passwords are hashed
                // We can't directly compare hashes since they may use different salts/algorithms
                // So we treat any non-null password difference as a potential conflict
                if ($user->password && $serverUser['password'] && 
                    $user->password !== $serverUser['password']) {
                    
                    // Check if force update server is enabled
                    if ($this->forceUpdateServer || $this->updatePasswords) {
                        // Mark for forced update instead of conflict
                        $conflicts['_password_force_update'] = [
                            'client' => '[password will be updated on server]',
                            'server' => '[password will be overwritten]',
                            'client_updated' => $user->updated_at,
                            'server_updated' => $serverUser['updated_at'] ?? null,
                            'force_update' => true
                        ];
                    } else {
                        // Mark as conflict for user resolution
                        $conflicts[$field] = [
                            'client' => '[hidden - client password]',
                            'server' => '[hidden - server password]',
                            'client_updated' => $user->updated_at,
                            'server_updated' => $serverUser['updated_at'] ?? null
                        ];
                    }
                }
                continue;
            }

            $conflicts[$field] = [
                'client' => $clientValue,
                'server' => $serverValue,
                'client_updated' => $user->updated_at,
                'server_updated' => $serverUser['updated_at'] ?? null
            ];
        }

        return $conflicts;
    }

    protected function resolveConflicts($user, array $serverUser, array $conflicts, bool $isDryRun): ?string
    {
        // Check command options for automatic resolution
        if ($this->option('force') || $this->option('server-wins')) {
            return 'server';
        }
        
        if ($this->option('client-wins')) {
            return 'client';
        }

        // For dry run, just log the conflict
        if ($isDryRun) {
            $this->line("  ⚠️  Conflict detected for user: {$user->email}");
            foreach ($conflicts as $field => $conflict) {
                $this->line("    - {$field}: client='{$conflict['client']}' server='{$conflict['server']}'");
            }
            return null;
        }

        // Interactive resolution would be handled in handleConflicts()
        return null;
    }

    protected function applySyncResolution($user, array $serverUser, string $resolution): void
    {
        if ($resolution === 'server') {
            // Update client with server data
            $updateData = [];
            $syncableFields = $this->getSyncableFields();
            
            foreach ($syncableFields as $field) {
                if (isset($serverUser[$field]) && $field !== 'password') {
                    $updateData[$field] = $serverUser[$field];
                }
            }
            
            $updateData['oauth_id'] = $serverUser['id'];
            $updateData['synced_at'] = now();
            
            $user->update($updateData);
            
        } elseif ($resolution === 'client') {
            // Update server with client data
            $userData = $this->prepareUserDataForServer($user);
            $this->ssoUserService->updateUser($serverUser['id'], $userData);
            
            $user->update([
                'oauth_id' => $serverUser['id'],
                'synced_at' => now()
            ]);
        }
    }

    protected function handleConflicts(): void
    {
        if (empty($this->conflicts)) {
            return;
        }

        $this->newLine();
        $this->warn("⚠️  Found " . count($this->conflicts) . " conflicts that need resolution:");
        $this->newLine();

        foreach ($this->conflicts as $index => $conflict) {
            $user = $conflict['user'];
            $this->info("Conflict #" . ($index + 1) . " - User: {$user->email}");
            
            foreach ($conflict['conflicts'] as $field => $details) {
                $this->line("  Field: {$field}");
                $this->line("    Client: {$details['client']} (updated: {$details['client_updated']})");
                $this->line("    Server: {$details['server']} (updated: {$details['server_updated']})");
            }

            $choice = $this->choice(
                'How would you like to resolve this conflict?',
                [
                    'client' => 'Use client data (update server)',
                    'server' => 'Use server data (update client)',
                    'skip' => 'Skip this user'
                ],
                'skip'
            );

            if ($choice !== 'skip') {
                $this->applySyncResolution($user, $conflict['server_user'], $choice);
                $this->syncedUsers++;
            } else {
                $this->skippedUsers++;
            }

            $this->newLine();
        }
    }

    protected function prepareUserDataForServer($user): array
    {
        $userData = [];
        $syncableFields = $this->getSyncableFields();

        foreach ($syncableFields as $field) {
            if ($user->{$field} !== null) {
                // Special handling for password field
                if ($field === 'password') {
                    // Skip password if password sync is disabled
                    if ($this->skipPasswordSync) {
                        continue;
                    }
                    // Always include password for new users, or when specifically updating passwords
                    $userData[$field] = $user->{$field}; // Send the hashed password as-is
                } else {
                    $userData[$field] = $user->{$field};
                }
            }
        }

        // Grant access to this client when creating users
        if ($this->grantAccess) {
            $userData['grant_client_access'] = true;
        }
        
        // Add client app identifier for access control
        $userData['client_apps'] = [config('app.name', 'default')];

        // Add role mapping if configured
        $mappedRole = $this->mapUserRole($user);
        if ($mappedRole) {
            $userData['roles'] = [$mappedRole];
        }

        // Add scope configuration if set
        if ($this->customScopes !== null || $this->noScopes || $this->interactiveScopes) {
            $userData['client_scopes'] = $this->scopeOptions;
        }

        return $userData;
    }

    protected function getSyncableFields(): array
    {
        $userModel = new $this->userModel;
        $userTableColumns = Schema::getColumnListing($userModel->getTable());
        
        // Get server user fields by making a sample request to understand the server's user structure
        $serverFields = $this->getServerUserFields();
        
        // Standard OAuth/SSO fields that should always be synced if they exist on both sides
        $standardSyncFields = [
            'name', 'email', 'username', 'email_verified_at', 
            'password', 'phone', 'avatar'
        ];
        
        // Fields that are typically local-only and should be preserved
        $typicalLocalFields = [
            'id', 'created_at', 'updated_at', 'remember_token', 'oauth_id', 
            'oauth_data', 'synced_at', 'is_active', 'deleted_at'
        ];
        
        // Auto-detect syncable fields: fields that exist on both client and server
        $syncableFields = [];
        if ($serverFields) {
            foreach ($userTableColumns as $field) {
                // Skip typical local-only fields
                if (in_array($field, $typicalLocalFields)) {
                    continue;
                }
                
                // Include if field exists on server (suggesting it's meant to be synced)
                if (in_array($field, $serverFields)) {
                    $syncableFields[] = $field;
                }
                
                // Always include standard sync fields if they exist locally
                if (in_array($field, $standardSyncFields)) {
                    $syncableFields[] = $field;
                }
            }
        } else {
            // Fallback to standard fields if we can't determine server structure
            $syncableFields = array_intersect($standardSyncFields, $userTableColumns);
        }

        return array_unique($syncableFields);
    }
    
    protected function getServerUserFields(): ?array
    {
        try {
            // Get a sample user from server to understand field structure
            $response = $this->ssoUserService->searchUsers(['limit' => 1]);
            
            if ($response && isset($response['data']) && count($response['data']) > 0) {
                $sampleUser = $response['data'][0];
                return array_keys($sampleUser);
            }
            
            return null;
        } catch (\Exception $e) {
            // If we can't get server structure, return null to use fallback
            return null;
        }
    }

    protected function hasClientAccess(array $serverUser): bool
    {
        try {
            // Check if user has access to this client via the OAuth server API
            $response = $this->ssoUserService->searchUsers([
                'email' => $serverUser['email'],
                'client_id' => $this->clientId,
                'check_access' => true,
                'paginate' => 'false',
                'limit' => 1
            ]);

            return $response && isset($response['data']) && count($response['data']) > 0;
        } catch (\Exception $e) {
            $this->warn("⚠️  Could not verify client access for {$serverUser['email']}: " . $e->getMessage());
            return true; // Default to allowing if we can't verify
        }
    }

    protected function setupRoleMapping(): bool
    {
        try {
            // Get available roles from OAuth server
            $rolesResponse = $this->ssoUserService->getRoles();
            
            if (!$rolesResponse || !isset($rolesResponse['roles'])) {
                $this->error('❌ Failed to get roles from OAuth server');
                return false;
            }
            
            $this->availableRoles = collect($rolesResponse['roles'])->keyBy('name');
            
            if ($this->defaultRole) {
                // Validate default role exists
                if (!$this->availableRoles->has($this->defaultRole)) {
                    $this->error("❌ Default role '{$this->defaultRole}' not found on OAuth server");
                    $this->line("Available roles: " . $this->availableRoles->pluck('name')->implode(', '));
                    return false;
                }
                
                $this->info("✅ Default role set: {$this->defaultRole}");
            }
            
            if ($this->roleMapping || $this->updateRoles) {
                $this->info('🎭 Interactive role mapping enabled');
                $this->setupInteractiveRoleMapping();
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->error('❌ Failed to setup role mapping: ' . $e->getMessage());
            return false;
        }
    }

    protected function setupScopeManagement(): bool
    {
        try {
            // Validate scope options
            if ($this->noScopes && $this->customScopes) {
                $this->error('❌ Cannot use both --no-scopes and --scopes options together');
                return false;
            }

            if ($this->noScopes) {
                $this->scopeOptions = [];
                $this->info('✅ Scopes set to none (empty array)');
                return true;
            }

            if ($this->customScopes) {
                $this->scopeOptions = array_map('trim', explode(',', $this->customScopes));
                $this->info('✅ Custom scopes set: ' . implode(', ', $this->scopeOptions));
                return true;
            }

            if ($this->interactiveScopes) {
                return $this->setupInteractiveScopeSelection();
            }

            return true;
        } catch (\Exception $e) {
            $this->error('❌ Failed to setup scope management: ' . $e->getMessage());
            return false;
        }
    }

    protected function setupInteractiveScopeSelection(): bool
    {
        $this->info('🔐 Interactive scope selection enabled');
        $this->newLine();

        $availableScopes = [
            'read' => 'Read access to user data',
            'write' => 'Write access to modify data',
            'admin' => 'Administrative privileges',
            'profile' => 'Access to user profile information',
            'email' => 'Access to user email',
            'roles' => 'Access to user roles and permissions'
        ];

        $this->info('📋 Available scopes:');
        foreach ($availableScopes as $scope => $description) {
            $this->line("  • {$scope} - {$description}");
        }

        $this->newLine();

        $choices = ['none' => 'No scopes (empty access)'];
        foreach ($availableScopes as $scope => $description) {
            $choices[$scope] = "{$scope} - {$description}";
        }

        $selectedScopes = $this->choice(
            'Select scopes to assign (comma-separated for multiple):',
            $choices,
            'none'
        );

        if ($selectedScopes === 'none') {
            $this->scopeOptions = [];
            $this->info('✅ Selected: No scopes');
        } else {
            $this->scopeOptions = array_map('trim', explode(',', $selectedScopes));
            $this->info('✅ Selected scopes: ' . implode(', ', $this->scopeOptions));
        }

        return true;
    }

    protected function setupInteractiveRoleMapping(): void
    {
        $this->info('');
        $this->info('📋 Available roles on OAuth server:');
        
        foreach ($this->availableRoles as $role) {
            $this->line("  • {$role['name']} ({$role['display_name']}) - {$role['description']}");
        }
        
        $this->newLine();
        
        // Get unique local roles from users that will be synced
        $localRoles = $this->getLocalUserRoles();
        
        if (!empty($localRoles)) {
            $this->info('🗂️  Local user roles found:');
            foreach ($localRoles as $role) {
                $this->line("  • {$role}");
            }
            
            $this->newLine();
            
            // Pre-map ALL local roles now instead of during processing
            $this->info('🎯 Setting up role mappings for all local roles:');
            $this->preMapAllRoles($localRoles);
        }
    }

    protected function preMapAllRoles(array $localRoles): void
    {
        foreach ($localRoles as $localRole) {
            $this->newLine();
            $this->info("🎭 Mapping local role: '{$localRole}'");
            
            $choices = ['skip' => 'Skip (no role assigned)'];
            foreach ($this->availableRoles as $role) {
                $choices[$role['name']] = "{$role['name']} ({$role['display_name']})";
            }
            
            $mapping = $this->choice(
                'Map to OAuth server role:',
                $choices,
                'skip'
            );
            
            // Store the mapping
            $this->roleMappings[$localRole] = $mapping;
            
            if ($mapping === 'skip') {
                $this->line("  → '{$localRole}' will be skipped (no role assigned)");
            } else {
                $this->line("  → '{$localRole}' mapped to '{$mapping}'");
            }
        }
        
        $this->newLine();
        $this->info('✅ All role mappings configured!');
    }

    protected function getLocalUserRoles(): array
    {
        $roles = [];
        $userModel = new $this->userModel;
        
        // Check if users have Spatie roles (preferred, more descriptive)
        if (method_exists($userModel, 'getRoleNames')) {
            try {
                $spatieRoles = $this->userModel::with('roles')
                    ->get()
                    ->pluck('roles')
                    ->flatten()
                    ->pluck('name')
                    ->unique()
                    ->toArray();
                    
                $roles = array_merge($roles, $spatieRoles);
            } catch (\Exception $e) {
                // Ignore if Spatie roles not properly configured
            }
        }
        
        // Try to get roles from role fields if Spatie roles not available or empty
        if (empty($roles)) {
            $roleFields = ['role', 'user_role', 'role_name', 'id_role'];
            
            foreach ($roleFields as $field) {
                if (Schema::hasColumn($userModel->getTable(), $field)) {
                    $fieldRoles = $this->userModel::distinct()
                        ->whereNotNull($field)
                        ->where($field, '!=', '')
                        ->pluck($field)
                        ->map(function ($role) {
                            return (string) $role;
                        })
                        ->toArray();
                        
                    $roles = array_merge($roles, $fieldRoles);
                    break;
                }
            }
        }
        
        return array_unique(array_filter($roles));
    }

    protected function mapUserRole($user): ?string
    {
        // Get the user's local role
        $userRole = $this->getUserRole($user);
        
        // If user has a role and we have a mapping for it, use the mapping
        if ($userRole && isset($this->roleMappings[$userRole])) {
            return $this->roleMappings[$userRole] === 'skip' ? null : $this->roleMappings[$userRole];
        }
        
        // Fall back to default role if configured
        if ($this->defaultRole) {
            return $this->defaultRole;
        }
        
        // No role assignment
        return null;
    }

    protected function getUserRole($user): ?string
    {
        // Try Spatie roles first (more descriptive)
        if (method_exists($user, 'getRoleNames')) {
            try {
                $roles = $user->getRoleNames();
                if ($roles->isNotEmpty()) {
                    return $roles->first();
                }
            } catch (\Exception $e) {
                // Ignore if not available
            }
        }
        
        // Try different possible role fields
        $roleFields = ['role', 'user_role', 'role_name', 'id_role'];
        
        foreach ($roleFields as $field) {
            if (isset($user->{$field}) && $user->{$field}) {
                return (string) $user->{$field};
            }
        }
        
        return null;
    }

    protected function checkRoleSync($user, array $serverUser, bool $isDryRun): array
    {
        // Only check role sync if we have role mapping or update-roles is enabled
        if (!$this->roleMapping && !$this->updateRoles) {
            return ['needsUpdate' => false, 'mappedRole' => null];
        }
        
        $mappedRole = $this->mapUserRole($user);
        
        if (!$mappedRole) {
            return ['needsUpdate' => false, 'mappedRole' => null];
        }
        
        // Check if the user already has this role on the server
        // Note: We would need to get user roles from server, but for now we'll assume it needs updating
        // This could be enhanced by getting current user roles from the server
        
        if ($isDryRun) {
            $this->line("  🎭 Would update role for {$user->email}: {$mappedRole}");
        }
        
        return ['needsUpdate' => true, 'mappedRole' => $mappedRole];
    }

    protected function syncUserRole($user, array $serverUser, string $role): void
    {
        try {
            // Update user roles on server
            $userData = ['roles' => [$role]];
            $response = $this->ssoUserService->updateUser($serverUser['id'], $userData);
            
            if ($response && isset($response['success']) && $response['success'] !== false) {
                $this->line("  ✅ Updated role for {$user->email}: {$role}");
            } else {
                $this->warn("  ⚠️  Failed to update role for {$user->email}");
            }
        } catch (\Exception $e) {
            $this->warn("  ⚠️  Role update error for {$user->email}: " . $e->getMessage());
        }
    }

    protected function checkScopeSync($user, array $serverUser, bool $isDryRun): array
    {
        // Only check scope sync if we have scope management enabled
        if (!$this->updateScopes && !$this->customScopes && !$this->noScopes && !$this->interactiveScopes) {
            return ['needsUpdate' => false, 'scopes' => null];
        }

        if ($isDryRun) {
            $scopeDisplay = empty($this->scopeOptions) ? 'none' : implode(', ', $this->scopeOptions);
            $this->line("  🔐 Would update scopes for {$user->email}: [{$scopeDisplay}]");
        }

        return ['needsUpdate' => true, 'scopes' => $this->scopeOptions];
    }

    protected function syncUserScopes($user, array $serverUser, array $scopes): void
    {
        try {
            // Update user client access scopes on server
            $userData = ['client_scopes' => $scopes];
            $response = $this->ssoUserService->updateUser($serverUser['id'], $userData);

            if ($response && isset($response['success']) && $response['success'] !== false) {
                $scopeDisplay = empty($scopes) ? 'none' : implode(', ', $scopes);
                $this->line("  ✅ Updated scopes for {$user->email}: [{$scopeDisplay}]");
            } else {
                $this->warn("  ⚠️  Failed to update scopes for {$user->email}");
            }
        } catch (\Exception $e) {
            $this->warn("  ⚠️  Scope update error for {$user->email}: " . $e->getMessage());
        }
    }

    protected function hasForceUpdateConflicts(array $conflicts): bool
    {
        return isset($conflicts['_password_force_update']) && $conflicts['_password_force_update']['force_update'] === true;
    }

    protected function forceUpdateServerUser($user, array $serverUser, array $conflicts): void
    {
        try {
            // Prepare all user data for server update (force client-wins approach)
            $userData = $this->prepareUserDataForServer($user);
            
            // Remove client-specific fields that shouldn't be sent to server
            unset($userData['grant_client_access'], $userData['client_apps']);
            
            // Force update the user on server
            $response = $this->ssoUserService->updateUser($serverUser['id'], $userData);
            
            if ($response && isset($response['success']) && $response['success'] !== false) {
                $this->line("  ✅ Force updated server user: {$user->email}");
                
                // Show what was updated
                if ($this->hasForceUpdateConflicts($conflicts)) {
                    $this->line("    🔒 Password updated on server");
                }
                
                // Update local user with server sync info
                $user->update([
                    'oauth_id' => $serverUser['id'],
                    'synced_at' => now()
                ]);
                
                $this->syncedUsers++;
            } else {
                $this->warn("  ⚠️  Failed to force update user {$user->email} on server");
                $this->skippedUsers++;
            }
        } catch (\Exception $e) {
            $this->warn("  ⚠️  Force update error for {$user->email}: " . $e->getMessage());
            $this->skippedUsers++;
        }
    }

    protected function showSummary(bool $isDryRun): void
    {
        $this->info('📊 Sync Summary:');
        $this->line("  Client ID: {$this->clientId}");
        $this->line("  Created: {$this->createdUsers} users");
        $this->line("  Synced: {$this->syncedUsers} users");
        $this->line("  Conflicts: " . count($this->conflicts) . " users");
        $this->line("  Skipped: {$this->skippedUsers} users");
        
        if ($this->grantAccess) {
            $this->line("  🔑 Access granted to new users for this client");
        }
        
        if ($this->verifyAccess) {
            $this->line("  ✅ Only synced users with client access");
        }
        
        if ($this->roleMapping) {
            $this->line("  🎭 Role mapping enabled");
        }
        
        if ($this->updateRoles) {
            $this->line("  🔄 Role updates enabled for existing users");
        }
        
        if ($this->defaultRole) {
            $this->line("  👤 Default role: {$this->defaultRole}");
        }

        // Show scope information
        if ($this->customScopes || $this->noScopes || $this->interactiveScopes) {
            $scopeDisplay = empty($this->scopeOptions) ? 'none' : implode(', ', $this->scopeOptions);
            $this->line("  🔐 Client scopes: [{$scopeDisplay}]");
        }
        
        if ($this->updateScopes) {
            $this->line("  🔄 Scope updates enabled for existing users");
        }

        // Show password sync information
        if ($this->forceUpdateServer) {
            $this->line("  💪 Force update server enabled - client data will overwrite server");
        }
        
        if ($this->updatePasswords) {
            $this->line("  🔒 Password updates enabled for existing users");
        }
        
        if ($this->skipPasswordSync) {
            $this->line("  ⚠️  Password synchronization disabled");
        }
        
        if (!empty($this->roleMappings)) {
            $this->newLine();
            $this->info('📋 Role mappings used:');
            foreach ($this->roleMappings as $local => $oauth) {
                if ($oauth === 'skip') {
                    $this->line("  • {$local} → (skipped)");
                } else {
                    $this->line("  • {$local} → {$oauth}");
                }
            }
        }
        
        if ($isDryRun) {
            $this->newLine();
            $this->warn('💡 Run without --dry-run to apply these changes');
        }
    }

    protected function runPasswordTest(): int
    {
        $this->info('🔍 Testing password synchronization...');
        $this->newLine();

        // Find the user
        $user = $this->userModel::where('email', $this->testPasswordSync)->first();
        if (!$user) {
            $this->error("❌ User with email '{$this->testPasswordSync}' not found");
            return 1;
        }

        $this->info("📧 Testing password sync for: {$user->email}");
        $this->line("👤 User ID: {$user->id}");
        $this->line("📅 Last updated: {$user->updated_at}");
        $this->newLine();

        // Test with hashed password
        $this->info('🔒 Testing with hashed password...');
        $hashedResult = $this->ssoUserService->testPasswordSync($user->email, $user->password, true);
        
        if ($hashedResult) {
            $this->displayPasswordTestResults($hashedResult, 'Hashed Password Test');
        } else {
            $this->error('❌ Failed to test hashed password');
        }

        $this->newLine();

        // Ask for plain text password to test
        if ($this->confirm('Would you like to test with a plain text password?')) {
            $plainPassword = $this->secret('Enter the plain text password for this user:');
            
            if ($plainPassword) {
                $this->info('🔓 Testing with plain text password...');
                $plainResult = $this->ssoUserService->testPasswordSync($user->email, $plainPassword, false);
                
                if ($plainResult) {
                    $this->displayPasswordTestResults($plainResult, 'Plain Text Password Test');
                } else {
                    $this->error('❌ Failed to test plain text password');
                }
            }
        }

        return 0;
    }

    protected function displayPasswordTestResults(array $results, string $testType): void
    {
        $this->newLine();
        $this->info("📋 {$testType} Results:");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // Display password check results
        if (isset($results['password_check_results'])) {
            foreach ($results['password_check_results'] as $testName => $result) {
                $status = $result['success'] ? '✅' : '❌';
                $this->line("{$status} {$result['method']}: " . ($result['success'] ? 'PASS' : 'FAIL'));
                $this->line("   {$result['description']}");
                if (isset($result['error'])) {
                    $this->line("   Error: {$result['error']}");
                }
                $this->newLine();
            }
        }

        // Display stored password info
        if (isset($results['stored_password_info'])) {
            $info = $results['stored_password_info'];
            $this->info('📊 OAuth Server Password Information:');
            $this->line("   Is Hashed: " . ($info['is_hashed'] ? 'Yes' : 'No'));
            $this->line("   Algorithm: {$info['hash_algorithm']}");
            $this->line("   Hash Preview: {$info['hash_prefix']}");
            $this->newLine();
        }

        // Display provided password info
        if (isset($results['provided_password_info'])) {
            $info = $results['provided_password_info'];
            $this->info('🔍 Provided Password Information:');
            $this->line("   Is Hashed: " . ($info['is_hashed'] ? 'Yes' : 'No'));
            $this->line("   Algorithm: {$info['hash_algorithm']}");
            $this->line("   Hash Preview: {$info['hash_prefix']}");
            $this->newLine();
        }

        // Provide recommendations
        $this->info('💡 Recommendations:');
        if (isset($results['password_check_results']['direct_check']) && 
            $results['password_check_results']['direct_check']['success']) {
            $this->line("   ✅ Passwords are compatible - sync will work correctly");
        } elseif (isset($results['password_check_results']['hash_comparison']) && 
                  $results['password_check_results']['hash_comparison']['success']) {
            $this->line("   ✅ Hashes match exactly - direct hash sync is safe");
        } else {
            $this->line("   ⚠️  Password compatibility issues detected:");
            $this->line("   • Consider using --skip-password-sync to avoid sync issues");
            $this->line("   • Or manually reset passwords on OAuth server after sync");
            $this->line("   • Check if both systems use the same hashing algorithm");
        }
    }

    protected function showFieldDetectionInfo(): void
    {
        $this->info('🔍 Field Detection Analysis');
        $this->newLine();

        $userModel = new $this->userModel;
        $userTableColumns = Schema::getColumnListing($userModel->getTable());
        $serverFields = $this->getServerUserFields();
        $syncableFields = $this->getSyncableFields();

        $this->info('📋 Local User Model Fields:');
        foreach ($userTableColumns as $field) {
            $this->line("  • {$field}");
        }
        $this->newLine();

        if ($serverFields) {
            $this->info('🌐 Server User Fields:');
            foreach ($serverFields as $field) {
                $this->line("  • {$field}");
            }
            $this->newLine();

            $this->info('🔄 Auto-Detected Syncable Fields:');
            foreach ($syncableFields as $field) {
                $this->line("  • {$field}");
            }
            $this->newLine();

            // Show preserved fields
            $preservedFields = array_diff($userTableColumns, $syncableFields);
            $this->info('🔒 Auto-Preserved Fields (Local-Only):');
            foreach ($preservedFields as $field) {
                $this->line("  • {$field}");
            }
            $this->newLine();

            $this->info('💡 Field Detection Logic:');
            $this->line('  • Fields that exist on both client and server are syncable');
            $this->line('  • Standard OAuth fields (name, email, username, etc.) are always syncable');
            $this->line('  • Fields that exist only locally are automatically preserved');
            $this->line('  • System fields (id, timestamps, tokens) are always preserved');
        } else {
            $this->warn('⚠️  Could not retrieve server user fields');
            $this->line('  Using fallback syncable fields:');
            foreach ($syncableFields as $field) {
                $this->line("  • {$field}");
            }
        }
    }

    protected function runConnectionTest(): void
    {
        $this->info('🔍 OAuth Server Connection & Permission Test');
        $this->newLine();

        // Test 1: Configuration Check
        $this->info('1️⃣ Configuration Check:');
        $host = config('services.laravelpassport.host');
        $clientId = config('services.laravelpassport.client_id');
        $clientSecret = config('services.laravelpassport.client_secret');

        $this->line("   Host: " . ($host ?: '❌ Not Set'));
        $this->line("   Client ID: " . ($clientId ?: '❌ Not Set'));
        $this->line("   Client Secret: " . ($clientSecret ? '✅ Set' : '❌ Not Set'));
        $this->newLine();

        if (!$host || !$clientId || !$clientSecret) {
            $this->error('❌ Configuration incomplete. Please set all required environment variables.');
            return;
        }

        // Test 2: OAuth Token Request
        $this->info('2️⃣ OAuth Token Request Test:');
        try {
            $token = $this->ssoUserService->clientCredentials->getAccessToken();
            if ($token) {
                $this->line('   ✅ Successfully obtained access token');
            } else {
                $this->line('   ❌ Failed to obtain access token');
                return;
            }
        } catch (\Exception $e) {
            $this->line('   ❌ Token request failed: ' . $e->getMessage());
            return;
        }
        $this->newLine();

        // Test 3: API Endpoints Test
        $this->info('3️⃣ API Endpoints Test:');
        
        // Test user search endpoint
        try {
            $response = $this->ssoUserService->searchUsers(['limit' => 1]);
            if ($response !== null) {
                $this->line('   ✅ /api/users/search - Accessible');
            } else {
                $this->line('   ❌ /api/users/search - Failed');
            }
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '403') !== false) {
                $this->line('   ❌ /api/users/search - Permission Denied (403)');
            } else {
                $this->line('   ❌ /api/users/search - Error: ' . $e->getMessage());
            }
        }

        // Test roles endpoint
        try {
            $response = $this->ssoUserService->getRoles();
            if ($response !== null) {
                $this->line('   ✅ /api/users/roles - Accessible');
            } else {
                $this->line('   ❌ /api/users/roles - Failed');
            }
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), '403') !== false) {
                $this->line('   ❌ /api/users/roles - Permission Denied (403)');
            } else {
                $this->line('   ❌ /api/users/roles - Error: ' . $e->getMessage());
            }
        }
        $this->newLine();

        // Test 4: Permission Summary
        $this->info('4️⃣ Permission Summary:');
        $this->line('   If you see permission denied (403) errors above, your OAuth client');
        $this->line('   needs to be granted admin/user-management permissions on the server.');
        $this->newLine();

        $this->info('💡 Next Steps:');
        $this->line('   • If all tests pass: You can run sso:sync normally');
        $this->line('   • If 403 errors: Contact your OAuth server admin for permissions');
        $this->line('   • If token fails: Check your client_id and client_secret');
        $this->line('   • If connection fails: Verify the host URL');
    }

    protected function showUserCreationDebug(): void
    {
        $this->info('🔍 User Creation Debug Information');
        $this->newLine();

        $userModel = new $this->userModel;
        $userTableColumns = \Illuminate\Support\Facades\Schema::getColumnListing($userModel->getTable());

        $this->info('1️⃣ User Model Information:');
        $this->line("   Model: {$this->userModel}");
        $this->line("   Table: {$userModel->getTable()}");
        $this->newLine();

        $this->info('2️⃣ Table Columns:');
        foreach ($userTableColumns as $column) {
            $this->line("   • {$column}");
        }
        $this->newLine();

        $this->info('3️⃣ UUID Field Detection:');
        $uuidFields = ['uuid', 'user_uuid', 'guid', 'user_guid'];
        $foundUuidFields = array_intersect($uuidFields, $userTableColumns);
        
        if (!empty($foundUuidFields)) {
            $this->line('   Found UUID fields (will auto-generate during user creation):');
            foreach ($foundUuidFields as $field) {
                $this->line("   • {$field}");
            }
        } else {
            $this->line('   ✅ No UUID fields found - no automatic UUID generation needed');
        }
        $this->newLine();

        $this->info('4️⃣ User Creation Strategy:');
        $this->line('   ✅ Simplified approach - no constraint checking required');
        $this->line('   ✅ Auto-generates UUIDs for any UUID field that exists');
        $this->line('   ✅ Works without Doctrine DBAL dependency');
        $this->newLine();

        $this->info('💡 User Creation Process:');
        $this->line('   • Automatically generates UUIDs for any UUID field found in the user table');
        $this->line('   • Simple and reliable - no complex constraint analysis needed');
        $this->line('   • Prevents constraint violations during OAuth user creation');
        $this->line('   • Check the logs for "Auto-generated UUID" messages during login');
    }
}