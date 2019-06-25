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
use Phalcon\Validation\Validator\Between;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;
use Phalcon\Validation\Validator\Digit;

class Attribute extends Model
{
    public $Id;

    public $Name;

    public $MaxLength;

    public $ProductCategoryId;

    public $Created;

    public $IsRequired;

    public function initialize()
    {
        $this->belongsTo('ProductCategoryId', ProductCategory::class, 'Id', ['alias' => 'ProductCategory']);
        $this->hasMany('Id', ProductAttribute::class, 'AttributeId', ['alias' => 'Products']);
    }

    public function getSource()
    {
        return 'Attribute';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Name', [
            new PresenceOf(['message' => '属性名不能为空']),
            new StringLength(["min" => 0, "max" => 10, "messageMaximum" => '属性名不能超过10个字符']),
        ]);
        $validate->rule('MaxLength', new Between(['minimum' => 0, 'maximum' => 256, 'message' => '属性值最大长度(不超过256)']));
        $validate->rule('CategoryId', new Digit(['message' => '商品分类格式错误']));
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }
}