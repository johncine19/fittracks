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

    // 1. Check for REDIS_URL first (standard on Render, Railway, Upstash, Heroku)
    $redisUrl = app_env('REDIS_URL');
    if (!empty($redisUrl)) {
        try {
            $client = new Client($redisUrl, [
                'timeout' => 3.0,
                'read_write_timeout' => 3.0,
            ]);
            $client->ping();
            return $client;
        } catch (Throwable $e) {
            error_log('Redis URL connection error: ' . $e->getMessage());
            $client = null;
        }
    }

    // 2. Fallback to discrete parameters (REDIS_HOST, REDIS_PORT, REDIS_USER, REDIS_PASSWORD)
    $host = app_env('REDIS_HOST');
    if (!$host) {
        return null;
    }

    $port = (int) (app_env('REDIS_PORT') ?: 6379);
    $user = app_env('REDIS_USER');
    $password = app_env('REDIS_PASSWORD');
    $scheme = app_env('REDIS_SCHEME') ?: (($port === 6380 || strtolower((string)app_env('REDIS_TLS')) === 'true') ? 'tls' : 'tcp');

    try {
        $options = [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'timeout' => 3.0,
            'read_write_timeout' => 3.0,
        ];
        if (!empty($user)) {
            $options['username'] = $user;
        }
        if (!empty($password)) {
            $options['password'] = $password;
        }

        $instance = new Client($options);
        $instance->ping();
        $client = $instance;
        return $client;
    } catch (Throwable $e) {
        error_log("Redis connection error ({$scheme}://{$host}:{$port}): " . $e->getMessage());

        // If tcp failed on a remote host, attempt tls fallback (common for cloud-managed Redis)
        if ($scheme === 'tcp' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            try {
                $options['scheme'] = 'tls';
                $instance = new Client($options);
                $instance->ping();
                $client = $instance;
                return $client;
            } catch (Throwable $e2) {
                error_log("Redis TLS fallback connection error: " . $e2->getMessage());
            }
        }

        $client = null;
    }

    return $client;
}
