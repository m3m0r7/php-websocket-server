<?php

// include 'UPnP.php';
include 'WebSocketServer.php';
include 'WebSocketClient.php';
include 'WebSocketEvent.php';
include 'IWebSocketEvent.php';
include 'WebSocketException.php';

try {

    mb_internal_encoding('UTF-8');
    mb_http_input('UTF-8');
    mb_http_output('UTF-8');

    $serv = new WebSocketServer('0.0.0.0', 8484);
    $serv->setDisplayLog (true);

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

    $serv->registerEvent('receivedMessage', 'chat', function ($client, $message) use (&$serv) {

        $serv->broadcastMessage(array(
            $client,
            sprintf('> %s' . "\n", $message),
            sprintf('%s:%dさん: %s' . "\n", $client->address, $client->port, $message)
        ));

    });

    // タイムイベントをクラスで定義する
    class TimeEvent extends WebSocketEvent implements IWebSocketEvent {

        public function connect () {

            $this->client->sendMessage((string) time());

            printf("> Sent a time to %s:%d\n", $this->client->address, $this->client->port);

            // 終了させる。
            $this->client->sendClose();

        }

    }

    $serv->registerEvent(new TimeEvent(), 'time');

    $serv->serverRun();

} catch (WebSocketException $e) {

    echo $e->getMessage() . "\n";

}