<?php


namespace icy8\SocketMan\Connection;


use icy8\SocketMan\Server;

interface ConnectionInterface
{
    public function send($data);

    public function close($data = '');

    public function destroy();
}
