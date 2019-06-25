<?php

namespace App\Models;

use App\Libs\combo\Rule;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\StringLength;

class ComboOrderBatch extends Model
{
    //订单状态 1=>待支付 2=>已支付 3=>已使用 4=>关闭
    const STATUS_WAIT_PAY = 1;
    const STATUS_WAIT_ALLOT = 2;
    const STATUS_USED = 3;
    const STATUS_CLOSED = 4;
    const STATUS_NAME = [1 => '待支付', 2 => '待分配', 3 => '已使用', 4 => '关闭'];

    //买家退款 1=>支持 2=>不支持
    const MoneyBack_Yes = 1;
    const MoneyBack_No = 2;

    //类型  1=>自有 2=>共享
    const GENRE_SELF = 1;
    const GENRE_SHARE = 2;

    public $Id;
    public $OrderNumber;
    public $ComboId;
    public $HospitalId;
    public $OrganizationId;
    public $OrganizationName;
    public $Name;
    public $Price;
    public $Way;
    public $MoneyBack;
    public $Amount;
    public $InvoicePrice;
    public $QuantityBuy;
    public $Status;
    public $CreateTime;
    public $PayTime;
    public $FinishTime;
    public $QuantityUnAllot;
    public $QuantityBack;
    public $QuantityApply;
    public $Genre;
    public $Image;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'ComboOrderBatch';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Name', [
            new PresenceOf(['message' => '套餐名不能为空']),
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '套餐名字不超过50个字符']),
        ]);
        $validator->rules('Price', [
            new PresenceOf(['message' => '套餐价格不能为空']),
            new Digit(['message' => '套餐价格请填写为数字']),
        ]);
        $validator->rules('InvoicePrice', [
            new PresenceOf(['message' => '套餐开票价格不能为空']),
            new Digit(['message' => '套餐开票价格请填写为数字']),
        ]);
        $validator->rule('Way',
            new PresenceOf(['message' => '请选择佣金方式'])
        );
        $validator->rule('Amount',
            new PresenceOf(['message' => '不能为空'])
        );
        $validator->rule('Amount',
            new Digit(['message' => '佣金金额请填写为数字'])
        );
        $validator->add(['OrganizationId', 'Amount'],
            new Digit([
                'message' => [
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                    'Amount'         => '佣金设置必须为整形数字',
                ],
            ])
        );
        $validator->rules('MoneyBack', [
            new PresenceOf(['message' => '售后服务不能为空']),
            new Digit(['message' => '售后服务格式错误']),
        ]);
        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        $this->OrderNumber = Rule::orderNumber($this->OrganizationId);
        $this->CreateTime = time();
        $this->Status = self::STATUS_WAIT_PAY;
    }
}
