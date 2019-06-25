<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/5
 * Time: 8:39 AM
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class OnlineInquiry extends Model
{
    public $KeyID;
    public $ClinicID;
    public $ClinicHospitalID;
    public $DoctorID;
    public $DoctorHospitalID;
    public $RequestTerminal;
    public $RoomNo;
    public $RoomNoEncrypt;
    public $RequestTime;
    public $ResponseTime;
    public $EndTime;
    public $HoldingTime;
    public $EvaluateTime;
    public $HasPrescription;
    public $HasTransfer;
    public $HasVideo;
    public $AddUser;
    public $AddTime;
    public $ModifyUser;
    public $ModifyTime;
    public $IsDelete;

    public function getSource()
    {
        return 'OnlineInquiry';
    }

    public function initialize()
    {
        $this->setConnectionService('InquiryDB');
    }
}