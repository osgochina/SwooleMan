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

    const VERSION = '3.4.2';
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
    protected  $swServer;
    /**
     * worker 的实例
     * @var Worker;
     */
    protected static $_instance = null;

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

    /**
     * The file to store status info of current worker process.
     *
     * @var string
     */
    protected static $_statisticsFile = '';

    /**
     * Maximum length of the worker names.
     *
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;

    /**
     * Maximum length of the socket names.
     *
     * @var int
     */
    protected static $_maxSocketNameLength = 12;

    /**
     * Maximum length of the process user names.
     *
     * @var int
     */
    protected static $_maxUserNameLength = 12;



    public function __construct($listen,$context = '')
    {
        $this->_socketName = $listen;
        $this->_init();
        $this->_paramSocketName($this->_socketName);
        $this->_createSetting();
        if (self::$_instance){
            throw new \Exception("worker 对象已存在");
        }
        self::$_instance = $this;
    }

    public static function runAll()
    {
        self::_checkSapiEnv();
        self::_parseCommand();
        self::_run();
    }

    public static function stopAll()
    {
        // For master process.
        $master_pid = self::$_instance->swServer->master_pid;
        if ( $master_pid === posix_getpid()) {
            self::log("SwooleMan[" . basename(self::$_startFile) . "] Stopping ...");
            posix_kill($master_pid, SIGTERM);
            // Remove statistics file.
            if (is_file(self::$_statisticsFile)) {
                @unlink(self::$_statisticsFile);
            }
        } // For child processes.
        else {
            self::$_instance->swServer->stop();
            // Execute exit.
        }
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

        self::$_statisticsFile                      = sys_get_temp_dir() . '/swooleman.status';
    }

    public function _onStart($server)
    {
        $this->displayUI($server);
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
        $fd = $req->fd;
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

    public function _onMessage($server,$frame)
    {
        $fd = $frame->fd;
        if (!isset($this->connections[$fd])){
            return;
        }
        $connection = $this->connections[$fd];
        ConnectionInterface::$statistics['total_request']++;
        // Try to emit onConnect callback.
        if ($connection->onMessage) {
            try {
                call_user_func($connection->onMessage, $connection,$frame->data);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
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
                $this->swServer = $this->_newTcpServer();
                break;
            case "udp":
                $this->swServer = $this->_newUdpServer();
                break;
            case "unix":
                $this->swServer = $this->_newUnixServer();
                break;
            case "http":
                $this->swServer = $this->_newHttpServer();
                break;
            case "websocket":
                $this->swServer = $this->_newWebSocketServer();
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

    protected  function _init()
    {
        // Worker name.
        if (empty($this->name)) {
            $this->name = 'none';
        }
        // Get maximum length of worker name.
        $worker_name_length = strlen($this->name);
        if (self::$_maxWorkerNameLength < $worker_name_length) {
            self::$_maxWorkerNameLength = $worker_name_length;
        }
        // Get maximum length of socket name.
        $socket_name_length = strlen($this->_socketName);
        if (self::$_maxSocketNameLength < $socket_name_length) {
            self::$_maxSocketNameLength = $socket_name_length;
        }

        // Get unix user of the worker process.
        if (empty($this->user)) {
            $this->user = self::getCurrentUser();
        } else {
            if (posix_getuid() !== 0 && $this->user != self::getCurrentUser()) {
                self::log('Warning: You must have the root privileges to change uid and gid.');
            }
        }
        // Get maximum length of unix user name.
        $user_name_length = strlen($this->user);
        if (self::$_maxUserNameLength < $user_name_length) {
            self::$_maxUserNameLength = $user_name_length;
        }
    }

    protected static function _parseCommand()
    {

        global $argv;
        // Check argv;
        $start_file = $argv[0];
        if (!isset($argv[1])) {
            exit("Usage: php yourfile.php {start|stop|restart|reload|status}\n");
        }

        // Get command.
        $command  = trim($argv[1]);
        $command2 = isset($argv[2]) ? $argv[2] : '';

        // Start command.
        $mode = '';
        if ($command === 'start') {
            if ($command2 === '-d' || Worker::$daemonize) {
                $mode = 'in DAEMON mode';
            } else {
                $mode = 'in DEBUG mode';
            }
        }
        self::log("SwooleMan[$start_file] $command $mode");

        // Get master process PID.
        $master_pid      = @file_get_contents(self::$pidFile);
        $master_is_alive = $master_pid && @posix_kill($master_pid, 0);
        // Master is still alive?
        if ($master_is_alive) {
            if ($command === 'start' && posix_getpid() != $master_pid) {
                self::log("SwooleMan[$start_file] already running");
                exit;
            }
        } elseif ($command !== 'start' && $command !== 'restart') {
            self::log("SwooleMan[$start_file] not run");
            exit;
        }

        // execute command.
        switch ($command) {
            case 'start':
                if ($command2 === '-d') {
                    Worker::$daemonize = true;
                }
                break;
            case 'status':
//                if (is_file(self::$_statisticsFile)) {
//                    @unlink(self::$_statisticsFile);
//                }
//                // Master process will send status signal to all child processes.
//                posix_kill($master_pid, SIGUSR2);
//                // Waiting amoment.
//                usleep(500000);
//                // Display statisitcs data from a disk file.
//                @readfile(self::$_statisticsFile);
                exit(0);
            case 'stop':
                self::log("SwooleMan[$start_file] is stoping ...");
                // Send stop signal to master process.
                $master_pid && posix_kill($master_pid, SIGTERM);
                // Timeout.
                $timeout    = 5;
                $start_time = time();
                // Check master process is still alive?
                while (1) {
                    $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                    if ($master_is_alive) {
                        // Timeout?
                        if (time() - $start_time >= $timeout) {
                            self::log("SwooleMan[$start_file] stop fail");
                            exit;
                        }
                        // Waiting amoment.
                        usleep(10000);
                        continue;
                    }
                    // Stop success.
                    self::log("SwooleMan[$start_file] stop success");
                    if ($command === 'stop') {
                        exit(0);
                    }
                    if ($command2 === '-d') {
                        Worker::$daemonize = true;
                    }
                    break;
                }
                break;
            case 'reload':
                posix_kill($master_pid, SIGUSR1);
                self::log("SwooleMan[$start_file] reload");
                exit;
            default :
                exit("Usage: php yourfile.php {start|stop|restart|reload|status}\n");
        }
    }



    protected static function _run()
    {
        self::$_instance->_newService();
        self::$_instance->swServer->start();
    }

    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }


    /**
     * Display staring UI.
     *
     * @return void
     */
    protected  function displayUI($server)
    {
        self::safeEcho("\033[1A\n\033[K-----------------------\033[47;30m SWOOMEMAN \033[0m-----------------------------\n\033[0m");
        self::safeEcho('SwooleMan version:'. Worker::VERSION. "          PHP version:". PHP_VERSION. "\n");
        self::safeEcho("------------------------\033[47;30m WORKERS \033[0m-------------------------------\n");
        self::safeEcho("\033[47;30muser\033[0m". str_pad('',
                self::$_maxUserNameLength + 2 - strlen('user')). "\033[47;30mworker\033[0m". str_pad('',
                self::$_maxWorkerNameLength + 2 - strlen('worker')). "\033[47;30mlisten\033[0m". str_pad('',
                self::$_maxSocketNameLength + 2 - strlen('listen')). "\033[47;30mprocesses\033[0m \033[47;30m". "status\033[0m\n");

        self::safeEcho(str_pad($this->user, self::$_maxUserNameLength + 2). str_pad($this->name,
                self::$_maxWorkerNameLength + 2). str_pad($this->_socketName,
                self::$_maxSocketNameLength + 2). str_pad(' ' . $this->count, 9). " \033[32;40m [OK] \033[0m\n");

        self::safeEcho("----------------------------------------------------------------\n");
        if (self::$daemonize) {
            global $argv;
            $start_file = $argv[0];
            self::safeEcho("Input \"php $start_file stop\" to quit. Start success.\n\n");
        } else {
            self::safeEcho("Press Ctrl-C to quit. Start success.\n");
        }
    }

}