<?php

namespace App\Libs\task;

use Phalcon\Exception;

/**
 * 解析cli可选参数
 * 参数形式: -paramA=x -paramB=y
 *
 * Trait OptionalParams
 * @package App\Libs\task
 */
trait OptionalParams
{
    protected $params = [];

    public function parseParams(array $params)
    {
        foreach ($params as $param) {
            if (false === preg_match('/-([[:alpha:]_]+)=(.*)/', $param, $match)) {
                continue;
            }

            if (!array_key_exists($match[1], $this->params)) {
                continue;
            }

            $this->params[$match[1]] = $this->parseBool($match[2]);
        }
    }

    public function setParam($name, $default = null)
    {
        $this->params[$name] = $default;
    }

    public function getParam($name)
    {
        if (!isset($this->params[$name])) {
            throw new Exception('undefined param: '.$name);
        }

        return $this->params[$name];
    }

    private function parseBool($value)
    {
        switch ($value) {
            case 'true':
                return true;
            case 'false':
                return false;
            default:
                return $value;
        }
    }
}
