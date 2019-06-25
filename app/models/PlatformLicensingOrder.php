<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/25
 * Time: 上午11:34
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class PlatformLicensingOrder extends Model
{
    public $Id;

    public $PlatformLicensingId;

    public $Name;

    public $Price;

    public $Durations;

    public $OrganizationId;

    public $OrganizationName;

    public $UserId;

    public $UserName;

    public $Created;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
    }

    public function getSource()
    {
        return 'PlatformLicensingOrder';
    }
}