<?php
/**
 * Authentication Middleware - JWT validation
 */
declare(strict_types=1);

namespace Extrahelden\Api\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use Extrahelden\Api\Models\JwtHandler;

class AuthMiddleware
{
    private JwtHandler $jwtHandler;
    private bool $requireAdmin;

    public function __construct(JwtHandler $jwtHandler, bool $requireAdmin = false)
    {
        $this->jwtHandler = $jwtHandler;
        $this->requireAdmin = $requireAdmin;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $token = JwtHandler::extractBearerToken($authHeader);

        if (!$token) {
            return $this->unauthorizedResponse('Missing or invalid Authorization header');
        }

        $payload = $this->jwtHandler->validateToken($token);

        if (!$payload) {
            return $this->unauthorizedResponse('Invalid or expired token');
        }

        if ($payload['type'] !== 'access') {
            return $this->unauthorizedResponse('Invalid token type');
        }

        // Check admin requirement
        if ($this->requireAdmin && empty($payload['is_admin'])) {
            return $this->forbiddenResponse('Admin access required');
        }

        // Add user data to request attributes
        $request = $request->withAttribute('user_id', $payload['sub']);
        $request = $request->withAttribute('username', $payload['username']);
        $request = $request->withAttribute('is_admin', $payload['is_admin'] ?? false);
        $request = $request->withAttribute('jwt_payload', $payload);

        return $handler->handle($request);
    }

    private function unauthorizedResponse(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => $message,
            ],
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }

    private function forbiddenResponse(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => $message,
            ],
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(403);
    }
}
