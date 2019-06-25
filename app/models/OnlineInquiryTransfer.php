<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/5/5
 * Time: 8:39 AM
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class OnlineInquiryTransfer extends Model
{
    //预约状态(0:待患者确认 / 1:患者已确认（已生成转诊单） / 2:患者已取消)
    const OrderStatus_Wait = 0;
    const OrderStatus_Accept = 1;
    const OrderStatus_Cancel = 2;

    /**
     * 主键
     */
    public $KeyID;
    /**
     * 在线问诊ID
     */
    public $OnlineInquiryID;
    /**
     * 发起网点ID
     */
    public $ClinicID;
    /**
     * 发起网点所属医院ID
     */
    public $HospitalID;
    /**
     * 接诊医生ID
     */
    public $DoctorID;
    /**
     * 接诊科室ID
     */
    public $SectionID;
    /**
     * 接诊部门(1:门诊 / 2:住院)
     */
    public $OutpatientOrInpatient;
    /**
     * 患者姓名
     */
    public $PatientName;
    /**
     * 患者性别(1:男 / 2:女 / 3:其他)
     */
    public $PatientSex;
    /**
     * 患者电话
     */
    public $PatientTel;
    /**
     * 患者身份证
     */
    public $PatientID;
    /**
     * 患者病情描述
     */
    public $Disease;
    /**
     * 预约时间
     */
    public $ClinicTime;
    /**
     * 预约状态(0:待患者确认 / 1:患者已确认（已生成转诊单） / 2:患者已取消)
     */
    public $OrderStatus;
    /**
     * 转诊单ID
     */
    public $TransferID;
    /**
     * 添加人
     */
    public $AddUser;
    /**
     * 添加时间
     */
    public $AddTime;
    /**
     * 更新人
     */
    public $ModifyUser;
    /**
     * 更新时间(CURRENT_TIMESTAMP)
     */
    public $ModifyTime;
    /**
     * 删除标识(0:未删除 / 1:已删除)
     */
    public $IsDelete;

    public function getSource()
    {
        return 'OnlineInquiryTransfer';
    }

    public function initialize()
    {
        $this->setConnectionService('InquiryDB');
    }
}