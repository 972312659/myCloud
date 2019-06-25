<?php

namespace App\Models;


use App\Libs\Sphinx;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Uniqueness;

class Section extends Model
{
    //启用状态
    const STATUS_ON = 1;    //启用
    const STATUS_OFF = 2;   //停用

    //内置科室
    const BUILT_INSIDE = 1; //内置
    const BUILT_OUTSIDE = 2;//不是内置

    //默认科室图片
    const DEFAULT_IMAGE = 'https://referral-store.100cbc.com/FpT-1xK14i_rp8Wktdhbh4ZdDMQH';

    public $Id;

    public $Name;

    public $Pid;

    public $IsBuilt;

    public $Image;

    public $Poster;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->hasMany('Id', OrganizationAndSection::class, 'SectionId', ['alias' => 'Organization']);
        $this->hasMany('Id', Transfer::class, 'AcceptSectionId', ['alias' => 'Transfer']);
    }

    public function getSource()
    {
        return 'Section';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Name', [
            new PresenceOf(['message' => '请填写名称']),
        ]);
        $validator->add('Name',
            new Uniqueness(['message' => '科室已存在'])
        );
        return $this->validate($validator);
    }


    public function afterCreate()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'section');
        $sphinx->save(['id' => $this->Id, 'name' => $this->Name]);
        if ($this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null) {
            $this->staffSectionLog(StaffSectionLog::CREATE);
        }
    }

    public function afterUpdate()
    {
        $sphinx = new Sphinx($this->getDI()->getShared('sphinx'), 'section');
        $sphinx->save(['id' => $this->Id, 'name' => $this->Name]);
        if ($this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null) {
            $this->staffSectionLog(StaffSectionLog::UPDATE);
        }
    }

    public function beforeDelete()
    {
        if ($this->getDI()->getShared('session')->get('auth')['OrganizationId'] === null) {
            $this->staffSectionLog(StaffSectionLog::DELETE);
        }
    }

    private function staffSectionLog($status)
    {
        $staffHospitalLog = new StaffSectionLog();
        $staffHospitalLog->StaffId = $this->getDI()->getShared('session')->get('auth')['Id'];
        $staffHospitalLog->SectionId = $this->Id;
        $staffHospitalLog->Created = time();
        $staffHospitalLog->Operated = $status;
        $staffHospitalLog->save();
    }

    public function afterFetch()
    {
        if (!$this->Image) {
            $this->Image = self::DEFAULT_IMAGE;
        }
    }
}
