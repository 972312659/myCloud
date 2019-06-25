<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/19
 * Time: 下午12:59
 */

namespace App\Libs\product\structure;


class Attribute
{
    /**
     * @var int
     */
    public $Id;
    /**
     * @var string
     */
    public $Value;

    public static function getProperties()
    {
        $result = [];
        $reflectionClass = new \ReflectionClass(new self());
        $properties = $reflectionClass->getProperties();
        foreach ($properties as $property) {
            $result[] = $property->name;
        }
        return $result;
    }
}