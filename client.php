<?php

use React\Promise\Deferred;
use React\Socket\ConnectionInterface;

require __DIR__ . "/vendor/autoload.php";

$loop = \React\EventLoop\Factory::create();
$client = new \React\Socket\Connector($loop);
$client->connect("unix:///tmp/server.sock")
    ->then(function (ConnectionInterface $conn) {
        $response = new Deferred();
        $conn->on('data', function ($data) use ($response) {
            $response->resolve($data);
        });
        $conn->write("hi\n");
        $response->promise()->then(function () use ($conn) {
            $conn->close();
        });
    });
