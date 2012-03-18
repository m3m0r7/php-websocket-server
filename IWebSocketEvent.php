<?php

interface IWebSocketEvent {

    public function overflowConnection ();
    public function failureConnection ();

    public function connect ();
    public function disconnect ();

    public function send ($message);

    public function sendPing ($received);
    public function sendPong ($pong);
    public function sendClose ();

    public function sendMessage ($message);
    public function sendMessagePlain ($message);
    public function sendMessageBinary ($message);

    public function received ($message);

    public function receivedPing ($message);
    public function receivedPong ($message);
    public function receivedClose ();

    public function receivedMessage ($message, $isBinary);
    public function receivedMessagePlain ($message);
    public function receivedMessageBinary ($message);

}