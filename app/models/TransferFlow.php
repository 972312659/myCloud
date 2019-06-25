<?php

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class TransferFlow extends Model
{
    //门诊或者住院 1=>门诊 2=>住院
    const OutpatientOrInpatient_Out = 1;
    const OutpatientOrInpatient_In = 2;
    const OutpatientOrInpatient_Name = [1 => '门诊', 2 => '住院'];

    //是否能修改金额及分润方式 0=>不能 1=>能
    const CanModify_No = 0;
    const CanModify_Yes = 1;

    public $Id;

    public $TransferId;

    public $GenreOne;

    public $ShareOne;

    public $CloudGenre;

    public $ShareCloud;

    public $SectionId;

    public $SectionName;

    public $DoctorId;

    public $DoctorName;

    public $OutpatientOrInpatient;

    public $Created;

    public $Cost;

    public $ClinicRemark;

    public $FinishRemark;

    public $TherapiesExplain;

    public $ReportExplain;

    public $DiagnosisExplain;

    public $FeeExplain;

    public $TherapiesExplainImages;

    public $ReportExplainImages;

    public $DiagnosisExplainImages;

    public $FeeExplainImages;

    public $CanModify;

    public function getSource()
    {
        return 'TransferFlow';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->rules('Cost', [
            new Digit(['message' => '费用必须是数字']),
        ]);
        $validate->rules('Created', [
            new PresenceOf(['message' => '时间未选择']),
        ]);
        return $this->validate($validate);
    }
}
