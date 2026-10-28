<?php
declare(strict_types=1);

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        // {id} = ตัวเลข, {code} = ข้อความใน segment เดียว
        preg_match_all('#\{(\w+)\}#', $pattern, $names);
        $regex = preg_replace(['#\{id\}#', '#\{(\w+)\}#'], ['(\d+)', '([^/]+)'], $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler, $names[1]];
    }

    public function get(string $p, callable $h): void { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void { $this->add('POST', $p, $h); }
    public function put(string $p, callable $h): void { $this->add('PUT', $p, $h); }
    public function patch(string $p, callable $h): void { $this->add('PATCH', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(string $method, string $path): never
    {
        $allowed = false;
        foreach ($this->routes as [$m, $regex, $handler, $names]) {
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            $allowed = true;
            if ($m === $method) {
                $args = array_map(fn($v, $name) => $name === 'id' ? (int)$v : urldecode($v), array_slice($match, 1), $names);
                json_out($handler(...$args));
            }
        }
        fail($allowed ? 'Method not allowed' : 'ไม่พบ API นี้', $allowed ? 405 : 404);
    }
}
