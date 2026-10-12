<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
[$script, $method, $uri, $token, $payload, $barrier, $id] = $argv;
file_put_contents($barrier.'.'.$id.'.ready', 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier.'.go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Request barrier timed out.');
    }
    usleep(10000);
}
$request = Request::create($uri, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], $payload);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
$kernel->terminate($request, $response);
