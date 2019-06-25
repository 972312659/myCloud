<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/7
 * Time: 2:22 PM
 */

namespace App\Libs\illness;

use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Models\Illness;
use App\Models\OrganizationUser;
use App\Models\Patient;
use App\Models\PatientAndIllness;

class RelationDoctorAndPatient implements RelationInterface
{
    /** @var  Patient */
    public $patient;
    /** @var OrganizationUser */
    public $doctor;
    /** @var Illness */
    public $illness;

    protected $isFileCreate = false;

    /**
     * RelationDoctorAndPatient constructor.
     * @param OrganizationUser $doctor
     * @param Patient $patient
     * @param Illness $illness
     * @param bool $isFileCreate 是否为初次建档
     */
    public function __construct(OrganizationUser $doctor, Patient $patient, Illness $illness, bool $isFileCreate = false)
    {
        $this->doctor = $doctor;
        $this->patient = $patient;
        $this->illness = $illness;
        $this->isFileCreate = $isFileCreate;
    }

    public function exist(): bool
    {
        $patientAndIllness = PatientAndIllness::findFirst([
            'conditions' => 'OrganizationId=?0 and IDnumber=?1 and DoctorId=?2 and IllnessId=?3',
            'bind'       => [$this->doctor->OrganizationId, $this->patient->IDnumber, $this->doctor->UserId, $this->illness->Id],
        ]);
        return $patientAndIllness ? true : false;
    }

    public function create()
    {
        if (!$this->exist()) {
            $exception = new ParamException(Status::BadRequest);
            try {
                $patientAndIllness = new PatientAndIllness();
                $patientAndIllness->OrganizationId = $this->doctor->OrganizationId;
                $patientAndIllness->IDnumber = $this->patient->IDnumber;
                $patientAndIllness->DoctorId = $this->doctor->UserId;
                $patientAndIllness->IllnessId = $this->illness->Id;
                $patientAndIllness->IsFileCreate = $this->isFileCreate ? PatientAndIllness::IsFileCreate_Yes : PatientAndIllness::IsFileCreate_No;
                if (!$patientAndIllness->save()) {
                    $exception->loadFromModel($patientAndIllness);
                    throw $exception;
                }
            } catch (ParamException $e) {
                throw $e;
            }
        }
    }
}