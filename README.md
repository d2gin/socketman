# socketman

#### 介绍

对`workerman`的原理剖析，是对其基本功能的重新实现，并简化了相关逻辑。项目代码仅供学习交流。

#### 安装教程

- composer离线安装

    ```json
    {
        "require": {
            "icy8/socketman": "dev-master"
        },
        "repositories": [
            {
            "type": "path",
            "url": "vendor/icy8/socketman"
            }
        ]
    }
    ```

    ```shell
    composer install
    ```

#### 使用说明

- 从workerman中砍掉的功能比较多，比如进程、端口复用、定时器、SSL等等。

- 目前没有对http协议做过多的封装

#### 使用示例

1. 启动一个websocket服务

    ```php
    <?php
    use icy8\SocketMan\Server;

    include "vendor/autoload.php";
    $server = new Server('websocket://0.0.0.0:996');
    // 监听
    $server->onConnect = function ($connection) {
        // 这部分事件只能在onConnect中监听
        // 暂时没有对这部分功能进行优化
        $connection->onWebsocketConnect = function ($connection) {};
        $connection->onWebsocketPing    = function ($connection) {};
        $connection->onWebsocketPong    = function ($connection) {};
    };
    // 这个事件会被提取到connection中
    $server->onMessage = function ($connection, $data) {
        var_dump($data);
    };
    $server->run();
    ```

2. 启动一个http服务

    ```php
    <?php
    use icy8\SocketMan\Server;

    include "vendor/autoload.php";
    $server = new Server('http://0.0.0.0:996');
    // 监听
    $server->onConnect = function ($connection) {};
    // 这个事件会被提取到connection中
    $server->onMessage = function ($connection, $request) {
        var_dump($request->header);
    };
    $server->run();
    ```