<?php

declare(strict_types=1);
/**
 * This file is part of Simps.
 *
 * @link     https://simps.io
 * @document https://doc.simps.io
 * @license  https://github.com/simple-swoole/simps/blob/master/LICENSE
 */

namespace Simps;

use FastRoute\Dispatcher;
use RuntimeException;
use function FastRoute\simpleDispatcher;

class Route
{
    private static $instance;

    private static $config;

    private static $dispatcher = null;

    private function __construct()
    {
    }

    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();

            self::$config = Config::getInstance()->get('routes', []);
            self::$dispatcher = simpleDispatcher(
                function (\FastRoute\RouteCollector $routerCollector) {
                    foreach (self::$config as $routerDefine) {
                        $routerCollector->addRoute($routerDefine[0], $routerDefine[1], $routerDefine[2]);
                    }
                }
            );
        }
        return self::$instance;
    }

    /**
     * @param $request
     * @param $response
     * @throws \Exception
     * @return mixed|string
     */
    public function dispatch($request, $response)
    {
        $method = $request->server['request_method'] ?? 'GET';
        $uri = $request->server['request_uri'] ?? '/';
        $routeInfo = self::$dispatcher->dispatch($method, $uri);

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return $this->defaultRouter($request, $response, $uri);
                break;
            case Dispatcher::METHOD_NOT_ALLOWED:
                $response->status(405);
                throw new RuntimeException('Request Method Not Allowed', 405);
                break;
            case Dispatcher::FOUND:
                $handler = $routeInfo[1];
                $vars = $routeInfo[2];
                if (is_string($handler)) {
                    $handler = explode('@', $handler);
                    if (count($handler) != 2) {
                        throw new RuntimeException("Router {$uri} config error, Only @ are supported");
                    }

                    $className = $handler[0];
                    $func = $handler[1];

                    if (! class_exists($className)) {
                        throw new RuntimeException("Router {$uri} defined Class Not Found");
                    }

                    $controller = new $className();

                    if (! method_exists($controller, $func)) {
                        throw new RuntimeException("Router {$uri} defined {$func} Method Not Found");
                    }

                    return $controller->{$func}($request, $response, $vars ?? null);
                }
                if (is_callable($handler)) {
                    return call_user_func_array($handler, [$request, $response, $vars ?? null]);
                }

                throw new RuntimeException("Router {$uri} config error");
                break;
        }
        throw new RuntimeException("Undefined router {$uri}");
    }

    /**
     * @param $request
     * @param $response
     * @param $uri
     * @throws \Exception
     * @return mixed
     */
    public function defaultRouter($request, $response, $uri)
    {
        if (empty($uri)) {
            throw new RuntimeException('uri is empty');
        }

        $uri = trim($uri, '/');
        $uri = explode('/', $uri);

        if ($uri[0] === '') {
            $className = '\\App\\Controller\\IndexController';
            if (class_exists($className) && method_exists($className, 'index')) {
                return (new $className())->index($request, $response);
            }
            $response->status(404);
            throw new RuntimeException('The default router index/index class does not exist', 404);
        }
        $response->status(404);
        throw new RuntimeException('Router Not Found', 404);
    }
}
