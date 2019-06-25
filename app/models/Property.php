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

class Property extends Model
{
    public $Id;

    public $Name;

    public $MaxLength;

    public $Updated;

    public $Created;

    public function initialize()
    {
        $this->hasMany('Id', ProductProperty::class, 'PropertyId', ['alias' => 'Products']);
    }

    public function getSource()
    {
        return 'Property';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Name', [
            new PresenceOf(['message' => '属性名不能为空']),
            new StringLength(["min" => 0, "max" => 10, "messageMaximum" => '属性名不能超过10个字符']),
        ]);
        $validate->rule('MaxLength', new Between(['minimum' => 0, 'maximum' => 256, 'message' => '属性值最大长度(不超过256)']));
        return $this->validate($validate);
    }

}