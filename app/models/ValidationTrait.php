<?php

namespace App\Models;

trait ValidationTrait
{
    public function getMessages($filter = null)
    {
        return array_map(function ($item) {
            return [
                'field'   => $item->getField(),
                'message' => $item->getMessage(),
            ];
        }, parent::getMessages());
    }
}
