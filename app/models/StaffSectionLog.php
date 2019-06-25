<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/30
 * Time: 下午2:15
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class StaffSectionLog extends Model
{
    //操作方式
    const CREATE = 1;
    const UPDATE = 2;
    const DELETE = 3;

    public $Id;

    public $StaffId;

    public $SectionId;

    public $Created;

    public $Operated;

    public function initialize()
    {

    }

    public function getSource()
    {
        return 'StaffSectionLog';
    }

}