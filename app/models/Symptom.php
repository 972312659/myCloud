<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/12/12
 * Time: 2:25 PM
 */

namespace App\Models;


use Phalcon\Mvc\Model;

class Symptom extends Model
{
    //0=>不必填 1=>必填
    const IsRequired_No = 0;
    const IsRequired_Yes = 1;

    public $Id;

    public $SyndromeId;

    public $Describe;
    
    public $Image;

    public $Score;

    public $Level;

    public $Pid;

    public $IsRequired;

    public function initialize()
    {
    }

    public function getSource()
    {
        return 'Symptom';
    }
}