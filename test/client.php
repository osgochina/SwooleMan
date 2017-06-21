<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/21
 * Time: 17:53
 */

require __DIR__."/../Autoloader.php";
use SwooleMan\Worker;
use SwooleMan\Connection\AsyncTcpConnection;

$connection = new AsyncTcpConnection('tcp://127.0.0.1:6379');
$connection->connect();
