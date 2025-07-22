<?php

namespace Mdigi\LaravelSsoClient\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Mdigi\LaravelSsoClient\Services\SsoUserService;

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
            
            $user = $this->ssoUserService->syncUserFromOAuth($oauthUser);
            
            if (!$user || !$user->is_active) {
                Log::error('User not found or inactive', ['email' => $oauthUser->email]);
                abort(403, 'User account is not active or not found.');
            }

            session(['oauth_access_token' => $oauthUser->token]);
            session(['oauth_refresh_token' => $oauthUser->refreshToken]);
            
            Auth::login($user);

            $redirectPath = config('sso-client.redirect_after_login', '/home');
            return redirect($redirectPath);
        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::error('OAuth State Exception', [
                'error' => $e->getMessage(),
                'request_state' => request('state'),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->route('sso.redirect')->with('error', 'Authentication failed. Please try again.');
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
}