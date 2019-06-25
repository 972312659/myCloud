<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/5/5
 * Time: 下午3:17
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class PlatformLicensingLog extends Model
{
    public $Id;

    public $PlatformLicensingId;

    public $StaffId;

    public $StaffName;

    public $LogTime;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'PlatformLicensingLog';
    }
}