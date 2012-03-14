<?php

// include 'UPnP.php';
include 'WebSocket.php';
include 'WebSocketClient.php';
include 'WebSocketException.php';

try {

    mb_internal_encoding('UTF-8');
    mb_http_input('UTF-8');
    mb_http_output('UTF-8');

    $serv = new WebSocket('0.0.0.0', 8484);

    $serv->registerResource ('chat');
    $serv->registerResource ('time');

    $serv->setCheckOrigin(array(
        'localhost',
        '127.0.0.1'
    ));

    // 全イベント

    $serv->registerEvent('connect', function ($handle) use (&$serv) {

        printf("connected %s:%d\n", $handle->address, $handle->port);

        printf("now \"server\" connections %d\n", $serv->getConnections());

        foreach ($serv->getAllResourceConnections() as $resource => $connections) {
            printf("now \"%s\" connections %d\n", $resource, $connections);
        }

    });

    $serv->registerEvent('disconnect', function ($handle) use (&$serv) {

        printf(sprintf("disconnected %s:%d\n", $handle->address, $handle->port));

    });

    // チャット用イベント
    $serv->registerEvent('connect', 'chat', function ($client) use (&$serv) {

        $client->sendMessage(sprintf('%s:%d', $client->address, $client->port));

        $serv->broadcastMessage(sprintf('@%d', $serv->getResourceConnections($client->resource)));
        $serv->broadcastMessage(sprintf('%s:%dさんがチャットに参加しました。' . "\n", $client->address, $client->port));

    });

    $serv->registerEvent('disconnect', 'chat', function ($client) use (&$serv) {

        $serv->broadcastMessage(sprintf('@%d', $serv->getResourceConnections($client->resource)));
        $serv->broadcastMessage(sprintf('%s:%dさんがチャットを終了しました。' . "\n", $client->address, $client->port));

    });

    $serv->registerEvent('received-message', 'chat', function ($client, $message) use (&$serv) {

        $serv->broadcastMessage(array(
            $client,
            sprintf('> %s' . "\n", $message),
            sprintf('%s:%dさん: %s' . "\n", $client->address, $client->port, $message)
        ));

    });

    // 時計用イベント
    $serv->registerEvent('connect', 'time', function ($client) use (&$serv) {

        $client->sendMessage((string) time());

        printf("> Sent a time to %s:%d\n", $client->address, $client->port);

        // 終了させる。
        $client->sendClose();

    });

    $serv->serverRun();

} catch (WebSocketException $e) {

    echo $e->getMessage() . "\n";

}