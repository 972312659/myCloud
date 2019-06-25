<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/10/19
 * Time: 下午3:26
 */

namespace App\Libs\product\structure;


use App\Models\ProductPropertyValue;

class PropertyValue
{
    /**
     * @var int
     */
    public $Id;
    /**
     * @var string
     */
    public $Value;

    public function create($productId, $propertyId)
    {
        $productPropertyValue = new ProductPropertyValue();
        $productPropertyValue->ProductId = $productId;
        $productPropertyValue->PropertyId = $propertyId;
        $productPropertyValue->Value = $this->Value;
        $productPropertyValue->save();
    }
}