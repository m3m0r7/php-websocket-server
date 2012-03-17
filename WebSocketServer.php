<?php

class WebSocketServer {

    const VERSION = '2.1.0';
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    const LOG_INFO = 0;
    const LOG_WARNING = 1;
    const LOG_ERROR = 2;
    const LOG_NOTICE = 3;
    const LOG_EXCEPTION = 4;
    const LOG_WAIT = 5;
    const LOG_SUCCESS = 6;

    public $callEvent = true;

    public $pingProbability = 1;

    private $clients = array();

    private $events = array();

    private $maxResourceClients = array();

    private $maxServerClients = -1;

    private $listenInterval = 2000000;

    private $displayLog = false;

    private $displayExceptions = false;

    private $checkOrigin = false;

    private $port = 0;

    private $secure = false;

    private $passphrase = 'websocket-ssl-connection';

    private $sslDirectoryPath = 'ssl';

    private $address = '0.0.0.0';

    private $initEvents = array(

        'overflowConnection' => false,
        'failureConnection' => false,

        'connect' => false,
        'disconnect' => false,

        'send' => false,

        'sendPing' => false,
        'sendPong' => false,
        'sendClose' => false,

        'sendMessage' => false,
        'sendMessagePlain' => false,
        'sendMessageBinary' => false,

        'received' => false,

        'receivedPing' => false,
        'receivedPong' => false,
        'receivedClose' => false,

        'receivedMessage' => false,
        'receivedMessagePlain' => false,
        'receivedMessageBinary' => false,

        '__event_type__' => 0,
        '__class_handle__' => null
    );

    private $handle = null;

    public function __construct ($ip, $port, $secure = false) {

        ob_implicit_flush (true);

        $this->secure = (bool) $secure;

        $this->port = (int) $port;
        $this->address = $ip;

        // イベントハンドラの初期化

        $this->events = $this->initEvents;

        // サーバーイベントハンドラ
        $this->events['serverConnect'] = false;
        $this->events['serverOverflowConnection'] = false;
        $this->events['serverFailureConnection'] = false;

        // リソースハンドラ
        $this->events['__resource__'] = array();

    }

    public function triggerEvent ($eventName, &$clientHandle) {

        static $_s = 7; // strlen('server-')

        $ip = '0.0.0.0';
        $port = 0;

        if (($clientHandle instanceof WebSocketClient) === true && $eventName === 'disconnect' && $this->displayLog === true) {

            $ip = $clientHandle->address;
            $port = $clientHandle->port;

        }

        if ($this->callEvent === true) {

            if (($clientHandle instanceof WebSocketClient) === true && isset($this->events['__resource__'][$clientHandle->resource]) === true && $this->events['__resource__'][$clientHandle->resource][$eventName] !== false) {

                if ($this->events['__resource__'][$clientHandle->resource]['__event_type__'] === 1) {

                    $this->events['__resource__'][$clientHandle->resource]['__class_handle__']->setClient($clientHandle);

                    $this->log('Call client class event "%s::%s" on uri "%s"', self::LOG_INFO, get_class($this->events['__resource__'][$clientHandle->resource]['__class_handle__']), $eventName, $clientHandle->resource);

                } else {

                    $this->log('Call client event "%s" on uri "%s"', self::LOG_INFO, $eventName, $clientHandle->resource);

                }

                call_user_func_array($this->events['__resource__'][$clientHandle->resource][$eventName], array_slice(func_get_args(), 1));

            }


            if (isset($this->events[$eventName]) === true && $this->events[$eventName] !== false) {

                if (($clientHandle instanceof WebSocketClient) === true && $clientHandle->hasSocket() === true) {

                    if ($this->events['__event_type__'] === 1) {

                        $this->events['__class_handle__']->setClient($clientHandle);

                        $this->log('Call client class event "%s::%s" on uri "%s"', self::LOG_INFO, get_class($this->events['__class_handle__']), $eventName, $clientHandle->resource);

                    } else {

                        $this->log('Call client event "%s"', self::LOG_INFO, $eventName, $clientHandle->resource);

                    }

                    call_user_func_array($this->events[$eventName], array_slice(func_get_args(), 1));

                } else if (substr($eventName, 0, $_s) === 'server-') {

                    $this->log('Call server event "%s"', self::LOG_INFO, $eventName);

                    call_user_func($this->events[$eventName]);

                }

            }

        }

        if (($clientHandle instanceof WebSocketClient) === true && $eventName === 'disconnect') {

            $this->log('Finished session %s:%d', self::LOG_INFO, $ip, $port);

        }

    }

    public function registerResource ($resource, $maxClients = -1) {

        if (is_string($resource) === false || isset($this->events['__resource__'][$resource]) === true) {

            throw new WebSocketException('Register.Resource.Name.Exception');

        }

        $this->events['__resource__'][$resource] = $this->initEvents;
        $this->maxResourceClients[$resource]['size'] = (int) $maxClients;
        $this->maxResourceClients[$resource]['connections'] = 0;

        $this->log('Regist uri resource "%s"', self::LOG_INFO, $resource);

    }

    public function registerEvent ($eventName, $callbackORresource = null, $resourceORcallback = null) {

        $resource = null;
        $callback = $callbackORresource;

        if ($resourceORcallback !== null) {

            // 変更
            $resource = $callbackORresource;
            $callback = $resourceORcallback;

        }

        if (($parentClass = get_parent_class($eventName)) !== false) {

            if ($parentClass === 'WebSocketEvent') {

                $resource = $callbackORresource;

                // parse to array events...
                $eventName->setServer($this);

                if (is_string($resource) === true) {

                    if ($resource === '__resource__' || isset($this->events['__resource__'][$resource]) === false) {

                        throw new WebSocketException('Register.Event.Resource.Exception');

                    }

                    foreach ($this->initEvents as $name => $init) {

                        $this->events['__resource__'][$resource][$name] = array(
                            $eventName,
                            $name
                        );

                    }

                    $this->events['__resource__'][$resource]['__event_type__'] = 1;
                    $this->events['__resource__'][$resource]['__class_handle__'] = &$eventName;

                    $this->log('Regist event class "%s"' . ($resource !== null ? ' on uri resource "' . $resource . '"' : ''), self::LOG_INFO, get_class($eventName));

                } else {

                    foreach ($this->initEvents as $name => $init) {

                        $this->events[$name] = array(
                            $eventName,
                            $name
                        );

                    }

                    $this->events['__event_type__'] = 1;
                    $this->events['__class_handle__'] = &$eventName;

                }

            } else {

                throw new WebSocketException('Register.Event.Class.Exception');

            }

        } else {

            if (is_callable($callback) === false) {

                throw new WebSocketException('Register.Event.Callback.Exception');

            }

            if (isset($this->events[$eventName]) === false) {

                throw new WebSocketException('Register.Event.Name.Exception');

            }

            if (is_string($resource) === true) {

                if ($resource === '__resource__' || isset($this->events['__resource__'][$resource]) === false || isset($this->events['__resource__'][$resource][$eventName]) === false || $eventName === '__event_type__' || $eventName === '__class_handle__') {

                    throw new WebSocketException('Register.Event.Resource.Exception');

                }

                $this->events['__resource__'][$resource][$eventName] = $callback;

            } else {

                $this->events[$eventName] = $callback;


            }

            $this->log('Regist event "%s"' . ($resource !== null ? ' on uri resource "' . $resource . '"' : ''), self::LOG_INFO, $eventName);

        }

    }

    public function broadcastClose () {

        $this->log('Broadcasting Close...', self::LOG_WAIT);

        foreach ($this->clients as $client) {

            try {

                $client->sendClose();

            } catch (WebSocketException $e) {

            }

        }

        $this->log('Okay', self::LOG_SUCCESS);

    }

    public function broadcastPing ($message = 'HELLO') {

        $this->log('Broadcasting Ping ...', self::LOG_WAIT);

        $this->broadcastCommand ($message, 0x09);

    }

    public function broadcastMessage ($message) {

        $this->log('Broadcasting Message ...', self::LOG_WAIT);

        $this->broadcastCommand ($message);

    }

    public function broadcastBinaryMessage ($message) {

        $this->log('Broadcasting Binary Message ...', self::LOG_WAIT);

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

                $this->log($e->getMessage(), self::LOG_EXCEPTION);

            }

        }

        $this->log('Okay', self::LOG_SUCCESS);

    }

    public function serverRun ($callback = null) {

        $context = stream_context_create();

        if ($this->secure === true) {

            if (is_file($this->sslDirectoryPath . '/wss-cert.pem') === true) {

                $check = openssl_x509_parse(file_get_contents($this->sslDirectoryPath . '/wss-cert.pem'));

                if ($check['validTo_time_t'] < time()) {

                    $this->createPEM();

                }

            } else {

                $this->createPEM();

            }

            stream_context_set_option($context, 'ssl', 'local_cert', $this->sslDirectoryPath . '/wss-cert.pem');
            stream_context_set_option($context, 'ssl', 'passphrase', $this->passphrase);
            stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($context, 'ssl', 'verify_peer', false);

        }

        $this->handle = @stream_socket_server(($this->secure === true ? 'tls' : 'tcp') . '://' . $this->address . ':' . $this->port, $errno, $err, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if ($this->handle === false) {

            throw new WebSocketException ('Socket.Create.Exception');

        }

        if (isset($this->maxResourceClients['/']) === false) {

            // / が登録されていない場合登録する。
            $this->registerResource('/');

        }

        $dummy = null;

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

        gc_enable();

        $this->log('Server running on PHP %s', self::LOG_INFO, PHP_VERSION);

        while (true) {

            $sockets = array_merge(array($this->handle), $this->getClientSockets());

            if ($this->listenInterval > 0) {

                @stream_select ($sockets, $dummy, $dummy, 0, $this->listenInterval);

            } else {

                @stream_select ($sockets, $dummy, $dummy, null);

            }

            foreach ($sockets as $handle) {

                try {

                    if ($handle === $this->handle) {

                        $this->triggerEvent ('serverConnect', $dummy);

                        if ($this->maxServerClients > -1 && (sizeof($this->clients) + 1) > $this->maxServerClients) {

                            $this->sendHandShakeError (null, 503);

                        } else {

                            if ($this->pingProbability > 0 && mt_rand(1, 100) <= $this->pingProbability) {

                                $this->broadcastPing();

                            }

                            $this->registerClient();

                        }

                    } else {

                        $clientHandle = $this->getClientBySocket($handle);

                        if ($clientHandle !== false) {

                            $clientHandle->getMessage();

                        }

                    }

                } catch (WebSocketException $e) {

                    $this->log($e->getMessage(), self::LOG_EXCEPTION);

                }

            }

        }

    }

    private function createPEM () {

        $this->log('Creation SSL Key', self::LOG_WAIT, $this->address, $this->port);

        $configurePath = array(
            'config' => $this->sslDirectoryPath . '/openssl.cnf',
            'private_key_bits' => 2048,
            'digest_alg' => 'sha512',
            'private_key_type' => OPENSSL_KEYTYPE_RSA
        );

        if (function_exists('openssl_pkey_new') === false) {

            $this->log('Creation SSL Key Error. May you not openssl compiled with php.', self::LOG_ERROR);

            exit();

        }

        $sslHandle = openssl_pkey_new($configurePath);

        $csrNew = openssl_csr_new (array(
            'countryName' => 'JP',
            'stateOrProvinceName' => 'Tokyo',
            'localityName' => 'Nakano',
            'organizationName' => 'WebSocket',
            'organizationalUnitName' => 'PHP',
            'commonName' => 'localhost',
            'emailAddress' => 'none@localhost'
        ), $sslHandle, $configurePath);

        $cert = openssl_csr_sign($csrNew, null, $sslHandle, 3652, $configurePath, time());

        openssl_x509_export($cert, $x509);

        openssl_pkey_export($sslHandle, $pkey, $this->passphrase, $configurePath);

        file_put_contents($this->sslDirectoryPath . '/x509.crt', $x509);

        file_put_contents($this->sslDirectoryPath . '/wss-cert.pem', $x509 . $pkey);

        $this->log('Okay', self::LOG_SUCCESS);

    }

    private function registerClient () {

        if (($client = @stream_socket_accept ($this->handle)) !== false) {

            $clientHandle = new WebSocketClient($client);

            $this->log('Start session %s:%d', self::LOG_INFO, $clientHandle->address, $clientHandle->port);

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

            $resource = parse_url($match[1]);

            if (isset($resource['path']) === false) {

                $this->sendHandShakeError ($clientHandle, 400);

                return false;

            }

            $clientHandle->resource = $resource === '/' ? '/' : substr($resource['path'], 1);

            if (isset($resource['query']) === true) {

                parse_str($resource['query'], $clientHandle->resourceQuery);

            } else {

                $clientHandle->resourceQuery = array();

            }

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
                $handshake .= "Sec-WebSocket-Accept: " . base64_encode(sha1($header['sec-websocket-key'] . self::GUID, true)) . "\r\n";
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

                    $this->triggerEvent('serverFailureConnection', $clientHandle);

                } else {

                    $this->triggerEvent('failureConnection', $clientHandle);

                }

            break;
            case 503:

                $this->log('Overflow capacity ! I suggest change in capacity', self::LOG_WARNING);

                $status .= '503 Service Unavailable';

                if (($clientHandle instanceof WebSocketClient) === false) {

                    $this->triggerEvent('serverOverflowConnection', $clientHandle);

                } else {

                    $this->triggerEvent('overflowConnection', $clientHandle);

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

    public function setListenInterval($interval) {

        $this->listenInterval = ((int) $interval) * 1000;
    }

    public function setDisplayLog ($bool) {

        $this->displayLog = (bool) $bool;

    }

    public function setDisplayExceptions ($bool) {

        $this->displayExceptions = (bool) $bool;

    }

    public function setCheckOrigin ($list) {

        $this->checkOrigin = ($list === false ? false : (array) $list);

    }

    public function isSecure () {
        return $this->secure;
    }

    public function setSSLDirectoryPath ($path) {
        $this->sslDirectoryPath = (string) $path;
    }

    public function getSSLDirectoryPath () {
        return $this->sslDirectoryPath;
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

        $this->log('A new connections %d on uri resource "%s"', self::LOG_INFO, $this->maxResourceClients[$resource]['connections'], $resource);

    }

    private function log ($message, $type = self::LOG_INFO) {

        if ($this->displayLog === true) {

            $head = '';

            switch ($type) {
                case self::LOG_INFO:

                    $head = '[Info %s]';

                break;
                case self::LOG_WARNING:

                    $head = '[Warning %s]';

                break;
                case self::LOG_ERROR:

                    $head = '[Error %s]';

                break;
                case self::LOG_NOTICE:

                    $head = '[Notice %s]';

                break;
                case self::LOG_EXCEPTION:

                    $head = '[Exception %s] Caught Exception:';

                break;
                case self::LOG_WAIT:

                    $head = '[Wait %s]';

                break;
                case self::LOG_SUCCESS:

                    $head = '[Success %s]';

                break;

            }

            if ($type === self::LOG_EXCEPTION && $this->displayExceptions === false) {
                return;
            }

            vprintf(sprintf($head, date('Y-m-d H:i:s')) . ' ' . $message . "\n", array_slice(func_get_args(), 2));

        }

    }

}