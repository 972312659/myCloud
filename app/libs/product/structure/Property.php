<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/19
 * Time: 下午12:53
 */

namespace App\Libs\product\structure;

class Property
{
    /**
     * @var int
     */
    public $Id;
    /**
     * @var string
     */
    public $Value;
    /**
     * @property PropertyValue[] $PropertyValues
     */
    public $PropertyValues = [];//'PropertyValues' => [['Id'=>'1','Value'=>100'], ['Id'=>'2','Value'=>'200']],

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