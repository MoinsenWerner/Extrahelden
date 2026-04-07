<?php
/**
 * Document Controller - File management
 */
declare(strict_types=1);

namespace Extrahelden\Api\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DocumentController
{
    private PDO $pdo;
    private string $basePath;
    private string $uploadsPath;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = $basePath;
        $this->uploadsPath = $basePath . '/uploads';
    }

    /**
     * GET /documents/public
     * List public documents (no auth required)
     */
    public function listPublic(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("
            SELECT id, filename, path 
            FROM documents 
            WHERE is_public = 1 
            ORDER BY filename
        ");
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($doc) {
            return [
                'id' => (int) $doc['id'],
                'filename' => $doc['filename'],
                'download_url' => '/api/v1/documents/' . $doc['id'] . '/download',
            ];
        }, $documents);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'documents' => $result,
            ],
        ]);
    }

    /**
     * GET /documents
     * List documents for authenticated user
     */
    public function listUserDocuments(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $stmt = $this->pdo->prepare("
            SELECT d.id, d.filename, d.path, d.is_public
            FROM documents d
            JOIN user_documents ud ON d.id = ud.document_id
            WHERE ud.user_id = ?
            ORDER BY d.filename
        ");
        $stmt->execute([$userId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($doc) {
            return [
                'id' => (int) $doc['id'],
                'filename' => $doc['filename'],
                'is_public' => (bool) $doc['is_public'],
                'download_url' => '/api/v1/documents/' . $doc['id'] . '/download',
            ];
        }, $documents);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'documents' => $result,
            ],
        ]);
    }

    /**
     * GET /documents/{id}/download
     */
    public function download(Request $request, Response $response, array $args): Response
    {
        $docId = (int) ($args['id'] ?? 0);
        $userId = $request->getAttribute('user_id');
        $isAdmin = $request->getAttribute('is_admin');

        $stmt = $this->pdo->prepare("SELECT id, filename, path, is_public FROM documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Dokument nicht gefunden.',
                ],
            ], 404);
        }

        // Check access: public, admin, or assigned to user
        $hasAccess = (bool) $doc['is_public'] || $isAdmin;
        
        if (!$hasAccess && $userId) {
            $checkStmt = $this->pdo->prepare("SELECT 1 FROM user_documents WHERE user_id = ? AND document_id = ?");
            $checkStmt->execute([$userId, $docId]);
            $hasAccess = (bool) $checkStmt->fetch();
        }

        if (!$hasAccess) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Kein Zugriff auf dieses Dokument.',
                ],
            ], 403);
        }

        $filePath = $this->basePath . '/' . $doc['path'];
        
        if (!file_exists($filePath)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'FILE_NOT_FOUND',
                    'message' => 'Datei nicht gefunden.',
                ],
            ], 404);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath) ?: 'application/octet-stream';
        $fileSize = filesize($filePath);

        $stream = fopen($filePath, 'rb');
        $body = new \Slim\Psr7\Stream($stream);

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $doc['filename'] . '"')
            ->withHeader('Content-Length', (string) $fileSize)
            ->withBody($body);
    }

    /**
     * POST /admin/documents
     * Upload new document (admin only)
     */
    public function upload(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $data = $request->getParsedBody() ?? [];

        if (empty($uploadedFiles['file'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Keine Datei hochgeladen.',
                ],
            ], 400);
        }

        $file = $uploadedFiles['file'];
        
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'UPLOAD_ERROR',
                    'message' => 'Fehler beim Datei-Upload.',
                ],
            ], 400);
        }

        // Generate safe filename
        $originalName = $file->getClientFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $uniqueName = $safeFilename . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $targetPath = 'uploads/' . $uniqueName;
        $fullPath = $this->basePath . '/' . $targetPath;

        // Move uploaded file
        $file->moveTo($fullPath);

        // Insert into database
        $isPublic = !empty($data['is_public']) ? 1 : 0;
        $stmt = $this->pdo->prepare("INSERT INTO documents (filename, path, is_public) VALUES (?, ?, ?)");
        $stmt->execute([$originalName, $targetPath, $isPublic]);
        $docId = (int) $this->pdo->lastInsertId();

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $docId,
                'filename' => $originalName,
                'is_public' => (bool) $isPublic,
            ],
        ], 201);
    }

    /**
     * DELETE /admin/documents/{id}
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $docId = (int) ($args['id'] ?? 0);

        $stmt = $this->pdo->prepare("SELECT path FROM documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Dokument nicht gefunden.',
                ],
            ], 404);
        }

        // Delete file
        $filePath = $this->basePath . '/' . $doc['path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete from database (cascades to user_documents)
        $this->pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$docId]);

        return $response->withStatus(204);
    }

    /**
     * POST /admin/documents/{id}/assign
     */
    public function assign(Request $request, Response $response, array $args): Response
    {
        $docId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];
        $targetUserId = (int) ($data['user_id'] ?? 0);

        if ($targetUserId <= 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Benutzer-ID erforderlich.',
                ],
            ], 400);
        }

        // Check document exists
        $stmt = $this->pdo->prepare("SELECT id FROM documents WHERE id = ?");
        $stmt->execute([$docId]);
        if (!$stmt->fetch()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Dokument nicht gefunden.',
                ],
            ], 404);
        }

        // Check user exists
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        if (!$stmt->fetch()) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Benutzer nicht gefunden.',
                ],
            ], 404);
        }

        // Insert assignment (ignore if exists)
        $this->pdo->prepare("
            INSERT OR IGNORE INTO user_documents (user_id, document_id) VALUES (?, ?)
        ")->execute([$targetUserId, $docId]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'message' => 'Dokument zugewiesen.',
            ],
        ]);
    }

    /**
     * DELETE /admin/documents/{id}/assign/{userId}
     */
    public function unassign(Request $request, Response $response, array $args): Response
    {
        $docId = (int) ($args['id'] ?? 0);
        $targetUserId = (int) ($args['userId'] ?? 0);

        $this->pdo->prepare("
            DELETE FROM user_documents WHERE user_id = ? AND document_id = ?
        ")->execute([$targetUserId, $docId]);

        return $response->withStatus(204);
    }

    /**
     * PATCH /admin/documents/{id}/public
     */
    public function togglePublic(Request $request, Response $response, array $args): Response
    {
        $docId = (int) ($args['id'] ?? 0);
        $data = $request->getParsedBody() ?? [];
        $isPublic = !empty($data['is_public']) ? 1 : 0;

        $stmt = $this->pdo->prepare("UPDATE documents SET is_public = ? WHERE id = ?");
        $stmt->execute([$isPublic, $docId]);

        if ($stmt->rowCount() === 0) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Dokument nicht gefunden.',
                ],
            ], 404);
        }

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'id' => $docId,
                'is_public' => (bool) $isPublic,
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
