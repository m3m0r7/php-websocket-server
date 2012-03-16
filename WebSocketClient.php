<?php

class WebSocketClient {

    public $rfc = false;

    public $address = '0.0.0.0';
    public $port = -1;

    public $timestamp = -1;

    public $id = -1;

    public $resource = null;
    public $resourceQuery = null;

    public $version = -1;

    private $client = null;

    private static $server;

    public static function setServer (&$server) {

        if (($server instanceof WebSocketServer) === false) {

            throw new WebSocketException('Resource.NullPointer.Exception');

        }

        self::$server = &$server;

    }

    public function __construct ($clientHandle) {

        if (($peer = @stream_socket_get_name($clientHandle, true)) === false) {

            $this->close();

            throw new WebSocketException('Socket.GetPeer.Exception');

        }

        list ($this->address, $this->port) = explode(':', $peer);

        $this->client = $clientHandle;

        $this->timestamp = time();

    }

    public function __destruct () {

        if (($key = self::$server->searchClientKeyByInstance ($this)) !== false) {

            $trigger = is_resource($this->client) ? $this : null;

            self::$server->removeClientByKey($key);

            self::$server->amountResourceConnections ($this->resource, -1);

            @stream_socket_shutdown($this->client, STREAM_SHUT_RDWR);

            self::$server->triggerEvent ('disconnect', $trigger);

        } else {

            @stream_socket_shutdown($this->client, STREAM_SHUT_RDWR);

        }

        unset ($this->client);

    }

    public function getSocket () {

        return $this->client;

    }

    public function hasSocket () {

        return isset($this->client) === true;

    }

    public function close () {

        $this->__destruct();

    }

    public function getServerInstance () {

        return self::$server;

    }

    public function getMessage () {

        if ($this->rfc === true) {

            $fin = 0x00;

            $string = '';

            while ($fin === 0x00) {

                $ptr = 0;

                $message = $this->read();

                self::$server->triggerEvent ('received', $this, $message);

                if (empty($message) === true || strlen($message) < 6) {

                    return false;

                }

                $fin = (ord($message[$ptr]) >> 7);

                if ($fin < 0 || $fin > 1) {

                    $this->close();

                    return false;

                }

                // $rsv = array(0, 0, 0);

                $opcode = ((ord($message[$ptr]) << 4) & 0xff) >> 4;

                $ptr++;

                $maskFlag = (ord($message[$ptr]) >> 7) & 0xff;

                // サーバーは必ず受信するデータはマスクがセットされている。

                if ($maskFlag !== 1) {

                    $this->close();

                    return false;

                }

                $type = ((ord($message[$ptr]) << 1) & 0xff) >> 1;

                if ($type < 0 || $type > 127) {

                    $this->close();

                    return false;

                }

                $ptr++;

                switch ($type) {

                    case 126:

                        $size = current(unpack('n', $message[$ptr] . $message[$ptr + 1]));

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

                switch ($opcode) {

                    case 0x00:

                        // 継続

                    break;

                    case 0x01:

                        if (mb_detect_encoding($string) !== 'UTF-8') {

                            // UTF-8じゃないとか…
                            $this->close();

                            return false;

                        }

                        // テキスト (UTF-8)

                        self::$server->triggerEvent ('receivedMessage', $this, $string, false);

                        self::$server->triggerEvent ('receivedMessagePlain', $this, $string);

                    break;
                    case 0x02:

                        // バイナリ

                        self::$server->triggerEvent ('receivedMessage', $this, $string, true);

                        self::$server->triggerEvent ('receivedMessageBinary', $this, $string);

                    break;

                    case 0x08:

                        // セッションクローズ

                        self::$server->triggerEvent ('receivedClose', $this, $string, true);

                        $this->close();

                        return false;

                    break;

                    case 0x09:

                        // from ping

                        self::$server->triggerEvent ('receivedPing', $this, $string);

                        $this->sendPong($string);

                        return false;


                    break;

                    case 0x0A:

                        // from pong

                        self::$server->triggerEvent ('receivedPong', $this, $string);

                        return false;

                    break;

                    default:

                        // 不明なOpcodeのユーザーは攻撃の可能性があるのでセッションを切る。

                        $this->close();

                        return false;


                    break;

                }

            }

            return $string;

        } else {

            $message = $this->read();

            if (strlen($message) >= 2) {

                $frameType = ord($message[0]);

                if ($frameType < 0x80) {

                    // text frame...

                    $string = substr($message, 1, strlen($message) - 2);

                    if (mb_detect_encoding($string) !== 'UTF-8') {

                        // UTF-8じゃないとか…
                        $this->close();

                        return false;

                    }

                    self::$server->triggerEvent ('receivedMessage', $this, $string, false);

                    self::$server->triggerEvent ('receivedMessagePlain', $this, $string);

                    return $string;

                } else if ($frameType >= 0x80 && $frameType <= 0xFE) {

                    // binary frame is no supported...

                    self::$server->triggerEvent ('receivedMessage', $this, $string, false);

                    self::$server->triggerEvent ('receivedMessageBinary', $this, $string);

                    return false;

                } else if ($frameType === 0xFF && ord($message[1]) === 0x00) {

                    // send close
                    self::$server->triggerEvent ('receivedClose', $this, $string, true);

                    // close handshake
                    $this->sendClose();

                } else {

                    // 不明なフレーム
                    $this->close();

                    return false;

                }

            } else {

                // 不明なフレーム
                $this->close();

                return false;

            }

        }

        return false;

    }

    public function sendClose () {

        $this->sendCommand ('', 0x08);

        self::$server->triggerEvent ('sendClose', $this);

        $this->close();

    }

    public function sendPing ($ping = 'HELLO') {

        $this->sendCommand ($ping, 0x09);

        $receive = $this->getMessage();

        self::$server->triggerEvent ('sendPing', $this, $receive);

        return $ping === $receive;

    }

    public function sendPong ($pong) {

        $this->sendCommand ($pong, 0x0A);

        self::$server->triggerEvent ('sendPong', $this, $pong);

    }

    public function sendMessage ($message) {

        $detect = mb_detect_encoding($message);

        if ($detect !== 'UTF-8') {

            $message = mb_convert_encoding($message, 'UTF-8', $detect === false ? 'SJIS' : $detect);

        }

        $this->sendCommand ($message, 0x01, true);

        self::$server->triggerEvent ('sendMessagePlain', $this, $message);

    }

    public function sendBinaryMessage ($message) {

        $this->sendCommand ($message, 0x02);

        self::$server->triggerEvent ('sendMessageBinary', $this, $message);

    }

    public function sendCommand ($message, $opcode = 0x01, $useMask = false) {

        if ($this->rfc === true) {

            $message = (string) $message;

            $size = strlen($message);
            $type = ($size > 0xffff ? 127 : ($size <= 0xffff && $size >= 126 ? 126 : $size));

            $body = chr(128 + $opcode);
            $body .= chr(($useMask === true ? 128 : 0) + $type);

            $mask = array();

            switch ($type) {

                case 126:

                    $body .= pack('n', $size);

                break;
                case 127:

                    foreach (str_split(sprintf('%064b', $size), 8) as $value) {

                        $body .= chr(bindec($value));

                    }

                break;

            }

            if ($useMask === true) {

                $body .= $mask[0] = chr(mt_rand(0, 0xFF));
                $body .= $mask[1] = chr(mt_rand(0, 0xFF));
                $body .= $mask[2] = chr(mt_rand(0, 0xFF));
                $body .= $mask[3] = chr(mt_rand(0, 0xFF));

                $body .= self::packString ($message, $size, $mask);

            } else {

                $body .= $message;

            }

            $this->write($body);

            self::$server->triggerEvent ('send', $this, $message);

        } else {

            switch ($opcode) {

                case 0x01:

                    // to TEXT FRAME

                    $body = "\x00" . $message . "\xff";

                    $this->write($body);

                    self::$server->triggerEvent ('send', $this, $message);

                break;

                case 0x02:

                    // unknown binary frames...
                    return false;

                break;

                case 0x09:
                case 0x0A:

                    // なし。

                break;

                case 0x08:

                    // to CLOSE FRAME

                    $this->write("\xff\x00");

                    // skip \xff\x00
                    $this->read(2);

                break;

            }

        }

        return true;

    }

    public function read ($size = 0x0fffff) {

        if (is_resource($this->client) === false) {

            throw new WebSocketException('Socket.ClientIsNotResource.Exception');

        }

        $data = '';

        if (self::$server->isSecure () === true) {

            // wtf...?

            $data = @fread($this->client, $size);

            if ($data !== false) {

                if (strlen($data) === 1) {

                    $data .= $check = @fread($this->client, $size);

                    if ($check !== false) {

                        return $data;

                    }

                }

            }

        } else {

            if (($data = @fread($this->client, $size)) !== false) {

                return $data;

            }

        }

        $errorid = @socket_last_error ($this->client);

        $this->close();

        if ($errorid !== 0) {

            throw new WebSocketException('Socket.ReadBuffer.Exception -> ' . socket_strerror($errorid), $errorid);

        }

        return '';

    }

    public function write ($body) {

        if (is_resource($this->client) === false) {

            throw new WebSocketException('Socket.ClientIsNotResource.Exception');

        }

        if (@fwrite($this->client, $body, strlen($body)) === false) {

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