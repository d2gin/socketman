<?php


namespace icy8\SocketMan\Connection;


use icy8\SocketMan\Protocol\ProtocolInterface;

class UdpConnection implements ConnectionInterface
{

    protected $_socket;
    public    $server;
    protected $_remoteAddress = '';
    public    $protocol       = null;
    public    $transport      = 'udp';
    // 事件
    public $onMessage;
    public $onClose;

    public function __construct($socket, $remote_address = '')
    {
        $this->_socket        = $socket;
        $this->_remoteAddress = $remote_address;
    }

    /**
     * @param      $data
     * @param bool $raw
     * @return bool|void
     */
    public function send($data, $raw = false)
    {
        if ($this->protocol !== null && !$raw) {
            /* @var ProtocolInterface $protocolParser */
            $protocolParser = $this->server->getProtocolClass();
            $data           = $protocolParser::encode($data, $this);
            if ($data === '') return;
        }
        $sendto = $this->isIpV6() ? '[' . $this->getRemoteIp() . ']:' . $this->getRemotePort() : $this->_remoteAddress;
        return \strlen($data) === stream_socket_sendto($this->_socket, $data, 0, $sendto);
    }

    /**
     *
     * @param $buffer
     */
    public function read($buffer)
    {
        if (!$this->onMessage) return;
        if ($this->protocol !== null) {
            while ($buffer !== '') {
                /* @var ProtocolInterface $protocolParser */
                $protocolParser = $this->server->getProtocolClass();
                $len            = $protocolParser::input($buffer, $this);
                if (!$len) break;
                $package = substr($buffer, 0, $len);
                $buffer  = substr($buffer, $len);
                $data    = $protocolParser::decode($package, $this);
                call_user_func_array($this->onMessage, [$this, $data]);
            }
            return;
        }
        call_user_func_array($this->onMessage, [$this, $buffer]);
    }

    public function getRemoteIp()
    {
        $pos = \strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return \trim(\substr($this->_remoteAddress, 0, $pos), '[]');
        }
        return '';
    }

    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int)\substr(\strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * Is ipv4.
     *
     * @return bool.
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     * Is ipv6.
     *
     * @return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') !== false;
    }

    public function close($data = '', $raw = false)
    {
        if ($data !== '') {
            $this->send($data, $raw);
        }
        return true;
    }

    public function destroy()
    {
        return true;
    }
}