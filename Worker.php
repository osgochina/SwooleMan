<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/7/15
 * Time: 13:58
 */

namespace SwooleMan;

use Swoole;
use SwooleMan\Connection\ConnectionInterface;
use SwooleMan\Connection\TcpConnection;

class Worker
{
    /**
     * 当前worker进程的id编号
     * @var int
     */
    public $id;

    /**
     * 当前Worker实例启动多少个进程，不设置时默认为1
     * @var int
     */
    public $count = 1;

    /**
     * Worker实例的名称
     * @var string
     */
    public $name = "none";

    /**
     * Worker实例以哪个用户运行
     * @var string
     */
    public $user;

    /**
     * Worker实例是否可以reload
     * @var bool
     */
    public $reloadable = true;

    /**
     * Worker实例所使用的传输层协议
     * @var string
     */
    public $transport = 'tcp';

    /**
     * 此属性中存储了当前进程的所有的客户端连接对象
     * @var array
     */
    public $connections;

    /**
     * 是否以daemon(守护进程)方式运行
     * @var bool
     */
    public static $daemonize = false;

    /**
     * 输出重定向
     * @var string
     */
    public static $stdoutFile = '/dev/null';

    /**
     * SwooleMan进程的pid文件路径
     * @var string
     */
    public static $pidFile = '';

    /**
     * 日志文件位置
     * @var string
     */
    public static $logFile = '';

    /**
     * 全局的eventloop实例
     * @var Events\EventInterface
     */
    public static $globalEvent;

    /**
     * 此属性用来设置发送缓冲区大小
     * @var int
     */
    public static $defaultMaxSendBufferSize = 1048576;

    /**
     * 连接能够接收的最大包包长
     * @var int
     */
    public static $maxPackageSize = 10485760;



    /**
     * 设置当前worker是否开启监听端口复用
     * @var bool
     */
    public $reusePort = false;

    /**
     * Worker实例的协议类
     * @var
     */
    public $protocol;

    /**
     * Worker启动时的回调函数
     * @var  callback
     */
    public $onWorkerStart = null;

    /**
     * Worker收到reload信号后执行的回调
     * @var callback
     */
    public $onWorkerReload = null;

    /**
     * Workert停止时的回调函数
     * @var callback
     */
    public $onWorkerStop = null;

    /**
     * 当连接建立时触发的回调函数
     * @var callback
     */
    public $onConnect = null;

    /**
     * 当有客户端的连接上有数据发来时触发
     * @var callback
     */
    public $onMessage = null;

    /**
     * 当连接断开时触发的回调函数
     * @var callback
     */
    public $onClose = null;

    /**
     * 当连接的应用层发送缓冲区满时触发
     * @var callback
     */
    public $onBufferFull = null;

    /**
     * 当连接的应用层发送缓冲区数据全部发送完毕时触发
     * @var callback
     */
    public $onBufferDrain = null;

    /**
     * 当客户端的连接上发生错误时触发
     * @var callback
     */
    public $onError = null;

    /**
     * Socket name. The format is like this http://0.0.0.0:80
     * @var string
     */
    protected $_socketName = '';

    /**
     * 套接字上下文选项
     * @var array
     */
    protected $_context = [];

    /**
     * worker 监听的ip
     * @var string
     */
    protected $_host = '';

    /**
     * worker监听的端口
     * @var int
     */
    protected $_port = 0;

    /**
     * 配置信息
     * @var array
     */
    protected $_setting = [];

    /**
     * @var \Swoole\Server;
     */
    protected static $swServer;

    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'tcp',
        'websocket' => 'websocket',
        'http'   => 'http',
    );

    /**
     * 启动脚本文件路径
     * @var string
     */
    protected static $_startFile = '';



    public function __construct($listen,$context = '')
    {
        $this->_socketName = $listen;
        $this->_paramSocketName($this->_socketName);
        $this->_createSetting();
        $this->_newService();
    }

    public static function runAll()
    {
        self::_checkSapiEnv();
        self::_init();
        self::_parseCommand();
        self::_run();
    }

    public static function stopAll()
    {

    }

    public static function listen()
    {

    }


    /**
     * 解析协议
     * @param $_socketName
     * @return bool
     * @throws \Exception
     */
    protected function _paramSocketName($_socketName)
    {
        if (!$this->_socketName) {
            return false;
        }
        list($scheme, $address) = explode(':', $_socketName, 2);
        if (isset(self::$_builtinTransports[$scheme])){
            $this->transport = $scheme;
        }else{
            if(class_exists($scheme)){
                $this->protocol = $scheme;
            }else{
                $scheme         = ucfirst($scheme);
                $this->protocol = '\\Protocols\\' . $scheme;
                if (!class_exists($this->protocol)) {
                    $this->protocol = "\\SwooleMan\\Protocols\\$scheme";
                    if (!class_exists($this->protocol)) {
                        throw new \Exception("class \\Protocols\\$scheme not exist");
                    }
                }
            }
            if (!isset(self::$_builtinTransports[$this->transport])) {
                throw new \Exception('Bad worker->transport ' . var_export($this->transport, true));
            }
        }

        if (stripos($address,":") === false){
            $this->_host = "/".trim($address,"/");
            $this->_port = 0;
        }else{
            list($this->_host,$this->_port) = explode(":",trim($address,"//"),2);
        }
        return true;
    }

    /**
     * 根据设计创建配置选项
     */
    protected function _createSetting()
    {
        if (isset($this->_context['backlog'])){
            $this->_setting['backlog'] = $this->_context['backlog'];
        }
        if ($this->count > 1){
            $this->_setting['worker_num'] = $this->count;
        }
        if (!empty($this->user)){
            $this->_setting['user'] = $this->user;
        }
        // Start file.
        $backtrace        = debug_backtrace();
        self::$_startFile = $backtrace[count($backtrace) - 1]['file'];

        // Pid file.
        if (empty(self::$pidFile)) {
            self::$pidFile = __DIR__ . "/../" . str_replace('/', '_', self::$_startFile) . ".pid";
        }
        $this->_setting['pid_file'] = self::$pidFile;
        // Log file.
        if (empty(self::$logFile)) {
            self::$logFile = __DIR__ . '/../swooleman.log';
        }
        $this->_setting['log_file'] = self::$logFile;
        //设置端口重用
        $this->_setting['enable_reuse_port'] = $this->reusePort;
        //守护进程运行
        $this->_setting['daemonize'] = self::$daemonize;
    }

    public function _onStart($server)
    {

    }
    public function _onShutdown($server)
    {

    }

    /**
     * worker启动
     * @param $server
     * @param $worker_id
     */
    public function _onWorkerStart($server,$worker_id)
    {
        $this->id = $worker_id;
        if ($this->onWorkerStart) {
            try {
                call_user_func($this->onWorkerStart, $this);
            } catch (\Exception $e) {
                self::log($e);
                // Avoid rapid infinite loop exit.
                sleep(1);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                // Avoid rapid infinite loop exit.
                sleep(1);
                exit(250);
            }
        }
    }

    public function _onWorkerStop($server,$worker_id)
    {
        if ($this->onWorkerStop) {
            try {
                call_user_func($this->onWorkerStop, $this);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
    }

    public function _onConnect($server,$fd,$from_id)
    {
        var_dump("_onConnect");
        $connection                         = new TcpConnection($server, $fd);
        $this->connections[$fd] = $connection;
        $connection->worker                 = $this;
        $connection->protocol               = $this->protocol;
        $connection->transport              = $this->transport;
        $connection->onMessage              = $this->onMessage;
        $connection->onClose                = $this->onClose;
        $connection->onError                = $this->onError;
        $connection->onBufferDrain          = $this->onBufferDrain;
        $connection->onBufferFull           = $this->onBufferFull;
        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                call_user_func($this->onConnect, $connection);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
    }
    public function _onReceive($server,$fd,$from_id,$data)
    {
        if (!isset($this->connections[$fd])){
            return;
        }

        $connection = $this->connections[$fd];
        if ($connection->protocol) {
            $parser = $connection->protocol;
            $data = $parser::decode($data, $connection);
        }
        ConnectionInterface::$statistics['total_request']++;

        // Try to emit onConnect callback.
        if ($connection->onMessage) {
            try {
                call_user_func($connection->onMessage, $connection,$data);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
    }
    public function _onPacket($server,$data, $client_info)
    {

    }
    public function _onClose($server,$fd,$from_id)
    {
        if (!isset($this->connections[$fd])){
            return;
        }
        $connection = $this->connections[$fd];
        // Try to emit onConnect callback.
        if ($connection->onClose) {
            try {
                call_user_func($connection->onClose, $connection);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
    }
    public function _onBufferFull($server,$fd)
    {
        if (!isset($this->connections[$fd])){
            return;
        }
        $connection = $this->connections[$fd];
        // Try to emit onConnect callback.
        if ($connection->onBufferFull) {
            try {
                call_user_func($connection->onBufferFull, $connection);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
    }
    public function _onBufferEmpty($server,$fd)
    {
        if (!isset($this->connections[$fd])){
            return;
        }
        $connection = $this->connections[$fd];
        // Try to emit onConnect callback.
        if ($connection->onBufferDrain) {
            try {
                call_user_func($connection->onBufferDrain, $connection);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
    }
    public function _onWorkerError($server,$worker_id, $worker_pid, $exit_code, $signal)
    {

    }

    public function _onRequest($request,$response)
    {

    }

    public function _onOpen($server, $req)
    {

    }

    public function _onMessage($server,$frame)
    {

    }

    /**
     * 创建tcp服务
     * @return Swoole\Server
     */
    protected function _newTcpServer()
    {
        $server = new Swoole\Server($this->_host,$this->_port,SWOOLE_BASE,SWOOLE_SOCK_TCP);
        $server->on("Start",[$this,"_onStart"]);
        $server->on("Shutdown",[$this,"_onShutdown"]);
        $server->on("WorkerStart",[$this,"_onWorkerStart"]);
        $server->on("WorkerStop",[$this,"_onWorkerStop"]);
        $server->on("Connect",[$this,"_onConnect"]);
        $server->on("Receive",[$this,"_onReceive"]);
        $server->on("Close",[$this,"_onClose"]);
        $server->on("BufferFull",[$this,"_onBufferFull"]);
        $server->on("BufferEmpty",[$this,"_onBufferEmpty"]);
        $server->on("WorkerError",[$this,"_onWorkerError"]);
        if (!empty($this->_setting)){
            $server->set($this->_setting);
        }
        return $server;
    }

    /**
     * 创建udp服务
     * @return Swoole\Server
     */
    protected function _newUdpServer()
    {
        $server = new Swoole\Server($this->_host,$this->_port,SWOOLE_BASE,SWOOLE_SOCK_UDP);
        $server->on("Start",[$this,"_onStart"]);
        $server->on("Shutdown",[$this,"_onShutdown"]);
        $server->on("WorkerStart",[$this,"_onWorkerStart"]);
        $server->on("WorkerStop",[$this,"_onWorkerStop"]);
        $server->on("Packet",[$this,"_onPacket"]);
        $server->on("BufferFull",[$this,"_onBufferFull"]);
        $server->on("BufferEmpty",[$this,"_onBufferEmpty"]);
        $server->on("WorkerError",[$this,"_onWorkerError"]);
        if (!empty($this->_setting)){
            $server->set($this->_setting);
        }
        return $server;
    }

    /**
     * 创建unix协议服务
     * @return Swoole\Server
     */
    protected function _newUnixServer()
    {
        $server = new Swoole\Server($this->_host,$this->_port,SWOOLE_BASE,SWOOLE_UNIX_STREAM);
        $server->on("Start",[$this,"_onStart"]);
        $server->on("Shutdown",[$this,"_onShutdown"]);
        $server->on("WorkerStart",[$this,"_onWorkerStart"]);
        $server->on("WorkerStop",[$this,"_onWorkerStop"]);
        $server->on("Connect",[$this,"_onConnect"]);
        $server->on("Receive",[$this,"_onReceive"]);
        $server->on("Close",[$this,"_onClose"]);
        $server->on("BufferFull",[$this,"_onBufferFull"]);
        $server->on("BufferEmpty",[$this,"_onBufferEmpty"]);
        $server->on("WorkerError",[$this,"_onWorkerError"]);
        if (!empty($this->_setting)){
            $server->set($this->_setting);
        }
        return $server;
    }

    /**
     * 创建http协议服务
     * @return Swoole\Http\Server
     */
    protected function _newHttpServer()
    {
        $server = new Swoole\Http\Server($this->_host,$this->_port,SWOOLE_BASE);
        $server->on("Start",[$this,"_onStart"]);
        $server->on("Shutdown",[$this,"_onShutdown"]);
        $server->on("WorkerStart",[$this,"_onWorkerStart"]);
        $server->on("WorkerStop",[$this,"_onWorkerStop"]);
        $server->on("Request",[$this,"_onRequest"]);
        $server->on("BufferFull",[$this,"_onBufferFull"]);
        $server->on("BufferEmpty",[$this,"_onBufferEmpty"]);
        $server->on("WorkerError",[$this,"_onWorkerError"]);
        if (!empty($this->_setting)){
            $server->set($this->_setting);
        }
        return $server;
    }

    /**
     * 创建websocket服务
     * @return Swoole\WebSocket\Server
     */
    protected function _newWebSocketServer()
    {
        $server = new Swoole\WebSocket\Server($this->_host,$this->_port,SWOOLE_BASE);
        $server->on("Start",[$this,"_onStart"]);
        $server->on("Shutdown",[$this,"_onShutdown"]);
        $server->on("WorkerStart",[$this,"_onWorkerStart"]);
        $server->on("WorkerStop",[$this,"_onWorkerStop"]);
        $server->on("Open",[$this,"_onOpen"]);
        $server->on("Message",[$this,"_onMessage"]);
        $server->on("BufferFull",[$this,"_onBufferFull"]);
        $server->on("BufferEmpty",[$this,"_onBufferEmpty"]);
        $server->on("WorkerError",[$this,"_onWorkerError"]);
        if (!empty($this->_setting)){
            $server->set($this->_setting);
        }
        return $server;
    }

    /**
     * 创建服务
     */
    protected function _newService()
    {
        switch (strtolower($this->transport)){
            case "tcp":
                self::$swServer = $this->_newTcpServer();
                break;
            case "udp":
                self::$swServer = $this->_newUdpServer();
                break;
            case "unix":
                self::$swServer = $this->_newUnixServer();
                break;
            case "http":
                self::$swServer = $this->_newHttpServer();
                break;
            case "websocket":
                self::$swServer = $this->_newWebSocketServer();
                break;
        }
    }

    /**
     * Safe Echo.
     *
     * @param $msg
     */
    public static function safeEcho($msg)
    {
        if (!function_exists('posix_isatty') || posix_isatty(STDOUT)) {
            echo $msg;
        }
    }

    /**
     * Log.
     *
     * @param string $msg
     * @return void
     */
    public static function log($msg)
    {
        $msg = $msg . "\n";
        if (!self::$daemonize) {
            self::safeEcho($msg);
        }
        file_put_contents((string)self::$logFile, date('Y-m-d H:i:s') . ' ' . 'pid:'. posix_getpid() . ' ' . $msg, FILE_APPEND | LOCK_EX);
    }

    protected static function _checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != "cli") {
            exit("only run in command line mode \n");
        }
    }

    protected static function _init()
    {

    }

    protected static function _parseCommand()
    {

    }



    protected static function _run()
    {
        self::$swServer->start();
    }

}