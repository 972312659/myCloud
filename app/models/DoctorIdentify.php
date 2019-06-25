<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\Between;
use Phalcon\Validation\Validator\Digit;

class DoctorIdentify extends Model
{
    //状态 1=>申请认证 2=>失败 3=>成功
    const STATUS_READY = 1;
    const STATUS_REFUSE = 2;
    const STATUS_SUCCESS = 3;
    const STATUS_NAME = [1 => '认证中', 2 => '认证未通过', 3 => '认证通过'];

    //执业类型 0:西医 1:中医 2:中西医结合
    const MedicineClass_Western = 0;
    const MedicineClass_Chinese = 1;
    const MedicineClass_ChineseAndWestern = 2;
    const MedicineClassName = [0 => '西医', 1 => '中医', 2 => '中西医结合'];
    const MedicineClassPrescriptionName = [0 => '西药', 1 => '中药', 2 => '中西医结合'];

    //类型 0:医师 1:药师
    const IdentifyType_Physician = 0;
    const IdentifyType_Pharmacist = 1;

    public $OrganizationId;

    public $UserId;

    public $Image;

    public $Status;

    public $Created;

    public $Number;

    public $PhysicianNumber;

    public $PhysicianImage;

    public $MedicineClass;

    public $IdentifyType;

    public $Reason;

    public $AuditTime;

    public $UpdateTime;

    public function initialize()
    {
        $this->keepSnapshots(true);
        $this->useDynamicUpdate(true);
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
        $this->belongsTo('UserId', User::class, 'Id', ['alias' => 'User']);
    }

    public function getSource()
    {
        return 'DoctorIdentify';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('OrganizationId', [new Digit(['message' => '所属机构的格式错误'])]);
        $validate->rules('UserId', [new Digit(['message' => '用户格式格式错误'])]);
        $validate->rules('Image', [new PresenceOf(['message' => '资格证书必传'])]);
        $this->validate($validate);
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (count($changed)) {
            $this->UpdateTime = time();

            if (isset($this->getDI()->getShared('session')->get('auth')['OrganizationId'])) {
                $this->Status = self::STATUS_READY;
                /** @var OrganizationUser $organizationUser */
                $organizationUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
                    'bind'       => [$this->OrganizationId, $this->UserId, OrganizationUser::IsDelete_No],
                ]);
                if ($organizationUser) {
                    $organizationUser->Identified = OrganizationUser::IDENTIFIED_OFF;
                    $organizationUser->save();
                }
            }
        }
    }
}
