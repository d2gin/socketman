<?php


namespace icy8\SocketMan\Exception;


use Throwable;

class SocketException extends \Exception
{
    
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}