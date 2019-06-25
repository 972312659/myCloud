<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/7/25
 * Time: 15:17
 */

namespace App\Libs;

use Phalcon\Session\AdapterInterface;
use Redis;

class Session implements AdapterInterface
{
    const SESSION_ACTIVE = 2;

    const SESSION_NONE = 1;

    const SESSION_DISABLED = 0;

    private $_sessName = 'PHPSESSID';

    private $_started = false;

    private $_redis;

    private $_lifetime = 3600;

    private $_index = 0;

    public function __construct(array $options = [])
    {
        if (!isset($options['host'])) {
            $options['host'] = '127.0.0.1';
        }

        if (!isset($options['port'])) {
            $options['port'] = 6379;
        }

        if (!isset($options['persistent'])) {
            $options['persistent'] = false;
        }

        if (isset($options['lifetime'])) {
            $this->_lifetime = $options['lifetime'];
        }

        if (isset($options['index'])) {
            $this->_index = $options['index'];
        }

        if (isset($options['sessionName'])) {
            $this->_sessName = $options['sessionName'];
        }

        $this->_redis = new Redis();
        $this->_redis->connect($options['host'], $options['port']);
        $this->_redis->select($this->_index);


        session_set_save_handler(
            [$this, 'open'],
            [$this, 'close'],
            [$this, 'read'],
            [$this, 'write'],
            [$this, 'destroy'],
            [$this, 'gc'],
            [$this, 'create_sid']
        );

        $token = $_SERVER['HTTP_TOKEN'] ?: $_GET['Token'] ?: $this->create_sid();
        if ($this->isApp()) {
            $this->_lifetime = 1<<21;
        }
        $this->setId($token);
    }

    public function open()
    {
        return true;
    }

    public function start()
    {
        if (!headers_sent()) {
            if (!$this->_started && $this->status() !== self::SESSION_ACTIVE) {
                $this->setName($this->_sessName);
                session_start();
                $this->_started = true;
                return true;
            }
        }
        return false;
    }

    public function close()
    {
        return true;
    }

    public function read($id)
    {
        $data = $this->_redis->get($id);
        $_SESSION = json_decode($data, true);
        $this->_redis->expire($id, $this->_lifetime);
        return '';
    }

    public function write($id, $data)
    {
        return $this->_redis->setex($id, $this->_lifetime, json_encode($_SESSION));
    }

    public function setOptions(array $options)
    {
    }

    public function getOptions()
    {
    }

    public function create_sid()
    {
        return $this->isApp() ? sha1(microtime()) : md5(microtime());
    }

    public function get($index, $defaultValue = null, $remove = false)
    {
        if (isset($_SESSION[$index])) {
            $value = $_SESSION[$index];
            if ($remove) {
                unset($_SESSION[$index]);
            }
            return $value;
        }
        return $defaultValue;
    }

    public function set($index, $value)
    {
        $_SESSION[$index] = $value;
        return true;
    }

    public function has($index)
    {
        return isset($_SESSION[$index]);
    }

    public function remove($index)
    {
        unset($_SESSION[$index]);
    }

    public function getId()
    {
        return session_id();
    }

    public function setId($id)
    {
        session_id($id);
    }

    public function isStarted()
    {
        return $this->_started;
    }

    public function destroy($removeData = true)
    {
        $_SESSION = [];
        $id = $this->getId();
        return $this->_redis->exists($id) ? $this->_redis->del($id) : true;
    }

    public function regenerateId($deleteOldSession = true)
    {
        session_regenerate_id($deleteOldSession);
        return $this->getId();
    }

    public function setName($name)
    {
        session_name($name);
    }

    public function getName()
    {
        return session_name();
    }

    public function status()
    {
        $status = session_status();
        switch ($status) {
            case PHP_SESSION_DISABLED:
                return self::SESSION_DISABLED;

            case PHP_SESSION_ACTIVE:
                return self::SESSION_ACTIVE;
        }
        return self::SESSION_NONE;
    }

    public function gc()
    {
        return true;
    }

    public function __get($index)
    {
        return $this->get($index);
    }

    public function __set($index, $value)
    {
        return $this->set($index, $value);
    }

    public function __isset($index)
    {
        return $this->has($index);
    }

    public function __unset($index)
    {
        $this->remove($index);
    }

    public function isApp()
    {
        return $_SERVER['HTTP_PLATFORM'] === 'iOS' || $_SERVER['HTTP_PLATFORM'] === 'Android';
    }
}