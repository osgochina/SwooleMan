<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/7/15
 * Time: 22:59
 */

namespace SwooleMan\Connection;
use SwooleMan\Worker;
use SwooleMan\Lib\Timer;

class AsyncTcpConnection extends ConnectionInterface
{
    /**
     * 连接的id。这是一个自增的整数。
     * @var int
     */
    public $id;

    /**
     * 当前连接的协议类
     * @var string
     */
    public $protocol;

    /**
     * 此属性为只读属性，即当前connection对象所属的worker实例
     * @var \SwooleMan\Worker
     */
    public $worker = null;


    /**
     * 作用与Worker::$onBufferFull回调相同
     *
     * @var callback
     */
    public $onBufferFull = null;

    /**
     * 作用与Worker::$onBufferDrain回调相同
     *
     * @var callback
     */
    public $onBufferDrain = null;

    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls'   => 'tls',
        'ws'    => 'ws',
    );

    protected $_socketName = "";
    /**
     * 套接字上下文选项
     * @var array
     */
    protected $_context = [];

    /**
     * 配置信息
     * @var array
     */
    protected $_setting = [];
    public $transport = 'tcp';
    /**
     * worker 监听的ip
     * @var string
     */
    protected $_remoteHost = '';

    /**
     * worker监听的端口
     * @var int
     */
    protected $_remotePort = 0;

    protected $_reconnectTimer;

    protected $swClient;

    public $onConnect = null;

    /**
     * @var \SplQueue
     */
    protected $_tmp_data;


    public function __construct($remote_address, $context_option = null)
    {
        self::$statistics['connection_count']++;
        $this->_socketName = $remote_address;
        $this->_context = $context_option;
        $this->_paramSocketName($this->_socketName);
        $this->_createSetting();
        $this->_tmp_data = new \SplQueue();
        $this->_newClient();
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
            $this->_remoteHost = "/".trim($address,"/");
            $this->_remotePort = 0;
        }else{
            list($this->_remoteHost,$this->_remotePort) = explode(":",trim($address,"//"),2);
        }
        return true;
    }

    protected function _createSetting()
    {

    }

    public function _onConnect($client)
    {
        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                call_user_func($this->onConnect, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        if (!$this->_tmp_data->isEmpty()){
            while (!$this->_tmp_data->isEmpty()){
                $data = $this->_tmp_data->pop();
                $this->send($data['data'],$data['raw']);
            }
        }
    }
    public function _onReceive($client,$data)
    {
        if ($this->protocol) {
            $parser = $this->protocol;
            $data = $parser::decode($data, $this);
        }
        ConnectionInterface::$statistics['total_request']++;
        // Try to emit onConnect callback.
        if ($this->onMessage) {
            try {
                call_user_func($this->onMessage, $this,$data);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }

    public function _OnError($client)
    {
        $code = $client->errCode;
        $msg = socket_strerror($client->errCode);
        if ($client->errCode == 61){
            $code = SWOOLEMAN_CONNECT_FAIL;
            $msg = 'connect ' . "{$this->_remoteHost}:{$this->_remotePort}" . ' fail ';
        }
        if ($this->onError) {
            try {
                call_user_func($this->onError, $this, $code, $msg);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }

    public function _OnClose( $client)
    {
        if ($this->onClose) {
            try {
                call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }

    protected function _newTcpClient()
    {
        $swClient = new \swoole_client(SWOOLE_SOCK_TCP , SWOOLE_SOCK_ASYNC);
        $swClient->on("Receive",array($this,'_OnReceive'));
        $swClient->on("Connect",array($this,'_OnConnect'));
        $swClient->on("Error",array($this,'_OnError'));
        $swClient->on("Close",array($this,'_OnClose'));
        if (!empty($this->setting)){
            $swClient->set($this->setting);
        }
        return $swClient;
    }

    public function _OnMessage($client,$frame)
    {
        ConnectionInterface::$statistics['total_request']++;
        // Try to emit onConnect callback.
        if ($this->onMessage) {
            try {
                call_user_func($this->onMessage, $this,$frame->data);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }


    protected function _newUdpClient()
    {
        $swClient = new \swoole_client(SWOOLE_SOCK_UDP , SWOOLE_SOCK_ASYNC);
        $swClient->on("Receive",array($this,'_OnReceive'));
        $swClient->on("Connect",array($this,'_OnConnect'));
        $swClient->on("Error",array($this,'_OnError'));
        $swClient->on("Close",array($this,'_OnClose'));
        if (!empty($this->setting)){
            $swClient->set($this->setting);
        }
        return $swClient;
    }

    protected function _newUnixClient()
    {
        $swClient = new \swoole_client(SWOOLE_SOCK_UNIX_DGRAM , SWOOLE_SOCK_ASYNC);
        $swClient->on("Receive",array($this,'_OnReceive'));
        $swClient->on("Connect",array($this,'_OnConnect'));
        $swClient->on("Error",array($this,'_OnError'));
        $swClient->on("Close",array($this,'_OnClose'));
        if (!empty($this->setting)){
            $swClient->set($this->setting);
        }
        return $swClient;
    }

    protected function _newWsClient()
    {
        $swClient = new \swoole_http_client($this->_remoteHost , $this->_remotePort);
        $swClient->on("Message",array($this,'_OnMessage'));
        if (!empty($this->setting)){
            $swClient->set($this->setting);
        }
        return $swClient;
    }


    /**
     * 创建服务
     */
    protected function _newClient()
    {
        switch (strtolower($this->transport)){
            case "tcp":
                $this->swClient = $this->_newTcpClient();
                break;
            case "udp":
                $this->swClient = $this->_newUdpClient();
                break;
            case "unix":
                $this->swClient = $this->_newUnixClient();
                break;
            case "ws":
                $this->swClient = $this->_newWsClient();
                break;
        }
    }

    /**
     * 连接
     * @return bool|mixed
     */
    public function connect()
    {
        //websocket协议
        if ($this->transport == "ws"){
            return $this->swClient->upgrade('/', array($this,'_onConnect'));
        }

        if ($this->swClient->isConnected()){
            return true;
        }
        return $this->swClient->connect($this->_remoteHost,$this->_remotePort);
    }

    /**
     * 重连
     * @param int $after
     */
    public function reConnect($after = 0) {
        if ($this->_reconnectTimer) {
            Timer::del($this->_reconnectTimer);
        }
        if ($after > 0) {
            $this->_reconnectTimer = Timer::add($after, array($this, 'connect'), null, false);
            return;
        }
        $this->connect();
    }

    /**
     * Sends data on the connection.
     *
     * @param string $send_buffer
     * @return boolean
     */
    public function send($send_buffer,$raw = false)
    {
        if (!$this->swClient->isConnected()){
            $this->_tmp_data->push(["data"=>$send_buffer,'raw'=>$raw]);
            return false;
        }

        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }
        if ($this->transport == 'ws'){
            $len = $this->swClient->push($send_buffer);
        }else{
            $len = $this->swClient->send($send_buffer);
        }
        if (!$len){
            //$code = $this->swServer->getLastError();
            self::$statistics['send_fail']++;
            if ($this->onError) {
                try {
                    call_user_func($this->onError, $this, SWOOLEMAN_SEND_FAIL, 'client closed');
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            return false;
        }
        return $len;
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp()
    {
        return $this->_remoteHost;
    }

    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort()
    {
        return $this->_remotePort;
    }

    /**
     * Close connection.
     *
     * @param $data
     * @return void
     */
    public function close($data = null)
    {
        $this->send($data);
        $this->swClient->close();
    }

    public function destroy()
    {

    }


    public function __destruct()
    {
        self::$statistics['connection_count']--;
    }
}