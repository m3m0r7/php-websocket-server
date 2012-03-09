<?php


class WebSocketClient {

    private $client;
    private $server;

    public $address;
    public $port;

    public $timestamp;

    public $resource = null;

    public function __construct (&$serverHandle, $clientHandle) {

        if (($peer = @socket_getpeername($clientHandle, $address, $port)) === false) {

            $this->close();

            throw new WebSocketException('Socket.GetPeer.Exception');

        }

        $this->server = &$serverHandle;
        $this->client = $clientHandle;

        $this->address = $address;
        $this->port = $port;

        $this->timestamp = time();

    }

    public function __destruct () {

        if (($key = array_search($this, $this->server->clients, true)) !== false) {

            $this->server->triggerEvent ('disconnect', is_resource($this->client) ? $this : null);

            // 存在しない。
            unset($this->server->clients[$key]);

            @socket_close($this->client);

        }

    }

    private function close () {

        $this->__destruct();

    }

    public function getResource () {

        return $this->client;

    }

    public function getServerInstance () {

        return $this->server;

    }

    public function getMessage () {

        $fin = 0x00;

        $string = '';

        while ($fin === 0x00) {

            $ptr = 0;

            $message = $this->read(130);

            $this->server->triggerEvent ('received', $this, $message);

            if (empty($message) === true) {
                return false;

            }

            $fin = (ord($message[$ptr]) >> 7) & 0xff;

            // $rsv = array(0, 0, 0);

            $opcode = (ord($message[$ptr]) & 0x0f);

            $ptr++;

            $maskFlag = (ord($message[$ptr]) >> 7) & 0xff;

            if ($maskFlag === 0) {

                $this->close();

                return false;

            }

            $type = ord($message[$ptr]) & 0x7F;

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

            // マスク
            $mask = array();

            $mask[0] = $message[$ptr];
            $mask[1] = $message[++$ptr];
            $mask[2] = $message[++$ptr];
            $mask[3] = $message[++$ptr];

            $ptr++;

            if ($type === 126 || $type === 127) {

                $data = (string) substr($message, $ptr, $size);

                do {

                    $data .= $this->read($size);

                } while (strlen($data) < $size);

            } else {

                $data = substr($message, $ptr, $size);

            }

            $string .= self::packString ($data, $size, $mask);

            switch ($opcode) {

                case 0x00:

                    // 継続

                break;

                case 0x01:

                    // テキスト (UTF-8)

                    $this->server->triggerEvent ('received-message', $this, $string, false);

                    $this->server->triggerEvent ('received-message-plain', $this, $string);

                break;
                case 0x02:

                    // バイナリ

                    $this->server->triggerEvent ('received-message', $this, $string, true);

                    $this->server->triggerEvent ('received-message-binary', $this, $string);

                break;

                case 0x08:

                    // セッションクローズ

                    $this->server->triggerEvent ('received-close', $this, $string, true);

                    $this->close();

                    return false;

                break;

                case 0x09:

                    // from ping

                    $this->server->triggerEvent ('received-ping', $this, $string);

                    $this->sendPong($client, $string);

                    return false;


                break;

                case 0x0A:

                    // from pong

                    $this->server->triggerEvent ('received-pong', $this, $string);

                    return false;

                break;

            }

        }

        return $string;

    }

    public function sendClose () {

        $this->sendCommand ('', 0x08);

        $this->server->triggerEvent ('send-close', $this);

        $this->close();

    }

    public function sendPing ($ping = 'HELLO') {

        $this->sendCommand ($ping, 0x09);

        $this->server->triggerEvent ('send-ping', $this, $ping);

        return $ping === $this->getMessage();

    }

    public function sendPong ($pong) {

        $this->sendCommand ($pong, 0x0A);

        $this->server->triggerEvent ('send-pong', $this, $pong);

    }

    public function sendMessage ($message) {

        $this->sendCommand ($message, 0x01);

        $this->server->triggerEvent ('send-message-plain', $this, $message);

    }

    public function sendBinaryMessage ($message) {

        $this->sendCommand ($message, 0x02);

        $this->server->triggerEvent ('send-message-binary', $this, $message);

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

                $this->server->triggerEvent ('send-header', $this, $body);

                $body = '';

            break;
            case 127:

                foreach (str_split(str_pad(decbin($size), 64, '0', STR_PAD_LEFT), 8) as $value) $body .= chr(bindec($value));

                $this->write($body);

                $this->server->triggerEvent ('send-header', $this, $body);

                $body = '';

            break;

        }

        if ($useMask === true) {

            $body .= self::packString ($message, $size, $mask);

        } else {

            $body .= $message;

        }

        $this->write($body);

        $this->server->triggerEvent ('send-body', $this, $body);

        $this->server->triggerEvent ('send', $this, $message);

        return true;

    }

    public function read ($size = 0xffff) {

        if (is_resource($this->client) === false) {

            throw new WebSocketException('Socket.ClientIsNotResource.Exception');

        }

        if (($data = @socket_read($this->client, $size)) === false) {

            $errorid = @socket_last_error ($this->client);

            $this->close();

            throw new WebSocketException('Socket.ReadBuffer.Exception', $errorid);

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

            throw new WebSocketException('Socket.WriteBuffer.Exception', $errorid);

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