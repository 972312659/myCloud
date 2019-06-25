<?php

namespace App\Models;

use App\Libs\combo\Rule;
use App\Validators\Mobile;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class ComboOrder extends Model
{
    //订单状态 1=>待支付 2=>已支付 3=>已使用 4=>已关闭 5=>退款
    const STATUS_UNPAY = 1;
    const STATUS_PAYED = 2;
    const STATUS_USED = 3;
    const STATUS_CLOSED = 4;
    const STATUS_BACK_MONEY_END = 5;
    const STATUS_BACK_MONEY_ING = 6;
    const STATUS_NAME = [1 => '待支付', 2 => '待使用', 3 => '已使用', 4 => '已关闭', 5 => '已退款', 6 => '退款中'];

    //类型  1=>自有 2=>共享
    const GENRE_SELF = 1;
    const GENRE_SHARE = 2;

    //0=>未被删除 1=>已被删除
    const IsDeleted_No = 0;
    const IsDeleted_Yes = 1;

    public $Id;

    public $OrderNumber;

    public $SendHospitalId;

    public $SendOrganizationId;

    public $SendOrganizationName;

    public $HospitalId;

    public $HospitalName;

    public $PatientName;

    public $PatientAge;

    public $PatientSex;

    public $PatientAddress;

    public $PatientId;

    public $PatientTel;

    public $Created;

    public $Status;

    public $Genre;

    public $TransferId;

    public $Explain;

    public $IsDeleted;

    public $Message;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('SendHospitalId', Organization::class, 'Id', ['alias' => 'SendHospital']);
        $this->belongsTo('HospitalId', Organization::class, 'Id', ['alias' => 'Hospital']);
    }

    public function getSource()
    {
        return 'ComboOrder';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rule('PatientName',
            new PresenceOf(['message' => '姓名不能为空'])
        );
        $validator->rules('PatientTel', [
            new PresenceOf(['message' => '请填写电话号码']),
            new Mobile(['message' => '请填写有效的手机号码']),
        ]);
        $validator->rule('Message',
            new StringLength(["min" => 0, "max" => 200, "messageMaximum" => '留言不超过200个字符'])
        );
        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        $auth = $this->getDI()->getShared('session')->get('auth');
        $this->SendOrganizationId = $auth['OrganizationId'];
        $this->SendOrganizationName = $auth['OrganizationName'];
        $this->SendHospitalId = $auth['HospitalId'];
        $this->Created = time();
        $this->OrderNumber = Rule::orderNumber($this->SendOrganizationId);
    }

    public function afterCreate()
    {
        if ($this->Status != self::STATUS_PAYED) $this->log();
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (in_array('Status', $changed, true)) {
            $auth = $this->getDI()->getShared('session')->get('auth');
            if ($auth['OrganizationId'] == $auth['HospitalId']) {
                //医院将套餐单状态从6变成2在外面记录日志
                if ($this->Status != self::STATUS_PAYED) {
                    $this->log();
                }
            } else {
                //网点将套餐单状态从2变成6在外面记录日志
                if ($this->Status != self::STATUS_PAYED && $this->Status != self::STATUS_BACK_MONEY_ING) {
                    $this->log();
                }
            }
        }
    }

    public function log($content = '')
    {
        $log = new ComboOrderLog();
        $auth = $this->getDI()->getShared('session')->get('auth');
        $log->OrganizationId = $auth['OrganizationId'];
        $log->UserId = $auth['Id'];
        $log->UserName = $auth['Name'];
        $log->ComboOrderId = $this->Id;
        $log->Status = $this->Status;
        $log->Content = $content;
        $log->LogTime = time();
        $log->save();
    }
}
