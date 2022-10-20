<?php

namespace icy8\SocketMan;

use icy8\SocketMan\Connection\TcpConnection;
use icy8\SocketMan\Connection\UdpConnection;
use icy8\SocketMan\Event\EventInterface;
use icy8\SocketMan\Event\Select;
use icy8\SocketMan\Exception\SocketException;

class Server
{
    protected $serverSocket;
    protected $serverAddress;
    protected $_serverPort;
    protected $_contextOption     = [];
    static    $_loopEvent         = null;
    protected $protocol           = null;// 传输协议 自定义
    public    $transport          = 'tcp';// 传输方式 php内置支持 tcp udp unix
    protected $_builtinTransports = ['tcp', 'udp', 'unix'];
    public    $connections        = [];
    public    $onMessage;
    public    $onConnect;
    public    $onClose;

    public function __construct($serverAddress, $context = [])
    {
        $this->serverAddress  = $serverAddress;
        $this->_contextOption = $context;// 资源流上下文选项
        self::$_loopEvent     = new Select();//@todo 待扩展
    }

    /**
     * 启动服务
     * @throws SocketException
     */
    public function run()
    {
        // 监听服务器端口
        $this->listen();
        // 等待客户端连接
        $this->acceptClient();
        // 资源监听 常驻内存
        // 就是用死循环让程序不退出 如果跑出异常或者die 程序会关闭
        self::$_loopEvent->loop();
    }

    public function listen()
    {
        $address = $this->parseSocketAddress();
        $flags   = $this->transport == 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        // 创建资源上下文
        $context = stream_context_create($this->_contextOption);
        // 创建套接字 监听端口
        $socket  = stream_socket_server($address, $errno, $errstr, $flags, $context);
        if (!$socket) {
            throw new SocketException("Failed to create the server socket:[{$errno}]{$errstr}");
        }
        // 为资源流设置阻塞或者阻塞模式
        // 但如果不借助libevent、swoole等扩展的话，处理资源流回调时依然是阻塞的，workerman也是如此
        stream_set_blocking($socket, false);
        $this->serverSocket = $socket;
    }

    /**
     * 解析服务地址
     * @return string
     * @throws \Exception
     */
    public function parseSocketAddress()
    {
        $info   = parse_url($this->serverAddress);
        $scheme = $info['scheme'] ?? '';
        if (!in_array($scheme, $this->_builtinTransports)) {
            // 最后还是以php内置支持的协议开启服务
            // 此时的协议是tcp
            $this->protocol = ucfirst($scheme);
            if (!class_exists($this->getProtocolClass())) {
                throw new \Exception('Protocol ' . $this->protocol . ' is not supported');
            }
        } else {
            $this->transport = $scheme;// 记录为内置协议
        }
        $this->_serverPort = $info['port'];
        // 返回真实监听的地址
        return $this->transport . '://' . $info['host'] . ':' . $info['port'];
    }

    /**
     * 客户端连接
     */
    public function acceptClient()
    {
        if (!is_resource($this->serverSocket)) return;
        if ($this->transport == 'udp') {
            self::$_loopEvent->add($this->serverSocket, EventInterface::EV_READ, [$this, 'acceptUdpConnection']);
        } else {
            self::$_loopEvent->add($this->serverSocket, EventInterface::EV_READ, [$this, 'acceptConnection']);
        }
    }

    /**
     * tcp连接
     * @param $socket
     */
    public function acceptConnection($socket)
    {
        set_error_handler(function () {
        });
        $clientSocket = stream_socket_accept($socket, 0, $remote_address);
        restore_error_handler();
        if (!$clientSocket) return;
        // 创建一个链接实例
        $connection            = new TcpConnection($clientSocket, $remote_address);
        $connection->server    = $this;
        $connection->protocol  = $this->protocol;
        $connection->transport = $this->transport;
        $connection->onMessage = $this->onMessage;
        $connection->onClose   = $this->onClose;
        // 将实例保存起来
        $this->connections[$connection->id] = $connection;
        if ($this->onConnect) {
            // 客户端连接
            call_user_func($this->onConnect, $connection);
        }
    }

    /**
     * udp连接
     * @param $socket
     */
    public function acceptUdpConnection($socket)
    {
        set_error_handler(function () {
        });
        $buffer = stream_socket_recvfrom($socket, 65535, 0, $remote_address);
        restore_error_handler();
        if (!$buffer) return;
        $connection            = new UdpConnection($socket, $remote_address);
        $connection->server    = $this;
        $connection->protocol  = $this->protocol;
        $connection->onMessage = $this->onMessage;
        $connection->read($buffer);
    }

    /**
     * 获取协议解析器
     * @return string
     */
    public function getProtocolClass()
    {
        return "icy8\\SocketMan\\Protocol\\{$this->protocol}";
    }
}
