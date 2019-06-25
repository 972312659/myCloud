<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class IllnessForDoctorIdentificationLog extends Model
{
    const STATUS_CREATE = 1;
    const STATUS_DELETE = 2;
    const STATUS_NAME = [1 => '认证', 2 => '取消认证'];

    public $Id;

    public $UserId;

    public $IllnessId;

    public $StaffId;

    public $StaffName;

    public $Status;

    public $LogTime;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'IllnessForDoctorIdentificationLog';
    }

    public function beforeCreate()
    {
        $this->LogTime = time();
    }
}