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
use Phalcon\Validation\Validator\Digit;

class ProductAttribute extends Model
{
    public $ProductId;

    public $AttributeId;

    public $Value;

    public $Updated;

    public $Created;

    public function initialize()
    {
        $this->belongsTo('ProductId', Product::class, 'Id', ['alias' => 'Product']);
        $this->belongsTo('AttributeId', Attribute::class, 'Id', ['alias' => 'Attribute']);
    }

    public function getSource()
    {
        return 'ProductAttribute';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rule('Value', new PresenceOf(['message' => '商品属性指不能为空']));
        $validate->rule('ProductId', new Digit(['message' => '商品格式错误']));
        $validate->rule('AttributeId', new Digit(['message' => '属性格式错误']));
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Created = time();
        $this->Updated = time();
    }
}