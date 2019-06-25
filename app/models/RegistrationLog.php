<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class RegistrationLog extends Model
{
    //挂号单状态  1=>待支付 2=>已支付 3=>已预约 4=>已取消
    const STATUS_UNPAID = 1;
    const STATUS_PREPAID = 2;
    const STATUS_REGISTRATION = 3;
    const STATUS_CANCEL = 4;
    const STATUS_NAME = ['', '申请挂号', '支付完成', '挂号完成', '已取消'];

    public $Id;

    public $RegistrationId;

    public $Status;

    public $LogTime;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'RegistrationLog';
    }

}