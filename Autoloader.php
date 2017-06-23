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

/**
 * Autoload.
 */
class Autoloader
{
    protected static $namespaces;
    /**
     * Autoload root path.
     *
     * @var string
     */
    protected static $_autoloadRootPath = '';

    /**
     * Set autoload root path.
     *
     * @param string $root_path
     * @return void
     */
    public static function setRootPath($root_path)
    {
        self::$_autoloadRootPath = $root_path;
    }

    /**
     * Load files by namespace.
     *
     * @param string $name
     * @return boolean
     */
    public static function loadByNamespace($name)
    {
        if (self::autoload($name)){
            return true;
        }
        $class_path = str_replace('\\', DIRECTORY_SEPARATOR, $name);
        if (strpos($name, 'SwooleMan\\') === 0) {
            $class_file = __DIR__ . substr($class_path, strlen('SwooleMan')) . '.php';
        } else {
            if (self::$_autoloadRootPath) {
                $class_file = self::$_autoloadRootPath . DIRECTORY_SEPARATOR . $class_path . '.php';
            }
            if (empty($class_file) || !is_file($class_file)) {
                $class_file = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . "$class_path.php";
            }
        }

        if (is_file($class_file)) {
            require_once($class_file);
            if (class_exists($name, false)) {
                return true;
            }
        }
        return false;
    }
    /**
     * 自动载入类
     * @param $class
     */
    protected static function autoload($class)
    {
        $root = explode('\\', trim($class, '\\'), 2);
        if (count($root) > 1 and isset(self::$namespaces[$root[0]]))
        {
            include self::$namespaces[$root[0]].'/'.str_replace('\\', '/', $root[1]).'.php';
            return true;
        }
        return false;
    }

    /**
     * 设置根命名空间
     * @param $root
     * @param $path
     */
    static function addNameSpace($root, $path)
    {
        self::$namespaces[$root] = $path;
    }
}

spl_autoload_register('\SwooleMan\Autoloader::loadByNamespace');