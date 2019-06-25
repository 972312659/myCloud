<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/19
 * Time: 下午12:58
 */

namespace App\Libs\product\structure;

class Sku
{
    /**
     * @var int
     */
    public $Id;
    /**
     * @var int
     */
    public $Price;
    /**
     * @var int
     */
    public $PriceForSlave;
    /**
     * @var int
     */
    public $Postage;
    /**
     * @var string
     */
    public $Number;
    /**
     * @var int
     */
    public $IsDefault;
    /**
     * @var PropertyId[] $PropertyIds
     */
    public $PropertyIds = [];
    // 'PropertyIds'   => [
    //     //1销售属性的属性id,上面1后面数组对应的角标
    // ['PropertyId'=>'1','PropertyValueId'=>'2'], ['PropertyId'=>'2','PropertyValueId'=>'1'],
    // ],
    /**
     * @var Image[] $Images
     */
    public $Images = [];

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