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

class ProductPicture extends Model
{
    public $Id;

    public $ProductId;

    public $Image;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'ProductPicture';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Image', [
            new PresenceOf(['message' => '图片不能为空']),
            new StringLength(["min" => 0, "max" => 255, "messageMaximum" => '商品种类不能超过255个字符']),
        ]);
        return $this->validate($validate);
    }
}