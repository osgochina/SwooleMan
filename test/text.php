<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/21
 * Time: 19:58
 */

require __DIR__."/../Autoloader.php";
use SwooleMan\Worker;

//// 代理监听本地4406端口
//$proxy = new Worker('text://0.0.0.0:4406');
//
//$proxy->onMessage = function ($connection, $buffer){
//    $connection->send($buffer);
//};
//$proxy::runAll();

//$serv = new swoole_server("0.0.0.0", 9502,SWOOLE_BASE);
//$serv->set(['worker_num'=>4]);
//$serv->on('workerstart', function($server, $id) {
//    //仅在worker-0中监听管理端口
//    //if ($id != 0) return;
//    $local_listener = stream_socket_server("tcp://127.0.0.1:8081", $errno, $errstr);
//    swoole_event_add($local_listener, function($server) {
//        $local_client = stream_socket_accept($server, 0);
//        swoole_event_add($local_client, function($client) {
//            echo fread($client, 8192);
//            fwrite($client, "hello");
//        });
//    });
//});
//$serv->on("Receive",function (){});
//$serv->start();

$swClient = new \swoole_client(SWOOLE_SOCK_TCP , SWOOLE_SOCK_ASYNC);
$swClient->on("Connect",function (){});
$swClient->on("Error",function (){});
$swClient->on("Receive",function (){});
$swClient->on("Close",function (){});

$swClient->connect("127.0.0.1",1278);
//echo "hellp";
swoole_event_wait();
echo "hellp";


//$client = new \SwooleMan\Connection\AsyncTcpConnection("tcp://127.0.0.1:6379");
//$client->connect();