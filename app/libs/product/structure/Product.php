<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/19
 * Time: 上午11:28
 */

namespace App\Libs\product\structure;

/**
 * Class Product
 * @package App\Libs\product\structure
 *          属性不可以随意添加，否则商品更新对比时可能会出现漏洞
 */

final class Product
{
    /**
     * @var int
     */
    public $OrganizationId;
    /**
     * @var int
     */
    public $Id;
    /**
     * @var string
     */
    public $Name;

    /**
     * @var string
     */
    public $Description;
    /**
     * @var string
     */
    public $Manufacturer;
    /**
     * @var int
     */
    public $Way;
    /**
     * @var int
     */
    public $ProductCategoryId;
    /**
     * @var Image[] $Image
     */
    public $Image;
    //'Id'    => '1',
    //'Value' => './images/sss.jpg',
    /**
     * @var Property[] $Properties
     */
    public $Properties;
    //1销售属性的属性id
    // [
    // 'Id'     => '1',
    // 'Value'  => '规则',
    // 'PropertyValues' => [['Id'=>'1','Value'=>'100'], ['Id'=>'2','Value'=>'200']],
    // ],
    /**
     * @var Sku[] $sku
     */
    public $Sku;
    // 'Id'            => '1',
    // 'Price'         => '100',
    // 'PriceForSlave' => '100',
    // 'Postage'       => '100',
    // 'Stock'         => '100',
    // 'WarningLine'   => '100',
    // 'Number'        => '1213134141234',
    // 'PropertyIds'   => [
    //     //1销售属性的属性id,上面1后面数组对应的角标
    //      ['PropertyId'=>1,'PropertyName'=>'规格','PropertyValueId'=>'2','PropertyValueName'=>'200'],
    // ],
    // 'Images'        => [['Id'=>'1','Value'=>'./images/sss.jpg'],['Id'=>'1','Value'=>'./images/sss.jpg']],
    /**
     * @var Attribute[] $Attributes
     */
    public $Attributes;
    //1Attribute的id
    //['Id' => '1', 'Value' => '描述'],

    public function getSku($id)
    {
        foreach ($this->Sku as $sku) {
            if ($sku->Id == $id) {
                return $sku;
            }
        }

        return null;
    }

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
