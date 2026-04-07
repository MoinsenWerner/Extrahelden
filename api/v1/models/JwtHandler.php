<?php
/**
 * JWT Handler - Token generation and validation
 */
declare(strict_types=1);

namespace Extrahelden\Api\Models;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Exception;

class JwtHandler
{
    private string $secret;
    private string $algorithm;
    private int $accessTokenTtl;
    private int $refreshTokenTtl;
    private string $issuer;

    public function __construct(array $config)
    {
        $this->secret = $config['secret'];
        $this->algorithm = $config['algorithm'];
        $this->accessTokenTtl = $config['access_token_ttl'];
        $this->refreshTokenTtl = $config['refresh_token_ttl'];
        $this->issuer = $config['issuer'];
    }

    /**
     * Generate access token
     */
    public function generateAccessToken(int $userId, string $username, bool $isAdmin): string
    {
        $issuedAt = time();
        $payload = [
            'iss' => $this->issuer,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $this->accessTokenTtl,
            'sub' => $userId,
            'username' => $username,
            'is_admin' => $isAdmin,
            'type' => 'access',
        ];

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Generate refresh token
     */
    public function generateRefreshToken(int $userId): string
    {
        $issuedAt = time();
        $payload = [
            'iss' => $this->issuer,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $this->refreshTokenTtl,
            'sub' => $userId,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)), // Unique token ID
        ];

        return JWT::encode($payload, $this->secret, $this->algorithm);
    }

    /**
     * Validate and decode token
     * @return array|null Decoded payload or null if invalid
     */
    public function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
            return (array) $decoded;
        } catch (ExpiredException $e) {
            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get token from Authorization header
     */
    public static function extractBearerToken(?string $authHeader): ?string
    {
        if (!$authHeader) {
            return null;
        }

        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Get refresh token TTL in seconds
     */
    public function getRefreshTokenTtl(): int
    {
        return $this->refreshTokenTtl;
    }

    /**
     * Hash refresh token for storage
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
