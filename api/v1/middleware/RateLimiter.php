<?php
/**
 * Rate Limiting Middleware - SQLite-based request counter
 */
declare(strict_types=1);

namespace Extrahelden\Api\Middleware;

use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

class RateLimiter
{
    private PDO $pdo;
    private array $limits;
    private int $windowSeconds = 60;

    public function __construct(PDO $pdo, array $limits)
    {
        $this->pdo = $pdo;
        $this->limits = $limits;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                ip_address TEXT NOT NULL,
                endpoint_group TEXT NOT NULL,
                request_count INTEGER DEFAULT 1,
                window_start TEXT NOT NULL,
                PRIMARY KEY (ip_address, endpoint_group)
            )
        ");
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $ip = $this->getClientIp($request);
        $path = $request->getUri()->getPath();
        $group = $this->getEndpointGroup($path);
        $limit = $this->limits[$group] ?? $this->limits['default'] ?? 30;

        if (!$this->checkLimit($ip, $group, $limit)) {
            return $this->rateLimitResponse($limit);
        }

        $response = $handler->handle($request);
        
        // Add rate limit headers
        $remaining = max(0, $limit - $this->getCurrentCount($ip, $group));
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $limit)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) ($this->getWindowReset($ip, $group)));
    }

    private function getClientIp(Request $request): string
    {
        // Check forwarded headers (for proxies)
        $headers = ['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP'];
        foreach ($headers as $header) {
            $value = $request->getHeaderLine($header);
            if ($value) {
                $ips = explode(',', $value);
                return trim($ips[0]);
            }
        }
        
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function getEndpointGroup(string $path): string
    {
        if (str_contains($path, '/auth/')) {
            return 'auth';
        }
        if (str_contains($path, '/admin/')) {
            return 'admin';
        }
        if (str_contains($path, '/public/') || str_contains($path, '/servers/') || str_contains($path, '/posts')) {
            return 'public';
        }
        return 'default';
    }

    private function checkLimit(string $ip, string $group, int $limit): bool
    {
        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now - $this->windowSeconds);

        // Clean old entries
        $this->pdo->prepare("
            DELETE FROM rate_limits 
            WHERE datetime(window_start) < datetime(?)
        ")->execute([$windowStart]);

        // Get or create rate limit record
        $stmt = $this->pdo->prepare("
            SELECT request_count, window_start 
            FROM rate_limits 
            WHERE ip_address = ? AND endpoint_group = ?
        ");
        $stmt->execute([$ip, $group]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // New window
            $this->pdo->prepare("
                INSERT INTO rate_limits (ip_address, endpoint_group, request_count, window_start)
                VALUES (?, ?, 1, datetime('now'))
            ")->execute([$ip, $group]);
            return true;
        }

        $count = (int) $row['request_count'];
        
        if ($count >= $limit) {
            return false;
        }

        // Increment counter
        $this->pdo->prepare("
            UPDATE rate_limits 
            SET request_count = request_count + 1 
            WHERE ip_address = ? AND endpoint_group = ?
        ")->execute([$ip, $group]);

        return true;
    }

    private function getCurrentCount(string $ip, string $group): int
    {
        $stmt = $this->pdo->prepare("
            SELECT request_count FROM rate_limits 
            WHERE ip_address = ? AND endpoint_group = ?
        ");
        $stmt->execute([$ip, $group]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['request_count'] : 0;
    }

    private function getWindowReset(string $ip, string $group): int
    {
        $stmt = $this->pdo->prepare("
            SELECT window_start FROM rate_limits 
            WHERE ip_address = ? AND endpoint_group = ?
        ");
        $stmt->execute([$ip, $group]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            return strtotime($row['window_start']) + $this->windowSeconds;
        }
        return time() + $this->windowSeconds;
    }

    private function rateLimitResponse(int $limit): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => "Rate limit exceeded. Maximum $limit requests per minute.",
            ],
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', '60')
            ->withStatus(429);
    }
}
