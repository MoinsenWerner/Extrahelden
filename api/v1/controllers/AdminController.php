<?php
/**
 * Admin Controller - User, Post, Server Management
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    private PDO $pdo;
    private string $basePath;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = $basePath;
    }

    // ==================
    // USER MANAGEMENT
    // ==================

    /**
     * GET /admin/users
     */
    public function listUsers(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("
            SELECT u.id, u.username, u.is_admin, u.discord_name, u.calendar_color,
                   (SELECT COUNT(*) FROM user_documents WHERE user_id = u.id) as document_count
            FROM users u
            ORDER BY u.username
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($user) {
            return [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'is_admin' => (bool) $user['is_admin'],
                'discord_name' => $user['discord_name'],
                'calendar_color' => $user['calendar_color'],
                'document_count' => (int) $user['document_count'],
            ];
        }, $users);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'users' => $result,
            ],
        ]);
    }

    /**
     * POST /admin/users
     */
    public function createUser(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $isAdmin = !empty($data['is_admin']) ? 1 : 0;
        $discordName = trim((string) ($data['discord_name'] ?? ''));

        if ($username === '' || $password === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Benutzername und Passwort sind erforderlich.',
                ],
            ], 400);
        }

        if (strlen($password) < 8) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Passwort muss mindestens 8 Zeichen lang sein.',
                ],
            ], 400);
        }

        // Check username exists
        $checkStmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->execute([$username]);
        if ($checkStmt->fetch()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'USERNAME_EXISTS',
                    'message' => 'Benutzername existiert bereits.',
                ],
            ], 400);
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $this->pdo->prepare("
            INSERT INTO users (username, password_hash, is_admin, discord_name)
            VALUES (?, ?, ?, ?)
        ")->execute([$username, $passwordHash, $isAdmin, $discordName ?: null]);

        $userId = (int) $this->pdo->lastInsertId();

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $userId,
                'username' => $username,
                'is_admin' => (bool) $isAdmin,
            ],
        ], 201);
    }

    /**
     * DELETE /admin/users/{id}
     */
    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);
        $currentUserId = $request->getAttribute('user_id');

        if ($userId === $currentUserId) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'CANNOT_DELETE_SELF',
                    'message' => 'Du kannst dich nicht selbst löschen.',
                ],
            ], 400);
        }

        $this->pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

        return $response->withStatus(204);
    }

    /**
     * PUT /admin/users/{id}/password
     */
    public function setUserPassword(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];
        $newPassword = (string) ($data['password'] ?? '');

        if (strlen($newPassword) < 8) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Passwort muss mindestens 8 Zeichen lang sein.',
                ],
            ], 400);
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$passwordHash, $userId]);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Benutzer nicht gefunden.',
                ],
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Passwort aktualisiert.',
            ],
        ]);
    }

    // ==================
    // POST MANAGEMENT
    // ==================

    /**
     * GET /admin/posts
     */
    public function listAllPosts(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("
            SELECT id, title, content, created_at, published, image_path
            FROM posts
            ORDER BY datetime(created_at) DESC
        ");
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($post) {
            return [
                'id' => (int) $post['id'],
                'title' => $post['title'],
                'content' => $post['content'],
                'published' => (bool) $post['published'],
                'image_url' => $post['image_path'] ?: null,
                'created_at' => $post['created_at'],
            ];
        }, $posts);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'posts' => $result,
            ],
        ]);
    }

    /**
     * POST /admin/posts
     */
    public function createPost(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $uploadedFiles = $request->getUploadedFiles();

        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        $published = !empty($data['published']) ? 1 : 0;

        if ($title === '' || $content === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Titel und Inhalt sind erforderlich.',
                ],
            ], 400);
        }

        $imagePath = null;
        if (!empty($uploadedFiles['image']) && $uploadedFiles['image']->getError() === UPLOAD_ERR_OK) {
            $file = $uploadedFiles['image'];
            $ext = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
            $uniqueName = 'post_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $imagePath = 'uploads/' . $uniqueName;
            $file->moveTo($this->basePath . '/' . $imagePath);
        }

        $this->pdo->prepare("
            INSERT INTO posts (title, content, published, image_path)
            VALUES (?, ?, ?, ?)
        ")->execute([$title, $content, $published, $imagePath]);

        $postId = (int) $this->pdo->lastInsertId();

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $postId,
                'title' => $title,
                'published' => (bool) $published,
            ],
        ], 201);
    }

    /**
     * PUT /admin/posts/{id}
     */
    public function updatePost(Request $request, Response $response, array $args): Response
    {
        $postId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];

        $updates = [];
        $params = [];

        if (isset($data['title'])) {
            $updates[] = 'title = ?';
            $params[] = trim($data['title']);
        }
        if (isset($data['content'])) {
            $updates[] = 'content = ?';
            $params[] = trim($data['content']);
        }
        if (isset($data['published'])) {
            $updates[] = 'published = ?';
            $params[] = $data['published'] ? 1 : 0;
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

        $params[] = $postId;
        $sql = "UPDATE posts SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Post nicht gefunden.',
                ],
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Post aktualisiert.',
            ],
        ]);
    }

    /**
     * DELETE /admin/posts/{id}
     */
    public function deletePost(Request $request, Response $response, array $args): Response
    {
        $postId = (int) ($args['id'] ?? 0);

        // Delete associated image
        $stmt = $this->pdo->prepare("SELECT image_path FROM posts WHERE id = ?");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($post && $post['image_path']) {
            $filePath = $this->basePath . '/' . $post['image_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $this->pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);

        return $response->withStatus(204);
    }

    /**
     * PATCH /admin/posts/{id}/publish
     */
    public function togglePublishPost(Request $request, Response $response, array $args): Response
    {
        $postId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("UPDATE posts SET published = NOT published WHERE id = ?");
        $stmt->execute([$postId]);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Post nicht gefunden.',
                ],
            ], 404);
        }

        // Get new state
        $getStmt = $this->pdo->prepare("SELECT published FROM posts WHERE id = ?");
        $getStmt->execute([$postId]);
        $post = $getStmt->fetch(PDO::FETCH_ASSOC);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $postId,
                'published' => (bool) $post['published'],
            ],
        ]);
    }

    // ==================
    // SERVER MANAGEMENT
    // ==================

    /**
     * GET /admin/servers
     */
    public function listServers(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("
            SELECT id, name, host, port, enabled, sort_order, created_at
            FROM minecraft_servers
            ORDER BY sort_order, name
        ");
        $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($server) {
            return [
                'id' => (int) $server['id'],
                'name' => $server['name'],
                'host' => $server['host'],
                'port' => (int) $server['port'],
                'enabled' => (bool) $server['enabled'],
                'sort_order' => (int) $server['sort_order'],
                'created_at' => $server['created_at'],
            ];
        }, $servers);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'servers' => $result,
            ],
        ]);
    }

    /**
     * POST /admin/servers
     */
    public function addServer(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $name = trim((string) ($data['name'] ?? ''));
        $host = trim((string) ($data['host'] ?? ''));
        $port = (int) ($data['port'] ?? 25565);

        if ($name === '' || $host === '') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Name und Host sind erforderlich.',
                ],
            ], 400);
        }

        // Get max sort_order
        $maxOrder = (int) $this->pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM minecraft_servers")->fetchColumn();

        $this->pdo->prepare("
            INSERT INTO minecraft_servers (name, host, port, enabled, sort_order)
            VALUES (?, ?, ?, 1, ?)
        ")->execute([$name, $host, $port, $maxOrder + 1]);

        $serverId = (int) $this->pdo->lastInsertId();

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $serverId,
                'name' => $name,
                'host' => $host,
                'port' => $port,
            ],
        ], 201);
    }

    /**
     * DELETE /admin/servers/{id}
     */
    public function deleteServer(Request $request, Response $response, array $args): Response
    {
        $serverId = (int) ($args['id'] ?? 0);

        $this->pdo->prepare("DELETE FROM minecraft_servers WHERE id = ?")->execute([$serverId]);
        $this->pdo->prepare("DELETE FROM server_status_cache WHERE server_id = ?")->execute([$serverId]);

        return $response->withStatus(204);
    }

    /**
     * PATCH /admin/servers/{id}/order
     */
    public function updateServerOrder(Request $request, Response $response, array $args): Response
    {
        $serverId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];
        $direction = (string) ($data['direction'] ?? '');

        if (!in_array($direction, ['up', 'down'], true)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Richtung muss "up" oder "down" sein.',
                ],
            ], 400);
        }

        // Get current server
        $stmt = $this->pdo->prepare("SELECT sort_order FROM minecraft_servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$server) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Server nicht gefunden.',
                ],
            ], 404);
        }

        $currentOrder = (int) $server['sort_order'];
        $newOrder = $direction === 'up' ? $currentOrder - 1 : $currentOrder + 1;

        // Swap with adjacent server
        $this->pdo->prepare("UPDATE minecraft_servers SET sort_order = ? WHERE sort_order = ?")
            ->execute([$currentOrder, $newOrder]);
        $this->pdo->prepare("UPDATE minecraft_servers SET sort_order = ? WHERE id = ?")
            ->execute([$newOrder, $serverId]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Reihenfolge aktualisiert.',
            ],
        ]);
    }

    /**
     * PATCH /admin/servers/{id}/toggle
     */
    public function toggleServer(Request $request, Response $response, array $args): Response
    {
        $serverId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("UPDATE minecraft_servers SET enabled = NOT enabled WHERE id = ?");
        $stmt->execute([$serverId]);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Server nicht gefunden.',
                ],
            ], 404);
        }

        $getStmt = $this->pdo->prepare("SELECT enabled FROM minecraft_servers WHERE id = ?");
        $getStmt->execute([$serverId]);
        $server = $getStmt->fetch(PDO::FETCH_ASSOC);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $serverId,
                'enabled' => (bool) $server['enabled'],
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
