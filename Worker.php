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

class Worker{


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


    protected static $_status = self::STATUS_STARTING;

    /**
     * Maximum length of the worker names.
     *
     * @var int
     */
    protected static $_maxWorkerNameLength = 12;

    protected static $_workers = array();
    protected static $_pidMap = array();
    protected static $_idMap = array();

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
        self::forkWorkers();
    }

    public static function stopAll()
    {

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
//        $pid = pcntl_fork();
//        // For master process.
//        if ($pid > 0) {
//            self::$_pidMap[$worker->workerId][$pid] = $pid;
//            self::$_idMap[$worker->workerId][$id]   = $pid;
//        } // For child processes.
//        elseif (0 === $pid) {
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
//        } else {
//            throw new \Exception("forkOneWorker fail");
//        }
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

    protected static function reload()
    {
    }

    protected static function writeStatisticsToStatusFile()
    {
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
}

