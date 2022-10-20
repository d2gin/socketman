<?php


namespace icy8\SocketMan\Connection\Http;


class Request
{
    protected $buffer    = '';
    public    $headers   = [];// 请求头
    public    $headerRaw = '';// 请求头原始数据
    public    $uri;// 请求路径
    public    $protocol  = 'HTTP/1.1';// 协议
    public    $query     = [];// 查询子串
    public    $method    = 'GET';// 请求方法
    public    $cookies   = []; // @todo 解析cookie
    public    $body      = '';// 请求body

    public function __construct($buffer)
    {
        $this->buffer    = $buffer;
        $buffer_lines    = explode("\r\n", $buffer);
        $request_line    = $buffer_lines[0];
        $buffer_groups   = explode("\r\n\r\n", implode("\r\n", $buffer_lines));
        $header_raw      = $buffer_groups[0];
        $this->body      = $buffer_groups[1] ?? '';
        $this->headerRaw = $header_raw;
        $header_lines    = array_slice(explode("\r\n", $header_raw), 1);
        $request_item    = explode(" ", $request_line);
        $this->method    = trim($request_item[0]);
        $this->uri       = trim($request_item[1]);
        $this->protocol  = trim($request_item[2] ?? '');
        parse_str(parse_url($this->uri, PHP_URL_QUERY), $this->query);
        foreach ($header_lines as $header) {
            if (empty($header)) {
                continue;
            }
            list($name, $value) = explode(":", $header, 2);
            $this->headers[$name] = trim($value);
        }
    }
}