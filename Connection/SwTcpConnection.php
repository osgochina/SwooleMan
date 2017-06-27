<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/20
 * Time: 17:00
 */

namespace SwooleMan\Connection;

use SwooleMan\Worker;

class SwTcpConnection extends ConnectionInterface
{

    public $id = 0;

    public $protocol = '';
    public $transport = 'tcp';

    public $worker = null;

    public $onMessage = null;

    public $onClose = null;

    public $onError = null;

    public $onBufferFull = null;

    public $onBufferDrain = null;


    public $maxSendBufferSize = 1048576;

    public static $defaultMaxSendBufferSize = 1048576;

    public static $maxPackageSize = 10485760;

    public $swServer;

    public function __construct(\swoole_server $server, $fd, $from_id)
    {
        self::$statistics['connection_count']++;
        $this->id = $fd;
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->swServer = $server;
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
        // Try to call protocol::encode($send_buffer) before sending.
        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }
        if ($this->transport == "websocket"){
            $len = $this->swServer->push($this->id,$send_buffer);
        }else{
            $len = $this->swServer->send($this->id,$send_buffer);
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

    /**
     * @param $dest SwTcpConnection
     */
    public function pipe($dest)
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