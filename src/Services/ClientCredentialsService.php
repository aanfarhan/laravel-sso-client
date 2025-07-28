<?php

namespace Omniglies\LaravelSsoClient\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ClientCredentialsService
{
    protected $tokenUrl;
    protected $clientId;
    protected $clientSecret;
    protected $accessToken = null;

    public function __construct()
    {
        $this->tokenUrl = rtrim(config('services.laravelpassport.host'), '/') . '/oauth/token';
        $this->clientId = config('services.laravelpassport.client_id');
        $this->clientSecret = config('services.laravelpassport.client_secret');
    }

    /**
     * Get a valid access token using client credentials grant
     */
    public function getAccessToken(): ?string
    {
        // Check cache first
        $cacheKey = 'sso_client_credentials_token_' . $this->clientId;
        
        try {
            $cachedToken = Cache::get($cacheKey);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Cache decryption failed (likely due to APP_KEY mismatch)
            // Clear corrupted cache and request new token
            Cache::forget($cacheKey);
            $cachedToken = null;
        }
        
        if ($cachedToken) {
            $this->accessToken = $cachedToken;
            return $this->accessToken;
        }

        // Request new token
        try {
            $response = Http::post($this->tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->accessToken = $data['access_token'];
                $expiresIn = $data['expires_in'] ?? 3600;
                
                // Cache the token for slightly less than its expiry time
                Cache::put($cacheKey, $this->accessToken, now()->addSeconds($expiresIn - 60));
                
                return $this->accessToken;
            }
            
            throw new \Exception('Failed to get access token: ' . $response->body());
            
        } catch (\Exception $e) {
            throw new \Exception('OAuth client credentials request failed: ' . $e->getMessage());
        }
    }

    /**
     * Make an authenticated API request using client credentials
     */
    public function makeRequest(string $method, string $url, array $data = []): Response
    {
        $token = $this->getAccessToken();
        
        if (!$token) {
            throw new \Exception('Failed to obtain access token for API request');
        }
        
        // Use withToken method which is more reliable than withHeaders for OAuth
        $request = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

        switch (strtolower($method)) {
            case 'get':
                return $request->get($url, $data);
            case 'post':
                return $request->post($url, $data);
            case 'put':
                return $request->put($url, $data);
            case 'patch':
                return $request->patch($url, $data);
            case 'delete':
                return $request->delete($url, $data);
            default:
                throw new \InvalidArgumentException("Unsupported HTTP method: $method");
        }
    }
}