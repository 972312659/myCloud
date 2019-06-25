<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use App\Libs\Rule;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\StringLength;

class Order extends Model
{
    //一级订单
    const PARENT_ID_TOP = 0;

    //1=>一级订单 2=>二级订单 3=>不需要拆单的一级订单
    const IsParent_parent = 1;
    const IsParent_child = 2;
    const IsParent_both = 3;

    //1=>待付款  2=>待发货 3=>待收货 4=>已收货 5=>退款 6=>完结 7=>买家取消 8=>退款中
    const STATUS_WAIT_PAY = 1;
    const STATUS_WAIT_SEND = 2;
    const STATUS_WAIT_RECEIVE = 3;
    const STATUS_RECEIVED = 4;
    const STATUS_REFUNDED = 5;
    const STATUS_FINISH = 6;
    const STATUS_CLOSED = 7;
    const STATUS_REFUNDING = 8;
    const STATUS_NAME = [1 => '待付款', 2 => '待发货', 3 => '待收货', 4 => '已收货', 5 => '已退款', 6 => '完结', 7 => '订单关闭', 8 => '退款中'];

    public $Id;

    public $OrderNumber;

    public $Created;

    public $SellerOrganizationId;

    public $SellerOrganizationName;

    public $BuyerOrganizationId;

    public $BuyerOrganizationName;

    public $Quantity;

    public $Amount;

    public $RealAmount;

    public $Postage;

    public $Remark;

    public $ParentId;

    public $IsParent;

    public $OrderInfoId;

    public $Status;

    public $CloseCause;

    public $IsRefund;

    public function initialize()
    {
        $this->belongsTo('OrderInfoId', OrderInfo::class, 'Id', ['alias' => 'Info']);
        $this->hasMany('Id', OrderAndProductUnit::class, 'OrderId', ['alias' => 'Items']);
        $this->hasOne('Id', OrderExpress::class, 'OrderId', ['alias' => 'Express']);
        $this->hasOne('Id', OrderRefund::class, 'OrderId', ['alias' => 'Refund', 'params' => ['order' => 'Id DESC']]);
    }

    public function getSource()
    {
        return 'Order';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Amount', [
            new PresenceOf(['message' => '总金额不能为空']),
        ]);
        $validate->rules('Quantity', [
            new PresenceOf(['message' => '总数量不能为空']),
            new Digit(['message' => '总数量格式错误']),
        ]);
        $validate->rules('Remark', [
            new StringLength(["min" => 0, "max" => 50, "messageMaximum" => '备注不能超过50个字符']),
        ]);
        return $this->validate($validate);
    }

    public function beforeCreate()
    {
        $this->Created = time();
        $this->OrderNumber = Rule::productOrderNumber($this->getDI()->getShared('session')->get('auth')['Id'], $this->SellerOrganizationId);
        $this->Status = static::STATUS_WAIT_PAY;
    }
}
