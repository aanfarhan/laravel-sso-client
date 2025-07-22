<?php

namespace Mdigi\LaravelSsoClient\Services;

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
                $preservedFields = config('sso-client.preserved_fields', [
                    'id_role', 'nik', 'address', 'nip_pbb', 
                    'kd_propinsi', 'kd_dati2', 'kd_kecamatan', 'kd_kelurahan'
                ]);
                
                $updateData = array_diff_key($userData, array_flip($preservedFields));
                $user->update($updateData);
                
                Log::info('Existing user synced from OAuth', [
                    'user_id' => $user->id, 
                    'oauth_id' => $userData['oauth_id']
                ]);
            } else {
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

        } catch (\Exception $e) {
            Log::error('User search API error', [
                'error' => $e->getMessage()
            ]);
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
}