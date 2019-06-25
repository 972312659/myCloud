<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Regex;

class PatientAndIllness extends Model
{
    //是否是建档者 0=>不是 1=>是
    const IsFileCreate_No = 0;
    const IsFileCreate_Yes = 1;

    public $OrganizationId;

    public $IDnumber;

    public $IllnessId;

    public $DoctorId;

    public $Created;

    public $IsFileCreate;

    public $Updated;

    public function initialize()
    {
        $this->belongsTo('OrganizationId', Organization::class, 'Id', ['alias' => 'Organization']);
    }

    public function getSource()
    {
        return 'PatientAndIllness';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('IsFileCreate', [
            new PresenceOf(['message' => '是否为建档者为空']),
            new Regex([
                'pattern' => "/^[0-1]$/",
                'message' => '是否为建档者错误',
            ]),
        ]);
        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }
}