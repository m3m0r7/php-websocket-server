<?php


class WebSocketClient {

    public $address;
    public $port;

    public $timestamp;

    public $id;

    public $resource = null;
    public $version = -1;

    private $client;
    private static $server;

    public static function setServer (&$server) {

        if (($server instanceof WebSocket) === false) {

            throw new WebSocketException('Resource.NullPointer.Exception');

        }

        self::$server = &$server;

    }

    public function __construct ($clientHandle) {

        if (($peer = @socket_getpeername($clientHandle, $address, $port)) === false) {

            $this->close();

            throw new WebSocketException('Socket.GetPeer.Exception');

        }

        $this->client = $clientHandle;

        $this->address = $address;
        $this->port = $port;

        $this->timestamp = time();

    }

    public function __destruct () {

        if (in_array($this, self::$server->getClients (), true) === true) {

            $trigger = is_resource($this->client) ? $this : null;

            self::$server->triggerEvent ('disconnect', $trigger);

            self::$server->removeClientByInstance($this);

            @socket_close($this->client);

            unset($this->client);

        }

    }

    public function getSocket () {

        return $this->client;

    }

    public function hasSocket () {

        return isset($this->client);

    }

    public function close () {

        $this->__destruct();

    }

    public function getServerInstance () {

        return self::$server;

    }

    public function getMessage () {

        $fin = 0x00;

        $string = '';

        while ($fin === 0x00) {

            $ptr = 0;

            $message = $this->read();

            self::$server->triggerEvent ('received', $this, $message);

            if (empty($message) === true) {
                return false;

            }

            $fin = (ord($message[$ptr]) >> 7);

            // $rsv = array(0, 0, 0);

            $opcode = ((ord($message[$ptr]) << 4) & 0xff) >> 4;

            $ptr++;

            $maskFlag = (ord($message[$ptr]) >> 7) & 0xff;

            $type = ((ord($message[$ptr]) << 1) & 0xff) >> 1;

            $ptr++;

            switch ($type) {

                case 126:

                    $size = (ord($message[$ptr]) * 256) + ord($message[++$ptr]);

                    $ptr++;

                break;

                case 127:

                    $size = 0;

                    for ($i = 7; $i >= 0; $i--) $size += ord($message[$ptr + (7 - $i)]) * pow(256, $i);
                    $size = (int) $size;

                    $ptr += 8;

                break;

                default:

                    $size = $type;

                break;

            }


            if ($maskFlag === 1) {

                // マスク
                $mask = array();

                $mask[0] = $message[$ptr];
                $mask[1] = $message[$ptr + 1];
                $mask[2] = $message[$ptr + 2];
                $mask[3] = $message[$ptr + 3];

                $ptr += 4;

            }

            if ($type === 126 || $type === 127) {

                $data = (string) substr($message, $ptr, $size);

                while (strlen($data) < $size) {

                    $data .= $this->read($size);

                }

            } else {

                $data = substr($message, $ptr, $size);

            }

            if ($maskFlag === 1) {

                $string .= self::packString ($data, $size, $mask);

            } else {

                $string .= $data;

            }

            if (strlen($string) > 0) {

                $detect = mb_detect_encoding($string);

                if ($detect !== 'UTF-8') {

                    // UTF-8じゃないとか…
                    $this->close();

                }

            }

            switch ($opcode) {

                case 0x00:

                    // 継続

                break;

                case 0x01:

                    // テキスト (UTF-8)

                    self::$server->triggerEvent ('received-message', $this, $string, false);

                    self::$server->triggerEvent ('received-message-plain', $this, $string);

                break;
                case 0x02:

                    // バイナリ

                    self::$server->triggerEvent ('received-message', $this, $string, true);

                    self::$server->triggerEvent ('received-message-binary', $this, $string);

                break;

                case 0x08:

                    // セッションクローズ

                    self::$server->triggerEvent ('received-close', $this, $string, true);

                    $this->close();

                    return false;

                break;

                case 0x09:

                    // from ping

                    self::$server->triggerEvent ('received-ping', $this, $string);

                    $this->sendPong($string);

                    return false;


                break;

                case 0x0A:

                    // from pong

                    self::$server->triggerEvent ('received-pong', $this, $string);

                    return false;

                break;

            }

        }

        return $string;

        return false;

    }

    public function sendClose () {

        $this->sendCommand ('', 0x08);

        self::$server->triggerEvent ('send-close', $this);

        $this->close();

    }

    public function sendPing ($ping = 'HELLO') {

        $this->sendCommand ($ping, 0x09);

        $receive = $this->getMessage();

        self::$server->triggerEvent ('send-ping', $this, $receive);

        return $ping === $receive;

    }

    public function sendPong ($pong) {

        $this->sendCommand ($pong, 0x0A);

        self::$server->triggerEvent ('send-pong', $this, $pong);

    }

    public function sendMessage ($message) {

        $this->sendCommand ($message, 0x01);

        self::$server->triggerEvent ('send-message-plain', $this, $message);

    }

    public function sendBinaryMessage ($message) {

        $this->sendCommand ($message, 0x02);

        self::$server->triggerEvent ('send-message-binary', $this, $message);

    }

    public function sendCommand ($message, $opcode = 0x01, $useMask = false) {

        $message = (string) $message;

        $size = strlen($message);
        $type = ($size >= 0xffff ? 127 : ($size < 0xffff && $size > 126 ? 126 : $size));

        $body = chr(128 + $opcode);
        $body .= chr(($useMask === true ? 128 : 0) + $type);

        $mask = array();

        if ($useMask === true) {

            $body .= $mask[0] = mt_rand(0, 255);
            $body .= $mask[1] = mt_rand(0, 255);
            $body .= $mask[2] = mt_rand(0, 255);
            $body .= $mask[3] = mt_rand(0, 255);

        }

        switch ($type) {

            case 126:

                $body .= strrev(pack('v', $size));

                $this->write($body);

                self::$server->triggerEvent ('send-header', $this, $body);

                $body = '';

            break;
            case 127:

                foreach (str_split(str_pad(decbin($size), 64, '0', STR_PAD_LEFT), 8) as $value) $body .= chr(bindec($value));

                $this->write($body);

                self::$server->triggerEvent ('send-header', $this, $body);

                $body = '';

            break;

        }

        $detect = mb_detect_encoding($message);

        if ($detect !== 'UTF-8') {

            $message = mb_convert_encoding($message, 'UTF-8', $detect === false ? 'SJIS' : $detect);

        }

        if ($useMask === true) {

            $body .= self::packString ($message, $size, $mask);

        } else {

            $body .= $message;

        }

        $this->write($body);

        self::$server->triggerEvent ('send-body', $this, $body);

        self::$server->triggerEvent ('send', $this, $message);

        return true;

    }

    public function read ($size = 0xffff) {

        if (is_resource($this->client) === false) {

            throw new WebSocketException('Socket.ClientIsNotResource.Exception');

        }

        $data = '';

        if ((@socket_recv($this->client, $data, $size, 0)) === false) {

            $errorid = @socket_last_error ($this->client);

            $this->close();

            if ($errorid !== 0) {

                throw new WebSocketException('Socket.ReadBuffer.Exception -> ' . socket_strerror($errorid), $errorid);

            }

            return '';
        }

        return $data;

    }

    public function write ($body) {

        if (is_resource($this->client) === false) {

            throw new WebSocketException('Socket.ClientIsNotResource.Exception');

        }

        if (@socket_write($this->client, $body) === false) {

            $errorid = @socket_last_error ($this->client);

            $this->close();


            if ($errorid !== 0) {

                throw new WebSocketException('Socket.WriteBuffer.Exception -> ' . socket_strerror($errorid), $errorid);

            }

        }

    }

    private static function packString ($string, $size, $mask) {

        $build = '';


        for ($i = 0; $i < $size; $i++) {

            $build .= $string[$i] ^ $mask[$i % 4];

        }

        return $build;

    }

}