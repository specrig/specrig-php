<?php

declare(strict_types=1);

[, $socket, $log] = $argv;
$server = stream_socket_server("unix://{$socket}");
echo "ready\n";
while ($client = stream_socket_accept($server, -1)) {
    [$method, $path] = explode(' ', (string) fgets($client));
    $length = 0;
    while (($line = trim((string) fgets($client))) !== '') {
        if (stripos($line, 'Content-Length:') === 0) {
            $length = (int) substr($line, strlen('Content-Length:'));
        }
    }
    $body = $length > 0 ? (string) stream_get_contents($client, $length) : '';
    file_put_contents($log, json_encode(['method' => $method, 'path' => $path, 'body' => json_decode($body, true)]) . "\n", FILE_APPEND);
    $answer = match (true) {
        str_starts_with($path, '/containers/json') => '[]',
        str_starts_with($path, '/containers/create') => '{"Id":"made"}',
        default => '{}',
    };
    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($answer) . "\r\nConnection: close\r\n\r\n{$answer}");
    fclose($client);
}
