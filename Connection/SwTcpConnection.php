<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/20
 * Time: 17:00
 */

namespace SwooleMan\Connection;


class SwTcpConnection extends ConnectionInterface
{

    public $id = 0;

    public $protocol = '';

    public $worker = null;

    public $onMessage = null;

    public $onClose = null;

    public $onError = null;

    public $onBufferFull = null;

    public $onBufferDrain = null;


    public $maxSendBufferSize = 1048576;

    public static $defaultMaxSendBufferSize = 1048576;

    public static $maxPackageSize = 10485760;

    /**
     * Sends data on the connection.
     *
     * @param string $send_buffer
     * @return void|boolean
     */
    public function send($send_buffer)
    {
        // TODO: Implement send() method.
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp()
    {
        // TODO: Implement getRemoteIp() method.
    }

    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort()
    {
        // TODO: Implement getRemotePort() method.
    }

    /**
     * Close connection.
     *
     * @param $data
     * @return void
     */
    public function close($data = null)
    {
        // TODO: Implement close() method.
    }

    public function destroy()
    {

    }

    public function pauseRecv()
    {

    }

    public function resumeRecv()
    {

    }

    public function pipe()
    {

    }
}