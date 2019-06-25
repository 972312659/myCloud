<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Callback;

class OrderRefund extends Model
{
    const STATUS_PENDING = 1; //等待处理

    const STATUS_REFUNDED = 2; //已退款

    const STATUS_REFUSED = 3; //商家拒绝

    const STATUS_WAIT_SEND = 4; //等待买家发货

    const STATUS_WAIT_RECEIVE = 5 ;//等待卖家收货/买家已发货

    const TYPE_ONLY_REFUND = 1; //仅退款

    const TYPE_RETURN_GOODS = 2; //退款退货

    public $Id;

    public $SerialNumber;

    public $OrderId;

    public $Feedback;

    public $Reason;

    public $Status;

    public $Type;

    public $Created;

    public $OrderStatus;

    public $Amount;

    public function getSource()
    {
        return 'OrderRefund';
    }

    public function initialize()
    {
        $this->belongsTo('OrderId', Order::class, 'Id', ['alias' => 'Order']);
        $this->hasOne('InteriorTradeId', InteriorTrade::class, 'Id', ['alias' => 'Trade']);
    }

    public function validationCreate()
    {
        $validate = new Validation();
        $validate->rules('OrderId', [
            new PresenceOf(['message' => '订单id不能为空']),
        ]);
        $validate->rules('SerialNumber', [
            new PresenceOf(['message' => '总数量不能为空']),
        ]);
        $validate->rules('Reason', [
            new PresenceOf(["min" => 0, "max" => 50, "messageMaximum" => '申请原因不能为空']),
        ]);

        $validate->rules('Type', [
            new Callback([
                'callback' => function ($data) {
                    return in_array($data, [1, 2], true);
                },
                'message' => '退款单类型错误'
            ])
        ]);

        $validate->rules('InteriorTradeId', [
            new PresenceOf(['message' => '财务申请单id不能为空'])
        ]);

        return $this->validate($validate);
    }
}
