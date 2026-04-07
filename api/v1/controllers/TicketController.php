<?php
/**
 * Ticket Controller - Support Ticket System
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TicketController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                subject TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'open',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
            
            CREATE TABLE IF NOT EXISTS ticket_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                message TEXT NOT NULL,
                is_admin_reply INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );
        ");
    }

    /**
     * GET /tickets
     * List user's tickets
     */
    public function listUserTickets(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $stmt = $this->pdo->prepare("
            SELECT t.*, 
                   (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as message_count
            FROM tickets t
            WHERE t.user_id = ?
            ORDER BY datetime(t.updated_at) DESC
        ");
        $stmt->execute([$userId]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($ticket) {
            return [
                'id' => (int) $ticket['id'],
                'subject' => $ticket['subject'],
                'status' => $ticket['status'],
                'message_count' => (int) $ticket['message_count'],
                'created_at' => $ticket['created_at'],
                'updated_at' => $ticket['updated_at'],
            ];
        }, $tickets);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'tickets' => $result,
            ],
        ]);
    }

    /**
     * POST /tickets
     * Create new ticket
     */
    public function create(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody() ?? [];

        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));

        if ($subject === '' || $message === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Betreff und Nachricht sind erforderlich.',
                ],
            ], 400);
        }

        // Create ticket
        $this->pdo->prepare("
            INSERT INTO tickets (user_id, subject, status)
            VALUES (?, ?, 'open')
        ")->execute([$userId, $subject]);
        $ticketId = (int) $this->pdo->lastInsertId();

        // Add initial message
        $this->pdo->prepare("
            INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin_reply)
            VALUES (?, ?, ?, 0)
        ")->execute([$ticketId, $userId, $message]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $ticketId,
                'subject' => $subject,
                'status' => 'open',
                'message' => 'Ticket erfolgreich erstellt.',
            ],
        ], 201);
    }

    /**
     * GET /tickets/{id}
     * Get ticket with messages
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $ticketId = (int) ($args['id'] ?? 0);
        $userId = $request->getAttribute('user_id');
        $isAdmin = $request->getAttribute('is_admin');

        // Get ticket
        $stmt = $this->pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Ticket nicht gefunden.',
                ],
            ], 404);
        }

        // Check access
        if (!$isAdmin && (int) $ticket['user_id'] !== $userId) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Kein Zugriff auf dieses Ticket.',
                ],
            ], 403);
        }

        // Get messages
        $msgStmt = $this->pdo->prepare("
            SELECT tm.*, u.username
            FROM ticket_messages tm
            JOIN users u ON tm.user_id = u.id
            WHERE tm.ticket_id = ?
            ORDER BY datetime(tm.created_at) ASC
        ");
        $msgStmt->execute([$ticketId]);
        $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get ticket owner username
        $ownerStmt = $this->pdo->prepare("SELECT username FROM users WHERE id = ?");
        $ownerStmt->execute([$ticket['user_id']]);
        $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => (int) $ticket['id'],
                'subject' => $ticket['subject'],
                'status' => $ticket['status'],
                'user' => [
                    'id' => (int) $ticket['user_id'],
                    'username' => $owner['username'] ?? 'Unknown',
                ],
                'created_at' => $ticket['created_at'],
                'updated_at' => $ticket['updated_at'],
                'messages' => array_map(function ($msg) {
                    return [
                        'id' => (int) $msg['id'],
                        'message' => $msg['message'],
                        'is_admin_reply' => (bool) $msg['is_admin_reply'],
                        'user' => [
                            'id' => (int) $msg['user_id'],
                            'username' => $msg['username'],
                        ],
                        'created_at' => $msg['created_at'],
                    ];
                }, $messages),
            ],
        ]);
    }

    /**
     * POST /tickets/{id}/messages
     * Add message to ticket
     */
    public function addMessage(Request $request, Response $response, array $args): Response
    {
        $ticketId = (int) ($args['id'] ?? 0);
        $userId = $request->getAttribute('user_id');
        $isAdmin = $request->getAttribute('is_admin');
        $data = $request->getParsedBody() ?? [];

        $message = trim((string) ($data['message'] ?? ''));

        if ($message === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Nachricht ist erforderlich.',
                ],
            ], 400);
        }

        // Get ticket
        $stmt = $this->pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Ticket nicht gefunden.',
                ],
            ], 404);
        }

        // Check access
        if (!$isAdmin && (int) $ticket['user_id'] !== $userId) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Kein Zugriff auf dieses Ticket.',
                ],
            ], 403);
        }

        // Check if ticket is closed
        if ($ticket['status'] === 'closed' && !$isAdmin) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'TICKET_CLOSED',
                    'message' => 'Dieses Ticket ist geschlossen.',
                ],
            ], 400);
        }

        // Add message
        $isAdminReply = $isAdmin && (int) $ticket['user_id'] !== $userId ? 1 : 0;
        $this->pdo->prepare("
            INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin_reply)
            VALUES (?, ?, ?, ?)
        ")->execute([$ticketId, $userId, $message, $isAdminReply]);

        // Update ticket timestamp
        $this->pdo->prepare("UPDATE tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$ticketId]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Nachricht hinzugefügt.',
            ],
        ], 201);
    }

    /**
     * GET /admin/tickets
     * List all tickets
     */
    public function listAll(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $status = $queryParams['status'] ?? null;

        $sql = "
            SELECT t.*, u.username,
                   (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as message_count
            FROM tickets t
            JOIN users u ON t.user_id = u.id
        ";
        $params = [];

        if ($status) {
            $sql .= " WHERE t.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY datetime(t.updated_at) DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($ticket) {
            return [
                'id' => (int) $ticket['id'],
                'subject' => $ticket['subject'],
                'status' => $ticket['status'],
                'message_count' => (int) $ticket['message_count'],
                'user' => [
                    'id' => (int) $ticket['user_id'],
                    'username' => $ticket['username'],
                ],
                'created_at' => $ticket['created_at'],
                'updated_at' => $ticket['updated_at'],
            ];
        }, $tickets);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'tickets' => $result,
            ],
        ]);
    }

    /**
     * PUT /admin/tickets/{id}/status
     */
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $ticketId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];
        $newStatus = (string) ($data['status'] ?? '');

        $validStatuses = ['open', 'in_progress', 'closed'];
        if (!in_array($newStatus, $validStatuses, true)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Ungültiger Status. Erlaubt: ' . implode(', ', $validStatuses),
                ],
            ], 400);
        }

        $stmt = $this->pdo->prepare("UPDATE tickets SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$newStatus, $ticketId]);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Ticket nicht gefunden.',
                ],
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $ticketId,
                'status' => $newStatus,
                'message' => 'Status aktualisiert.',
            ],
        ]);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
