<?php

class WebSocket {

    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public $handle = null;

    public $clients = array();

    public $callEvent = true;

    public $events = array();

    public $pingProbability = 1;

    private $versions = array(
        7, 8, 13
    );

    private $initEvents = array(

        'connect' => false,
        'disconnect' => false,

        'send' => false,

        'send-ping' => false,
        'send-pong' => false,
        'send-header' => false,
        'send-body' => false,

        'send-close' => false,

        'send-message' => false,
        'send-message-plain' => false,
        'send-message-binary' => false,

        'received' => false,

        'received-ping' => false,
        'received-pong' => false,
        'received-close' => false,

        'received-message' => false,
        'received-message-plain' => false,
        'received-message-binary' => false

    );

    public function __construct ($ip, $port) {

        $this->handle = @socket_create (AF_INET, SOCK_STREAM, SOL_TCP);

        if (@socket_bind($this->handle, $ip, $port) === false) {

            throw new WebSocketException('Socket.Bind.Exception');

        }

        if (@socket_listen($this->handle) === false) {

            throw new WebSocketException('Socket.Listen.Exception');

        }

        // イベントハンドラの初期化

        $this->events = $this->initEvents;

        // リソースハンドラ
        $this->events['__resource__'] = array();

    }

    public function triggerEvent ($eventName, &$clientHandle) {

        if ($this->callEvent === false) {

            return;

        }

        if (($clientHandle instanceof WebSocketClient) === true && isset($this->events['__resource__'][$clientHandle->resource]) === true && $this->events['__resource__'][$clientHandle->resource][$eventName] !== false) {

            call_user_func_array($this->events['__resource__'][$clientHandle->resource][$eventName], array_slice(func_get_args(), 1));

        }

        if (($clientHandle instanceof WebSocketClient) === true && isset($clientHandle->client) === true && isset($this->events[$eventName]) === true && $this->events[$eventName] !== false) {

            call_user_func_array($this->events[$eventName], array_slice(func_get_args(), 1));

        }

    }

    public function registerResource ($resource) {

        if (is_string($resource) === false) {

            throw new WebSocketException('Register.Resource.Name.Exception');

        }

        $this->events['__resource__'][$resource] = $this->initEvents;

    }

    public function registerEvent ($eventName, $callbackORresource, $resourceORcallback = null) {

        if (isset($this->events[$eventName]) === false) {

            throw new WebSocketException('Register.Event.Name.Exception');

        }

        $resource = null;
        $callback = $callbackORresource;

        if ($resourceORcallback !== null) {

            // 変更
            $resource = $callbackORresource;
            $callback = $resourceORcallback;

        }

        if (is_callable($callback) === false) {

            throw new WebSocketException('Register.Event.Callback.Exception');

        }

        if (is_string($resource) === true) {

            if ($resource === '__resource__' || isset($this->events['__resource__'][$resource]) === false) {

                throw new WebSocketException('Register.Event.Resource.Exception');

            }

            $this->events['__resource__'][$resource][$eventName] = $callback;

        } else {

            $this->events[$eventName] = $callback;

        }

    }

    public function broadcastClose () {

        foreach ($this->clients as $client) {

            try {

                $client->sendClose();

            } catch (WebSocketException $e) {

            }

        }

    }

    public function broadcastPing ($message = 'HELLO') {

        $this->broadcastCommand ($message, 0x09);

    }

    public function broadcastMessage ($message) {

        $this->broadcastCommand ($message);

    }

    public function broadcastBinaryMessage ($message) {

        $this->broadcastCommand ($message, 0x02);

    }

    public function broadcastCommand ($message, $opcode = 0x01, $useMask = false) {

        $messageDivide = is_array($message);

        if ($messageDivide === true) {

            if (($message[0] instanceof WebSocketClient) === false) {

                throw new Exception('Send.Broadcast.Arg.Exception');

            }

        }

        foreach ($this->clients as $client) {

            try {

                if ($messageDivide === true) {

                    if ($client === $message[0]) {

                        $client->sendCommand($message[1], $opcode, $useMask);

                    } else {

                        $client->sendCommand($message[2], $opcode, $useMask);

                    }

                } else {

                    $client->sendCommand($message, $opcode, $useMask);

                }

            } catch (WebSocketException $e) {

                printf("%s\n", $e->getMessage());

            }

        }

    }

    public function serverRun ($callback = null) {

        $write = null;
        $except = null;

        socket_set_option($this->handle, SOL_SOCKET, SO_REUSEADDR, 1);

        WebSocketClient::setServer ($this);

        mb_detect_order(array(

            'UTF-8',
            'SJIS-win',
            'eucJP-win',
            'SJIS',
            'EUC-JP',
            'ISO-2022-JP',
            'JIS',
            'UTF-7',
            'ASCII'

        ));

        if (is_callable($callback) === true) {

            $callback($this);

        }

        while (true) {

            $sockets = array_merge(array($this->handle), $this->getClientResources());

            @socket_select($sockets, $write, $except, null);

            foreach ($sockets as $handle) {

                try {

                    if ($handle === $this->handle) {

                        $this->registerClient();

                    } else {

                        $clientHandle = $this->getClient($handle);

                        if ($clientHandle !== false) {

                            $clientHandle->getMessage();

                        }

                    }

                } catch (WebSocketException $e) {

                    printf("%s\n", $e->getMessage());

                }

            }

            if (mt_rand(0, 100) <= $this->pingProbability) {

                $this->broadcastPing();

            }

        }

    }

    private function registerClient () {

        if (($client = @socket_accept ($this->handle)) !== false) {

            socket_set_option($client, SOL_SOCKET, SO_REUSEADDR, 1);

            $clientHandle = new WebSocketClient($client);

            $header = array();

            $state = false;

            foreach(explode("\n", trim($clientHandle->read())) as $line) {

                if ($state === false) {

                    $header[] = trim($line);

                    $state = true;

                } else {

                    $data = explode(':', $line);

                    $header[strtolower($data[0])] = trim(implode(':', array_slice($data, 1)));

                }

            }

            if (isset($header[0]) === false || preg_match('/^GET (.*?) HTTP/i', $header[0], $match) === 0) {

                return false;

            }

            $resource = trim($match[1]);
            $clientHandle->resource = $resource === '/' ? '/' : substr($resource, 1);

            $clientHandle->version = isset($header['sec-websocket-version']) === false ? -1 : (int) $header['sec-websocket-version'];

            if (in_array($clientHandle->version, $this->versions) === false) {

                $handshake = "HTTP/1.1 400 Bad Request\r\n";
                $handshake .= "Sec-WebSocket-Version: " . implode(',', $this->versions) . "\r\n";
                $handshake .= "\r\n";

                $clientHandle->write($handshake);

                $clientHandle->close();

                return false;

            }

            if (isset($header['sec-websocket-key']) === false) {

                $handshake = "HTTP/1.1 400 Bad Request\r\n";
                $handshake .= "\r\n";

                $clientHandle->write($handshake);

                $clientHandle->close();

                return false;

            }

            // send handshake
            $handshake = "HTTP/1.1 101 Switching Protocols\r\n";
            $handshake .= "Upgrade: websocket\r\n";
            $handshake .= "Connection: Upgrade\r\n";
            $handshake .= "Sec-WebSocket-Accept: " . base64_encode(sha1($header['sec-websocket-key'] . WebSocket::GUID, true)) . "\r\n";
            $handshake .= "\r\n";

            $clientHandle->write($handshake);

            $this->clients[] = $clientHandle;

            // connect イベント
            $this->triggerEvent ('connect', $clientHandle);


        }

        return false;

    }

    private function getClient ($client) {

        foreach ($this->clients as $_) {

            if ($_->getResource() === $client) {

                return $_;

            }

        }

        return false;

    }

    private function getClientResources () {

        $sockets = array();

        foreach ($this->clients as $_) {

            $sockets[] = $_->getResource();

        }

        return $sockets;

    }

}