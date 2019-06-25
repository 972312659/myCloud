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

class ProductProperty extends Model
{
    public $ProductId;

    public $PropertyId;

    public $Created;

    public function initialize()
    {
        $this->belongsTo('ProductId', Product::class, 'Id', ['alias' => 'Product']);
        $this->belongsTo('PropertyId', Property::class, 'Id', ['alias' => 'Property']);
    }

    public function getSource()
    {
        return 'ProductProperty';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rule('ProductId', new Digit(['message' => '商品格式错误']));
        $validate->rule('PropertyId', new Digit(['message' => '属性格式错误']));
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }
}