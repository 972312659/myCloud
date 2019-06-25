<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/17
 * Time: 3:13 PM
 */

use Phalcon\Cli\Task;

class SphinxTask extends Task
{
    public function transferAction()
    {
        $transfers = \App\Models\Transfer::find([
            'conditions' => 'IsFake=?0',
            'bind'       => [0],
        ]);
        $sphinxTransfer = new \App\Libs\sphinx\model\Transfer();
        foreach ($transfers as $transfer) {
            $sphinxTransfer->save($transfer);
        }
        echo 'ok' . PHP_EOL;
    }

    public function productAction()
    {
        $products = \App\Models\Product::find();
        $productSphinx = new \App\Libs\sphinx\model\Product(new \App\Libs\Sphinx($this->getDI()->getShared('sphinx'), \App\Libs\sphinx\TableName::Product));
        $mapper = new \App\Libs\product\Mapper($this->getDI()->getShared(\App\Enums\Mongo::database));
        if (count($products->toArray())) {
            foreach ($products as $product) {
                /** @var \App\Models\Product $product */
                $mongoId = $mapper->getMongoId($product->Id);
                $productSphinx->save($product, $mongoId ?: '');
                // if (!$productSphinx->save($product, $mongoId ?: '')) {
                //     throw new LogicException('sphinx缓存错误', \App\Enums\Status::BadRequest);
                // }
            }
        }
        echo 'ok' . PHP_EOL;
    }
}