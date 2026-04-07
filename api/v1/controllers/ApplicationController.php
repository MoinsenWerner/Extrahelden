<?php
/**
 * Application Controller - Bewerbungs-Management
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApplicationController
{
    private PDO $pdo;
    private string $basePath;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = $basePath;
    }

    /**
     * POST /applications
     * Submit new application (public)
     */
    public function submit(Request $request, Response $response): Response
    {
        // Check if applications are enabled
        $enabled = $this->getSetting('apply_enabled', '0') === '1';
        if (!$enabled) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'APPLICATIONS_DISABLED',
                    'message' => 'Bewerbungen sind derzeit geschlossen.',
                ],
            ], 400);
        }

        $data = $request->getParsedBody() ?? [];
        
        $youtubeUrl = trim((string) ($data['youtube_url'] ?? ''));
        $mcName = trim((string) ($data['mc_name'] ?? ''));
        $discordName = trim((string) ($data['discord_name'] ?? ''));
        $projectName = trim((string) ($data['project_name'] ?? ''));

        // Validation
        $errors = [];
        
        if ($youtubeUrl === '') {
            $errors['youtube_url'] = 'YouTube-URL ist erforderlich.';
        } else {
            $videoId = $this->extractYoutubeId($youtubeUrl);
            if (!$videoId) {
                $errors['youtube_url'] = 'Ungültige YouTube-URL.';
            }
        }

        if ($mcName === '') {
            $errors['mc_name'] = 'Minecraft-Name ist erforderlich.';
        } elseif (!$this->isValidMcName($mcName)) {
            $errors['mc_name'] = 'Ungültiger Minecraft-Name (3-16 Zeichen, a-z, 0-9, _).';
        }

        if ($discordName === '') {
            $errors['discord_name'] = 'Discord-Name ist erforderlich.';
        }

        if (!empty($errors)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validierungsfehler.',
                    'details' => $errors,
                ],
            ], 400);
        }

        // Get MC UUID
        $mcUuid = $this->getMcUuid($mcName);
        $videoId = $this->extractYoutubeId($youtubeUrl);

        // Insert application
        $stmt = $this->pdo->prepare("
            INSERT INTO applications (youtube_url, youtube_video_id, mc_name, mc_uuid, discord_name, project_name, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([$youtubeUrl, $videoId, $mcName, $mcUuid, $discordName, $projectName]);
        $appId = (int) $this->pdo->lastInsertId();

        // Trigger automation
        $this->triggerAutoTask('application_submitted', [
            'mc_name' => $mcName,
            'discord_name' => $discordName,
            'project_name' => $projectName,
        ]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $appId,
                'message' => 'Bewerbung erfolgreich eingereicht.',
            ],
        ], 201);
    }

    /**
     * GET /admin/applications
     */
    public function listAll(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $status = $queryParams['status'] ?? null;
        
        $sql = "SELECT * FROM applications";
        $params = [];
        
        if ($status) {
            $sql .= " WHERE status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY datetime(created_at) DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($app) {
            return [
                'id' => (int) $app['id'],
                'youtube_url' => $app['youtube_url'],
                'youtube_video_id' => $app['youtube_video_id'],
                'mc_name' => $app['mc_name'],
                'mc_uuid' => $app['mc_uuid'],
                'discord_name' => $app['discord_name'],
                'project_name' => $app['project_name'],
                'status' => $app['status'],
                'created_at' => $app['created_at'],
                'created_user_id' => $app['created_user_id'] ? (int) $app['created_user_id'] : null,
            ];
        }, $applications);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'applications' => $result,
            ],
        ]);
    }

    /**
     * GET /admin/applications/{id}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $appId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("SELECT * FROM applications WHERE id = ?");
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Bewerbung nicht gefunden.',
                ],
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => (int) $app['id'],
                'youtube_url' => $app['youtube_url'],
                'youtube_video_id' => $app['youtube_video_id'],
                'mc_name' => $app['mc_name'],
                'mc_uuid' => $app['mc_uuid'],
                'discord_name' => $app['discord_name'],
                'project_name' => $app['project_name'],
                'status' => $app['status'],
                'generated_password' => $app['generated_password'],
                'created_at' => $app['created_at'],
                'created_user_id' => $app['created_user_id'] ? (int) $app['created_user_id'] : null,
            ],
        ]);
    }

    /**
     * POST /admin/applications/{id}/accept
     */
    public function accept(Request $request, Response $response, array $args): Response
    {
        $appId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("SELECT * FROM applications WHERE id = ?");
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Bewerbung nicht gefunden.',
                ],
            ], 404);
        }

        if ($app['status'] !== 'pending' && $app['status'] !== 'shortlist') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_STATUS',
                    'message' => 'Bewerbung kann nicht angenommen werden.',
                ],
            ], 400);
        }

        // Generate password and create user
        $password = bin2hex(random_bytes(4)); // 8 char password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $username = $app['mc_name'];

        // Check if username exists
        $checkStmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->execute([$username]);
        if ($checkStmt->fetch()) {
            $username = $app['mc_name'] . '_' . random_int(100, 999);
        }

        // Create user
        $this->pdo->prepare("
            INSERT INTO users (username, password_hash, is_admin, discord_name)
            VALUES (?, ?, 0, ?)
        ")->execute([$username, $passwordHash, $app['discord_name']]);
        $userId = (int) $this->pdo->lastInsertId();

        // Update application
        $this->pdo->prepare("
            UPDATE applications SET status = 'accepted', generated_password = ?, created_user_id = ?
            WHERE id = ?
        ")->execute([$password, $userId, $appId]);

        // Trigger automation
        $this->triggerAutoTask('application_accepted', [
            'mc_name' => $app['mc_name'],
            'discord_name' => $app['discord_name'],
            'username' => $username,
            'password' => $password,
        ]);

        // Check whitelist
        $this->checkWhitelist($app['mc_name'], $app['discord_name']);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Bewerbung angenommen.',
                'user' => [
                    'id' => $userId,
                    'username' => $username,
                    'password' => $password,
                ],
            ],
        ]);
    }

    /**
     * POST /admin/applications/{id}/reject
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $appId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("SELECT * FROM applications WHERE id = ?");
        $stmt->execute([$appId]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Bewerbung nicht gefunden.',
                ],
            ], 404);
        }

        $this->pdo->prepare("UPDATE applications SET status = 'rejected' WHERE id = ?")
            ->execute([$appId]);

        // Trigger automation
        $this->triggerAutoTask('application_rejected', [
            'mc_name' => $app['mc_name'],
            'discord_name' => $app['discord_name'],
        ]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Bewerbung abgelehnt.',
            ],
        ]);
    }

    /**
     * POST /admin/applications/{id}/shortlist
     */
    public function shortlist(Request $request, Response $response, array $args): Response
    {
        $appId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("UPDATE applications SET status = 'shortlist' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$appId]);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Bewerbung nicht gefunden oder bereits bearbeitet.',
                ],
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Bewerbung zur engeren Auswahl hinzugefügt.',
            ],
        ]);
    }

    /**
     * DELETE /admin/applications/{id}
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $appId = (int) ($args['id'] ?? 0);

        $this->pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$appId]);

        return $response->withStatus(204);
    }

    // Helper methods

    private function extractYoutubeId(string $url): ?string
    {
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
            '/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }

    private function isValidMcName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]{3,16}$/', $name);
    }

    private function getMcUuid(string $name): ?string
    {
        $url = "https://api.mojang.com/users/profiles/minecraft/" . urlencode($name);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['id'] ?? null;
        }

        return null;
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM site_settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['value'] : $default;
    }

    private function triggerAutoTask(string $triggerKey, array $data = []): void
    {
        $rules = json_decode($this->getSetting('auto_rules', '[]'), true) ?: [];
        $events = json_decode($this->getSetting('custom_discord_events', '[]'), true) ?: [];
        $token = $this->getSetting('discord_bot_token', '');

        if (empty($token)) return;

        foreach ($rules as $rule) {
            if (!empty($rule['active']) && $rule['trigger'] === $triggerKey) {
                $event = $events[$rule['event_id']] ?? null;
                if ($event && !empty($event['channel'])) {
                    $message = $event['message'];
                    foreach ($data as $key => $val) {
                        $message = str_replace('{' . $key . '}', (string) $val, $message);
                    }

                    $ch = curl_init("https://discord.com/api/v10/channels/{$event['channel']}/messages");
                    curl_setopt_array($ch, [
                        CURLOPT_HTTPHEADER => ['Authorization: Bot ' . $token, 'Content-Type: application/json'],
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode(['content' => $message]),
                        CURLOPT_RETURNTRANSFER => true,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
            }
        }
    }

    private function checkWhitelist(string $mcName, string $discordName): void
    {
        // Placeholder for whitelist check logic
        // This would integrate with the Minecraft server RCON or plugin API
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
