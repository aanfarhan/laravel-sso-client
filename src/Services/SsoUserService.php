<?php

namespace Omniglies\LaravelSsoClient\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SsoUserService
{
    protected $oauthHost;
    protected $accessToken;
    protected $userModel;
    protected $clientCredentials;

    public function __construct()
    {
        $this->oauthHost = config('services.laravelpassport.host');
        $this->userModel = config('sso-client.user_model', 'App\\Models\\User');
        $this->clientCredentials = new ClientCredentialsService();
    }

    public function withToken(string $token): self
    {
        $this->accessToken = $token;
        return $this;
    }

    public function syncUserFromOAuth($oauthUser)
    {
        try {
            $enhancedUserData = $this->getEnhancedUserData($oauthUser->token);
            
            $userData = $enhancedUserData ?: [
                'oauth_id' => $oauthUser->id,
                'username' => $oauthUser->nickname ?? $oauthUser->email,
                'email' => $oauthUser->email,
                'name' => $oauthUser->name,
                'oauth_data' => [
                    'id' => $oauthUser->getId(),
                    'email' => $oauthUser->getEmail(),
                    'name' => $oauthUser->getName(),
                    'nickname' => $oauthUser->getNickname(),
                    'avatar' => $oauthUser->getAvatar(),
                    'raw' => $oauthUser->getRaw()
                ],
                'synced_at' => now(),
            ];

            $user = $this->userModel::where('email', $userData['email'])
                       ->orWhere('oauth_id', $userData['oauth_id'])
                       ->first();

            if ($user) {
                // Auto-detect fields to preserve by comparing with OAuth data structure
                $preservedFields = $this->getAutoPreservedFields($userData, $user);
                
                $updateData = array_diff_key($userData, array_flip($preservedFields));
                $user->update($updateData);
                
                Log::info('Existing user synced from OAuth', [
                    'user_id' => $user->id, 
                    'oauth_id' => $userData['oauth_id']
                ]);
            } else {
                // Prepare user data with auto-generated UUIDs if needed
                $userData = $this->prepareUserDataForCreation($userData);
                $user = $this->userModel::create($userData);
                
                if (config('sso-client.default_role') && method_exists($user, 'assignRole')) {
                    $user->assignRole(config('sso-client.default_role'));
                }
                
                Log::info('New user created from OAuth', [
                    'user_id' => $user->id, 
                    'oauth_id' => $userData['oauth_id'],
                    'default_role' => config('sso-client.default_role')
                ]);
            }
            
            return $user;

        } catch (\Exception $e) {
            Log::error('Failed to sync user from OAuth', [
                'error' => $e->getMessage(),
                'oauth_user' => [
                    'id' => $oauthUser->getId(),
                    'email' => $oauthUser->getEmail(),
                    'name' => $oauthUser->getName(),
                    'nickname' => $oauthUser->getNickname(),
                    'avatar' => $oauthUser->getAvatar()
                ]
            ]);
            
            return $this->userModel::updateOrCreate(
                ['email' => $oauthUser->email],
                [
                    'oauth_id' => $oauthUser->id,
                    'username' => $oauthUser->nickname ?? $oauthUser->email,
                    'email' => $oauthUser->email,
                    'name' => $oauthUser->name,
                    'synced_at' => now(),
                ]
            );
        }
    }

    protected function getEnhancedUserData(?string $token): ?array
    {
        if (!$token) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->get($this->oauthHost . '/api/users/me');

            if ($response->successful()) {
                $userData = $response->json();
                return [
                    'oauth_id' => $userData['id'],
                    'username' => $userData['username'],
                    'email' => $userData['email'],
                    'name' => $userData['name'],
                    'oauth_data' => $userData,
                    'synced_at' => now(),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get enhanced user data', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    public function searchUsers(array $params = []): ?array
    {
        try {
            if ($this->accessToken) {
                // Use provided access token (for user-based requests)
                $response = Http::withToken($this->accessToken)
                    ->get($this->oauthHost . '/api/users/search', $params);
            } else {
                // Use client credentials for admin-level requests
                $response = $this->clientCredentials->makeRequest(
                    'GET', 
                    $this->oauthHost . '/api/users/search',
                    $params
                );
            }

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to search users', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            // Throw specific exceptions for permission issues
            if ($response->status() === 403) {
                $bodyContent = $response->body();
                throw new \Exception("HTTP 403: $bodyContent");
            }

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('User search HTTP error', [
                'error' => $e->getMessage()
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('User search API error', [
                'error' => $e->getMessage()
            ]);
            throw $e; // Re-throw other exceptions so they can be handled properly
        }
        
        return null;
    }

    /**
     * Find user on server by email or username
     */
    public function findUserByEmailOrUsername(string $email, ?string $username = null): ?array
    {
        try {
            // Search by email first
            if ($this->accessToken) {
                $response = Http::withToken($this->accessToken)
                    ->get($this->oauthHost . '/api/users/search', [
                        'email' => $email,
                        'paginate' => 'false',
                        'limit' => 1
                    ]);
            } else {
                $response = $this->clientCredentials->makeRequest(
                    'GET',
                    $this->oauthHost . '/api/users/search',
                    [
                        'email' => $email,
                        'paginate' => 'false',
                        'limit' => 1
                    ]
                );
            }

            if ($response->successful()) {
                $result = $response->json();
                if (isset($result['data']) && count($result['data']) > 0) {
                    return $result['data'][0];
                }
            }

            // If not found by email and username provided, search by username
            if ($username) {
                if ($this->accessToken) {
                    $response = Http::withToken($this->accessToken)
                        ->get($this->oauthHost . '/api/users/search', [
                            'username' => $username,
                            'paginate' => 'false',
                            'limit' => 1
                        ]);
                } else {
                    $response = $this->clientCredentials->makeRequest(
                        'GET',
                        $this->oauthHost . '/api/users/search',
                        [
                            'username' => $username,
                            'paginate' => 'false',
                            'limit' => 1
                        ]
                    );
                }

                if ($response->successful()) {
                    $result = $response->json();
                    if (isset($result['data']) && count($result['data']) > 0) {
                        return $result['data'][0];
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('User search API error', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    public function createUser(array $userData): ?array
    {
        try {
            if ($this->accessToken) {
                $response = Http::withToken($this->accessToken)
                    ->post($this->oauthHost . '/api/users', $userData);
            } else {
                $response = $this->clientCredentials->makeRequest(
                    'POST',
                    $this->oauthHost . '/api/users',
                    $userData
                );
            }

            if ($response->successful()) {
                $newUser = $response->json();
                Log::info('User created on OAuth server', ['user_id' => $newUser['user']['id']]);
                return $newUser;
            }

            Log::error('Failed to create user on OAuth server', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            return [
                'success' => false,
                'errors' => $response->json()['errors'] ?? ['Unknown error']
            ];

        } catch (\Exception $e) {
            Log::error('User creation API error', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'errors' => ['API connection failed: ' . $e->getMessage()]
            ];
        }
    }

    public function updateUser(int $oauthUserId, array $userData): ?array
    {
        try {
            if ($this->accessToken) {
                $response = Http::withToken($this->accessToken)
                    ->put($this->oauthHost . "/api/users/{$oauthUserId}", $userData);
            } else {
                $response = $this->clientCredentials->makeRequest(
                    'PUT',
                    $this->oauthHost . "/api/users/{$oauthUserId}",
                    $userData
                );
            }

            if ($response->successful()) {
                $updatedUser = $response->json();
                Log::info('User updated on OAuth server', ['user_id' => $oauthUserId]);
                return $updatedUser;
            }

            Log::error('Failed to update user on OAuth server', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            return [
                'success' => false,
                'errors' => $response->json()['errors'] ?? ['Unknown error']
            ];

        } catch (\Exception $e) {
            Log::error('User update API error', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'errors' => ['API connection failed: ' . $e->getMessage()]
            ];
        }
    }

    public function syncLocalUser($user): bool
    {
        if (!$user->oauth_id) {
            Log::warning('Cannot sync user without oauth_id', ['user_id' => $user->id]);
            return false;
        }

        $token = session('oauth_access_token') ?? $this->accessToken;
        
        if (!$token) {
            Log::warning('No access token available for user sync');
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->get($this->oauthHost . "/api/users/{$user->oauth_id}/sync");

            if ($response->successful()) {
                $userData = $response->json();
                
                $user->update([
                    'username' => $userData['username'],
                    'email' => $userData['email'],
                    'name' => $userData['name'],
                    'oauth_data' => $userData,
                    'synced_at' => now(),
                ]);

                Log::info('User synced successfully', ['user_id' => $user->id]);
                return true;
            }

        } catch (\Exception $e) {
            Log::error('User sync failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    /**
     * Get available roles from OAuth server
     */
    public function getRoles(): ?array
    {
        try {
            if ($this->accessToken) {
                $response = Http::withToken($this->accessToken)
                    ->get($this->oauthHost . '/api/users/roles');
            } else {
                $response = $this->clientCredentials->makeRequest(
                    'GET',
                    $this->oauthHost . '/api/users/roles'
                );
            }

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to get roles from OAuth server', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('Get roles API error', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Test password synchronization with OAuth server
     */
    public function testPasswordSync(string $email, string $password, bool $isHashed = false): ?array
    {
        try {
            $payload = [
                'email' => $email,
                'password' => $password,
                'is_hashed' => $isHashed
            ];

            if ($this->accessToken) {
                $response = Http::withToken($this->accessToken)
                    ->post($this->oauthHost . '/api/users/test-password-sync', $payload);
            } else {
                $response = $this->clientCredentials->makeRequest(
                    'POST',
                    $this->oauthHost . '/api/users/test-password-sync',
                    $payload
                );
            }

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Failed to test password sync', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

        } catch (\Exception $e) {
            Log::error('Password sync test API error', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Auto-detect fields that should be preserved based on local user model vs OAuth data
     */
    protected function getAutoPreservedFields(array $oauthData, $user): array
    {
        $userModel = new $this->userModel;
        $userTableColumns = \Illuminate\Support\Facades\Schema::getColumnListing($userModel->getTable());
        
        // Fields that are always preserved (local-only fields)
        $alwaysPreserved = [
            'id', 'created_at', 'updated_at', 'remember_token', 'oauth_id', 
            'oauth_data', 'synced_at', 'is_active', 'deleted_at'
        ];
        
        // Fields that exist in local database but not in OAuth data should be preserved
        $oauthFields = array_keys($oauthData);
        $preservedFields = [];
        
        foreach ($userTableColumns as $column) {
            // Always preserve the always-preserved fields
            if (in_array($column, $alwaysPreserved)) {
                $preservedFields[] = $column;
                continue;
            }
            
            // Preserve fields that don't exist in OAuth data (local-only fields)
            if (!in_array($column, $oauthFields)) {
                $preservedFields[] = $column;
            }
        }
        
        return $preservedFields;
    }

    /**
     * Prepare user data for creation by auto-generating required fields like UUIDs
     */
    protected function prepareUserDataForCreation(array $userData): array
    {
        $userModel = new $this->userModel;
        $userTableColumns = \Illuminate\Support\Facades\Schema::getColumnListing($userModel->getTable());
        
        // Check for UUID fields and generate them if they exist
        $uuidFields = ['uuid', 'user_uuid', 'guid', 'user_guid'];
        
        foreach ($uuidFields as $uuidField) {
            if (in_array($uuidField, $userTableColumns) && !isset($userData[$uuidField])) {
                // Generate UUID for any UUID field that exists in the table
                // This is safer than trying to check constraints
                $userData[$uuidField] = $this->generateUuid();
                \Illuminate\Support\Facades\Log::info("Auto-generated UUID for field: {$uuidField}");
            }
        }
        
        return $userData;
    }

    /**
     * Generate a UUID v4
     */
    protected function generateUuid(): string
    {
        // Use Laravel's Str::uuid() if available, otherwise generate manually
        if (class_exists('Illuminate\Support\Str') && method_exists('Illuminate\Support\Str', 'uuid')) {
            return \Illuminate\Support\Str::uuid()->toString();
        }
        
        // Fallback UUID v4 generation
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

}