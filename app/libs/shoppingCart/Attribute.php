<?php

namespace App\Libs\ShoppingCart;

class Attribute
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var string
     */
    public $value;

    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }
}
