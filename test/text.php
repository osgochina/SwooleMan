<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/21
 * Time: 19:58
 */

require __DIR__."/../Autoloader.php";
use SwooleMan\Worker;

// 代理监听本地4406端口
$proxy = new Worker('text://0.0.0.0:4406');

$proxy->onMessage = function ($connection, $buffer){
    $connection->send($buffer);
};
$proxy::runAll();

