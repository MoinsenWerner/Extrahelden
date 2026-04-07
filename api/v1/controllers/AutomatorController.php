<?php
/**
 * Automator Controller - Discord Automation Rules
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AutomatorController
{
    private PDO $pdo;
    
    // Available triggers
    private const TRIGGERS = [
        'application_submitted' => 'Bewerbung eingereicht',
        'application_accepted' => 'Bewerbung angenommen',
        'application_rejected' => 'Bewerbung abgelehnt',
        'ticket_created' => 'Ticket erstellt',
        'ticket_closed' => 'Ticket geschlossen',
        'user_created' => 'Benutzer erstellt',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * GET /admin/automator/rules
     */
    public function listRules(Request $request, Response $response): Response
    {
        $rulesJson = $this->getSetting('auto_rules', '[]');
        $rules = json_decode($rulesJson, true) ?: [];

        // Add trigger labels
        $result = array_map(function ($rule, $index) {
            return [
                'id' => $index,
                'trigger' => $rule['trigger'],
                'trigger_label' => self::TRIGGERS[$rule['trigger']] ?? $rule['trigger'],
                'event_id' => $rule['event_id'],
                'active' => (bool) ($rule['active'] ?? false),
            ];
        }, $rules, array_keys($rules));

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'rules' => $result,
                'available_triggers' => self::TRIGGERS,
            ],
        ]);
    }

    /**
     * POST /admin/automator/rules
     */
    public function createRule(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $trigger = (string) ($data['trigger'] ?? '');
        $eventId = (int) ($data['event_id'] ?? -1);
        $active = !empty($data['active']);

        if (!isset(self::TRIGGERS[$trigger])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Ungültiger Trigger.',
                    'available_triggers' => array_keys(self::TRIGGERS),
                ],
            ], 400);
        }

        $rules = json_decode($this->getSetting('auto_rules', '[]'), true) ?: [];
        $rules[] = [
            'trigger' => $trigger,
            'event_id' => $eventId,
            'active' => $active,
        ];

        $this->setSetting('auto_rules', json_encode($rules));

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => count($rules) - 1,
                'message' => 'Regel erstellt.',
            ],
        ], 201);
    }

    /**
     * DELETE /admin/automator/rules/{id}
     */
    public function deleteRule(Request $request, Response $response, array $args): Response
    {
        $ruleId = (int) ($args['id'] ?? -1);

        $rules = json_decode($this->getSetting('auto_rules', '[]'), true) ?: [];

        if (!isset($rules[$ruleId])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Regel nicht gefunden.',
                ],
            ], 404);
        }

        array_splice($rules, $ruleId, 1);
        $this->setSetting('auto_rules', json_encode($rules));

        return $response->withStatus(204);
    }

    /**
     * GET /admin/automator/events
     */
    public function listEvents(Request $request, Response $response): Response
    {
        $eventsJson = $this->getSetting('custom_discord_events', '[]');
        $events = json_decode($eventsJson, true) ?: [];

        $result = array_map(function ($event, $index) {
            return [
                'id' => $index,
                'name' => $event['name'] ?? 'Event ' . $index,
                'channel' => $event['channel'] ?? '',
                'message' => $event['message'] ?? '',
            ];
        }, $events, array_keys($events));

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'events' => $result,
            ],
        ]);
    }

    /**
     * POST /admin/automator/events
     */
    public function createEvent(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $name = trim((string) ($data['name'] ?? ''));
        $channel = trim((string) ($data['channel'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));

        if ($name === '' || $channel === '' || $message === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Name, Channel-ID und Nachricht sind erforderlich.',
                ],
            ], 400);
        }

        $events = json_decode($this->getSetting('custom_discord_events', '[]'), true) ?: [];
        $events[] = [
            'name' => $name,
            'channel' => $channel,
            'message' => $message,
        ];

        $this->setSetting('custom_discord_events', json_encode($events));

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => count($events) - 1,
                'message' => 'Event erstellt.',
            ],
        ], 201);
    }

    /**
     * DELETE /admin/automator/events/{id}
     */
    public function deleteEvent(Request $request, Response $response, array $args): Response
    {
        $eventId = (int) ($args['id'] ?? -1);

        $events = json_decode($this->getSetting('custom_discord_events', '[]'), true) ?: [];

        if (!isset($events[$eventId])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Event nicht gefunden.',
                ],
            ], 404);
        }

        array_splice($events, $eventId, 1);
        $this->setSetting('custom_discord_events', json_encode($events));

        return $response->withStatus(204);
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM site_settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['value'] : $default;
    }

    private function setSetting(string $key, string $value): void
    {
        $this->pdo->prepare("
            INSERT INTO site_settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ")->execute([$key, $value]);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
