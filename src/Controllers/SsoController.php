<?php

namespace Omniglies\LaravelSsoClient\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Omniglies\LaravelSsoClient\Services\SsoUserService;

class SsoController extends Controller
{
    protected $ssoUserService;

    public function __construct(SsoUserService $ssoUserService)
    {
        $this->ssoUserService = $ssoUserService;
    }

    public function redirect()
    {
        if (Auth::check()) {
            $redirectPath = config('sso-client.redirect_after_login', '/home');
            return redirect($redirectPath);
        }
        
        return Socialite::driver('laravelpassport')->stateless()->redirect();
    }

    public function callback()
    {
        try {
            $oauthUser = Socialite::driver('laravelpassport')->stateless()->user();
            
            // Safe logging - SocialiteProviders\Manager\OAuth2\User doesn't have toArray()
            if ($oauthUser) {
                Log::debug('OAuth User received', [
                    'user' => [
                        'id' => $oauthUser->getId(),
                        'email' => $oauthUser->getEmail(),
                        'name' => $oauthUser->getName(),
                        'nickname' => $oauthUser->getNickname(),
                        'avatar' => $oauthUser->getAvatar(),
                        'raw' => $oauthUser->getRaw()
                    ]
                ]);
            } else {
                Log::warning('OAuth User is null');
                return redirect()->route('login')->withErrors(['error' => 'Failed to authenticate with OAuth server']);
            }
            
            // Check if user exists in client database (DO NOT create new user)
            $userModel = config('sso-client.user_model', 'App\\Models\\User');
            $user = $userModel::where('email', $oauthUser->getEmail())
                       ->orWhere('oauth_id', $oauthUser->getId())
                       ->first();
            
            // If user doesn't exist, show contact admin message instead of creating
            if (!$user) {
                Log::warning('OAuth user not found in client database', [
                    'oauth_id' => $oauthUser->getId(),
                    'email' => $oauthUser->getEmail()
                ]);
                
                return redirect()->route('login')->withErrors([
                    'error' => 'Your account is not registered in this application. Please contact your administrator to create your account.'
                ]);
            }
            
            // Check if existing user is active
            if (!$user->is_active) {
                Log::error('User account is inactive', [
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
                
                return redirect()->route('login')->withErrors([
                    'error' => 'Your account is inactive. Please contact your administrator.'
                ]);
            }

            // Sync user data selectively for existing users only (username, name, password, email)
            $this->syncExistingUserData($user, $oauthUser);

            session(['oauth_access_token' => $oauthUser->token]);
            session(['oauth_refresh_token' => $oauthUser->refreshToken]);
            
            // Login the existing user
            Auth::login($user);
            
            Log::info('User successfully logged in via OAuth', [
                'user_id' => $user->id,
                'email' => $user->email,
                'oauth_id' => $oauthUser->getId()
            ]);

            $redirectPath = config('sso-client.redirect_after_login', '/home');
            return redirect($redirectPath);
            
        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error('OAuth State Exception', [
                'error' => $e->getMessage(),
                'request_state' => request('state'),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->route('sso.redirect')->with('error', 'Authentication failed. Please try again.');
        } catch (\Exception $e) {
            Log::error('OAuth callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->route('login')->withErrors([
                'error' => 'Authentication failed. Please try again or contact your administrator.'
            ]);
        }
    }

    public function ssoLogout(Request $request)
    {
        Auth::guard('web')->logout();
        
        $request->session()->forget(['oauth_access_token', 'oauth_refresh_token']);
        
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        $redirectUri = urlencode(url('/'));
        $ssoLogoutUrl = config('services.laravelpassport.host') . '/sso/logout?redirect_uri=' . $redirectUri;
        
        Log::info('Redirecting to SSO logout', ['url' => $ssoLogoutUrl]);
        
        return redirect($ssoLogoutUrl);
    }

    public function localLogout(Request $request)
    {
        Auth::guard('web')->logout();
        
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect('/')->with('status', 'Successfully logged out locally.');
    }

    /**
     * Sync existing user data selectively (username, name, password, email only)
     * Based on which side has the most up-to-date data
     */
    protected function syncExistingUserData($user, $oauthUser)
    {
        try {
            // Get enhanced user data from OAuth server using the access token
            $enhancedUserData = $this->getEnhancedUserDataFromOAuth($oauthUser->token);
            
            // Fields to sync selectively
            $syncFields = ['username', 'name', 'email', 'password'];
            $updateData = [];
            
            foreach ($syncFields as $field) {
                $oauthValue = null;
                $clientValue = $user->{$field};
                
                // Get OAuth server value based on field mapping
                switch ($field) {
                    case 'username':
                        $oauthValue = $enhancedUserData['username'] ?? $oauthUser->getNickname() ?? $oauthUser->getEmail();
                        break;
                    case 'name':
                        $oauthValue = $enhancedUserData['name'] ?? $oauthUser->getName();
                        break;
                    case 'email':
                        $oauthValue = $enhancedUserData['email'] ?? $oauthUser->getEmail();
                        break;
                    case 'password':
                        // Get hashed password from OAuth server (should already be hashed)
                        $oauthValue = $enhancedUserData['password'] ?? null;
                        break;
                }
                
                // Determine which side has more up-to-date data
                if ($this->shouldUpdateFromOAuth($user, $field, $clientValue, $oauthValue, $enhancedUserData)) {
                    $updateData[$field] = $oauthValue;
                    
                    // Log sync operation but hide password values for security
                    if ($field === 'password') {
                        Log::debug("Syncing {$field} from OAuth server", [
                            'user_id' => $user->id,
                            'old_value' => '[hidden]',
                            'new_value' => '[hidden]'
                        ]);
                    } else {
                        Log::debug("Syncing {$field} from OAuth server", [
                            'user_id' => $user->id,
                            'old_value' => $clientValue,
                            'new_value' => $oauthValue
                        ]);
                    }
                }
            }
            
            // Update oauth_id if not set
            if (!$user->oauth_id && $oauthUser->getId()) {
                $updateData['oauth_id'] = $oauthUser->getId();
            }
            
            // Update oauth_data with fresh data
            $updateData['oauth_data'] = [
                'id' => $oauthUser->getId(),
                'email' => $oauthUser->getEmail(),
                'name' => $oauthUser->getName(),
                'nickname' => $oauthUser->getNickname(),
                'avatar' => $oauthUser->getAvatar(),
                'raw' => $oauthUser->getRaw()
            ];
            
            // Always update synced_at timestamp
            $updateData['synced_at'] = now();
            
            // Apply updates if any
            if (!empty($updateData)) {
                $user->update($updateData);
                Log::info('User data synced successfully', [
                    'user_id' => $user->id,
                    'synced_fields' => array_keys($updateData)
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to sync existing user data', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            // Don't fail the login process if sync fails
        }
    }

    /**
     * Get enhanced user data from OAuth server
     */
    protected function getEnhancedUserDataFromOAuth($token)
    {
        if (!$token) {
            return [];
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get(config('services.laravelpassport.host') . '/api/users/me');

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get enhanced user data from OAuth server', [
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Determine if field should be updated from OAuth server based on which side has more recent data
     */
    protected function shouldUpdateFromOAuth($user, $field, $clientValue, $oauthValue, $enhancedUserData)
    {
        // Special handling for password field
        if ($field === 'password') {
            // Skip if OAuth doesn't provide password
            if (empty($oauthValue)) {
                return false;
            }
            
            // For password, we need to be more careful about comparison
            // since we're dealing with hashed values
            if (empty($clientValue) && !empty($oauthValue)) {
                return true; // Client has no password, use OAuth password
            }
            
            // If both have passwords, compare using timestamps or sync status
            // Don't compare hashed passwords directly
        } else {
            // Regular field comparison for non-password fields
            // If values are the same, no update needed
            if ($clientValue === $oauthValue) {
                return false;
            }
        }
        
        // If client value is empty/null but OAuth has a value, update from OAuth
        if (empty($clientValue) && !empty($oauthValue)) {
            return true;
        }
        
        // If OAuth value is empty but client has a value, don't update
        if (empty($oauthValue) && !empty($clientValue)) {
            return false;
        }
        
        // Compare timestamps to determine which side is more recent
        $clientUpdatedAt = $user->updated_at;
        $oauthUpdatedAt = null;
        
        // Try to get OAuth server's updated_at timestamp
        if (isset($enhancedUserData['updated_at'])) {
            try {
                $oauthUpdatedAt = \Carbon\Carbon::parse($enhancedUserData['updated_at']);
            } catch (\Exception $e) {
                // Ignore parsing errors
            }
        }
        
        // If we have both timestamps, use the more recent one
        if ($clientUpdatedAt && $oauthUpdatedAt) {
            return $oauthUpdatedAt->isAfter($clientUpdatedAt);
        }
        
        // If we can't determine timestamps, check if synced_at exists
        // If user was never synced, prefer OAuth data
        if (!$user->synced_at) {
            return true;
        }
        
        // Default: don't update if we can't determine which is more recent
        return false;
    }
}