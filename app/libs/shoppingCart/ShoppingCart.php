<?php

namespace App\Libs\ShoppingCart;

use App\Libs\ShoppingCart\Store\Store;

/**
 * Class ShoppingCart
 * @package App\Libs\ShoppingCart
 */
class ShoppingCart
{
    /**
     * @var Owner
     */
    protected $owner;

    /**
     * @var ItemCollection
     */
    protected $collection;

    /**
     * @var Store
     */
    protected $store;

    public function __construct(Store $store)
    {
        $this->collection = new ItemCollection();

        $this->store = $store;
    }

    /**
     * @return string
     */
    protected function getKey()
    {
        return 'cart';
    }


    public function restore(Owner $owner)
    {
        $this->owner = $owner;
        $this->store->restore($this);
    }

    /**
     * 保存当前购物车
     *
     * @return $this
     */
    public function store()
    {
        $this->store->store($this);

        return $this;
    }

    /**
     * 添加物品
     *
     * @param Item ...$items
     * @return $this
     */
    public function put(Item ...$items)
    {
        foreach ($items as $item) {
            $this->getCollection()->put($item);
        }

        return $this;
    }

    /**
     * @param $sku_id
     * @return $this
     */
    public function remove($sku_id)
    {
        $this->getCollection()->remove($sku_id);
        return $this;
    }

    /**
     * 清空购物车
     *
     * @return $this
     */
    public function flush()
    {
        $this->collection = new ItemCollection();

        return $this;
    }

    /**
     * @param Item $item
     * @param int $num
     * @return $this
     */
    public function increment(Item $item, int $num = 1)
    {
        if ($this->getCollection()->has($item->sku_id)) {
            $item = $this->getCollection()->get($item->sku_id);
            $item->quantity += $num;
        }

        $this->put($item);

        return $this;
    }

    /**
     * @param $sku_id
     * @return bool
     */
    public function has($sku_id)
    {
        return $this->getCollection()->has($sku_id);
    }

    /**
     * @param $sku_id
     * @return Item|null
     */
    public function get($sku_id)
    {
        return $this->getCollection()->get($sku_id);
    }

//    /**
//     * @param $sku_id
//     * @param $num
//     * @return $this
//     */
//    public function decrement($sku_id, $num)
//    {
//        if ($this->getCollection()->has($sku_id)) {
//            $item = $this->getCollection()->get($sku_id);
//            if ($item->quantity <= $num) {
//                $this->getCollection()->remove($item->sku_id);
//            } else {
//                $item->quantity -= $num;
//                $this->getCollection()->put($item);
//            }
//        }
//
//        return $this;
//    }

    /**
     * @return Owner
     */
    public function getOwner(): Owner
    {
        return $this->owner;
    }

    /**
     * @return ItemCollection
     */
    public function getCollection(): ItemCollection
    {
        return $this->collection;
    }
}
