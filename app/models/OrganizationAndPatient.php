<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/6
 * Time: 2:33 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class OrganizationAndPatient extends Model
{
    public $OrganizationId;

    public $IDnumber;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'OrganizationAndPatient';
    }
}