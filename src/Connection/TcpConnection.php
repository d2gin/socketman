<?php

namespace icy8\SocketMan\Connection;

use icy8\SocketMan\Event\EventInterface;
use icy8\SocketMan\Protocol\ProtocolInterface;
use icy8\SocketMan\Server;

class TcpConnection implements ConnectionInterface
{
    public    $id      = 0;
    protected $_id     = 0;
    protected $_socket;
    protected $_buffer = '';
    /**
     * @var Server $server
     */
    public           $server;
    protected        $_remoteAddress        = '';
    protected        $_currentPackageLength = 0;
    public           $protocol              = null;
    public           $transport             = 'tcp';
    protected static $_idNumber             = 1;
    static           $connections           = [];
    // 事件
    public $onMessage;
    public $onClose;
    const READ_BUFFER_SIZE = 65535;// 数据包大小

    public function __construct($socket, $remote_address = '')
    {
        // 生成id号
        $this->id = $this->_id = self::$_idNumber++;
        // 达到上限PHP_INT_MAX时就重置索引值
        // PHP_INT_MAX的值非常大，一般id要达到这个值需要运行非常久
        // 所以重置这个值造成id重复的可能性基本可以忽略不计
        if (self::$_idNumber === \PHP_INT_MAX) {
            self::$_idNumber = 1;
        }
        $this->_socket        = $socket;
        $this->_remoteAddress = $remote_address;
        if (function_exists('stream_set_read_buffer')) {
            // 解决fread 8192问题
            stream_set_read_buffer($socket, 0);
        }
        // 全局事件循环读取数据包
        Server::$_loopEvent->add($socket, EventInterface::EV_READ, [$this, 'baseRead']);
        self::$connections[$this->_id] = $this;
    }

    public function baseRead($socket)
    {
        // 一次只读65535字节
        // 如果没读完的话下次事件调度时会自动拼接buffer，直到满足至少一个数据包的长度为止
        $buffer = \fread($socket, self::READ_BUFFER_SIZE);
        if ($buffer !== '' && $buffer !== false) {
            $this->_buffer .= $buffer;// 将数据包连接起来
        } else if (\feof($socket) || !\is_resource($socket) || $buffer === false) {
            //$this->destroy();//@todo 这个操作有疑问
            return;
        }
        if ($this->protocol !== null) {
            /* @var ProtocolInterface $protocolParser */
            $protocolParser = $this->server->getProtocolClass();
            while ($this->_buffer !== '') {// @todo 暂停接收数据包
                if (!$this->_currentPackageLength) {
                    // 获取数据包长度
                    $this->_currentPackageLength = $protocolParser::input($this->_buffer, $this);
                }
                if ($this->_currentPackageLength === 0) {
                    // 没有数据包 等待新的包
                    // 如果直到资源读取结束都没有合格的数据包，那么本次的通讯包将不被onMessage接收到，即数据包被丢弃。
                    return;
                } else if ($this->_currentPackageLength > strlen($this->_buffer)) {
                    // 数据包长度不满足一个完整包长度
                    return;
                }
                if (strlen($this->_buffer) === $this->_currentPackageLength) {// 单一数据包
                    $raw           = $this->_buffer;
                    $this->_buffer = '';
                } else {// 多数据包解包
                    $raw           = substr($this->_buffer, 0, $this->_currentPackageLength);
                    $this->_buffer = substr($this->_buffer, $this->_currentPackageLength);
                }
                if ($this->onMessage) {
                    call_user_func_array($this->onMessage, [$this, $protocolParser::decode($raw, $this)]);
                }
            }
            // 重置缓存数据
            $this->_currentPackageLength = 0;
            return;
        }
        // 原始包
        if ($this->onMessage) {
            call_user_func_array($this->onMessage, [$this, $this->_buffer]);
        }
        $this->_buffer               = '';
        $this->_currentPackageLength = 0;
    }

    /**
     * @param $pos
     */
    public function cutRecvBuffer($pos)
    {
        $this->_buffer = substr($this->_buffer, $pos);
    }

    /**
     * 向客户端发送数据
     * @param      $payload
     * @param bool $raw
     * @return false|int
     */
    public function send($payload, $raw = false)
    {
        if ($this->protocol !== null && !$raw) {
            // 通过对应的自定义协议进行封包
            /* @var ProtocolInterface $protocolParser */
            $protocolParser = $this->server->getProtocolClass();
            $payload        = $protocolParser::encode($payload, $this);
        }
        return fwrite($this->_socket, $payload);
    }

    /**
     * 关闭客户端
     * @param string $data
     * @param bool   $raw
     */
    public function close($data = '', $raw = false)
    {
        if ($data) {// 关闭前向客户端发送一条数据
            $this->send((string)$data, $raw);
        }
        $this->destroy();
    }

    /**
     * 关闭客户端并销毁对应资源
     */
    public function destroy()
    {
        // 关闭资源
        $res = fclose($this->_socket);
        if (!$res) return;
        if ($this->onClose) {
            call_user_func_array($this->onClose, [$this]);
        }
        // 全局事件解绑
        Server::$_loopEvent->delete($this->_socket, EventInterface::EV_READ);
        Server::$_loopEvent->delete($this->_socket, EventInterface::EV_WRITE);
        // 将客户端从内存中删除
        unset(self::$connections[$this->_id]);
        unset($this->server->connections[$this->_id]);
        // 重置套接字事件
        $this->onMessage = $this->onClose = null;
    }
}
