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
use Phalcon\Validation\Validator\StringLength;

class OrderAndProductUnit extends Model
{
    public $Id;

    public $OrderId;

    public $ChildOrderId;

    public $ProductUnitId;

    public $Quantity;

    public $Price;

    public $ProductVersion;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'OrderAndProductUnit';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('ProductVersion', [
            new PresenceOf(['message' => '商品版本号不能为空']),
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '商品版本号不超过50个字符']),
        ]);
        $validate->rules('Remark', [
            new StringLength(["min" => 0, "max" => 100, "messageMaximum" => '备注不超过100个字符']),
        ]);
        $validate->rules('Quantity', [
            new PresenceOf(['message' => '总数量不能为空']),
            new Digit(['message' => '总数量格式错误']),
        ]);
        $validate->rules('Price', [
            new PresenceOf(['message' => '单价不能为空']),
            new Digit(['message' => '单价格式错误']),
        ]);
        return $this->validate($validate);
    }
}