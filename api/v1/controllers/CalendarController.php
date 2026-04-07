<?php
/**
 * Calendar Controller - Event Management
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CalendarController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS calendar_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                description TEXT,
                start_date TEXT NOT NULL,
                end_date TEXT,
                all_day INTEGER DEFAULT 0,
                color TEXT,
                user_id INTEGER,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
    }

    /**
     * GET /admin/calendar
     */
    public function list(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $startDate = $queryParams['start'] ?? null;
        $endDate = $queryParams['end'] ?? null;

        $sql = "
            SELECT e.*, u.username, u.calendar_color as user_color
            FROM calendar_events e
            LEFT JOIN users u ON e.user_id = u.id
        ";
        $params = [];
        $conditions = [];

        if ($startDate) {
            $conditions[] = "date(e.start_date) >= date(?)";
            $params[] = $startDate;
        }
        if ($endDate) {
            $conditions[] = "date(e.start_date) <= date(?)";
            $params[] = $endDate;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY datetime(e.start_date)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($event) {
            return [
                'id' => (int) $event['id'],
                'title' => $event['title'],
                'description' => $event['description'],
                'start' => $event['start_date'],
                'end' => $event['end_date'],
                'allDay' => (bool) $event['all_day'],
                'color' => $event['color'] ?: $event['user_color'] ?: '#3788d8',
                'user' => $event['user_id'] ? [
                    'id' => (int) $event['user_id'],
                    'username' => $event['username'],
                ] : null,
            ];
        }, $events);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'events' => $result,
            ],
        ]);
    }

    /**
     * POST /admin/calendar
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $userId = $request->getAttribute('user_id');

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $startDate = (string) ($data['start'] ?? '');
        $endDate = $data['end'] ?? null;
        $allDay = !empty($data['allDay']) ? 1 : 0;
        $color = $data['color'] ?? null;

        if ($title === '' || $startDate === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Titel und Startdatum sind erforderlich.',
                ],
            ], 400);
        }

        $this->pdo->prepare("
            INSERT INTO calendar_events (title, description, start_date, end_date, all_day, color, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$title, $description ?: null, $startDate, $endDate, $allDay, $color, $userId]);

        $eventId = (int) $this->pdo->lastInsertId();

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $eventId,
                'title' => $title,
                'start' => $startDate,
            ],
        ], 201);
    }

    /**
     * PUT /admin/calendar/{id}
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $eventId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];

        $updates = [];
        $params = [];

        $fields = [
            'title' => 'title',
            'description' => 'description',
            'start' => 'start_date',
            'end' => 'end_date',
            'allDay' => 'all_day',
            'color' => 'color',
        ];

        foreach ($fields as $input => $column) {
            if (isset($data[$input])) {
                $updates[] = "$column = ?";
                $value = $data[$input];
                if ($input === 'allDay') {
                    $value = $value ? 1 : 0;
                }
                $params[] = $value;
            }
        }

        if (empty($updates)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Keine Änderungen angegeben.',
                ],
            ], 400);
        }

        $params[] = $eventId;
        $sql = "UPDATE calendar_events SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Event nicht gefunden.',
                ],
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Event aktualisiert.',
            ],
        ]);
    }

    /**
     * DELETE /admin/calendar/{id}
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $eventId = (int) ($args['id'] ?? 0);

        $this->pdo->prepare("DELETE FROM calendar_events WHERE id = ?")->execute([$eventId]);

        return $response->withStatus(204);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
