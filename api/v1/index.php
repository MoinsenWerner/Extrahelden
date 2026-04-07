<?php
/**
 * Extrahelden REST API v1
 * Main router using Slim Framework
 */
declare(strict_types=1);

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

// Load config
$config = require __DIR__ . '/config/api_config.php';

// Initialize database connection (reuse existing db.php logic)
$basePath = dirname(__DIR__, 2);
$dbFile = $basePath . '/database.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'DB_CONNECTION_ERROR',
            'message' => 'Database connection failed.',
        ],
    ]);
    exit;
}

// Initialize Slim App
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Import middleware
use Extrahelden\Api\Middleware\CorsMiddleware;
use Extrahelden\Api\Middleware\RateLimiter;
use Extrahelden\Api\Middleware\AuthMiddleware;

// Import controllers
use Extrahelden\Api\Controllers\AuthController;
use Extrahelden\Api\Controllers\ServerController;
use Extrahelden\Api\Controllers\PostController;
use Extrahelden\Api\Controllers\UserController;
use Extrahelden\Api\Controllers\DocumentController;
use Extrahelden\Api\Controllers\ApplicationController;
use Extrahelden\Api\Controllers\TicketController;
use Extrahelden\Api\Controllers\CalendarController;
use Extrahelden\Api\Controllers\AdminController;
use Extrahelden\Api\Controllers\SettingsController;
use Extrahelden\Api\Controllers\AutomatorController;

// Import models
use Extrahelden\Api\Models\JwtHandler;

$app = AppFactory::create();

// Set base path for API
$app->setBasePath('/api/v1');

// Add body parsing middleware
$app->addBodyParsingMiddleware();

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Initialize JWT Handler
$jwtHandler = new JwtHandler($config['jwt']);

// Initialize Controllers
$authController = new AuthController($pdo, $jwtHandler, $config);
$serverController = new ServerController($pdo);
$postController = new PostController($pdo);

// Lazy-load other controllers only when needed
$controllers = [
    'user' => fn() => new UserController($pdo),
    'document' => fn() => new DocumentController($pdo, $basePath),
    'application' => fn() => new ApplicationController($pdo, $basePath),
    'ticket' => fn() => new TicketController($pdo),
    'calendar' => fn() => new CalendarController($pdo),
    'admin' => fn() => new AdminController($pdo, $basePath),
    'settings' => fn() => new SettingsController($pdo),
    'automator' => fn() => new AutomatorController($pdo),
];

// ===================
// MIDDLEWARE
// ===================

// CORS (applied globally)
$app->add(new CorsMiddleware($config['cors']));

// Rate Limiter (applied globally)
$app->add(new RateLimiter($pdo, $config['rate_limits']));

// Auth middleware instances
$authMiddleware = new AuthMiddleware($jwtHandler, false);
$adminMiddleware = new AuthMiddleware($jwtHandler, true);

// ===================
// PUBLIC ROUTES (No Auth Required)
// ===================

// Health check
$app->get('/health', function (Request $request, Response $response) use ($config) {
    $response->getBody()->write(json_encode([
        'success' => true,
        'data' => [
            'status' => 'ok',
            'version' => $config['version'],
            'timestamp' => date('c'),
        ],
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Auth endpoints
$app->post('/auth/login', [$authController, 'login']);
$app->post('/auth/refresh', [$authController, 'refresh']);
$app->get('/auth/discord', [$authController, 'discordRedirect']);
$app->get('/auth/discord/callback', [$authController, 'discordCallback']);

// Server status (public)
$app->get('/servers/status', [$serverController, 'status']);
$app->get('/servers/{id}/status', [$serverController, 'singleStatus']);

// Posts (public)
$app->get('/posts', [$postController, 'list']);
$app->get('/posts/{id}', [$postController, 'get']);

// Application settings (public)
$app->get('/settings/apply', function (Request $request, Response $response) use ($pdo) {
    $enabled = false;
    $title = 'Projekt-Anmeldung';
    
    $stmt = $pdo->prepare("SELECT key, value FROM site_settings WHERE key IN ('apply_enabled', 'apply_title')");
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        if ($row['key'] === 'apply_enabled') $enabled = $row['value'] === '1';
        if ($row['key'] === 'apply_title') $title = $row['value'];
    }
    
    $response->getBody()->write(json_encode([
        'success' => true,
        'data' => [
            'enabled' => $enabled,
            'title' => $title,
        ],
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Submit application (public)
$app->post('/applications', function (Request $request, Response $response) use ($controllers) {
    return $controllers['application']()->submit($request, $response);
});

// Public documents (downloads)
$app->get('/documents/public', function (Request $request, Response $response) use ($controllers) {
    return $controllers['document']()->listPublic($request, $response);
});

// ===================
// AUTHENTICATED ROUTES (User)
// ===================

$app->group('', function ($group) use ($authController, $controllers) {
    // Auth
    $group->post('/auth/logout', [$authController, 'logout']);
    $group->get('/auth/me', [$authController, 'me']);
    
    // User profile
    $group->get('/user/profile', function (Request $request, Response $response) use ($controllers) {
        return $controllers['user']()->getProfile($request, $response);
    });
    $group->put('/user/profile', function (Request $request, Response $response) use ($controllers) {
        return $controllers['user']()->updateProfile($request, $response);
    });
    $group->put('/user/password', function (Request $request, Response $response) use ($controllers) {
        return $controllers['user']()->changePassword($request, $response);
    });
    
    // User documents
    $group->get('/documents', function (Request $request, Response $response) use ($controllers) {
        return $controllers['document']()->listUserDocuments($request, $response);
    });
    $group->get('/documents/{id}/download', function (Request $request, Response $response, array $args) use ($controllers) {
        return $controllers['document']()->download($request, $response, $args);
    });
    
    // Tickets
    $group->get('/tickets', function (Request $request, Response $response) use ($controllers) {
        return $controllers['ticket']()->listUserTickets($request, $response);
    });
    $group->post('/tickets', function (Request $request, Response $response) use ($controllers) {
        return $controllers['ticket']()->create($request, $response);
    });
    $group->get('/tickets/{id}', function (Request $request, Response $response, array $args) use ($controllers) {
        return $controllers['ticket']()->get($request, $response, $args);
    });
    $group->post('/tickets/{id}/messages', function (Request $request, Response $response, array $args) use ($controllers) {
        return $controllers['ticket']()->addMessage($request, $response, $args);
    });
    
})->add($authMiddleware);

// ===================
// ADMIN ROUTES
// ===================

$app->group('/admin', function ($group) use ($controllers) {
    // Users management
    $group->get('/users', fn($req, $res) => $controllers['admin']()->listUsers($req, $res));
    $group->post('/users', fn($req, $res) => $controllers['admin']()->createUser($req, $res));
    $group->delete('/users/{id}', fn($req, $res, $args) => $controllers['admin']()->deleteUser($req, $res, $args));
    $group->put('/users/{id}/password', fn($req, $res, $args) => $controllers['admin']()->setUserPassword($req, $res, $args));
    
    // Documents management
    $group->post('/documents', fn($req, $res) => $controllers['document']()->upload($req, $res));
    $group->delete('/documents/{id}', fn($req, $res, $args) => $controllers['document']()->delete($req, $res, $args));
    $group->post('/documents/{id}/assign', fn($req, $res, $args) => $controllers['document']()->assign($req, $res, $args));
    $group->delete('/documents/{id}/assign/{userId}', fn($req, $res, $args) => $controllers['document']()->unassign($req, $res, $args));
    $group->patch('/documents/{id}/public', fn($req, $res, $args) => $controllers['document']()->togglePublic($req, $res, $args));
    
    // Posts management
    $group->get('/posts', fn($req, $res) => $controllers['admin']()->listAllPosts($req, $res));
    $group->post('/posts', fn($req, $res) => $controllers['admin']()->createPost($req, $res));
    $group->put('/posts/{id}', fn($req, $res, $args) => $controllers['admin']()->updatePost($req, $res, $args));
    $group->delete('/posts/{id}', fn($req, $res, $args) => $controllers['admin']()->deletePost($req, $res, $args));
    $group->patch('/posts/{id}/publish', fn($req, $res, $args) => $controllers['admin']()->togglePublishPost($req, $res, $args));
    
    // Server management
    $group->get('/servers', fn($req, $res) => $controllers['admin']()->listServers($req, $res));
    $group->post('/servers', fn($req, $res) => $controllers['admin']()->addServer($req, $res));
    $group->delete('/servers/{id}', fn($req, $res, $args) => $controllers['admin']()->deleteServer($req, $res, $args));
    $group->patch('/servers/{id}/order', fn($req, $res, $args) => $controllers['admin']()->updateServerOrder($req, $res, $args));
    $group->patch('/servers/{id}/toggle', fn($req, $res, $args) => $controllers['admin']()->toggleServer($req, $res, $args));
    
    // Applications management
    $group->get('/applications', fn($req, $res) => $controllers['application']()->listAll($req, $res));
    $group->get('/applications/{id}', fn($req, $res, $args) => $controllers['application']()->get($req, $res, $args));
    $group->post('/applications/{id}/accept', fn($req, $res, $args) => $controllers['application']()->accept($req, $res, $args));
    $group->post('/applications/{id}/reject', fn($req, $res, $args) => $controllers['application']()->reject($req, $res, $args));
    $group->post('/applications/{id}/shortlist', fn($req, $res, $args) => $controllers['application']()->shortlist($req, $res, $args));
    $group->delete('/applications/{id}', fn($req, $res, $args) => $controllers['application']()->delete($req, $res, $args));
    
    // Tickets management
    $group->get('/tickets', fn($req, $res) => $controllers['ticket']()->listAll($req, $res));
    $group->put('/tickets/{id}/status', fn($req, $res, $args) => $controllers['ticket']()->updateStatus($req, $res, $args));
    
    // Calendar management
    $group->get('/calendar', fn($req, $res) => $controllers['calendar']()->list($req, $res));
    $group->post('/calendar', fn($req, $res) => $controllers['calendar']()->create($req, $res));
    $group->put('/calendar/{id}', fn($req, $res, $args) => $controllers['calendar']()->update($req, $res, $args));
    $group->delete('/calendar/{id}', fn($req, $res, $args) => $controllers['calendar']()->delete($req, $res, $args));
    
    // Settings
    $group->get('/settings', fn($req, $res) => $controllers['settings']()->getAll($req, $res));
    $group->put('/settings', fn($req, $res) => $controllers['settings']()->update($req, $res));
    
    // Automator
    $group->get('/automator/rules', fn($req, $res) => $controllers['automator']()->listRules($req, $res));
    $group->post('/automator/rules', fn($req, $res) => $controllers['automator']()->createRule($req, $res));
    $group->delete('/automator/rules/{id}', fn($req, $res, $args) => $controllers['automator']()->deleteRule($req, $res, $args));
    $group->get('/automator/events', fn($req, $res) => $controllers['automator']()->listEvents($req, $res));
    $group->post('/automator/events', fn($req, $res) => $controllers['automator']()->createEvent($req, $res));
    $group->delete('/automator/events/{id}', fn($req, $res, $args) => $controllers['automator']()->deleteEvent($req, $res, $args));
    
})->add($adminMiddleware);

// ===================
// RUN APP
// ===================

$app->run();
