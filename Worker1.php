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

use SwooleMan\Connection\SwTcpConnection;
use SwooleMan\Connection\SwUdpConnection;
use SwooleMan\Connection\ConnectionInterface;
use SwooleMan\Events\SwEvent;
use Exception;

class Worker1 extends Base
{

    /**
     * @var \Swoole\Server
     */
    protected $_swServer;

    /**
     * @var array
     */
    protected $_setting = [];

    /**
     * Worker constructor.
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name = '', $context_option = array())
    {
        parent::__construct($socket_name,$context_option);
        $this->formatSetting($context_option);
    }

    /**
     * 格式化配置信息
     * @param $context_option
     */
    protected function formatSetting($context_option)
    {
        // Context for socket.
        if (isset($context_option['socket']['backlog'])) {
            $this->_setting['backlog'] = $context_option['socket']['backlog'];
        }
    }

    /**
     *
     */
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
            $event_loop_class = self::getEventLoopName();
            self::$globalEvent = new $event_loop_class;
        }

        $this->newServer();
        if ($this->_swServer){
            $this->_swServer->start();
        }else{
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
            swoole_event_wait();
        }

    }

    /**
     * 解析协议
     * @return array|bool
     * @throws \Exception
     */
    protected function analyzeProtocol()
    {
        if (!$this->_socketName){
            return false;
        }
        list($scheme, $address) = explode(':', $this->_socketName, 2);
        $scheme = strtolower($scheme);
        if (!isset(self::$_builtinTransports[$scheme])) {
            switch ($scheme){
                case "text":
                    $this->_setting['open_eof_check'] = true;
                    $this->_setting['package_eof'] = "\n";
                    $this->transport = 'tcp';
                    break;
                case "frame":
                    $this->_setting['open_length_check'] = true;
                    $this->_setting['package_length_type'] = "N";
                    $this->_setting['package_max_length'] = 10485760;
                    $this->transport = 'tcp';
                    break;
                case "http":
                    $this->transport = 'tcp';
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

    /**
     * 创建swoole server
     */
    public function newServer()
    {
        $setting = $this->analyzeProtocol();
        if ($setting == false || $this->_swServer){
            return;
        }
        $this->_setting['worker_num'] = $this->count;
        if ($this->transport == 'websocket'){
            $this->_swServer = new \swoole_websocket_server($setting['host'],$setting['port'],SWOOLE_BASE,$setting['flags']);
            $this->_swServer->set($this->_setting);
            $this->_swServer->on("Message",array($this,"swOnMessage"));
            $this->_swServer->on("Open",array($this,"swOnOpen"));
        }elseif ($this->transport == 'http'){
            $this->_swServer = new \swoole_http_server($setting['host'],$setting['port'],SWOOLE_BASE,$setting['flags']);
            $this->_swServer->set($this->_setting);
            $this->_swServer->on("Request",array($this,"swOnRequest"));
            $this->_swServer->on("Connect",array($this,"swOnConnect"));
            if ($this->count > 0){
                $this->_swServer->on("Start",array($this,"swOnStart"));
                $this->_swServer->on("Shutdown",array($this,"swOnShutdown"));
            }
        }else{
            $this->_swServer = new \swoole_server($setting['host'],$setting['port'],SWOOLE_BASE,$setting['flags']);
            $this->_swServer->set($this->_setting);
            $this->_swServer->on("Receive",array($this,"swOnReceive"));
            $this->_swServer->on("Connect",array($this,"swOnConnect"));
        }
        $this->_swServer->on("WorkerStart",array($this,"swOnWorkerStart"));
        $this->_swServer->on("WorkerStop",array($this,"swOnWorkerStop"));
        $this->_swServer->on("BufferFull",array($this,"swOnBufferFull"));
        $this->_swServer->on("BufferEmpty",array($this,"swOnBufferEmpty"));
        $this->_swServer->on("Close",array($this,"swOnClose"));
    }

    public function swOnStart($serv)
    {
        //var_dump("swOnStart");
    }
    public function swOnShutdown($serv)
    {
        //var_dump("swOnShutdown");
    }


    /**
     * swoole worker 进程启动回调事件
     * @param \swoole_server $server
     * @param int $worker_id
     */
    public function swOnWorkerStart(\swoole_server $server, $worker_id)
    {
        $this->id = $worker_id;
        $pid = posix_getpid();
        self::$_pidMap[$this->workerId][$pid] = $pid;
        self::$_idMap[$this->workerId][$worker_id]   = $pid;
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
     * swoole server worker stop
     * @param \swoole_server $server
     * @param $worker_id
     */
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

    /**
     * websocket 连接完成
     * @param \swoole_websocket_server $server
     * @param \swoole_http_request $req
     */
    public function swOnOpen(\swoole_websocket_server $server, \swoole_http_request $req)
    {
        $fd = $req->fd;
        $connection                         = new SwTcpConnection($server, $fd, 0);
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

    /**
     * tcp server 收到消息回调事件
     * @param \swoole_server $server
     * @param int $fd
     * @param int $reactor_id
     * @param string $data
     */
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
     * websocket server 收到消息回调事件
     * @param \swoole_server $server
     * @param \swoole_websocket_frame $frame
     */
    public function swOnMessage(\swoole_server $server, \swoole_websocket_frame $frame)
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
     * http server  请求事件
     * @param \swoole_http_request $request
     * @param \swoole_http_response $response
     */
    public function swOnRequest(\swoole_http_request $request, \swoole_http_response $response)
    {
        ConnectionInterface::$statistics['total_request']++;
        // Try to emit onConnect callback.
        if ($this->onMessage) {
            try {
                call_user_func($this->onMessage, $request,$response);
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

    /**
     * 发送缓冲区已满事件
     * @param \swoole_server $serv
     * @param $fd
     */
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

    /**
     * 发送缓冲器已空事件
     * @param \swoole_server $serv
     * @param $fd
     */
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

    /**
     * 连接关闭
     * @param \swoole_server $server
     * @param $fd
     * @param $reactorId
     */
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

}

