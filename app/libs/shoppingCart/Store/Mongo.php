<?php

namespace App\Libs\ShoppingCart\Store;

use App\Libs\ShoppingCart\Attribute;
use App\Libs\ShoppingCart\Item;
use App\Libs\ShoppingCart\ShoppingCart;
use MongoDB\Database;

class Mongo implements Store
{
    /**
     * @var Database
     */
    protected $db;

    /**
     * @var \JsonMapper
     */
    protected $mapper;

    public function __construct(Database $db)
    {
        $this->db = $db;

        $this->mapper = new \JsonMapper();
    }

    public function store(ShoppingCart $cart)
    {
        $owner_id = $cart->getOwner()->id;
        $filter = [
            'owner_id' => $owner_id
        ];

        $data = [
            'items' => $cart->getCollection()->toArray()
        ];

        $this->db->selectCollection('cart')->updateOne($filter, ['$set' => $data], ['upsert' => true]);
    }

    public function restore(ShoppingCart $cart)
    {
        $record = $this->db->selectCollection('cart')->findOne(['owner_id' => $cart->getOwner()->id]);

        if ($record) {
            foreach ($record->items as $value) {
                $item = new Item();
                $item->product_id = $value->product_id;
                $item->sku_id = $value->sku_id;
                $item->name = $value->name;
                $item->image = $value->image;
                $item->quantity = $value->quantity;
                $item->provider_id = $value->provider_id;

                if ($value->attributes) {
                    foreach ($value->attributes as $attribute) {
                        $item->attributes[] = new Attribute($attribute->name, $attribute->value);
                    }
                }
                $cart->put($item);
            }
        }
    }
}
