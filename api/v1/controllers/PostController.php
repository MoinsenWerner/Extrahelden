<?php
/**
 * Post Controller - News/Posts endpoints
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PostController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * GET /posts
     * Public endpoint - returns published posts
     */
    public function list(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $limit = min(100, max(1, (int) ($queryParams['limit'] ?? 50)));
        $offset = max(0, (int) ($queryParams['offset'] ?? 0));

        $stmt = $this->pdo->prepare("
            SELECT id, title, content, created_at, image_path
            FROM posts
            WHERE published = 1
            ORDER BY datetime(created_at) DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get total count
        $totalStmt = $this->pdo->query("SELECT COUNT(*) FROM posts WHERE published = 1");
        $total = (int) $totalStmt->fetchColumn();

        $result = array_map(function ($post) {
            return [
                'id' => (int) $post['id'],
                'title' => $post['title'],
                'content' => $post['content'],
                'created_at' => $post['created_at'],
                'image_url' => $post['image_path'] ?: null,
            ];
        }, $posts);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'posts' => $result,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + count($posts)) < $total,
                ],
            ],
        ]);
    }

    /**
     * GET /posts/{id}
     * Get single published post
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $postId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("
            SELECT id, title, content, created_at, image_path
            FROM posts
            WHERE id = ? AND published = 1
        ");
        $stmt->execute([$postId]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$post) {
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
                'id' => (int) $post['id'],
                'title' => $post['title'],
                'content' => $post['content'],
                'created_at' => $post['created_at'],
                'image_url' => $post['image_path'] ?: null,
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
