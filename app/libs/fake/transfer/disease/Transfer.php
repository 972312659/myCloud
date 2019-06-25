<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/8/31
 * Time: 下午4:03
 */

namespace App\Libs\fake\transfer\disease;

class Transfer
{
    public $PatientAge;

    public $PatientSex;

    public $SendHospitalId;

    public $SendOrganizationId;

    public $SendOrganizationName;

    public $OldSectionName;

    public $OldDoctorName;

    public $AcceptOrganizationId;

    public $AcceptOrganizationName;

    public $AcceptSectionId;

    public $AcceptDoctorId;

    public $AcceptSectionName;

    public $AcceptDoctorName;

    public $Disease;

    public $Day;

    public $InHospital;

    public $Cost;
    /**
     * 员工id
     */
    public $StaffId;
    /**
     * 员工name
     */
    public $StaffName;

    /**
     * 财务id
     */
    public $CashierId;

    /**
     * 财务name
     */
    public $CashierName;

    /**
     * 费用清单
     */
    public $Fee;

    public $StartTime;

    public $AcceptTime;

    public $ClinicTime;

    public $LeaveTime;

    public $EndTime;
}
