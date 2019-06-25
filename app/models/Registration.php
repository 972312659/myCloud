<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class Registration extends Model
{
    //挂号单状态 1=>待支付 2=>已支付 3=>已预约成功 4=>挂号单取消，已退款 5=>患者取消，已退款
    const STATUS_UNPAID = 1;
    const STATUS_PREPAID = 2;
    const STATUS_REGISTRATION = 3;
    const STATUS_CANCEL = 4;
    const STATUS_REFUND = 5;
    const STATUS_NAME = ['', '待支付', '已支付', '已预约成功', '挂号单取消，已退款', '患者取消，已退款'];

    //挂号时间
    const DUTY_TIME_MORNING = 1;          //早上
    const DUTY_TIME_NOON = 2;             //中午
    const DUTY_TIME_AFTERNOON = 3;        //下午
    const DUTY_TIME_EVENING = 4;          //晚上
    const DUTY_TIME_NAME = ['', '早上', '中午', '下午', '晚上'];

    //挂号方式
    const WAY_HAVE = 1;     //有号
    const WAY_ADD = 2;      //加号
    const WAY_ROB = 3;      //抢号

    public $Id;

    public $OrderNumber;

    public $Created;

    public $SendOrganizationId;

    public $SendHospitalId;

    public $SectionId;

    public $DoctorId;

    public $HospitalId;

    public $Card;

    public $Name;

    public $Sex;

    public $CertificateId;

    public $RealNameCardTel;

    public $Tel;

    public $ExHospitalId;

    public $ExSectionId;

    public $ExDoctorId;

    public $HospitalName;

    public $SectionName;

    public $DoctorName;

    public $Price;

    public $Status;

    public $DutyDate;

    public $DutyTime;

    public $IsAllowUpdateTime;

    public $IsAllowUpdateDoctor;

    public $BeginTime;

    public $EndTime;

    public $ServicePackageName;

    public $ServicePackagePrice;

    public $ShareToHospital;

    public $ShareToSlave;

    public $Way;

    public $Explain;

    public $Type;

    public $TransferId;

    public $FinalTime;

    public $FinalSectionName;

    public $FinalDoctorName;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('SendOrganizationId', Organization::class, 'Id', ['alias' => 'SendOrganization']);
        $this->belongsTo('SendHospitalId', Organization::class, 'Id', ['alias' => 'SendHospital']);
        $this->belongsTo('HospitalId', Organization::class, 'Id', ['alias' => 'Hospital']);
        $this->belongsTo('SectionId', Section::class, 'Id', ['alias' => 'Section']);
        $this->belongsTo('DoctorId', User::class, 'Id', ['alias' => 'Doctor']);
    }

    public function getSource()
    {
        return 'Registration';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->add(['SendOrganizationId'],
            new Digit([
                'message' => [
                    'SendOrganizationId' => '网点格式错误',
                ],
            ])
        );
        return $this->validate($validator);
    }

    public function afterCreate()
    {
        $this->log();
    }

    public function afterUpdate()
    {
        $this->log();
    }

    public function log()
    {
        $log = new RegistrationLog;
        $log->RegistrationId = $this->Id;
        $log->Status = $this->Status;
        $log->LogTime = time();
        $log->save();
    }
}