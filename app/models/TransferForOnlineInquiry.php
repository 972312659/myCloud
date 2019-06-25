<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class TransferForOnlineInquiry extends Model
{
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

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'Transfer';
    }

    public function afterCreate()
    {
        //创建sphinx
        $transferSphinx = new \App\Libs\sphinx\model\Transfer();
        $transferSphinx->save($this);
    }

}
