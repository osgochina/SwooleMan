<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/27
 * Time: 18:46
 */

require __DIR__."/../Autoloader.php";
use SwooleMan\Worker;
use SwooleMan\Connection\AsyncTcpConnection;

$worker = new Worker();

$worker->onWorkerStart = function($worker)
{
    $client = new AsyncTcpConnection("ws://127.0.0.1:8282");

    $client->onMessage = function ($connection,$data){
        var_dump($data);
    };
    $client->onConnect = function ($connection){
      //var_dump("onConnect") ;
        $connection->send("aa");
    };
    $client->connect();

};
$worker::runAll();


//$cli = new swoole_http_client('127.0.0.1', 8282);
//
//$cli->on('message', function ($_cli, $frame) {
//    var_dump($frame);
//});
//
//$cli->upgrade('/', function ($cli) {
//    echo $cli->body;
//    $cli->push("hello world");
//});
