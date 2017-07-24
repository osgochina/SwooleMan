<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/7/24
 * Time: 15:55
 */


$server = new \Swoole\WebSocket\Server("127.0.0.1",1234);
$server->on("Open",function ($svr, $req){
    var_dump("Open");
});
$server->on("Message",function ($svr, $frame){
    $svr->push($frame->fd,"1234".$frame->data);
    var_dump("Message");
});

$port = $server->listen("127.0.0.1",5678,SWOOLE_SOCK_UDP);
//$port->set([
//    'open_eof_split' => true,
//    'package_eof' => "\r\n",
//]);
$port->on("Packet",function ($server,  string $data, array $client_info){
    var_dump("Packet");
    //$server->send($fd,$data);
});

$server->start();
