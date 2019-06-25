<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class StaffShareLog extends Model
{
    public $Id;

    public $StaffId;

    public $ApplyId;

    public $StatusBefore;

    public $StatusAfter;

    public $Created;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'StaffShareLog';
    }

}