<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/5/5
 * Time: 下午3:17
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class PlatformLicensing extends Model
{
    //是否上架 0=>下架 1=>上架
    const STATUS_OFF = 0;
    const STATUS_ON = 1;

    public $Id;

    public $Name;

    public $Durations;

    public $Price;

    public $Created;

    public $Updated;

    public $Limited;

    public $Status;

    public $Amount;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'PlatformLicensing';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Durations', [
            new PresenceOf(['message' => '月数不能为空']),
            new Digit(['message' => '月数格式错误']),
        ]);
        $validator->rules('Price', [
            new PresenceOf(['message' => '价格不能为空']),
            new Digit(['message' => '价格格式错误']),
        ]);
        $validator->rules('Limited', [
            new PresenceOf(['message' => '限定次数不能为空']),
            new Digit(['message' => '限定次数格式错误']),
        ]);
        $validator->rules('Status', [
            new PresenceOf(['message' => '状态不能为空']),
            new Digit(['message' => '状态格式错误']),
        ]);
        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        $this->Created = time();
        if (!$this->Limited) {
            $this->Limited = 0;
        }
        if (!$this->Status) {
            $this->Status = 0;
        }
    }

    public function afterCreate()
    {
        $this->PlatformLicensingLog();
    }

    public function beforeUpdate()
    {
        // $this->Updated = time();
    }

    public function afterUpdate()
    {
        // $this->PlatformLicensingLog();
    }

    public function PlatformLicensingLog()
    {
        $log = new PlatformLicensingLog();
        $log->PlatformLicensingId = $this->Id;
        $log->StaffId = $this->getDI()->getShared('session')->get('auth')['Id'];
        $log->StaffName = $this->getDI()->getShared('session')->get('auth')['Name'];
        $log->LogTime = time();
        $log->save();
    }
}
