<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/27
 * Time: 15:05
 */
require_once __DIR__."/../Autoloader.php";

use SwooleMan\WebServer;

// WebServer
$web = new WebServer("http://0.0.0.0:8383");
// WebServer数量
$web->count = 1;
// 设置站点根目录
$web->addRoot('www.your_domain.com', __DIR__.'/Web');

$web::runAll();
