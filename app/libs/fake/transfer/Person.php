<?php

namespace App\Libs\fake\transfer;

class Person
{
    public $name;

    public $gender;

    public $id_card;

    public $address;

    public $birthday;

    public $phone;

    public function getAge()
    {
        return (int)(date('Y') - substr($this->birthday, 0, 4));
    }
}
