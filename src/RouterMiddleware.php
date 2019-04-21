<?php

namespace Kiss;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class RouterMiddleware implements MiddlewareInterface
{
    /**
     * @var array
     */
    protected $routes = [];

    /**
     * @var array|null
     */
    protected $cache = null;

    /**
     * Router constructor.
     * @param string|null $routes_filepath
     * @param string|null $cache_filepath
     */
    public function __construct(string $routes_filepath = null, string $cache_filepath = null)
    {
        $this->routes_filepath = $routes_filepath; // @TODO EXTRA ADDED, DELETE IT AND FIX IT

        if ($routes_filepath !== null)
        {
            if ($data = file_get_contents($this->routes_filepath)) // E_WARNING if not found
            {
                $routes = json_decode($data, true);

                if (json_last_error() === JSON_ERROR_NONE)
                {
                    try
                    {
                        $this->routes+= $routes;
                    }
                    catch (\Error $e)
                    {
                        // DO NOTHING
                    }
                }
                else
                {
                    trigger_error(json_last_error_msg(), E_USER_WARNING);
                }
            }
        }
    }

    const PATTERN = '{ (.*) ({ (?<key>[a-zA-Z]+) (\:(?<reg>(\\\{|\{.*[^}]\}|.)+))? })? }xU';

    /**
     * @param string $pattern
     * @return string
     */
    public static function patternRegex(string $pattern) : string
    {
        return preg_replace_callback(
            static::PATTERN,
            function ($matches)
            {
                $str = preg_quote($matches[1]);
                if (isset($matches[2]))
                {
                    $key = $matches['key'];
                    $reg = $matches['reg'] ?? '[^/]+';
                    $str.= "(?<$key>$reg)";
                }

                return $str;
            },
            '/'.ltrim($pattern, '/')
        );
    }

    /**
     * @param string $pattern
     * @param string $action
     */
    public function route(string $pattern, string $action)
    {
        $regex = static::patternRegex($pattern);

        $this->routex($this->routes, $regex, $action);
    }

    /**
     * @param array $routes
     * @param string $regex
     * @param string $action
     */
    protected function routex(array &$routes, string $regex, string $action)
    {
        $regexs = preg_split('{//+}', $regex, 2, PREG_SPLIT_NO_EMPTY);

        // '/+'.
        $regexs[0] = ($regexs[0] ?? '');

        if (isset($regexs[1]))
        {
            if (isset($routes[$regexs[0]]))
            {
                if (is_string($routes[$regexs[0]]))
                {
                    $routes[$regexs[0]] = ['/*' => $routes[$regexs[0]]];
                }

                $this->routex($routes[$regexs[0]], $regexs[1], $action);
            }
            else
            {
                $this->routex($routes[$regexs[0]] = [], $regexs[1], $action);
            }
        }
        else
        {
            $routes[$regexs[0]] = $action;
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @param array $routes
     * @param int $offset
     * @return mixed|null
     */
    public static function match(ServerRequestInterface $request, array &$routes, int $offset = 0)
    {
        $path = substr($request->getUri()->getPath(), $offset);

        foreach ($routes as $pattern => &$route)
        {
            $final = is_string($route);

            if (preg_match($p='{^'.$pattern.($final?'$':'').'}', $path, $matches))
            {
                $request2 = $request;
                foreach (array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY) as $key => $value)
                {
                    $request2 = $request2->withAttribute($key, $value);
                }

                if ($final)
                {
                    /** @var RequestHandlerInterface $handler */
                    $handler = new $route;
                    return $handler->handle($request2);
                }
                else
                {
                    $response = static::match($request2, $route, $offset+strlen($matches[0]));

                    if ($response !== null)
                    {
                        return $response;
                    }
                }
            }
        }

        return null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Cache loading
        if ($this->routes_filepath)
        {
            if ($data = file_get_contents($this->routes_filepath))
            {
                $json = json_decode($data, true);

                if (json_last_error() == JSON_ERROR_NONE)
                {
                    if (is_array($json))
                    {
                        $this->routes = $json;
                    }
                    else
                    {
                        // WARNING_FORMAT
                    }
                }
                else
                {
                    // WARNING_JSON
                }
            }
            else
            {
                // NOTICE_READ_FILE
            }
        }

        if ($response = static::match($request, $this->routes))
        {
            return $response;
        }
        else
        {
            return $handler->handle($request);
        }
    }
}
