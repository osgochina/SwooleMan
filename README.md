# SwooleMan

SwooleMan 是基于[Swoole](https://github.com/swoole/swoole-src)开发的兼容[Workerman](https://github.com/walkor/Workerman) API 的一个服务框架.
致力于帮助现有的workerman项目,方便的运行在Swoole服务上,享受swoole带来的高性能.

注意:使用此服务请安装swoole扩展,[下载地址](https://github.com/swoole/swoole-src/releases).

- Swoole 项目地址 [https://github.com/swoole/swoole-src](https://github.com/swoole/swoole-src)
- Swoole 文档地址 [https://wiki.swoole.com/wiki/index](https://wiki.swoole.com/wiki/index)
- Workerman 项目地址 [https://github.com/walkor/Workerman](https://github.com/walkor/Workerman)
- Workerman 文档地址 [http://doc.workerman.net/](http://doc.workerman.net/)

## 使用方式

```php

require __DIR__."/../Autoloader.php";
use SwooleMan\Worker;

// 代理监听本地4406端口
$proxy = new Worker('text://0.0.0.0:4406');

$proxy->onMessage = function ($connection, $buffer){
    $connection->send($buffer);
};
$proxy::runAll();

```

### 注意事项
1.SwooleMan 不支持在同一个文件中实例化多个Worker.参考workerman的Windows版本 [链接](http://doc3.workerman.net/worker-development/run-all.html)

2.使用listen 方法,不能在onWorkerStart中调用,请正常new一个woker实例,在调用listen方法.