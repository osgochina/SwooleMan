<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/21
 * Time: 15:06
 */

namespace SwooleMan\Connection;

use Swoole\Exception;
use SwooleMan\Lib\Timer;
use SwooleMan\Worker;

class AsyncTcpConnection    extends ConnectionInterface
{
    /**
     * Status initial.
     *
     * @var int
     */
    const STATUS_INITIAL = 0;

    /**
     * Status connecting.
     *
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * Status connection established.
     *
     * @var int
     */
    const STATUS_ESTABLISH = 2;

    /**
     * Status closing.
     *
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * Status closed.
     *
     * @var int
     */
    const STATUS_CLOSED = 8;

    public $protocol = '';

    public $transport = 'tcp';
    public $onConnect = '';
    protected $_status = self::STATUS_INITIAL;

    protected $_connectStartTime = 0;
    protected $_remoteAddress;
    protected $_remoteHost;
    protected $_remotePort;
    protected $_remoteURI;
    protected $_reconnectTimer;


    protected static $_idRecorder = 1;

    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls'   => 'tls'
    );

    /**
     * @var \Swoole\Client
     */
    protected $swClient;
    protected $setting = [];

    /**
     * @var \SplQueue
     */
    protected $_tmp_data;

    public function __construct(string $remote_address, $context_option = null)
    {
        $address_info = parse_url($remote_address);
        if (!$address_info) {
            list($scheme, $this->_remoteAddress) = explode(':', $remote_address, 2);
            if (!$this->_remoteAddress) {
                echo new \Exception('bad remote_address');
            }
        } else {
            if (!isset($address_info['port'])) {
                $address_info['port'] = 80;
            }
            if (!isset($address_info['path'])) {
                $address_info['path'] = '/';
            }
            if (!isset($address_info['query'])) {
                $address_info['query'] = '';
            } else {
                $address_info['query'] = '?' . $address_info['query'];
            }
            $this->_remoteAddress = "{$address_info['host']}:{$address_info['port']}";
            $this->_remoteHost    = $address_info['host'];
            $this->_remotePort    = $address_info['port'];
            $this->_remoteURI     = "{$address_info['path']}{$address_info['query']}";
            $scheme               = isset($address_info['scheme']) ? $address_info['scheme'] : 'tcp';
        }

        $this->id             = self::$_idRecorder++;
        // Check application layer protocol class.
        if (!isset(self::$_builtinTransports[$scheme])) {
            $scheme         = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\SwooleMan\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new \Exception("class \\Protocols\\$scheme not exist");
                }
            }
        } else {
            $this->transport = self::$_builtinTransports[$scheme];
        }

        // For statistics.
        self::$statistics['connection_count']++;
        $this->newSwClient();
        $this->_tmp_data = new \SplQueue();
    }

    protected function formatSetting($context_option)
    {
        if (isset($context_option['socket'])){
            $socket = $context_option['socket'];
            if (isset($socket['bindto'])){
                $bindto = 'bindto';
                list($ip,$port) = explode(":",$bindto,2);
                $this->setting['bind_address'] = $ip;
                $this->setting['bind_port'] = $port;
            }
        }
        if (isset($context_option['ssl'])){
            $ssl = $context_option['ssl'];
            if (isset($ssl['local_cert'])){
                $this->setting['ssl_cert_file'] = $ssl['local_cert'];
            }
        }
    }

    protected function newSwClient()
    {
        switch ($this->transport){
            case 'tcp':
                $this->swClient = new \swoole_client(SWOOLE_SOCK_TCP , SWOOLE_SOCK_ASYNC);
                break;
//            case "ssl":
//                $this->swClient = new swoole_client(SWOOLE_SOCK_TCP , SWOOLE_ASYNC | SWOOLE_SSL);
//                break;
//            case "sslv2":
//                $this->swClient = new swoole_client(SWOOLE_SOCK_TCP , SWOOLE_ASYNC | SWOOLE_SSLv23_CLIENT_METHOD);
//                break;
//            case "sslv3":
//                $this->swClient = new swoole_client(SWOOLE_SOCK_TCP , SWOOLE_ASYNC | SWOOLE_SSLv3_CLIENT_METHOD);
//                break;
//            case "tls":
//                $this->swClient = new swoole_client(SWOOLE_SOCK_TCP , SWOOLE_ASYNC | SWOOLE_SSL);
//                break;
//            case "unix":
//                $this->swClient = new swoole_client(SWOOLE_SOCK_TCP | SWOOLE_ASYNC);
//                break;
//            case "udp":
//                $this->swClient = new swoole_client(SWOOLE_SOCK_TCP | SWOOLE_ASYNC | SWOOLE_SSL);
//                break;
        }
        if (!empty($this->setting)){
            $this->swClient->set($this->setting);
        }
        $this->swClient->on("Connect",array($this,'swOnConnect'));
        $this->swClient->on("Error",array($this,'swOnError'));
        $this->swClient->on("Receive",array($this,'swOnReceive'));
        $this->swClient->on("Close",array($this,'swOnClose'));
        $this->swClient->on("Close",array($this,'swOnClose'));
    }


    public function swOnConnect(\swoole_client $client)
    {
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
        // Try to emit protocol::onConnect
        if (method_exists($this->protocol, 'onConnect')) {
            try {
                call_user_func(array($this->protocol, 'onConnect'), $this);
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

    public function swOnError(\swoole_client $client)
    {
        $code = $client->errCode;
        $msg = socket_strerror($client->errCode);
        if ($client->errCode == 61){
            $code = SWOOLEMAN_CONNECT_FAIL;
            $msg = 'connect ' . $this->_remoteAddress . ' fail after ' . round(microtime(true) -
                    $this->_connectStartTime, 4) . ' seconds';
            $this->_status = self::STATUS_CLOSING;
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
        if ($this->_status === self::STATUS_CLOSING) {
            $this->destroy();
        }
        if ($this->_status === self::STATUS_CLOSED) {
            $this->onConnect = null;
        }
    }

    public function swOnReceive(\swoole_client $client,$data)
    {
        if ($this->protocol) {
            $parser = $this->protocol;
            $data = $parser::decode($data, $this);
        }
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

    public function swOnClose(\swoole_client $client)
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

    public function connect()
    {
        if ($this->swClient->isConnected()){
            return true;
        }
        $this->_connectStartTime = microtime(true);
        return $this->swClient->connect($this->_remoteHost,$this->_remotePort);
    }

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
     * @param bool $raw
     * @return boolean
     */
    public function send($send_buffer,$raw = false)
    {
        if (!$this->swClient->isConnected()){
            $this->_tmp_data->push(["data"=>$send_buffer,'raw'=>$raw]);
            return false;
        }
        // Try to call protocol::encode($send_buffer) before sending.
        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }
        $len = $this->swClient->send($send_buffer);
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

    public function getRemoteURI()
    {
        return $this->_remoteURI;
    }

    /**
     * Close connection.
     *
     * @param $data
     * @return void
     */
    public function close($data = null)
    {
        $this->swClient->close();
    }

    /**
     * Destroy connection.
     *
     * @return void
     */
    public function destroy()
    {
        // Avoid repeated calls.
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }
        $this->_status = self::STATUS_CLOSED;
        // Try to emit onClose callback.
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
        // Try to emit protocol::onClose
        if (method_exists($this->protocol, 'onClose')) {
            try {
                call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        if ($this->_status === self::STATUS_CLOSED) {
            // Cleaning up the callback to avoid memory leaks.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
        }
    }

    public function __destruct()
    {
        self::$statistics['connection_count']--;
    }
}