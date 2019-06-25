<?php

namespace App\Libs\ShoppingCart;

class Item
{
    /**
     * @var int
     */
    public $product_id;

    /**
     * @var int
     */
    public $sku_id;

    /**
     * @var int
     */
    public $provider_id;

    /**
     * @var string
     */
    public $name;

    /**
     * @var int
     */
    public $quantity;

    /**
     * @var Attribute[]
     */
    public $attributes = [];
}
