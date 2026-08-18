<?php
declare(strict_types=1);

use Predis\Client;

function redis(): ?Client
{
    static $client = null;
    static $attempted = false;

    if ($attempted) {
        return $client;
    }

    $attempted = true;

    $host = $_ENV['REDIS_HOST'] ?? null;
    $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
    $password = $_ENV['REDIS_PASSWORD'] ?? null;

    if (!$host) {
        return null;
    }

    try {
        $options = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'timeout' => 3.0,
            'read_write_timeout' => 3.0,
        ];
        if (!empty($password)) {
            $options['password'] = $password;
        }

        $instance = new Client($options);
        $instance->ping();
        $client = $instance;
    } catch (Throwable $e) {
        error_log('Redis connection error: ' . $e->getMessage());
        $client = null;
    }

    return $client;
}
