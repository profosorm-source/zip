<?php
/**
 * Health check routes (bypass most middleware for load balancers / k8s / monitoring)
 *
 * BUGFIX-HEALTH-ROUTES-2026-06: Standard version-less health endpoints
 * registered first so they cannot be shadowed by application middleware
 * groups or catch-all 404 handlers.
 */

use Core\Router;

$router = app()->router;

// Simple liveness probe
$router->get('/health', function () {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'time' => date('c'),
        'app' => config('app.name', 'Chortke'),
    ]);
    exit;
});

// Liveness — is the process alive?
$router->get('/health/live', ['App\Controllers\Api\HealthCheckController', 'live']);

// Readiness — can the process serve traffic?
$router->get('/health/ready', ['App\Controllers\Api\HealthCheckController', 'ready']);

// API health alias
$router->get('/api/health', ['App\Controllers\Api\HealthCheckController', 'live']);

// Ping (legacy)
$router->get('/ping', function () {
    echo 'pong';
    exit;
});

// Distributed system health / metrics endpoints (version-less monitoring aliases)
$router->get('/health/distributed', ['App\\Controllers\\Api\\HealthCheckController', 'distributed']);
$router->get('/metrics/distributed', ['App\\Controllers\\Api\\HealthCheckController', 'metrics']);
