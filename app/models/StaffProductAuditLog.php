<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class StaffProductAuditLog extends Model
{
    public $Id;

    public $ProductId;

    public $Audit;

    public $StaffId;

    public $StaffName;

    public $LogTime;

    public $MongoId;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'StaffProductAuditLog';
    }

}