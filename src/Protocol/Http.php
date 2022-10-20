<?php

namespace icy8\SocketMan\Protocol;

use icy8\SocketMan\Connection\ConnectionInterface;
use icy8\SocketMan\Connection\Http\Request;

class Http implements ProtocolInterface
{

    public static function input($recv_buffer, ConnectionInterface $connection)
    {
        return strlen($recv_buffer);
    }

    public static function encode($data, ConnectionInterface $connection)
    {
        return $data;
    }

    public static function decode($data, ConnectionInterface $connection)
    {
        $request = new Request($data);
        return $request;
    }
}
