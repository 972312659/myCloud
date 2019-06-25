<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class ZealSlaveReport extends Model
{
    public $Id;

    public $OrganizationId;

    public $HospitalId;

    public $Date;

    public $TransferDay;

    public $TransferMonth;

    public $TransferYear;

    public $ShareDay;

    public $ShareMonth;

    public $ShareYear;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'ZealSlaveReport';
    }

}