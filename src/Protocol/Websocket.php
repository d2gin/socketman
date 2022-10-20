<?php

namespace icy8\SocketMan\Protocol;

use icy8\SocketMan\Connection\ConnectionInterface;
use icy8\SocketMan\Connection\Http\Request;
use icy8\SocketMan\Connection\Http\Response;
use icy8\SocketMan\Connection\TcpConnection;

// 协议规则
/**
 *   0                   1                   2                   3
 *  0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
 * +-+-+-+-+-------+-+-------------+-------------------------------+
 * |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
 * |I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
 * |N|V|V|V|       |S|             |   (if payload len==126/127)   |
 * | |1|2|3|       |K|             |                               |
 * +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
 * |     Extended payload length continued, if payload len == 127  |
 * + - - - - - - - - - - - - - - - +-------------------------------+
 * |                               |Masking-key, if MASK set to 1  |
 * +-------------------------------+-------------------------------+
 * | Masking-key (continued)       |          Payload Data         |
 * +-------------------------------- - - - - - - - - - - - - - - - +
 * :                     Payload Data continued ...                :
 * + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
 * |                     Payload Data continued ...                |
 * +---------------------------------------------------------------+
 */

/**
 * Class Websocket
 * @package icy8\SocketMan\Protocol
 */
class Websocket implements ProtocolInterface
{
    /**
     * 计算数据包长度
     * @param                     $recv_buffer
     * @param ConnectionInterface $connection
     * @return float|int
     */
    public static function input($recv_buffer, ConnectionInterface $connection)
    {
        $bufferLen = strlen($recv_buffer);
        if ($bufferLen < 6) {
            return 0;
        } else if (empty($connection->websocketHandshake)) {
            // 握手
            return self::handshake($recv_buffer, $connection);
        }
        if ($connection->websocketCurrentFrameLength > $bufferLen) {
            return 0;
        } else if (!$connection->websocketCurrentFrameLength) {
            $firstByte  = ord($recv_buffer[0]);// 第1字节
            $secondByte = ord($recv_buffer[1]);// 第2字节
            $fin        = $firstByte >> 7;// 右移7比特得到字节最高位
            $dataLen    = $secondByte & 0x7f;// 取第2字节低7位
            $opcode     = $firstByte & 0xf;// 字节和00001111做位与运算得到低四位代码
            switch ($opcode) {
                // 连续帧
                case 0x0:
                    break;
                // 文本帧
                case 0x1:
                    break;
                // 二进制帧
                case 0x2:
                    break;
                // 断开连接
                case 0x8:
                    $connection->close();
                    break;
                // ping
                case 0x9:
                    break;
                // pong
                case 0xa:
                    break;
                // 帧异常
                default :
                    $connection->close();
                    return 0;
            }
            // 协议图示的 Extended payload length 部分
            if ($dataLen === 126) {// 如果 x 为 126，则后续16比特位代表一个16位的无符号整数，该无符号整数的值为载荷数据的有效长度；
                $headLen = 2 + 2 + 4;
                $dataLen = self::decodeDataLen($recv_buffer, 2);
            } else if ($dataLen === 127) {//如果 x 为 127，则后续64个比特位代表一个64位的无符号整数（最高位为0），该无符号整数的值为载荷数据的有效长度
                $headLen = 2 + 8 + 4;
                $dataLen = self::decodeDataLen($recv_buffer, 8);
            } else {// 如果 x 为 0~125，则 x 即为载荷数据的有效长度；
                $headLen = 6;
            }
            // 当前帧的总长度，指示头+数据体 的总长
            $currentFrameLength = $headLen + $dataLen;
            if ($currentFrameLength > $bufferLen) {
                return 0;// 帧数据长度不够 通知系统继续接收更多的资源数据
            } else if ($fin) {
                // 保存现场
                $tempScene = $connection->websocketRespFirstByte;
                // ping-pong 一般都是一次一帧来发
                switch ($opcode) {
                    case 0x9:// ping
                        // 声明为pong帧
                        $connection->websocketRespFirstByte = 0x8a;
                        // pong data
                        $pongData = self::decode(substr($recv_buffer, 0, $currentFrameLength), $connection);
                        // 当前帧的数据已经被使用，将这部分剪掉。
                        $connection->cutRecvBuffer($currentFrameLength);
                        if (isset($connection->onWebsocketPing)) {// 用户定制应答
                            call_user_func_array($connection->onWebsocketPing, [$connection, $pongData]);
                        } else {// 向客户端应答pong
                            $connection->send($pongData);
                        }
                        return 0;
                        break;
                    case 0xa:// pong
                        // pong帧
                        $connection->websocketRespFirstByte = 0x8a;
                        // pong data
                        $pongData = self::decode(substr($recv_buffer, 0, $currentFrameLength), $connection);
                        // 当前帧的数据已经被使用，将这部分剪掉。
                        $connection->cutRecvBuffer($currentFrameLength);
                        // 回调用户事件
                        if (isset($connection->onWebsocketPong)) {
                            call_user_func_array($connection->onWebsocketPong, [$connection, $pongData]);
                        }
                        return 0;
                        break;
                }
                // 还原现场
                $connection->websocketRespFirstByte = $tempScene;
                // 最后一帧 直接通知connection来处理
                return $currentFrameLength;
            }
            // 缓存当前帧的数据长度
            $connection->websocketCurrentFrameLength = $currentFrameLength;
        }
        if ($connection->websocketCurrentFrameLength < $bufferLen) {
            // 解码 并且把当前帧的数据保存起来
            self::decode(substr($recv_buffer, 0, $connection->websocketCurrentFrameLength), $connection);
            $currentFrameLength = $connection->websocketCurrentFrameLength;
            // 当前帧的数据已经被使用，将这部分剪掉。
            $connection->cutRecvBuffer($currentFrameLength);
            // 重置当前帧的数据长度
            $connection->websocketCurrentFrameLength = 0;
            return self::input(substr($recv_buffer, $currentFrameLength), $connection);
        } else if ($connection->websocketCurrentFrameLength === strlen($recv_buffer)) {
            // 解码 并且把当前帧的数据保存起来
            self::decode($recv_buffer, $connection);
            $currentFrameLength = $connection->websocketCurrentFrameLength;
            // 重置当前帧的数据长度
            $connection->websocketCurrentFrameLength = 0;
            // 当前帧的数据已经被使用，将这部分剪掉。
            $connection->cutRecvBuffer($currentFrameLength);
        }
        return 0;
    }

    /**
     * 数据封包
     * @param                     $data
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode($data, ConnectionInterface $connection)
    {
        // 和数据解析使用同一套协议
        $bufferLen = strlen($data);
        $firstByte = $connection->websocketRespFirstByte;
        //
        $responseBuffer = chr($firstByte);
        if ($bufferLen < 126) {
            // 用第2字节的无符号整数表示数据长度
            // 不需要额外字节来表示数据长度
            $responseBuffer .= chr($bufferLen);
        } else if ($bufferLen <= 65535) {//十进制(65535)=二进制(1111111111111111)
            $responseBuffer .= chr(126);
            // 用2字节表示数据长度
            $responseBuffer .= self::encodeDataLen($bufferLen, 2);// 数据长度的高8位和低8位，组成两个字节
        } else {
            $responseBuffer .= chr(127);
            // 用2字节表示数据长度
            $responseBuffer .= self::encodeDataLen($bufferLen, 8);
        }
        $responseBuffer .= $data;
        return $responseBuffer;
    }

    /**
     * 数据解包
     * @param                     $data
     * @param ConnectionInterface $connection
     * @return string|void
     */
    public static function decode($data, ConnectionInterface $connection)
    {
        $secondByte = ord($data[1]);
        $dataLength = $secondByte & 0x7f;// 取第2字节低7位
        $mask       = $secondByte >> 7;// 取第2字节最高位
        if (!$mask) {
            // 客户端推送的数据必须要有掩码
            $connection->destroy();
            return;
        }
        /**
         * mask-key
         * 恒定4字节，共64比特位。
         * 规定起始字节位紧跟payload-len
         */
        if ($dataLength == 126) {// 扩展payload-len占2字节
            $maskKey = substr($data, 4, 4);// 头部占2字节，荷载扩展长度占2字节。得出起始位置2+2
            $raw     = substr($data, 8);// 头部占2字节，荷载扩展长度占2字节，mask-key占4字节。得出起始位置2+2+4
        } else if ($dataLength === 127) {// 扩展payload-len占8字节
            $maskKey = substr($data, 10, 4);// 同上
            $raw     = substr($data, 14);// 同上
        } else {// 没有扩展payload-length
            $maskKey = substr($data, 2, 4);
            $raw     = substr($data, 6);
        }
        $buffer = '';
        foreach (range(0, strlen($raw) - 1) as $i) {
            $buffer .= chr(ord($raw[$i]) ^ ord($maskKey[$i % 4]));
        }
        if (!$connection->websocketCurrentFrameLength) {
            $decoded = $connection->websocketDataBuffer . $buffer;
            //
            $connection->websocketDataBuffer = '';
            return $decoded;
        }
        // 拼接多个帧的数据
        $connection->websocketDataBuffer .= $buffer;
        return $connection->websocketDataBuffer;
    }

    /**
     * 客户端握手
     * @param               $recv_buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function handshake($recv_buffer, TcpConnection $connection)
    {
        $request      = new Request($recv_buffer);// 自动解析请求数据
        $headerLength = strlen($request->headerRaw) + 4;// 4代表\r\n\r\n
        $secKey       = $request->headers['Sec-WebSocket-Key'];
        $guid         = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        // 固定公式：将Sec-WebSocket-Key跟258EAFA5-E914-47DA-95CA-C5AB0DC85B11拼接
        // 再通过SHA1计算出摘要，并转成base64字符串。
        $secAccept    = base64_encode(sha1($secKey . $guid));
        // 将握手数据剪辑掉
        $connection->cutRecvBuffer($headerLength);
        $upgrade = new Response();
        // 协议规定要响应给客户端的状态码&请求头
        $upgrade->statusCode(101);
        $upgrade->header('Upgrade', 'websocket');
        $upgrade->header('Sec-WebSocket-Version', 13);
        $upgrade->header('Connection', 'Upgrade');
        $upgrade->header('Sec-WebSocket-Accept', $secAccept);
        $connection->send($upgrade, true);// 向客户端应答握手信息
        // 初始化部分属性
        $connection->websocketHandshake          = true;// 是否已握手
        $connection->websocketDataBuffer         = '';// 资源流buffer解码后的客户端数据
        $connection->websocketCurrentFrameLength = 0;// 当前帧的数据长度
        $connection->websocketRespFirstByte      = 0x81;// 默认声明为文本帧 10000001
        if (isset($connection->onWebsocketConnect)) {// 回调用户事件
            call_user_func_array($connection->onWebsocketConnect, [$connection]);
        }
        return 0;// 通知系统继续接收数据包
    }

    /**
     * 解密数据长度
     * workerman中使用 unpack('nn/ntotal_len', $buffer)、unpack('n/N2c', $buffer) 来完成解码
     * @param $buffer
     * @param $byteLen
     * @return float|int
     */
    protected static function decodeDataLen($buffer, $byteLen)
    {
        $bits = '';
        // 8bytes
        foreach (range(2, $byteLen + 1) as $i) {
            $byte = decbin(ord($buffer[$i]));
            // 补零 最高位可以不补零
            // 举例(忽略空格):110 111 1010
            // 补零后(忽略空格):00000110 00000111 00001010
            // 最左的0可以不计
            $bit  = str_repeat('0', 8 - strlen($byte)) . $byte;
            $bits .= $bit;
        }
        // 将二进制转为十进制得到真实的数据长度
        return bindec($bits);
    }

    /**
     * 计算数据长度的字段值
     * @param     $bufferLen
     * @param int $bytes
     * @return string
     */
    protected static function encodeDataLen($bufferLen, $byteLen = 2)
    {
        // 先将长度值转为二进制
        $bin = decbin($bufferLen);
        // 再对二进制补零，补齐16或64比特
        // 举例(按2字节16比特):111010
        // 补零(忽略空格):00000000 00111010
        $bin = str_repeat('0', ($byteLen * 8) - strlen($bin)) . $bin;
        // 将所有比特位切成字节位 8bit = 1byte
        $bytes = array_chunk(str_split($bin, 1), 8);
        $data  = '';
        foreach ($bytes as $byte) {
            // 将8bit转成字节符
            $data .= chr(bindec(implode('', $byte)));
        }
        return $data;
    }
}
