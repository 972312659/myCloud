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
use Phalcon\Validation\Validator\Regex;
use Phalcon\Validation\Validator\StringLength;
use Phalcon\Validation\Validator\Callback;

class OrderExpress extends Model
{
    const TYPE_BUYER = 2;

    const TYPE_SELLER = 1;

    public $Id;

    public $OrderId;

    public $Number;

    public $Com;

    public $Type;

    public $Created;

    public function getSource()
    {
        return 'OrderExpress';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Com', [
            new PresenceOf(['message' => '快递公司不能为空']),
            new Regex([
                'message' => '快递公司格式错误（小写字母组成）',
                'pattern' => '/^[a-z]+$/',
            ]),
        ]);
        $validate->rules('Number', [
            new PresenceOf(['message' => '快递单号不能为空']),
            new StringLength(["min" => 0, "max" => 20, "messageMaximum" => '快递单号不能超过20个字符']),
        ]);

        $validate->rules('Type', [
            new Callback([
                'callback' => function ($value) {
                    return in_array($value, [static::TYPE_BUYER, static::TYPE_SELLER]);
                },
                'message' => '快递信息类型只能为买家或者卖家'
            ])
        ]);

        $validate->rules('Remark', [
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '备注不能超过50个字符']),
        ]);
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }
}
