<?php

namespace App\Libs\ShoppingCart\Store;

use App\Libs\ShoppingCart\ShoppingCart;

interface Store
{
    public function store(ShoppingCart $cart);

    public function restore(ShoppingCart $cart);
}
