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
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;
use Phalcon\Validation\Validator\Digit;

class ProductPropertyValue extends Model
{
    public $Id;

    public $Value;

    public $ProductId;

    public $PropertyId;

    public $Updated;

    public $Created;

    public function initialize()
    {
        $this->belongsTo('ProductId', Product::class, 'Id', ['alias' => 'Product']);
        $this->belongsTo('PropertyId', Property::class, 'Id', ['alias' => 'Property']);
    }

    public function getSource()
    {
        return 'ProductPropertyValue';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Value', [
            new PresenceOf(['message' => '商品属性值不能为空']),
            new StringLength(["min" => 0, "max" => 256, "messageMaximum" => '商品属性值不能超过256个字符']),
        ]);
        $validate->rule('ProductId', new Digit(['message' => '商品格式错误']));
        $validate->rule('PropertyId', new Digit(['message' => '属性格式错误']));
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Created = time();
        $this->Updated = time();
    }

    public function beforeUpdate()
    {
        $this->Updated = time();
    }
}