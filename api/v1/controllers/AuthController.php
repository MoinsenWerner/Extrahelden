<?php
/**
 * Authentication Controller - Login, Logout, Token Refresh, OAuth2 Discord
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Extrahelden\Api\Models\JwtHandler;

class AuthController
{
    private PDO $pdo;
    private JwtHandler $jwtHandler;
    private array $config;

    public function __construct(PDO $pdo, JwtHandler $jwtHandler, array $config)
    {
        $this->pdo = $pdo;
        $this->jwtHandler = $jwtHandler;
        $this->config = $config;
        $this->ensureRefreshTokenTable();
    }

    private function ensureRefreshTokenTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                revoked INTEGER DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }

    /**
     * POST /auth/login
     */
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Benutzername und Passwort sind erforderlich.',
                ],
            ], 400);
        }

        $stmt = $this->pdo->prepare('SELECT id, username, password_hash, is_admin FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Ungültige Zugangsdaten.',
                ],
            ], 401);
        }

        // Generate tokens
        $accessToken = $this->jwtHandler->generateAccessToken(
            (int) $user['id'],
            $user['username'],
            (bool) $user['is_admin']
        );
        $refreshToken = $this->jwtHandler->generateRefreshToken((int) $user['id']);

        // Store refresh token hash in DB
        $this->storeRefreshToken((int) $user['id'], $refreshToken);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->config['jwt']['access_token_ttl'],
                'user' => [
                    'id' => (int) $user['id'],
                    'username' => $user['username'],
                    'is_admin' => (bool) $user['is_admin'],
                ],
            ],
        ]);
    }

    /**
     * POST /auth/refresh
     */
    public function refresh(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $refreshToken = trim((string) ($data['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Refresh Token ist erforderlich.',
                ],
            ], 400);
        }

        // Validate refresh token
        $payload = $this->jwtHandler->validateToken($refreshToken);
        if (!$payload || $payload['type'] !== 'refresh') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_TOKEN',
                    'message' => 'Ungültiger oder abgelaufener Refresh Token.',
                ],
            ], 401);
        }

        // Check if token is in DB and not revoked
        $tokenHash = JwtHandler::hashToken($refreshToken);
        $stmt = $this->pdo->prepare('
            SELECT rt.id, rt.user_id, u.username, u.is_admin 
            FROM refresh_tokens rt
            JOIN users u ON rt.user_id = u.id
            WHERE rt.token_hash = ? AND rt.revoked = 0 AND datetime(rt.expires_at) > datetime("now")
        ');
        $stmt->execute([$tokenHash]);
        $tokenRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenRecord) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_TOKEN',
                    'message' => 'Token wurde widerrufen oder ist ungültig.',
                ],
            ], 401);
        }

        // Revoke old refresh token
        $this->pdo->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE id = ?')
            ->execute([$tokenRecord['id']]);

        // Generate new tokens
        $newAccessToken = $this->jwtHandler->generateAccessToken(
            (int) $tokenRecord['user_id'],
            $tokenRecord['username'],
            (bool) $tokenRecord['is_admin']
        );
        $newRefreshToken = $this->jwtHandler->generateRefreshToken((int) $tokenRecord['user_id']);

        // Store new refresh token
        $this->storeRefreshToken((int) $tokenRecord['user_id'], $newRefreshToken);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'access_token' => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->config['jwt']['access_token_ttl'],
            ],
        ]);
    }

    /**
     * POST /auth/logout
     */
    public function logout(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $refreshToken = trim((string) ($data['refresh_token'] ?? ''));

        if ($refreshToken !== '') {
            $tokenHash = JwtHandler::hashToken($refreshToken);
            $this->pdo->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE token_hash = ?')
                ->execute([$tokenHash]);
        }

        // Optionally revoke all tokens for user
        $userId = $request->getAttribute('user_id');
        if (!empty($data['revoke_all']) && $userId) {
            $this->pdo->prepare('UPDATE refresh_tokens SET revoked = 1 WHERE user_id = ?')
                ->execute([$userId]);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Erfolgreich abgemeldet.',
            ],
        ]);
    }

    /**
     * GET /auth/discord
     * Redirect to Discord OAuth
     */
    public function discordRedirect(Request $request, Response $response): Response
    {
        $clientId = $this->config['discord']['client_id'] ?? '';
        $redirectUri = $this->config['discord']['redirect_uri'] ?? '';

        if (!$clientId || !$redirectUri) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'CONFIGURATION_ERROR',
                    'message' => 'Discord OAuth ist nicht konfiguriert.',
                ],
            ], 500);
        }

        // Generate state for CSRF protection
        $state = bin2hex(random_bytes(16));
        
        // Store state in session-like mechanism (using DB for stateless API)
        $this->pdo->prepare('
            INSERT INTO site_settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ')->execute(["oauth_state_$state", json_encode([
            'created_at' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ])]);

        $authUrl = 'https://discord.com/api/oauth2/authorize?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'identify guilds.members.read',
            'state' => $state,
        ]);

        return $response
            ->withHeader('Location', $authUrl)
            ->withStatus(302);
    }

    /**
     * GET /auth/discord/callback
     * Handle Discord OAuth callback
     */
    public function discordCallback(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $code = $queryParams['code'] ?? '';
        $state = $queryParams['state'] ?? '';
        $error = $queryParams['error'] ?? '';

        if ($error) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'OAUTH_ERROR',
                    'message' => 'Discord-Authentifizierung fehlgeschlagen: ' . $error,
                ],
            ], 400);
        }

        // Validate state
        $stmt = $this->pdo->prepare('SELECT value FROM site_settings WHERE key = ?');
        $stmt->execute(["oauth_state_$state"]);
        $stateRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stateRecord) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_STATE',
                    'message' => 'Ungültiger OAuth State.',
                ],
            ], 400);
        }

        // Clean up state
        $this->pdo->prepare('DELETE FROM site_settings WHERE key = ?')
            ->execute(["oauth_state_$state"]);

        // Exchange code for token
        $tokenData = $this->exchangeDiscordCode($code);
        if (!$tokenData) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'TOKEN_EXCHANGE_FAILED',
                    'message' => 'Token-Austausch mit Discord fehlgeschlagen.',
                ],
            ], 400);
        }

        // Get Discord user info
        $discordUser = $this->getDiscordUser($tokenData['access_token']);
        if (!$discordUser) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'USER_FETCH_FAILED',
                    'message' => 'Discord-Benutzerinformationen konnten nicht abgerufen werden.',
                ],
            ], 400);
        }

        // Match Discord user to local user
        $discordName = $discordUser['username'] . '#' . ($discordUser['discriminator'] ?? '0');
        $discordNameNew = $discordUser['username']; // Discord new format without discriminator
        
        $stmt = $this->pdo->prepare('
            SELECT id, username, is_admin FROM users 
            WHERE discord_name = ? OR discord_name = ? 
            LIMIT 1
        ');
        $stmt->execute([$discordName, $discordNameNew]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Try matching via applications table
            $stmt = $this->pdo->prepare('
                SELECT u.id, u.username, u.is_admin 
                FROM applications a
                JOIN users u ON a.created_user_id = u.id
                WHERE a.discord_name = ? OR a.discord_name = ?
                LIMIT 1
            ');
            $stmt->execute([$discordName, $discordNameNew]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$user) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => 'Kein verknüpftes Benutzerkonto gefunden. Bitte verwende den normalen Login.',
                ],
            ], 404);
        }

        // Generate tokens
        $accessToken = $this->jwtHandler->generateAccessToken(
            (int) $user['id'],
            $user['username'],
            (bool) $user['is_admin']
        );
        $refreshToken = $this->jwtHandler->generateRefreshToken((int) $user['id']);
        $this->storeRefreshToken((int) $user['id'], $refreshToken);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => $this->config['jwt']['access_token_ttl'],
                'user' => [
                    'id' => (int) $user['id'],
                    'username' => $user['username'],
                    'is_admin' => (bool) $user['is_admin'],
                ],
                'discord' => [
                    'id' => $discordUser['id'],
                    'username' => $discordUser['username'],
                    'avatar' => $discordUser['avatar'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * GET /auth/me
     * Get current user info (requires auth)
     */
    public function me(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        
        $stmt = $this->pdo->prepare('SELECT id, username, is_admin, discord_name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => 'Benutzer nicht gefunden.',
                ],
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'is_admin' => (bool) $user['is_admin'],
                'discord_name' => $user['discord_name'],
            ],
        ]);
    }

    private function storeRefreshToken(int $userId, string $token): void
    {
        $tokenHash = JwtHandler::hashToken($token);
        $expiresAt = date('Y-m-d H:i:s', time() + $this->jwtHandler->getRefreshTokenTtl());

        $this->pdo->prepare('
            INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
            VALUES (?, ?, ?)
        ')->execute([$userId, $tokenHash, $expiresAt]);

        // Clean up old revoked tokens
        $this->pdo->exec("DELETE FROM refresh_tokens WHERE revoked = 1 AND datetime(expires_at) < datetime('now', '-1 day')");
    }

    private function exchangeDiscordCode(string $code): ?array
    {
        $ch = curl_init('https://discord.com/api/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->config['discord']['client_id'],
                'client_secret' => $this->config['discord']['client_secret'],
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config['discord']['redirect_uri'],
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        return json_decode($response, true);
    }

    private function getDiscordUser(string $accessToken): ?array
    {
        $ch = curl_init('https://discord.com/api/users/@me');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        return json_decode($response, true);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
