<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class PatientAndIllnessFileCreated extends Model
{
    public $IDnumber;

    public $IllnessId;

    public $FileCreatedAttributeId;

    public $Value;

    public $Created;

    public $Updated;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'PatientAndIllnessFileCreated';
    }


    public function beforeCreate()
    {
        $this->Created = time();
    }

    public function beforeUpdate()
    {
        $this->Updated = time();
    }
}