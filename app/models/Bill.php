<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Between;
use Phalcon\Db\RawValue;

class Bill extends Model
{
    //来源类型 1=>内部转账InteriorTrade  2=>网关充值提现Trade  3=>转诊Transfer  4=>挂号Registration
    // 5=>套餐订单ComboOrder 6=>平台续费 7=>商城订单 8=>线下充值 9=>新套餐订单ComboOrderBatch 10=>套餐退款单
    //11=>业务经理奖励 12=>远程影像
    const REFERENCE_TYPE_INTERIORTRADE = 1;
    const REFERENCE_TYPE_TRADE = 2;
    const REFERENCE_TYPE_TRANSFER = 3;
    const REFERENCE_TYPE_REGISTRATION = 4;
    const REFERENCE_TYPE_COMBOORDER = 5;
    const REFERENCE_TYPE_PLATFORMLICENSING = 6;
    const REFERENCE_TYPE_ORDER = 7;
    const REFERENCE_TYPE_OFFLINEPAY = 8;
    const REFERENCE_TYPE_COMBOORDERBATCH = 9;
    const REFERENCE_TYPE_COMBO_REFUND = 10;
    const REFERENCE_TYPE_SALESMAN_BONUS = 11;
    const REFERENCE_TYPE_EPACS = 12;
    const REFERENCE_TYPE_NAME_PAY = [1 => '活动转账', 2 => '提现', 3 => '转诊支付', 4 => '挂号支付', 5 => '套餐支付', 6 => '平台续费', 7 => '商城订单', 9 => '套餐支付', 10 => '套餐退款', 12 => '远程影像支付'];
    const REFERENCE_TYPE_NAME_INCOME = [1 => '活动转账', 2 => '充值', 3 => '转诊收入', 4 => '挂号收入', 5 => '套餐收入', 8 => '线下充值', 9 => '套餐收入', 10 => '套餐退款', 11 => '业务奖收入', 12 => '远程影像收入'];

    //0=>未被删除 1=>已被删除
    const IsDeleted_No = 0;
    const IsDeleted_Yes = 1;

    //属于机构还是个人 1=>机构 2=>个人
    const Belong_Organization = 1;
    const Belong_Personal = 2;
    const Belong_Name = [1 => '机构', 2 => '个人'];

    /**
     * 充值账单
     */
    const TYPE_CHARGE = 1;
    /**
     * 提现账单
     */
    const TYPE_ENCASH = 2;
    /**
     * 收入账单
     */
    const TYPE_PROFIT = 3;
    /**
     * 支付账单
     */
    const TYPE_PAYMENT = 4;

    public $Id;

    public $Title;

    public $Fee;

    public $Balance;

    public $OrganizationId;

    public $UserId;

    public $Type;

    public $Created;

    public $ReferenceType;

    public $ReferenceId;

    public $IsDeleted;

    public $Belong;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('TargetOrganizationId', Organization::class, 'Id', ['alias' => 'TargetOrganization']);
        $this->belongsTo('UserId', User::class, 'Id', ['alias' => 'User']);
    }

    public function getSource()
    {
        return 'Bill';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->add(
            ['UserId', 'OrganizationId'],
            new Digit([
                'message' => [
                    'UserId'         => 'UserId必须为整形数字',
                    'OrganizationId' => 'OrganizationId必须为整形数字',
                ],
            ])
        );
        $validator->rules('Title', [
            new PresenceOf(['message' => '标题不能为空']),
            new Between(["minimum" => 0, "maximum" => 50, "message" => '最大不超过50']),
        ]);
        return $this->validate($validator);
    }


    public static function inCome($money)
    {
        return $money;
    }

    public static function outCome($money): int
    {
        return -$money;
    }

    public function afterCreate()
    {
        if ($this->ReferenceType === self::REFERENCE_TYPE_PLATFORMLICENSING) {
            //平台续费包购买次数加一
            $platformLicensing = PlatformLicensing::findFirst(sprintf('Id=%d', $this->ReferenceId));
            $platformLicensing->Amount = new RawValue(sprintf('Amount+%d', 1));
            $platformLicensing->save();
        }
    }
}
