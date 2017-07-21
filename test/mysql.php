<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/20
 * Time: 16:29
 */

require __DIR__."/../Autoloader.php";
use SwooleMan\Worker;
use SwooleMan\Connection\AsyncTcpConnection;


// 真实的mysql地址，假设这里是本机3306端口
$REAL_MYSQL_ADDRESS = 'tcp://127.0.0.1:3306';

// 代理监听本地4406端口
$proxy = new Worker('tcp://0.0.0.0:4406');

$proxy->onWorkerStop = function ($connection){
    echo "onWorkerStop\n";
};
$proxy->onConnect = function($connection)
{
    global $REAL_MYSQL_ADDRESS;
    // 异步建立一个到实际mysql服务器的连接
    $connection_to_mysql = new AsyncTcpConnection($REAL_MYSQL_ADDRESS);
    // mysql连接发来数据时，转发给对应客户端的连接
    $connection_to_mysql->onMessage = function($connection_to_mysql, $buffer)use($connection)
    {
        $connection->send($buffer);
    };
    // mysql连接关闭时，关闭对应的代理到客户端的连接
    $connection_to_mysql->onClose = function($connection_to_mysql)use($connection)
    {
        $connection->close();
    };
    // mysql连接上发生错误时，关闭对应的代理到客户端的连接
    $connection_to_mysql->onError = function($connection_to_mysql)use($connection)
    {
        $connection->close();
    };
    // 执行异步连接
    $connection_to_mysql->connect();

    // 客户端发来数据时，转发给对应的mysql连接
    $connection->onMessage = function($connection, $buffer)use($connection_to_mysql)
    {
        $connection_to_mysql->send($buffer);
    };
    // 客户端连接断开时，断开对应的mysql连接
    $connection->onClose = function($connection)use($connection_to_mysql)
    {
        $connection_to_mysql->close();
    };
    // 客户端连接发生错误时，断开对应的mysql连接
    $connection->onError = function($connection)use($connection_to_mysql)
    {
        $connection_to_mysql->close();
    };

};
// 运行worker
Worker::runAll();