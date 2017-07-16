<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/7/14
 * Time: 16:41
 */

$serv = new swoole_server("127.0.0.1", 9527, SWOOLE_BASE, SWOOLE_SOCK_TCP);
$serv->set([
    'worker_num' => 4,
]);

$serv->on("Start",function ($server){
    echo "Start".posix_getpid()."\n";
});

$serv->on("Shutdown",function ($server){
    echo "Shutdown\n";
});
$serv->on("WorkerStart",function ($server){
    echo "WorkerStart".posix_getpid()."\n";
});
$serv->on("WorkerStop",function ($server){
    echo "WorkerStop\n";
});

$serv->on("Connect",function ($server){
    echo "Connect\n";
});
$serv->on("Receive",function ($server){
    echo "Receive\n";
});

$serv->on("Close",function ($server){
    echo "Close\n";
});

$serv->on("ManagerStop",function ($server){
    echo "ManagerStop\n";
});

$serv->on("ManagerStart",function ($server){
    echo "ManagerStart\n";
});

$serv->start();