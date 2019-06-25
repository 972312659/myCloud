<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/5
 * Time: 8:39 AM
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class InquiryEvaluateTotal extends Model
{
    public $KeyID;
    public $DoctorID;
    public $DoctorHospitalID;
    public $EvaluateItemCode;
    public $EvaluateItemName;
    public $EvaluateScoreTimes;
    public $EvaluateScoreTotal;
    public $EvaluateScoreAvg;
    public $AddUser;
    public $AddTime;
    public $ModifyUser;
    public $ModifyTime;
    public $IsDelete;

    public function getSource()
    {
        return 'InquiryEvaluateTotal';
    }

    public function initialize()
    {
        $this->setConnectionService('InquiryDB');
    }
}