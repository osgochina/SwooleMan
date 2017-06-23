<?php
/**
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 2017/6/21
 * Time: 14:19
 */

namespace SwooleMan\Events;


class SwEvent implements EventInterface
{
    /**
     * Add event listener to event loop.
     *
     * @param mixed $fd
     * @param int $flag
     * @param callable $func
     * @param mixed $args
     * @return bool
     */
    public function add($fd, $flag, $func, $args = null)
    {
        switch ($flag) {
            case self::EV_SIGNAL:
                return \swoole_process::signal($fd, $func);
            case self::EV_TIMER:
                return  swoole_timer_tick($fd,function ()use ($func,$args){
                    return call_user_func_array($func,$args);
                });
            case self::EV_TIMER_ONCE:
                return swoole_timer_after($fd,function ()use ($func,$args){
                    return call_user_func_array($func,$args);
                });
            case self::EV_READ:
                return swoole_event_add($fd,$func,null,SWOOLE_EVENT_READ);
            case self::EV_WRITE:
                return swoole_event_add($fd,null,$func,SWOOLE_EVENT_WRITE);
        }
        return false;
    }

    /**
     * Remove event listener from event loop.
     *
     * @param mixed $fd
     * @param int $flag
     * @return bool
     */
    public function del($fd, $flag)
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                return swoole_event_del($fd_key);
            case  self::EV_SIGNAL:
                $fd_key = (int)$fd;
                return \swoole_process::signal($fd_key,null);
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                return swoole_timer_clear($fd);
        }
        return true;
    }

    /**
     * Remove all timers.
     *
     * @return void
     */
    public function clearAllTimer()
    {
        // TODO: Implement clearAllTimer() method.
    }

    /**
     * Main loop.
     *
     * @return void
     */
    public function loop()
    {
        swoole_event_wait();
    }

    /**
     * Destroy loop.
     *
     * @return mixed
     */
    public function destroy()
    {
        // TODO: Implement destroy() method.
    }
}