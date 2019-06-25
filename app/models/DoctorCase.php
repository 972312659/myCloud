<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;

class DoctorCase extends Model
{
    public $Id;

    public $OrganizationId;

    public $UserId;

    public $Title;

    public $Image;

    public $Content;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'DoctorCase';
    }

    public function validation()
    {
        $validate = new Validation();
        $validate->add('Title',
            new PresenceOf(['message' => '案例标题不能为空'])
        );
        $validate->add('Image',
            new PresenceOf(['message' => '案例图片不能为空'])
        );
        $validate->add('Content',
            new PresenceOf(['message' => '案例详情不能为空'])
        );
        return $this->validate($validate);
    }
}