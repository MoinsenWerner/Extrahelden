<?php
/**
 * CORS Middleware - Cross-Origin Resource Sharing
 */
declare(strict_types=1);

namespace Extrahelden\Api\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

class CorsMiddleware
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');
        
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            $response = new SlimResponse();
            return $this->addCorsHeaders($response, $origin);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response, $origin);
    }

    private function addCorsHeaders(Response $response, string $origin): Response
    {
        $allowedOrigins = $this->config['allowed_origins'] ?? ['*'];
        
        // Check if origin is allowed
        $allowOrigin = '*';
        if (!in_array('*', $allowedOrigins, true)) {
            if (in_array($origin, $allowedOrigins, true)) {
                $allowOrigin = $origin;
            } else {
                // Origin not allowed - still return headers but with restricted origin
                $allowOrigin = $allowedOrigins[0] ?? '';
            }
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config['allowed_methods'] ?? []))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->config['allowed_headers'] ?? []))
            ->withHeader('Access-Control-Max-Age', (string) ($this->config['max_age'] ?? 86400))
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }
}
