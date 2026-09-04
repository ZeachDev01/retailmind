<?php

namespace App\Core;

abstract class Controller
{
    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    protected function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    protected function view(string $path, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require $path;
    }
}
