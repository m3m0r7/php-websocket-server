<?php

class WebSocketEvent {

    protected $client = null;
    protected $server = null;

    public function setServer (&$server) {
        $this->server = &$server;
    }

    public function setClient (&$client) {
        $this->client = $client;
    }

    public function overflowConnection () {}

    public function failureConnection () {}

    public function connect () {}
    public function disconnect () {}

    public function send () {}

    public function sendPing () {}
    public function sendPong () {}
    public function sendClose () {}

    public function sendMessage () {}
    public function sendMessagePlain () {}
    public function sendMessageBinary () {}

    public function received () {}

    public function receivedPing () {}
    public function receivedPong () {}
    public function receivedClose () {}

    public function receivedMessage () {}
    public function receivedMessagePlain () {}
    public function receivedMessageBinary () {}

}