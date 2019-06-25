<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class IllnessForDoctorIdentification extends Model
{
    public $UserId;

    public $IllnessId;

    public $Created;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'IllnessForDoctorIdentification';
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }

    public function afterCreate()
    {
        $this->log(IllnessForDoctorIdentificationLog::STATUS_CREATE);
    }

    public function afterDelete()
    {
        $this->log(IllnessForDoctorIdentificationLog::STATUS_DELETE);
    }

    public function log(int $status)
    {
        /** @var IllnessForDoctorIdentificationLog $log */
        $log = new IllnessForDoctorIdentificationLog();
        $log->UserId = $this->UserId;
        $log->IllnessId = $this->IllnessId;
        $log->Status = $status;
        $log->StaffId = $this->getDI()->getShared('session')->get('auth')['Id'];
        $log->StaffName = $this->getDI()->getShared('session')->get('auth')['Name'];
        $log->save();
    }
}