<?php

namespace App\Core;

class Router
{
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];

        // ВАЖНО: берём ровно то, что передали в route
        $uri = $_SERVER['REQUEST_URI'];

        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {

            // regex маршрут
            if (preg_match('#^' . $pattern . '$#', $uri, $matches)) {
                array_shift($matches);
                call_user_func_array($handler, $matches);
                return;
            }
        }

        http_response_code(404);
        echo 'Not Found';
    }
}
