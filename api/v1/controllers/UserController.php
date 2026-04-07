<?php
/**
 * User Controller - Profile management
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * GET /user/profile
     */
    public function getProfile(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $stmt = $this->pdo->prepare('
            SELECT id, username, is_admin, discord_name, calendar_color
            FROM users WHERE id = ?
        ');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Benutzer nicht gefunden.',
                ],
            ], 404);
        }

        // Get document count
        $docStmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_documents WHERE user_id = ?');
        $docStmt->execute([$userId]);
        $documentCount = (int) $docStmt->fetchColumn();

        // Get ticket count
        $ticketStmt = $this->pdo->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ?");
        $ticketStmt->execute([$userId]);
        $ticketCount = (int) $ticketStmt->fetchColumn();

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'is_admin' => (bool) $user['is_admin'],
                'discord_name' => $user['discord_name'],
                'calendar_color' => $user['calendar_color'],
                'stats' => [
                    'documents' => $documentCount,
                    'tickets' => $ticketCount,
                ],
            ],
        ]);
    }

    /**
     * PUT /user/profile
     */
    public function updateProfile(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody() ?? [];

        $allowedFields = ['discord_name', 'calendar_color'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updates)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Keine aktualisierbaren Felder angegeben.',
                ],
            ], 400);
        }

        $params[] = $userId;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $this->pdo->prepare($sql)->execute($params);

        return $this->getProfile($request, $response);
    }

    /**
     * PUT /user/password
     */
    public function changePassword(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data = $request->getParsedBody() ?? [];

        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? '');

        // Validation
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Alle Passwortfelder sind erforderlich.',
                ],
            ], 400);
        }

        if (strlen($newPassword) < 8) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Das neue Passwort muss mindestens 8 Zeichen lang sein.',
                ],
            ], 400);
        }

        if ($newPassword !== $confirmPassword) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Die Passwörter stimmen nicht überein.',
                ],
            ], 400);
        }

        // Verify current password
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'INVALID_PASSWORD',
                    'message' => 'Das aktuelle Passwort ist falsch.',
                ],
            ], 401);
        }

        // Update password
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, $userId]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Passwort erfolgreich geändert.',
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
