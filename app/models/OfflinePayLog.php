<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/26
 * Time: 上午11:01
 */

namespace App\Models;

use App\Validators\Mobile;
use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;

class OfflinePayLog extends Model
{
    public $Id;

    public $OfflinePayId;

    public $Status;

    public $UserId;

    public $StaffId;

    public $LogTime;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'OfflinePayLog';
    }

    public function beforeCreate()
    {
        $this->LogTime = time();
    }
}