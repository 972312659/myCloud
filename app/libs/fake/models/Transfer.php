<?php

namespace App\Libs\fake\models;

use Phalcon\Mvc\Model;

/**
 * Class Transfer
 * @package App\Libs\fake\transfer
 *
 * @property int Id
 * @property string PatientName
 * @property string PatientTel
 * @property int PatientSex
 * @property string PatientAddress
 * @property string PatientId
 * @property int SendHospitalId
 * @property int SendOrganizationId
 * @property string SendOrganizationName
 * @property int TranStyle
 * @property int AcceptOrganizationId
 * @property int StartTime
 * @property int ClinicTime
 * @property int LeaveTime
 * @property int EndTime
 * @property int Status
 * @property int OrderNumber
 * @property int ShareOne
 * @property int ShareCloud
 * @property int Genre
 * @property int GenreOne
 * @property int CloudGenre
 * @property int AcceptSectionId
 * @property int Sign
 * @property int AcceptDoctorId
 * @property string AcceptSectionName
 * @property string AcceptDoctorName
 * @property string Disease
 * @property int Cost
 * @property \Phalcon\Mvc\Model\Resultset TransferLogs
 * @property \Phalcon\Mvc\Model\Resultset Pictures
 * @property Organization SendOrganization
 * @property Organization Hospital
 * @property int GenreTwo
 * @property int ShareTwo
 * @property int PatientAge
 * @property int IsFake
 * @property int IsEncash
 *
 */
class Transfer extends Model
{
    const SHARE_ONE = 10;

    public function initialize()
    {
        $this->hasMany('Id', TransferLog::class, 'TransferId', [
            'alias' => 'TransferLogs'
        ]);

        $this->hasMany('Id', TransferPicture::class, 'TransferId', [
            'alias' => 'Pictures'
        ]);

        $this->belongsTo('SendOrganizationId', Organization::class, 'Id', [
            'alias' => 'SendOrganization'
        ]);

        $this->belongsTo('AcceptOrganizationId', Organization::class, 'Id', [
            'alias' => 'Hospital'
        ]);
    }

    public function getSource()
    {
        return 'Transfer';
    }
}
