<?php
/**
 * API Configuration
 */
declare(strict_types=1);

return [
    // JWT Settings
    'jwt' => [
        'secret' => getenv('JWT_SECRET') ?: 'CHANGE_THIS_TO_A_SECURE_32_CHAR_SECRET_KEY',
        'algorithm' => 'HS256',
        'access_token_ttl' => 900, // 15 minutes
        'refresh_token_ttl' => 2592000, // 30 days
        'issuer' => 'extrahelden-api',
    ],
    
    // OAuth2 Discord Settings
    'discord' => [
        'client_id' => getenv('DISCORD_CLIENT_ID') ?: '',
        'client_secret' => getenv('DISCORD_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('DISCORD_REDIRECT_URI') ?: '',
    ],
    
    // Rate Limiting (requests per minute)
    'rate_limits' => [
        'auth' => 10,      // /auth/* endpoints
        'admin' => 60,     // /admin/* endpoints  
        'public' => 120,   // /public/* endpoints
        'default' => 30,   // all other endpoints
    ],
    
    // CORS Settings
    'cors' => [
        'allowed_origins' => ['*'], // Adjust for production
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'max_age' => 86400, // 24 hours
    ],
    
    // API Version
    'version' => '1.0.0',
];
