<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/20
 * Time: 16:29
 */

require __DIR__."/../Autoloader.php";
use SwooleMan\Worker;

$worker = new Worker("tcp://127.0.0.1:9999");

$worker->onWorkerStart = function ($worker){
    echo "onWorkerStart\n";
};
$worker::runAll();