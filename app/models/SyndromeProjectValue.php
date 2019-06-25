<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/12
 * Time: 2:25 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class SyndromeProjectValue extends Model
{
    public $Id;

    public $SyndromeId;

    public $SyndromeProjectId;

    public $Value;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'SyndromeProjectValue';
    }
}