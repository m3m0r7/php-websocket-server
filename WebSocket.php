<?php

class WebSocket {

    const VERSION = '2.0.0';
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public $callEvent = true;

    public $pingProbability = 1;

    private $clients = array();

    private $events = array();

    private $maxResourceClients = array();

    private $maxServerClients = -1;

    private $displayExceptions = false;

    private $checkOrigin = false;

    private $port = 0;

    private $address = '0.0.0.0';

    private $initEvents = array(

        'overflow-connection' => false,
        'failure-connection' => false,

        'connect' => false,
        'disconnect' => false,

        'send' => false,

        'send-ping' => false,
        'send-pong' => false,
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

    private $handle = null;

    public function __construct ($ip, $port) {

        $this->handle = @socket_create (AF_INET, SOCK_STREAM, SOL_TCP);

        $this->port = $port;
        $this->address = $ip;

        if (@socket_bind($this->handle, $ip, $port) === false) {

            throw new WebSocketException('Socket.Bind.Exception');

        }

        if (@socket_listen($this->handle) === false) {

            throw new WebSocketException('Socket.Listen.Exception');

        }

        // イベントハンドラの初期化

        $this->events = $this->initEvents;

        // サーバーイベントハンドラ
        $this->events['server-connect'] = false;
        $this->events['server-overflow-connection'] = false;
        $this->events['server-failure-connection'] = false;

        // リソースハンドラ
        $this->events['__resource__'] = array();

        // 登録しておく。
        $this->registerResource('/');

    }

    public function triggerEvent ($eventName, &$clientHandle) {

        static $_s = 7; // strlen('server-')

        if ($this->callEvent === false) {

            return;

        }

        if (($clientHandle instanceof WebSocketClient) === true && isset($this->events['__resource__'][$clientHandle->resource]) === true && $this->events['__resource__'][$clientHandle->resource][$eventName] !== false) {

            call_user_func_array($this->events['__resource__'][$clientHandle->resource][$eventName], array_slice(func_get_args(), 1));

        }

        if (isset($this->events[$eventName]) === true && $this->events[$eventName] !== false) {

            if (($clientHandle instanceof WebSocketClient) === true && $clientHandle->hasSocket() === true) {

                call_user_func_array($this->events[$eventName], array_slice(func_get_args(), 1));

            } else if (substr($eventName, 0, $_s) === 'server-') {

                call_user_func($this->events[$eventName]);

            }

        }

    }

    public function registerResource ($resource, $maxClients = -1) {

        if (is_string($resource) === false || isset($this->events['__resource__'][$resource]) === true) {

            throw new WebSocketException('Register.Resource.Name.Exception');

        }

        $this->events['__resource__'][$resource] = $this->initEvents;
        $this->maxResourceClients[$resource]['size'] = (int) $maxClients;
        $this->maxResourceClients[$resource]['connections'] = 0;

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

            if ($resource === '__resource__' || isset($this->events['__resource__'][$resource]) === false || isset($this->events['__resource__'][$resource][$eventName]) === false) {

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

                        if ($message[1] === false) {

                            continue;

                        }

                        $client->sendCommand($message[1], $opcode, $useMask);

                    } else {

                        if ($message[2] === false) {

                            continue;

                        }

                        $client->sendCommand($message[2], $opcode, $useMask);

                    }

                } else {

                    if ($message === false) {

                        continue;

                    }

                    $client->sendCommand($message, $opcode, $useMask);

                }

            } catch (WebSocketException $e) {

                printf("%s\n", $e->getMessage());

            }

        }

    }

    public function serverRun ($callback = null) {

        $dummy = null;

        ob_implicit_flush (true);

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

            $sockets = array_merge(array($this->handle), $this->getClientSockets());

            @socket_select($sockets, $dummy, $dummy, null);

            foreach ($sockets as $handle) {

                try {

                    if ($handle === $this->handle) {

                        $this->triggerEvent ('server-connect', $dummy);

                        if ($this->maxServerClients > -1 && (sizeof($this->clients) + 1) > $this->maxServerClients) {

                            $this->sendHandShakeError (null, 503);

                        } else {

                            $this->registerClient();

                        }

                    } else {

                        $clientHandle = $this->getClientBySocket($handle);

                        if ($clientHandle !== false) {

                            $clientHandle->getMessage();

                        }

                    }

                } catch (WebSocketException $e) {

                    if ($this->displayExceptions === true) {
                        printf("%s\n", $e->getMessage());
                    }

                }

            }

            if ($this->pingProbability > 0 && mt_rand(1, 100) <= $this->pingProbability) {

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

            $request = $clientHandle->read();

            foreach(explode("\n", trim($request)) as $line) {

                if ($state === false) {

                    $header[] = trim($line);

                    $state = true;

                } else {

                    $data = explode(':', $line);

                    $header[strtolower($data[0])] = trim(implode(':', array_slice($data, 1)));

                }

            }

            if (isset($header[0]) === false || preg_match('/^GET (.*?) HTTP/i', $header[0], $match) === 0) {

                $this->sendHandShakeError ($clientHandle, 400);

                return false;

            }

            $resource = trim($match[1]);

            $clientHandle->resource = $resource === '/' ? '/' : substr($resource, 1);

            if ($clientHandle->resource !== '/' && isset($this->maxResourceClients[$clientHandle->resource]) === false) {

                $this->sendHandShakeError ($clientHandle, 400);

                return false;

            }

            $clientHandle->version = isset($header['sec-websocket-version']) === false ? 0 : (int) $header['sec-websocket-version'];

            if (isset($header['sec-websocket-key']) === true) {

                $clientHandle->rfc = true;

                if ($this->checkOrigin !== false) {

                    if (isset($header['origin']) === false && isset($header['sec-websocket-origin']) === false) {

                        $this->sendHandShakeError ($client, 400);

                        return false;

                    }

                    $check = parse_url(isset($header['host']) === true ? $header['host'] : (isset($header['origin']) === true ? $header['origin'] : $header['sec-websocket-origin']));

                    if (isset($check['host']) === false || in_array($check['host'], $this->checkOrigin) === false || (($this->port !== 80 && $this->port !== 443 && isset($check['port']) === false) || $this->port !== $check['port'])) {

                        $this->sendHandShakeError ($client, 400);

                        return false;

                    }

                }

                // send handshake
                $handshake = "HTTP/1.1 101 Switching Protocols\r\n";
                $handshake .= "Upgrade: websocket\r\n";
                $handshake .= "Connection: Upgrade\r\n";
                $handshake .= "Sec-WebSocket-Accept: " . base64_encode(sha1($header['sec-websocket-key'] . WebSocket::GUID, true)) . "\r\n";
                $handshake .= "\r\n";
                $clientHandle->write($handshake);

            } else if (isset($header['sec-websocket-key1'], $header['sec-websocket-key2'], $header['host']) === true) {

                $clientHandle->rfc = false;

                if (isset($header['origin']) === false && isset($header['sec-websocket-origin']) === false) {

                    $this->sendHandShakeError ($clientHandle, 400);

                    return false;

                }

                $origin = isset($header['origin']) === true ? $header['origin'] : $header['sec-websocket-origin'];

                if ($this->checkOrigin !== false) {

                    $check = parse_url(isset($header['host']) === true ? $header['host'] : (isset($header['origin']) === true ? $header['origin'] : $header['sec-websocket-origin']));

                    if (isset($check['host']) === false || in_array($check['host'], $this->checkOrigin) === false || (($this->port !== 80 && $this->port !== 443 && isset($check['port']) === false) || $this->port !== $check['port'])) {

                        $this->sendHandShakeError ($client, 400);

                        return false;

                    }

                }

                // 許可キー
                $accept = '';

                // 固定の最大サイズ
                $maxDigitSize = 10;

                // get spaces
                for ($i = 0; $i < 2; $i++) {

                    $spaces = 0;
                    $digit = '';{
                    $char = $header['sec-websocket-key' . ($i + 1)];

                    for ($j = 0, $size = strlen($char); $j < $size; $j++)
                        if (substr_count('0123456789', $char[$j]) > 0) {
                            $digit .= $char[$j];
                        } else if ($char[$j] === ' ') {
                            $spaces++;
                        }
                    }

                    if ($spaces === 0 || strlen($digit) > $maxDigitSize) {

                        // zero...

                        $this->sendHandShakeError ($clientHandle, 400);

                        return false;

                    }

                    // 11桁以上いかないので、floatで計算しても問題ない。
                    $accept .= pack('N', (int) (((float) $digit) / ((float) $spaces)));

                }

                $handshake = "HTTP/1.1 101 WebSocket Protocol Handshake\r\n";
                $handshake .= "Upgrade: WebSocket\r\n";
                $handshake .= "Connection: Upgrade\r\n";
                $handshake .= "Sec-WebSocket-Origin: " . $origin . "\r\n";
                $handshake .= "Sec-WebSocket-Location: ws://" . $header['host'] . "/" . ($clientHandle->resource !== '/' ? $clientHandle->resource  : '') . "\r\n";
                $handshake .= "\r\n";

                // find body from request
                $body = '';
                for ($i = 0, $size = strlen($request); $i < $size; $i++) {
                    if ($request[$i] === "\r" && isset($request[$i + 1], $request[$i + 2], $request[$i + 3]) === true && $request[$i + 1] === "\n" && $request[$i + 2] === "\r" && $request[$i + 3] === "\n") {
                        $i += 4;
                        break;
                    } else if ($request[$i] === "\n" && isset($request[$i + 1]) === true && $request[$i + 1] === "\n") {
                        $i += 2;
                        break;
                    }
                }
                $body = substr($request, $i);

                // attack code?
                if (strlen($body) !== 8) {

                    $this->sendHandShakeError ($clientHandle, 400);

                    return false;

                }

                // to binary
                $handshake .= md5($accept . $body, true);

                $clientHandle->write($handshake);

            } else {

                $this->sendHandShakeError ($clientHandle, 400);

                return false;

            }

            $clientHandle->id = sha1($clientHandle->timestamp . $clientHandle->address . $clientHandle->port . $clientHandle->resource . $clientHandle->version);

            if ($this->maxResourceClients[$clientHandle->resource]['size'] > -1 && ($this->maxResourceClients[$clientHandle->resource]['connections'] + 1) > $this->maxResourceClients[$clientHandle->resource]['size']) {

                $this->sendHandShakeError ($clientHandle, 503);

                return false;

            }

            // 接続数の増加
            $this->maxResourceClients[$clientHandle->resource]['connections']++;

            $this->clients[] = $clientHandle;

            // connect イベント
            $this->triggerEvent ('connect', $clientHandle);

            return true;

        }

        return false;

    }

    private function sendHandShakeError (&$clientHandle, $errorid = 400) {

        $status = 'HTTP/1.1 ';

        switch ((int) $errorid) {
            case 400:

                $status .= '400 Bad Request';

                if (($clientHandle instanceof WebSocketClient) === false) {

                    $this->triggerEvent('server-failure-connection', $clientHandle);

                } else {

                    $this->triggerEvent('failure-connection', $clientHandle);

                }
            break;
            case 503:

                $status .= '503 Service Unavailable';

                if (($clientHandle instanceof WebSocketClient) === false) {

                    $this->triggerEvent('server-overflow-connection', $clientHandle);

                } else {

                    $this->triggerEvent('overflow-connection', $clientHandle);

                }

            break;
        }

        $handshake = $status . "\r\n";
        $handshake .= "\r\n";

        if (($clientHandle instanceof WebSocketClient) === false) {

            @socket_write($clientHandle, $handshake);

        } else {

            $clientHandle->write($handshake);

            $clientHandle->close();

        }

    }

    public function getClientById ($id) {

        foreach ($this->clients as $_) {

            if ($_->id === $id) {

                return $_;

            }

        }

        return false;

    }

    public function getClientBySocket ($socket) {

        foreach ($this->clients as $_) {

            if ($_->getSocket() === $socket) {

                return $_;

            }

        }

        return false;

    }

    public function getClientSockets () {

        $sockets = array();

        foreach ($this->clients as $_) {

            $sockets[] = $_->getSocket();

        }

        return $sockets;

    }

    public function getClients () {

        return $this->clients;

    }

    public function getConnections () {

        return sizeof($this->clients);

    }

    public function getClientIds () {

        $ids = array();

        foreach ($this->clients as $_) {

            $ids[] = $_->id;

        }

        return $ids;

    }

    public function searchClientKeyByInstance ($client) {

        foreach ($this->clients as $key => $_) {

            if ($client === $_) {

                return $key;

            }

        }

        return false;

    }

    public function removeClientByInstance ($client) {

        if (($key = $this->searchClientKeyByInstance ($client)) !== false) {

            unset($this->clients[$key]);

        }

        return false;

    }

    public function removeClientByKey ($key) {

        if (isset($this->clients[$key]) === true) {

            unset($this->clients[$key]);

        }

        return false;

    }

    public function removeClientById ($id) {

        foreach ($this->clients as $key => $_) {

            if ($_->id === $id) {

                unset($this->clients[$key]);

                return true;

            }

        }

        return false;

    }

    public function setMaxServerClients ($size) {
        $this->maxServerClients = (int) $size;
    }

    public function getResourceConnections ($resource) {

        $resource = (string) $resource;

        if (isset($this->maxResourceClients[$resource]) === false) {

            throw new WebSocketException('Get.Resource.Connections.Exception');

        }

        return $this->maxResourceClients[$resource]['connections'];

    }

    public function getAllResourceConnections () {

        $result = array();

        foreach ($this->maxResourceClients as $key => $value) {

            if ($key === '/') {
                continue;
            }

            $result[$key] = $value['connections'];

        }

        return $result;

    }

    public function setMaxResourceClients ($resource, $size) {

        $resource = (string) $resource;

        if (isset($this->maxResourceClients[$resource]) === false) {

            throw new WebSocketException('Set.Max.Clients.Exception');

        }

        $this->maxResourceClients[$resource]['size'] = (int) $size;
    }

    public function setMaxAllResourceClients ($size) {

        foreach ($this->maxResourceClients as $resource => $_) {

            $this->maxResourceClients[$resource] = (int) $size;

        }

    }

    public function setDisplayExceptions ($bool) {

        $this->displayExceptions = (bool) $bool;

    }

    public function setCheckOrigin ($list) {

        $this->checkOrigin = ($list === false ? false : (array) $list);

    }

    public function amountResourceConnections ($resource, $size) {

        $resource = (string) $resource;

        if (isset($this->maxResourceClients[$resource]) === false) {

            throw new WebSocketException('Amount.Max.Clients.Exception');

        }

        if (($this->maxResourceClients[$resource]['connections'] + ((int) $size)) < 0) {

            $size = 0;

        }

        $this->maxResourceClients[$resource]['connections'] += (int) $size;

    }

}