<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/7
 * Time: 2:21 PM
 */

namespace App\Libs\illness;

use App\Enums\Status;
use App\Exceptions\ParamException;
use App\Models\OrganizationAndPatient;
use App\Models\OrganizationUser;
use App\Models\Patient;

class RelationOrganizationAndPatient implements RelationInterface
{
    /** @var  OrganizationUser */
    public $doctor;
    /** @var  Patient $patient */
    public $patient;

    public function __construct(OrganizationUser $doctor, Patient $patient)
    {
        $this->doctor = $doctor;
        $this->patient = $patient;
    }


    public function exist(): bool
    {
        $organizationAndPatient = OrganizationAndPatient::findFirst([
            'conditions' => 'OrganizationId=?0 and IDnumber=?1',
            'bind'       => [$this->doctor->UserId, $this->patient->IDnumber],
        ]);
        return $organizationAndPatient ? true : false;
    }

    public function create()
    {
        if (!$this->exist()) {
            $exception = new ParamException(Status::BadRequest);
            try {
                $organizationAndPatient = new OrganizationAndPatient();
                $organizationAndPatient->OrganizationId = $this->doctor->OrganizationId;
                $organizationAndPatient->IDnumber = $this->patient->IDnumber;
                if (!$organizationAndPatient->save()) {
                    $exception->loadFromModel($organizationAndPatient);
                    throw $exception;
                }
            } catch (ParamException $e) {
                throw $e;
            }
        }
    }
}