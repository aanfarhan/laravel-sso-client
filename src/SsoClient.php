<?php

namespace Mdigi\LaravelSsoClient;

class SsoClient
{
    public function version()
    {
        return '1.0.0';
    }

    public function getOAuthHost()
    {
        return config('services.laravelpassport.host');
    }

    public function isConfigured()
    {
        return config('services.laravelpassport.client_id') && 
               config('services.laravelpassport.client_secret') && 
               config('services.laravelpassport.host');
    }
}