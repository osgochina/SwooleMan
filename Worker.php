<?php

/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace SwooleMan;

require_once __DIR__ . '/Lib/Constants.php';

use SwooleMan\Connection\SwTcpConnection;
use SwooleMan\Connection\SwUdpConnection;
use SwooleMan\Connection\ConnectionInterface;
use SwooleMan\Events\SwEvent;
use SwooleMan\Lib\Timer;

class Worker
{

    const VERSION = '3.4.2';

    /**
     * Status starting.
     *
     * @var int
     */
    const STATUS_STARTING = 1;

    /**
     * Status running.
     *
     * @var int
     */
    const STATUS_RUNNING = 2;

    const STATUS_SHUTDOWN = 4;

    const STATUS_RELOADING = 8;

    const KILL_WORKER_TIMER_TIME = 2;

    public $id = 0;

    public $name = 'none';

    public $count = 1;

    public $user = '';

    public $group = '';

    public $protocol = '';

    public $transport = 'tcp';

    public $reusePort = false;

    public $connections = [];

    public $reloadable = true;

    public static $daemonize = false;

    public static $stdoutFile = '/dev/null';

    public static $pidFile = '';

    public static $logFile = '';

    protected static $_startFile = '';

    public static $globalEvent = null;

    public $onWorkerStart = null;

    public $onConnect = null;

    public $onMessage = null;

    public $onClose = null;

    public $onError = null;

    public $onBufferFull = null;

    public $onBufferDrain = null;

    public $onWorkerStop = null;

    public $onWorkerReload = null;

    public static $onMasterReload = null;

    public static $onMasterStop = null;


    protected static $_status = self::STATUS_STARTING;

    /**
     * Maximum length of the worker names.
     *
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;
    protected static $_maxSocketNameLength = 12;
    protected static $_maxUserNameLength = 12;

    protected static $_workers = array();
    protected static $_pidMap = array();
    protected static $_idMap = array();
    protected static $_masterPid = 0;

    protected static $_pidsToRestart = array();

    protected $_autoloadRootPath = '';

    protected $_socketName;

    protected static $_statisticsFile = '';

    protected static $_globalStatistics = array(
        'start_timestamp'  => 0,
        'worker_exit_info' => array()
    );

    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'tcp'
    );

    public $swServer;
    protected $_setting = [];


    public function __construct($socket_name = '', $context_option = array())
    {
        // Save all worker instances.
        $this->workerId                  = spl_object_hash($this);
        self::$_workers[$this->workerId] = $this;
        self::$_pidMap[$this->workerId]  = array();
        // Get autoload root path.
        $backtrace                = debug_backtrace();
        $this->_autoloadRootPath = dirname($backtrace[0]['file']);

        // Context for socket.
        if ($socket_name) {
            $this->_socketName = $socket_name;
            if (isset($context_option['socket']['backlog'])) {
                $this->_setting['backlog'] = $context_option['socket']['backlog'];
            }
        }
        // Set an empty onMessage callback.
        $this->onMessage = function () {};
    }

    public static function runAll()
    {
        self::checkSapiEnv();
        self::init();
        self::parseCommand();
        self::daemonize();
        self::initWorkers();
        self::saveMasterPid();
        self::forkWorkers();
        self::installSignal();
        self::displayUI();
        self::resetStd();
        self::monitorWorkers();
    }


    public  function listen()
    {

    }

    /**
     * 解析协议
     * @return array
     * @throws \Exception
     */
    protected function analyzeProtocol()
    {
        list($scheme, $address) = explode(':', $this->_socketName, 2);

        if (!isset(self::$_builtinTransports[$scheme])) {
            if(class_exists($scheme)){
                $this->protocol = $scheme;
            } else {
                switch ($scheme){
                    case "text":
                        $this->_setting['open_eof_check'] = true;
                        $this->_setting['package_eof'] = "\n";
                        break;
                    case "Frame":
                        $this->_setting['open_length_check'] = true;
                        $this->_setting['package_length_type'] = "N";
                        $this->_setting['package_max_length'] = 10485760;
                        break;
                }
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
        } else {
            $this->transport = $scheme;
        }
        if ($this->transport == 'unix'){
            return [
                "host"=>trim($address,"//"),
                "port"=>0,
                "flags"=>SWOOLE_UNIX_STREAM
            ];
        }

        list($host,$port) = explode(":",$address,2);
        if ($this->transport == 'udp'){
            return [
                "host"=>trim($host,"//"),
                "port"=>$port,
                "flags"=>SWOOLE_SOCK_UDP
            ];
        }
        if ($this->transport == 'ssl'){
            return [
                "host"=>trim($host,"//"),
                "port"=>$port,
                "flags"=>SWOOLE_SOCK_TCP | SWOOLE_SSL
            ];
        }
        return [
            "host"=>trim($host,"//"),
            "port"=>$port,
            "flags"=>SWOOLE_SOCK_TCP
        ];
    }

    protected static function forkWorkers()
    {
        foreach (self::$_workers as $worker) {
            if (self::$_status === self::STATUS_STARTING) {
                if (empty($worker->name)) {
                    $worker->name = $worker->getSocketName();
                }
                $worker_name_length = strlen($worker->name);
                if (self::$_maxWorkerNameLength < $worker_name_length) {
                    self::$_maxWorkerNameLength = $worker_name_length;
                }
            }
            $worker->count = $worker->count <= 0 ? 1 : $worker->count;
            while (count(self::$_pidMap[$worker->workerId]) < $worker->count) {
                static::forkOneWorker($worker);
            }
        }
    }

    /**
     *
     * @param \SwooleMan\Worker $worker
     * @throws \Exception
     */
    protected static function forkOneWorker($worker)
    {
        // Get available worker id.
        $id = self::getId($worker->workerId, 0);
        if ($id === false) {
            return;
        }
        $pid = pcntl_fork();
        // For master process.
        if ($pid > 0) {
            self::$_pidMap[$worker->workerId][$pid] = $pid;
            self::$_idMap[$worker->workerId][$id]   = $pid;
        } // For child processes.
        elseif (0 === $pid) {
            if (self::$_status === self::STATUS_STARTING) {
                self::resetStd();
            }
            self::$_pidMap  = array();
            self::$_workers = array($worker->workerId => $worker);
            self::setProcessTitle('SwooleMan: worker process  ' . $worker->name . ' ' . $worker->getSocketName());
            $worker->setUserAndGroup();
            $worker->id = $id;
            $worker->run();
            $err = new \Exception('event-loop exited');
            self::log($err);
            exit(250);
        } else {
            throw new \Exception("forkOneWorker fail");
        }
    }

    public function run()
    {
        //Update process state.
        self::$_status = self::STATUS_RUNNING;

        // Register shutdown function for checking errors.
        register_shutdown_function(array("\\SwooleMan\\Worker", 'checkErrors'));

        // Set autoload root path.
        Autoloader::setRootPath($this->_autoloadRootPath);
        if (!self::$globalEvent) {
            self::$globalEvent = new SwEvent();
        }
        if ($this->_socketName) {

        }
        $this->newServer();

        $this->swServer->start();
    }

    protected function formatSetting()
    {
    }

    /**
     * 创建swoole server
     */
    public function newServer()
    {
        $setting = $this->analyzeProtocol();
        //print_r($setting);
        $this->swServer = new \swoole_server($setting['host'],$setting['port'],SWOOLE_BASE,$setting['flags']);

        $this->swServer->set($this->_setting);
        $this->swServer->on("WorkerStart",array($this,"swOnWorkerStart"));
        $this->swServer->on("Connect",array($this,"swOnConnect"));
        $this->swServer->on("Receive",array($this,"swOnReceive"));
        $this->swServer->on("Close",array($this,"swOnClose"));
        $this->swServer->on("WorkerStop",array($this,"swOnWorkerStop"));
        $this->swServer->on("BufferFull",array($this,"swOnBufferFull"));
        $this->swServer->on("BufferEmpty",array($this,"swOnBufferEmpty"));
    }


    public function swOnWorkerStop(\swoole_server $server, $worker_id)
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

    /**
     * swoole worker 进程启动回调事件
     * @param \swoole_server $server
     * @param int $worker_id
     */
    public function swOnWorkerStart(\swoole_server $server, int $worker_id)
    {
        // Try to emit onWorkerStart callback.
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

    /**
     * 连接回调事件
     * @param \swoole_server $server
     * @param $fd
     * @param $from_id
     */
    public function swOnConnect(\swoole_server $server, $fd, $from_id)
    {
        $connection                         = new SwTcpConnection($server, $fd, $from_id);
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

    public function swOnReceive(\swoole_server $server, int $fd, int $reactor_id, string $data)
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

    /**
     * 收到udp包
     * @param \swoole_server $server
     * @param string $data
     * @param array $client_info
     * @return bool
     */
    public function swOnPacket(\swoole_server $server, string $data, array $client_info)
    {
        // UdpConnection.
        $connection           = new SwUdpConnection($server, $client_info);
        $connection->protocol = $this->protocol;
        if ($this->onMessage) {
            if ($this->protocol) {
                $parser      = $this->protocol;
                $data = $parser::decode($data, $connection);
                // Discard bad packets.
                if ($data === false)
                    return true;
            }
            ConnectionInterface::$statistics['total_request']++;
            try {
                call_user_func($this->onMessage, $connection, $data);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
        return true;
    }
    public function swOnBufferFull(\swoole_server $serv, $fd)
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

    public function swOnBufferEmpty(\swoole_server $serv, $fd)
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

    public function swOnClose(\swoole_server $server, $fd, $reactorId)
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


    protected static function checkSapiEnv()
    {
        // Only for cli.
        if (php_sapi_name() != "cli") {
            exit("only run in command line mode \n");
        }
    }

    public static function log($msg)
    {
        $msg = $msg . "\n";
        if (!self::$daemonize) {
            self::safeEcho($msg);
        }
        file_put_contents((string)self::$logFile, date('Y-m-d H:i:s') . ' ' . 'pid:'. posix_getpid() . ' ' . $msg, FILE_APPEND | LOCK_EX);
    }

    public static function safeEcho($msg)
    {
        if (!function_exists('posix_isatty') || posix_isatty(STDOUT)) {
            echo $msg;
        }
    }

    public function getSocketName()
    {
        return $this->_socketName ? lcfirst($this->_socketName) : 'none';
    }

    protected static function getId($worker_id, $pid)
    {
        return array_search($pid, self::$_idMap[$worker_id]);
    }

    protected static function initId()
    {
        foreach (self::$_workers as $worker_id => $worker) {
            $new_id_map = array();
            for($key = 0; $key < $worker->count; $key++) {
                $new_id_map[$key] = isset(self::$_idMap[$worker_id][$key]) ? self::$_idMap[$worker_id][$key] : 0;
            }
            self::$_idMap[$worker_id] = $new_id_map;
        }
    }

    public static function resetStd()
    {
        if (!self::$daemonize) {
            return;
        }
        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile, "a");
            $STDERR = fopen(self::$stdoutFile, "a");
        } else {
            throw new \Exception('can not open stdoutFile ' . self::$stdoutFile);
        }
    }

    protected static function setProcessTitle($title)
    {
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);
        }
    }

    public function setUserAndGroup()
    {
        // Get uid.
        $user_info = posix_getpwnam($this->user);
        if (!$user_info) {
            self::log("Warning: User {$this->user} not exsits");
            return;
        }
        $uid = $user_info['uid'];
        // Get gid.
        if ($this->group) {
            $group_info = posix_getgrnam($this->group);
            if (!$group_info) {
                self::log("Warning: Group {$this->group} not exsits");
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }

        // Set uid and gid.
        if ($uid != posix_getuid() || $gid != posix_getgid()) {
            if (!posix_setgid($gid) || !posix_initgroups($user_info['name'], $gid) || !posix_setuid($uid)) {
                self::log("Warning: change gid or uid fail.");
            }
        }
    }

    public static function checkErrors()
    {
        if (self::STATUS_SHUTDOWN != self::$_status) {
            $error_msg = "WORKER EXIT UNEXPECTED ";
            $errors    = error_get_last();
            if ($errors && ($errors['type'] === E_ERROR ||
                    $errors['type'] === E_PARSE ||
                    $errors['type'] === E_CORE_ERROR ||
                    $errors['type'] === E_COMPILE_ERROR ||
                    $errors['type'] === E_RECOVERABLE_ERROR)
            ) {
                $error_msg .= self::getErrorType($errors['type']) . " {$errors['message']} in {$errors['file']} on line {$errors['line']}";
            }
            self::log($error_msg);
        }
    }

    /**
     * Get error message by error code.
     *
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        switch ($type) {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
    }

    protected static function getAllWorkerPids()
    {
        $pid_array = array();
        foreach (self::$_pidMap as $worker_pid_array) {
            foreach ($worker_pid_array as $worker_pid) {
                $pid_array[$worker_pid] = $worker_pid;
            }
        }
        return $pid_array;
    }


    protected static function init()
    {
        // Start file.
        $backtrace        = debug_backtrace();
        self::$_startFile = $backtrace[count($backtrace) - 1]['file'];

        // Pid file.
        if (empty(self::$pidFile)) {
            self::$pidFile = __DIR__ . "/../" . str_replace('/', '_', self::$_startFile) . ".pid";
        }

        // Log file.
        if (empty(self::$logFile)) {
            self::$logFile = __DIR__ . '/../swooleman.log';
        }
        $log_file = (string)self::$logFile;
        if (!is_file($log_file)) {
            touch($log_file);
            chmod($log_file, 0622);
        }

        // State.
        self::$_status = self::STATUS_STARTING;

        // For statistics.
        self::$_globalStatistics['start_timestamp'] = time();
        self::$_statisticsFile                      = sys_get_temp_dir() . '/swooleman.status';

        // Process title.
        self::setProcessTitle('SwooleMan: master process  start_file=' . self::$_startFile);

        // Init data for worker id.
        self::initId();
    }

    protected static function parseCommand()
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
                if (is_file(self::$_statisticsFile)) {
                    @unlink(self::$_statisticsFile);
                }
                // Master process will send status signal to all child processes.
                posix_kill($master_pid, SIGUSR2);
                // Waiting amoment.
                usleep(500000);
                // Display statisitcs data from a disk file.
                @readfile(self::$_statisticsFile);
                exit(0);
            case 'restart':
            case 'stop':
                self::log("SwooleMan[$start_file] is stoping ...");
                // Send stop signal to master process.
                $master_pid && posix_kill($master_pid, SIGINT);
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

    protected static function daemonize()
    {
        if (!self::$daemonize) {
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \Exception('fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === posix_setsid()) {
            throw new \Exception("setsid fail");
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \Exception("fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

    protected static function initWorkers()
    {
        foreach (self::$_workers as $worker) {
            // Worker name.
            if (empty($worker->name)) {
                $worker->name = 'none';
            }

            // Get maximum length of worker name.
            $worker_name_length = strlen($worker->name);
            if (self::$_maxWorkerNameLength < $worker_name_length) {
                self::$_maxWorkerNameLength = $worker_name_length;
            }

            // Get maximum length of socket name.
            $socket_name_length = strlen($worker->getSocketName());
            if (self::$_maxSocketNameLength < $socket_name_length) {
                self::$_maxSocketNameLength = $socket_name_length;
            }

            // Get unix user of the worker process.
            if (empty($worker->user)) {
                $worker->user = self::getCurrentUser();
            } else {
                if (posix_getuid() !== 0 && $worker->user != self::getCurrentUser()) {
                    self::log('Warning: You must have the root privileges to change uid and gid.');
                }
            }

            // Get maximum length of unix user name.
            $user_name_length = strlen($worker->user);
            if (self::$_maxUserNameLength < $user_name_length) {
                self::$_maxUserNameLength = $user_name_length;
            }

            // Listen.
//            if (!$worker->reusePort) {
//                $worker->listen();
//            }
        }
    }

    protected static function getCurrentUser()
    {
        $user_info = posix_getpwuid(posix_getuid());
        return $user_info['name'];
    }

    protected static function installSignal()
    {
        // stop
        pcntl_signal(SIGINT, array('\SwooleMan\Worker', 'signalHandler'), false);
        // reload
        pcntl_signal(SIGUSR1, array('\SwooleMan\Worker', 'signalHandler'), false);
        // status
        pcntl_signal(SIGUSR2, array('\SwooleMan\Worker', 'signalHandler'), false);
        // ignore
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    protected static function saveMasterPid()
    {
        self::$_masterPid = posix_getpid();
        if (false === @file_put_contents(self::$pidFile, self::$_masterPid)) {
            throw new \Exception('can not save pid to ' . self::$pidFile);
        }
    }

    protected static function displayUI()
    {
        self::safeEcho("\033[1A\n\033[K-----------------------\033[47;30m SWOOMEMAN \033[0m-----------------------------\n\033[0m");
        self::safeEcho('Workerman version:'. Worker::VERSION. "          PHP version:". PHP_VERSION. "\n");
        self::safeEcho("------------------------\033[47;30m WORKERS \033[0m-------------------------------\n");
        self::safeEcho("\033[47;30muser\033[0m". str_pad('',
                self::$_maxUserNameLength + 2 - strlen('user')). "\033[47;30mworker\033[0m". str_pad('',
                self::$_maxWorkerNameLength + 2 - strlen('worker')). "\033[47;30mlisten\033[0m". str_pad('',
                self::$_maxSocketNameLength + 2 - strlen('listen')). "\033[47;30mprocesses\033[0m \033[47;30m". "status\033[0m\n");

        foreach (self::$_workers as $worker) {
            self::safeEcho(str_pad($worker->user, self::$_maxUserNameLength + 2). str_pad($worker->name,
                    self::$_maxWorkerNameLength + 2). str_pad($worker->getSocketName(),
                    self::$_maxSocketNameLength + 2). str_pad(' ' . $worker->count, 9). " \033[32;40m [OK] \033[0m\n");
        }
        self::safeEcho("----------------------------------------------------------------\n");
        if (self::$daemonize) {
            global $argv;
            $start_file = $argv[0];
            self::safeEcho("Input \"php $start_file stop\" to quit. Start success.\n\n");
        } else {
            self::safeEcho("Press Ctrl-C to quit. Start success.\n");
        }
    }

    protected static function monitorWorkers()
    {
        self::$_status = self::STATUS_RUNNING;
        while (1) {
            // Calls signal handlers for pending signals.
            pcntl_signal_dispatch();
            // Suspends execution of the current process until a child has exited, or until a signal is delivered
            $status = 0;
            $pid    = pcntl_wait($status, WUNTRACED);
            // Calls signal handlers for pending signals again.
            pcntl_signal_dispatch();
            // If a child has already exited.
            if ($pid > 0) {
                // Find out witch worker process exited.
                foreach (self::$_pidMap as $worker_id => $worker_pid_array) {
                    if (isset($worker_pid_array[$pid])) {
                        $worker = self::$_workers[$worker_id];
                        // Exit status.
                        if ($status !== 0) {
                            self::log("worker[" . $worker->name . ":$pid] exit with status $status");
                        }

                        // For Statistics.
                        if (!isset(self::$_globalStatistics['worker_exit_info'][$worker_id][$status])) {
                            self::$_globalStatistics['worker_exit_info'][$worker_id][$status] = 0;
                        }
                        self::$_globalStatistics['worker_exit_info'][$worker_id][$status]++;

                        // Clear process data.
                        unset(self::$_pidMap[$worker_id][$pid]);

                        // Mark id is available.
                        $id                            = self::getId($worker_id, $pid);
                        self::$_idMap[$worker_id][$id] = 0;

                        break;
                    }
                }
                // Is still running state then fork a new worker process.
                if (self::$_status !== self::STATUS_SHUTDOWN) {
                    self::forkWorkers();
                    // If reloading continue.
                    if (isset(self::$_pidsToRestart[$pid])) {
                        unset(self::$_pidsToRestart[$pid]);
                        self::reload();
                    }
                } else {
                    // If shutdown state and all child processes exited then master process exit.
                    if (!self::getAllWorkerPids()) {
                        self::exitAndClearAll();
                    }
                }
            } else {
                // If shutdown state and all child processes exited then master process exit.
                if (self::$_status === self::STATUS_SHUTDOWN && !self::getAllWorkerPids()) {
                    self::exitAndClearAll();
                }
            }
        }
    }

    protected static function exitAndClearAll()
    {
        foreach (self::$_workers as $worker) {
            $socket_name = $worker->getSocketName();
            if ($worker->transport === 'unix' && $socket_name) {
                list(, $address) = explode(':', $socket_name, 2);
                @unlink($address);
            }
        }
        @unlink(self::$pidFile);
        self::log("Swooleman[" . basename(self::$_startFile) . "] has been stopped");
        if (self::$onMasterStop) {
            call_user_func(self::$onMasterStop);
        }
        exit(0);
    }

    protected static function reload()
    {
        // For master process.
        if (self::$_masterPid === posix_getpid()) {
            // Set reloading state.
            if (self::$_status !== self::STATUS_RELOADING && self::$_status !== self::STATUS_SHUTDOWN) {
                self::log("SwooleMan[" . basename(self::$_startFile) . "] reloading");
                self::$_status = self::STATUS_RELOADING;
                // Try to emit onMasterReload callback.
                if (self::$onMasterReload) {
                    try {
                        call_user_func(self::$onMasterReload);
                    } catch (\Exception $e) {
                        self::log($e);
                        exit(250);
                    } catch (\Error $e) {
                        self::log($e);
                        exit(250);
                    }
                    self::initId();
                }
            }

            // Send reload signal to all child processes.
            $reloadable_pid_array = array();
            foreach (self::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = self::$_workers[$worker_id];
                if ($worker->reloadable) {
                    foreach ($worker_pid_array as $pid) {
                        $reloadable_pid_array[$pid] = $pid;
                    }
                } else {
                    foreach ($worker_pid_array as $pid) {
                        // Send reload signal to a worker process which reloadable is false.
                        posix_kill($pid, SIGUSR1);
                    }
                }
            }

            // Get all pids that are waiting reload.
            self::$_pidsToRestart = array_intersect(self::$_pidsToRestart, $reloadable_pid_array);

            // Reload complete.
            if (empty(self::$_pidsToRestart)) {
                if (self::$_status !== self::STATUS_SHUTDOWN) {
                    self::$_status = self::STATUS_RUNNING;
                }
                return;
            }
            // Continue reload.
            $one_worker_pid = current(self::$_pidsToRestart);
            // Send reload signal to a worker process.
            posix_kill($one_worker_pid, SIGUSR1);
            // If the process does not exit after self::KILL_WORKER_TIMER_TIME seconds try to kill it.
            Timer::add(self::KILL_WORKER_TIMER_TIME, 'posix_kill', array($one_worker_pid, SIGKILL), false);
        } // For child processes.
        else {
            $worker = current(self::$_workers);
            // Try to emit onWorkerReload callback.
            if ($worker->onWorkerReload) {
                try {
                    call_user_func($worker->onWorkerReload, $worker);
                } catch (\Exception $e) {
                    self::log($e);
                    exit(250);
                } catch (\Error $e) {
                    self::log($e);
                    exit(250);
                }
            }

            if ($worker->reloadable) {
                self::stopAll();
            }
        }
    }

    public static function stopAll()
    {
        self::$_status = self::STATUS_SHUTDOWN;
        // For master process.
        if (self::$_masterPid === posix_getpid()) {
            self::log("SwooleMan[" . basename(self::$_startFile) . "] Stopping ...");
            $worker_pid_array = self::getAllWorkerPids();
            // Send stop signal to all child processes.
            foreach ($worker_pid_array as $worker_pid) {
                posix_kill($worker_pid, SIGINT);
                Timer::add(self::KILL_WORKER_TIMER_TIME, 'posix_kill', array($worker_pid, SIGKILL), false);
            }
            // Remove statistics file.
            if (is_file(self::$_statisticsFile)) {
                @unlink(self::$_statisticsFile);
            }
        } // For child processes.
        else {
            // Execute exit.
            foreach (self::$_workers as $worker) {
                $worker->swServer->stop();
            }
        }
    }

    public static function signalHandler($signal)
    {
        switch ($signal) {
            // Stop.
            case SIGINT:
                self::stopAll();
                break;
            // Reload.
            case SIGUSR1:
                self::$_pidsToRestart = self::getAllWorkerPids();
                self::reload();
                break;
            // Show status.
            case SIGUSR2:
                self::writeStatisticsToStatusFile();
                break;
        }
    }

    /**
     * Write statistics data to disk.
     *
     * @return void
     */
    protected static function writeStatisticsToStatusFile()
    {
        // For master process.
        if (self::$_masterPid === posix_getpid()) {
            $loadavg = function_exists('sys_getloadavg') ? array_map('round', sys_getloadavg(), array(2)) : array(
                '-',
                '-',
                '-'
            );
            file_put_contents(self::$_statisticsFile,
                "---------------------------------------GLOBAL STATUS--------------------------------------------\n");
            file_put_contents(self::$_statisticsFile,
                'Workerman version:' . Worker::VERSION . "          PHP version:" . PHP_VERSION . "\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile, 'start time:' . date('Y-m-d H:i:s',
                    self::$_globalStatistics['start_timestamp']) . '   run ' . floor((time() - self::$_globalStatistics['start_timestamp']) / (24 * 60 * 60)) . ' days ' . floor(((time() - self::$_globalStatistics['start_timestamp']) % (24 * 60 * 60)) / (60 * 60)) . " hours   \n",
                FILE_APPEND);
            $load_str = 'load average: ' . implode(", ", $loadavg);
            file_put_contents(self::$_statisticsFile,
                str_pad($load_str, 33) . 'event-loop:' . self::getEventLoopName() . "\n", FILE_APPEND);
            file_put_contents(self::$_statisticsFile,
                count(self::$_pidMap) . ' workers       ' . count(self::getAllWorkerPids()) . " processes\n",
                FILE_APPEND);
            file_put_contents(self::$_statisticsFile,
                str_pad('worker_name', self::$_maxWorkerNameLength) . " exit_status     exit_count\n", FILE_APPEND);
            foreach (self::$_pidMap as $worker_id => $worker_pid_array) {
                $worker = self::$_workers[$worker_id];
                if (isset(self::$_globalStatistics['worker_exit_info'][$worker_id])) {
                    foreach (self::$_globalStatistics['worker_exit_info'][$worker_id] as $worker_exit_status => $worker_exit_count) {
                        file_put_contents(self::$_statisticsFile,
                            str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad($worker_exit_status,
                                16) . " $worker_exit_count\n", FILE_APPEND);
                    }
                } else {
                    file_put_contents(self::$_statisticsFile,
                        str_pad($worker->name, self::$_maxWorkerNameLength) . " " . str_pad(0, 16) . " 0\n",
                        FILE_APPEND);
                }
            }
            file_put_contents(self::$_statisticsFile,
                "---------------------------------------PROCESS STATUS-------------------------------------------\n",
                FILE_APPEND);
            file_put_contents(self::$_statisticsFile,
                "pid\tmemory  " . str_pad('listening', self::$_maxSocketNameLength) . " " . str_pad('worker_name',
                    self::$_maxWorkerNameLength) . " connections " . str_pad('total_request',
                    13) . " " . str_pad('send_fail', 9) . " " . str_pad('throw_exception', 15) . "\n", FILE_APPEND);

            chmod(self::$_statisticsFile, 0722);

            foreach (self::getAllWorkerPids() as $worker_pid) {
                posix_kill($worker_pid, SIGUSR2);
            }
            return;
        }
        // For child processes.
        /** @var Worker $worker */
        $worker           = current(self::$_workers);
        $worker_status_str = posix_getpid() . "\t" . str_pad(round(memory_get_usage(true) / (1024 * 1024), 2) . "M",
                7) . " " . str_pad($worker->getSocketName(),
                self::$_maxSocketNameLength) . " " . str_pad(($worker->name === $worker->getSocketName() ? 'none' : $worker->name),
                self::$_maxWorkerNameLength) . " ";
        $worker_status_str .= str_pad(ConnectionInterface::$statistics['connection_count'],
                11) . " " . str_pad(ConnectionInterface::$statistics['total_request'],
                14) . " " . str_pad(ConnectionInterface::$statistics['send_fail'],
                9) . " " . str_pad(ConnectionInterface::$statistics['throw_exception'], 15) . "\n";
        file_put_contents(self::$_statisticsFile, $worker_status_str, FILE_APPEND);
    }
}

