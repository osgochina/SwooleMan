<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/22
 * Time: 16:58
 */
require "/data/www/wwwroot/Workerman/Autoloader.php";
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:1234');
$worker->count=3;
$worker->onMessage = function($connection, $data)
{
    $connection->send("HTTP/1.1 200 OK\r\nConnection: keep-alive\r\nServer: workerman\r\nContent-Length: 5\r\n\r\nhello");
};
Worker::runAll();