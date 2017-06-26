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
namespace SwooleMan\Lib;

use Exception;

/**
 * Timer.
 *
 * example:
 * Workerman\Lib\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Timer
{
    protected  static $_timer_id = array();


    public static function init(){}
    /**
     * Add a timer.
     *
     * @param int      $time_interval
     * @param callback $func
     * @param mixed    $args
     * @param bool     $persistent
     * @return int/false
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true)
    {
        if ($time_interval <= 0) {
            echo new Exception("bad time_interval");
            return false;
        }
        if (!is_callable($func)) {
            echo new Exception("not callable");
            return false;
        }
        $time_interval = intval($time_interval*1000);
        if ($persistent){
            $timer_id = swoole_timer_tick($time_interval,function ()use ($func,$args){
                if ($args == null){
                    return call_user_func($func);
                }
                return call_user_func_array($func,$args);
            });
        }else{
            $timer_id = swoole_timer_after($time_interval,function ()use ($func,$args){
                if ($args == null){
                    return call_user_func($func);
                }
                return call_user_func_array($func,$args);
            });
        }
        self::$_timer_id[$timer_id] = 1;
        return $timer_id;
    }



    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        if (swoole_timer_clear($timer_id)){
            unset(self::$_timer_id[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * Remove all timers.
     *
     * @return void
     */
    public static function delAll()
    {
        foreach (self::$_timer_id as $timer_id=>$value){
            self::del($timer_id);
        }
    }
}
