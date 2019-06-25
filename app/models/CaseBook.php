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

class CaseBook extends Model
{
    //推荐的资料方案有无变化 0=>有变化 1=>无变化
    const Changed_No = 0;
    const Changed_Yes = 1;

    public $Id;

    public $IDnumber;

    public $IllnessId;

    public $SyndromeId;

    public $OrganizationId;

    public $OrganizationName;

    public $DoctorId;

    public $DoctorName;

    public $Content;

    public $Changed;

    public $Created;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'CaseBook';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Content', [
            new PresenceOf(['message' => '内容不能为空']),
        ]);
        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }
}