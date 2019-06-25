<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/17
 * Time: 1:22 PM
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class UserSignature extends Model
{
    public $UserId;

    public $Password;

    public $AddUser;

    public $AddTime;

    public $ModifyUser;

    public $ModifyTime;

    public $IsDelete;

    public function initialize()
    {
        $this->keepSnapshots(true);
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'UserSignature';
    }

    public function beforeCreate()
    {
        $userName = $this->getDI()->getShared('session')->get('auth')['Name'];
        $this->AddTime = date('Y-m-d H:i:s');
        $this->AddUser = $userName;
        $this->ModifyUser = $userName;
        $this->IsDelete = 0;
    }

    public function beforeUpdate()
    {
        $changed = (array)$this->getChangedFields();
        if (count($changed)) {
            $this->ModifyUser = $this->getDI()->getShared('session')->get('auth')['Name'];
        }
    }
}