<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/4
 * Time: 下午3:47
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class ComboOrderLog extends Model
{
    const STATUS_NAME = [1 => '创建套餐单', 2 => '支付成功', 3 => '已到院', 4 => '已关闭'];

    public $Id;

    public $OrganizationId;

    public $UserId;

    public $UserName;

    public $ComboOrderId;

    public $Status;

    public $LogTime;

    public $Content;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'ComboOrderLog';
    }
}