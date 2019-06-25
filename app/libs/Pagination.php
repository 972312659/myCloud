<?php

namespace App\Libs;

use Phalcon\Http\Request;
use Phalcon\Mvc\Model\Criteria;
use Phalcon\Mvc\Model\Query\BuilderInterface;
use Phalcon\Paginator\Adapter\QueryBuilder;

class Pagination
{
    public $Page;

    public $PageSize;

//    public $Columns;

    /**
     * @var Request
     */
    private $request;


    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->Page = $request->get('Page', 'absint', 1);
        $this->PageSize = $request->get('PageSize', 'absint', 10);
    }

    public function find(BuilderInterface $builder)
    {
        $builder = new QueryBuilder([
            'builder' => $builder,
            'limit' => $this->PageSize,
            'page' => $this->Page
        ]);

        $paginate =  $builder->getPaginate();

        return [
            'Data' => $paginate->items ,
            'PageInfo' => [
                'TotalPage' => $paginate->total_pages,
                'Page'      => $paginate->current,
                'Count'     => $paginate->total_items,
                'PageSize' => $paginate->limit
            ]
        ];
    }

//    private function getColumns($items)
//    {
//        return $this->Columns ? array_map(function ($item) {
//            return array_filter($item, function ($key) {
//                return in_array($key, $this->Columns);
//            }, ARRAY_FILTER_USE_KEY);
//        }, $items) : $items;
//    }
}
