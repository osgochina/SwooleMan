<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/7/15
 * Time: 22:59
 */

namespace SwooleMan\Connection;
use SwooleMan\Worker;

class TcpConnection extends ConnectionInterface
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

    public $transport;

    /**
     * 此属性为只读属性，即当前connection对象所属的worker实例
     * @var \SwooleMan\Worker
     */
    public $worker = null;

    /**
     * @var \Swoole\Server
     */
    protected $swServer;

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



    public function __construct($swServer,$fd)
    {
        self::$statistics['connection_count']++;
        $this->id      = $fd;
        $this->swServer = $swServer;
    }

    /**
     * Sends data on the connection.
     *
     * @param string $send_buffer
     * @return boolean
     */
    public function send($send_buffer,$raw = false)
    {
        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }
        if ($this->transport == "websocket"){
            $ret = $this->swServer->push($this->id,$send_buffer);
        }else{
            $ret = $this->swServer->send($this->id,$send_buffer);
        }
        if ($ret === false){
            self::$statistics['send_fail']++;
            if ($this->onError) {
                try {
                    call_user_func($this->onError, $this, $this->swServer->getLastError(), $this->swServer->getLastError());
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        }
        return $ret;
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp()
    {
        $info = $this->swServer->connection_info($this->id);
        return $info['remote_ip'];
    }

    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort()
    {
        $info = $this->swServer->connection_info($this->id);
        return $info['remote_port'];
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
        $this->swServer->close($this->id);
    }

    public function destroy()
    {

    }

    public function pauseRecv()
    {
        $this->swServer->pause($this->id);
    }

    public function resumeRecv()
    {
        $this->swServer->resume($this->id);
    }

    public function pipe(TcpConnection $dest)
    {
        $source              = $this;
        $this->onMessage     = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose       = function ($source) use ($dest) {
            $dest->destroy();
        };
        $dest->onBufferFull  = function ($dest) use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function ($dest) use ($source) {
            $source->resumeRecv();
        };
    }

    public function __destruct()
    {
        self::$statistics['connection_count']--;
    }
}