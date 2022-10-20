<?php

namespace icy8\SocketMan\Protocol;

use icy8\SocketMan\Connection\ConnectionInterface;

interface ProtocolInterface
{
    public static function input($recv_buffer, ConnectionInterface $connection);

    public static function encode($data, ConnectionInterface $connection);

    public static function decode($data, ConnectionInterface $connection);
}