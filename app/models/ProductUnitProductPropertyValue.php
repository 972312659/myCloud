<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class ProductUnitProductPropertyValue extends Model
{
    public $ProductUnitId;

    public $ProductPropertyValueId;

    public function initialize()
    {
        $this->belongsTo('ProductUnitId', ProductUnit::class, 'Id', ['alias' => 'ProductUnit']);
        $this->belongsTo('ProductPropertyValueId', ProductPropertyValue::class, 'Id', ['alias' => 'ProductPropertyValue']);
    }

    public function getSource()
    {
        return 'ProductUnitProductPropertyValue';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rule('ProductUnitId', new Digit(['message' => '商品sku格式错误']));
        $validate->rule('ProductPropertyValueId', new Digit(['message' => '属性值格式错误']));
        return $this->validate($validate);
    }
}