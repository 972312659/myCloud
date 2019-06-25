<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use App\Validators\IDCardNo;
use App\Validators\Mobile;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Numericality;
use Phalcon\Validation\Validator\Uniqueness;
use Phalcon\Validation\Validator\Regex;
use Phalcon\Validation\Validator\StringLength;

class Patient extends Model
{
    //性别 1=>男 2=>女
    const GENDER_MALE = 1;
    const GENDER_LADY = 2;
    const GENDER_NAME = [1 => '男', 2 => '女'];

    public $IDnumber;

    public $Name;

    public $Phone;

    public $Gender;

    public $Height;

    public $Weight;

    public $MedicalHistory;

    public $Created;

    public $Updated;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'Patient';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('Name', [
            new PresenceOf(['message' => '姓名不能为空']),
            new StringLength(["min" => 0, "max" => 30, "messageMaximum" => '最长不超过30']),
        ]);
        $validator->rules([/*'Height',*/
            'Weight'], [
            new PresenceOf([
                'message' => [
                    // 'Height' => '身高不能为空',
                    'Weight' => '体重不能为空',
                ],
            ]),
            new Numericality([
                'message' => [
                    // 'Height' => '身高输入错误',
                    'Weight' => '体重输入错误',
                ],
            ]),
        ]);
        $validator->rules('IDnumber', [
            new PresenceOf(['message' => '身份证不能为空']),
            new Uniqueness(['message' => '该身份证所属患者已存在，不能重复创建']),
            new IDCardNo(["message" => '最大不超过50']),
        ]);
        $validator->rules('Gender', [
            new PresenceOf(['message' => '性别不能为空']),
            new Regex([
                'pattern' => "/^[1-3]$/",
                'message' => '性别错误',
            ]),
        ]);

        $validator->rules('Phone', [
            new PresenceOf(['message' => '电话不能为空']),
            new Mobile(['message' => '电话号码错误']),
            new Uniqueness(['message' => '电话号码已被使用，不能重复创建']),
        ]);

        return $this->validate($validator);
    }

    public function beforeCreate()
    {
        $this->Created = time();
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (count($changed)) {
            if (in_array('Name', $changed) || in_array('Phone', $changed) || in_array('Height', $changed) || in_array('Weight', $changed) || in_array('MedicalHistory', $changed)) {
                $this->Updated = time();
            }
        }
    }
}