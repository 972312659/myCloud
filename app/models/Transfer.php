<?php

namespace App\Models;

use App\Validators\IDCardNo;
use App\Validators\Mobile;
use Phalcon\Db\RawValue;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class Transfer extends Model
{
    //转诊订单状态 1=>待处理  2=>待接诊  3=>待入院  4=>拒绝  5=>治疗中  6=>出院  7=>财务审核未通过 8=>结算完成 9=>重新提交
    const CREATE = 2;
    const ACCEPT = 3;
    const REFUSE = 4;
    const TREATMENT = 5;
    const LEAVE = 6;
    const NOTPAY = 7;
    const FINISH = 8;
    const REPEAT = 9;
    const STATUS_NAME = [2 => '发起转诊', 3 => '医院确认', 4 => '拒绝', 5 => '确认病人到院', 6 => '病人出院', 8 => '结算打款'];

    //转诊单生成的来源 1=>正常转诊 2=>挂号 3=>套餐
    const SOURCE_NORMAL = 1;
    const SOURCE_REGISTRATION = 2;
    const SOURCE_COMBO = 3;

    //分润方式 1=>固定 2=>比例
    const FIXED = 1;
    const RATIO = 2;

    //转诊单类型 1=>自有 2=>共享
    const GENRE_SELF = 1;
    const GENRE_SHARE = 2;

    //转诊单是否被删除 0=>未被删除 1=>已被删除
    const ISDELETED_NO = 0;
    const ISDELETED_YES = 1;

    //是否发送短信给患者 1=>发送 2=>不发送
    const SEND_MESSAGE_TO_PATIENT_YES = 1;
    const SEND_MESSAGE_TO_PATIENT_NO = 2;

    //门诊或者住院 1=>门诊 2=>住院
    const OutpatientOrInpatient_Out = 1;
    const OutpatientOrInpatient_In = 2;
    const OutpatientOrInpatient_Name = [1 => '门诊', 2 => '住院'];

    public $Id;

    public $PatientName;

    public $PatientAge;

    public $PatientSex;

    public $PatientAddress;

    public $PatientId;

    public $PatientTel;

    public $SendHospitalId;

    public $SendOrganizationId;

    public $SendOrganizationName;

    public $TranStyle;

    public $OldSectionName;

    public $OldDoctorName;

    public $AcceptOrganizationId;

    public $AcceptSectionId;

    public $AcceptDoctorId;

    public $AcceptSectionName;

    public $AcceptDoctorName;

    public $Disease;

    public $StartTime;

    public $EndTime;

    public $LeaveTime;

    public $ClinicTime;

    public $Status;

    public $OrderNumber;

    public $ShareOne;

    public $ShareTwo;

    public $ShareCloud;

    public $Remake;

    public $Genre;

    public $GenreOne;

    public $GenreTwo;

    public $Explain;

    public $Cost;

    public $CloudGenre;

    public $IsEvaluate;

    public $TherapiesExplain;

    public $ReportExplain;

    public $DiagnosisExplain;

    public $FeeExplain;

    public $Source;

    public $Sign;

    public $SendMessageToPatient;

    public $IsDeleted;

    public $IsDeletedForSendOrganization;

    public $IsEncash;

    public $IsFake;

    public $OutpatientOrInpatient;

    public $Pid;

    //验证
    private $selfValidate;

    //自定义验证场景
    const SCENE_CREATE = 'transfer-create';
    const SCENE_STATUS_TREATMENT = 'transfer-status-treatment';
    const SCENE_COMBOORDER_ARRIVE = 'comboorder-arrive';

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('SendOrganizationId', Organization::class, 'Id', ['alias' => 'SendOrganization']);
        $this->belongsTo('SendHospitalId', Organization::class, 'Id', ['alias' => 'SendHospital']);
        $this->belongsTo('AcceptOrganizationId', Organization::class, 'Id', ['alias' => 'Hospital']);
        $this->belongsTo('AcceptSectionId', Section::class, 'Id', ['alias' => 'Section']);
        $this->belongsTo('AcceptDoctorId', User::class, 'Id', ['alias' => 'Doctor']);
        $this->hasMany('Id', Pictures::class, 'TransferId', ['alias' => 'Pictures']);
        $this->hasMany('Id', TransferLog::class, 'TransferId', ['alias' => 'Logs']);
    }

    public function getSource()
    {
        return 'Transfer';
    }

    /**
     * 设置验证场景
     * @param $scene
     */
    public function setScene($scene)
    {
        $this->selfValidate = new Validation();
        $fields = [
            'PatientName'          => [new PresenceOf(['message' => '姓名不能为空'])],
            'PatientTel'           => [new PresenceOf(['message' => '患者电话不能为空']), new Mobile(['message' => '请输入正确的手机号'])],
            'PatientId'            => [new PresenceOf(['message' => '身份证不能为空']), new IDCardNo(['message' => '18位身份证号码错误']),],
            'SendHospitalId'       => [new PresenceOf(['message' => '发送网点的上级医院不能为空']), new Digit(['message' => '发送网点的上级医院的格式错误'])],
            'SendOrganizationId'   => [new PresenceOf(['message' => '发送网点不能为空']), new Digit(['message' => '发送网点的格式错误'])],
            'AcceptOrganizationId' => [new PresenceOf(['message' => '接诊医院不能为空']), new Digit(['message' => '接诊医院的格式错误'])],
            'AcceptSectionId'      => [new PresenceOf(['message' => '接诊科室不能为空']), new Digit(['message' => '接诊科室的格式错误'])],
            'AcceptDoctorId'       => [new PresenceOf(['message' => '接诊医生不能为空']), new Digit(['message' => '接诊医生的格式错误'])],
        ];
        $scenes = [
            self::SCENE_CREATE            => ['PatientTel', 'SendHospitalId', 'SendOrganizationId', 'AcceptOrganizationId', 'AcceptSectionId', 'AcceptDoctorId'],
            self::SCENE_STATUS_TREATMENT  => ['PatientId'],
            self::SCENE_COMBOORDER_ARRIVE => ['PatientName', 'AcceptSectionId', 'AcceptDoctorId'],
        ];
        foreach ($scenes[$scene] as $v) {
            $this->selfValidate->rules($v, $fields[$v]);
        }
    }

    public function validation()
    {
        if ($this->selfValidate) {
            return $this->validate($this->selfValidate);
        }
    }

    public function afterCreate()
    {
        $this->log($this->StartTime);
        //创建sphinx
        $transferSphinx = new \App\Libs\sphinx\model\Transfer();
        $transferSphinx->save($this);
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (in_array('Status', $changed, true)) {
            //审核未通过、重新提交不记录日志
            switch ($this->Status) {
                case self::LEAVE:
                    $this->log($this->LeaveTime);
                    break;
                case self::NOTPAY:
                    break;
                case self::REPEAT:
                    break;
                default:
                    $this->log();
            }
            //患者出院时时医生总转诊单数量加一
            if ($this->Status == self::LEAVE) {
                $user = User::findFirst(sprintf('Id=%d', $this->AcceptDoctorId));
                $user->TransferAmount = new RawValue(sprintf('TransferAmount+%d', 1));
                $user->save();
                $user->refresh();
            }
        }
        if (in_array('PatientName', $changed, true) || in_array('AcceptDoctorName', $changed, true)) {
            $transferSphinx = new \App\Libs\sphinx\model\Transfer();
            $transferSphinx->save($this);
        }
    }

    public function log($time = 0)
    {
        $auth = $this->getDI()->getShared('session')->get('auth');
        TransferLog::addLog($auth['OrganizationId'], $auth['OrganizationName'], $auth['Id'], $auth['Name'], $this->Id, (int)$this->Status, $time ?: time());
    }
}
