<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/17
 * Time: 2:50 PM
 */

namespace App\Libs\sphinx\model;


use App\Libs\Sphinx;
use App\Libs\sphinx\TableName;
use Phalcon\Di\FactoryDefault;

class Transfer
{
    public $id;
    /**
     * 患者姓名
     * @var string
     */
    public $patientname;
    /**
     * 医生姓名
     * @var string
     */
    public $doctorname;
    /**
     * 医院id
     * @var int
     */
    public $hospitalid;

    /**
     * @param $transfer transfer对象
     */
    public function save($transfer)
    {
        $sphinx = new Sphinx(FactoryDefault::getDefault()->get('sphinx'), TableName::Transfer);
        $sphinx_data['id'] = $transfer->Id;
        $sphinx_data['patientname'] = $transfer->PatientName;
        $sphinx_data['doctorname'] = $transfer->AcceptDoctorName;
        $sphinx_data['hospitalid'] = $transfer->AcceptOrganizationId;
        if ($sphinx->where('=', (int)$transfer->Id, 'id')->fetch()) {
            $sphinx->update($sphinx_data, $transfer->Id);
        } else {
            $sphinx->save($sphinx_data);
        }
    }
}