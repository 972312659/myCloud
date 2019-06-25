<?php

namespace App\Libs\ShoppingCart;

/**
 * Class ItemCollection
 * @package App\Libs\ShoppingCart
 */
class ItemCollection implements \Iterator, \JsonSerializable
{
    /**
     * @var int
     */
    private $position = 0;

    /**
     * @var Item[]
     */
    protected $items = [];

    /**
     * @param Item $item
     * @return $this
     */
    public function put(Item $item)
    {
        $this->items[$item->sku_id] = $item;
        return $this;
    }

    /**
     * @param $sku_id
     * @return $this
     */
    public function remove($sku_id)
    {
        unset($this->items[$sku_id]);
        return $this;
    }

    /**
     * @param $sku_id
     * @return bool
     */
    public function has($sku_id)
    {
        return isset($this->items[$sku_id]);
    }

    /**
     * @param $id
     * @return Item|null
     */
    public function get($sku_id)
    {
        return $this->has($sku_id) ? $this->items[$sku_id] : null;
    }

    /**
     * Return the current element
     * @link https://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     * @since 5.0.0
     */
    public function current()
    {
        return $this->items[$this->position];
    }

    /**
     * Move forward to next element
     * @link https://php.net/manual/en/iterator.next.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function next()
    {
        ++$this->position;
    }

    /**
     * Return the key of the current element
     * @link https://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     * @since 5.0.0
     */
    public function key()
    {
        return $this->position;
    }

    /**
     * Checks if current position is valid
     * @link https://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     * Returns true on success or false on failure.
     * @since 5.0.0
     */
    public function valid()
    {
        return $this->has($this->position);
    }

    /**
     * Rewind the Iterator to the first element
     * @link https://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function rewind()
    {
        $this->position = 0;
    }

    public function toArray()
    {
        return array_values(array_map(function (Item $item) {
            return [
                'sku_id'      => $item->sku_id,
                'product_id' => $item->product_id,
                'name'    => $item->name,
                'quantity' => $item->quantity,
                'attributes' => $item->attributes,
                'provider_id' => $item->provider_id
            ];
        }, $this->items));
    }

    /**
     * Specify data which should be serialized to JSON
     * @link https://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return json_encode($this->toArray());
    }

    /**
     * @return Item[]
     */
    public function getItems()
    {
        return $this->items;
    }

    /**
     * @return $this
     */
    public function flush()
    {
        $this->items = [];
        return $this;
    }
}
