<?php
/**
 * Settings Controller - Site Settings Management
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SettingsController
{
    private PDO $pdo;
    
    // Settings that can be read/written via API
    private const ALLOWED_SETTINGS = [
        'apply_enabled',
        'apply_title',
        'discord_bot_token',
        'discord_fallback_channel',
        'site_title',
        'maintenance_mode',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * GET /admin/settings
     */
    public function getAll(Request $request, Response $response): Response
    {
        $placeholders = implode(',', array_fill(0, count(self::ALLOWED_SETTINGS), '?'));
        $stmt = $this->pdo->prepare("SELECT key, value FROM site_settings WHERE key IN ($placeholders)");
        $stmt->execute(self::ALLOWED_SETTINGS);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($rows as $row) {
            $key = $row['key'];
            $value = $row['value'];
            
            // Type conversion
            if (in_array($key, ['apply_enabled', 'maintenance_mode'], true)) {
                $value = $value === '1';
            }
            
            // Mask sensitive values
            if ($key === 'discord_bot_token' && $value) {
                $value = substr($value, 0, 10) . '...' . substr($value, -4);
            }
            
            $settings[$key] = $value;
        }

        // Add defaults for missing settings
        foreach (self::ALLOWED_SETTINGS as $key) {
            if (!isset($settings[$key])) {
                $settings[$key] = $this->getDefaultValue($key);
            }
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'settings' => $settings,
            ],
        ]);
    }

    /**
     * PUT /admin/settings
     */
    public function update(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $updated = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, self::ALLOWED_SETTINGS, true)) {
                continue;
            }

            // Convert boolean to string
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            // Skip if discord_bot_token is masked (unchanged)
            if ($key === 'discord_bot_token' && str_contains($value, '...')) {
                continue;
            }

            $this->setSetting($key, (string) $value);
            $updated[] = $key;
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Einstellungen aktualisiert.',
                'updated' => $updated,
            ],
        ]);
    }

    private function setSetting(string $key, string $value): void
    {
        $this->pdo->prepare("
            INSERT INTO site_settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ")->execute([$key, $value]);
    }

    private function getDefaultValue(string $key): mixed
    {
        return match ($key) {
            'apply_enabled' => false,
            'apply_title' => 'Projekt-Anmeldung',
            'maintenance_mode' => false,
            default => null,
        };
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
