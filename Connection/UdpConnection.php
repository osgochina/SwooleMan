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
namespace SwooleMan\Connection;

/**
 * UdpConnection.
 */
class UdpConnection extends ConnectionInterface
{
    /**
     * Application layer protocol.
     * The format is like this SwooleMan\\Protocols\\Http.
     *
     * @var \SwooleMan\Protocols\ProtocolInterface
     */
    public $protocol = null;

    /**
     * Udp socket.
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * Remote address.
     *
     * @var array
     */
    protected $_remoteAddress = [];

    public $swServer;

    /**
     * SwUdpConnection constructor.
     * @param \swoole_server $server
     * @param $client_info
     */
    public function __construct(\swoole_server $server, $client_info)
    {
        $this->swServer        = $server;
        $this->_remoteAddress = $client_info;
    }

    /**
     * Sends data on the connection.
     *
     * @param string $send_buffer
     * @param bool   $raw
     * @return boolean
     */
    public function send($send_buffer, $raw = false)
    {
        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }
        return $this->swServer->sendto($this->getRemoteIp(),$this->getRemotePort(),$send_buffer);
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp()
    {
        return $this->_remoteAddress["address"];
    }

    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort()
    {
        return $this->_remoteAddress["port"];
    }

    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool  $raw
     * @return bool
     */
    public function close($data = null, $raw = false)
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        return true;
    }
}
