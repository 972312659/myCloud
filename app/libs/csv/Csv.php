<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/16
 * Time: 下午2:34
 */

namespace App\Libs\csv;

use Phalcon\Mvc\Model\Query\Builder;

class Csv
{
    /**
     * @var Builder
     */
    protected $builder;

    /**
     * Csv constructor.
     * @param Builder $builder
     */
    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * @param string $filename
     * @param string $title
     * @param array $rows
     * @param array $columns
     */
    public function export(string $filename, string $title, array $rows, array $columns)
    {
        $str = '';
        $header = mb_convert_encoding($title . "\n", "UTF-8", "auto");
        $count = count($columns);
        foreach ($rows as $row) {
            foreach ($columns as $key => $column) {
                $value = is_int($row[$column]) ? $row[$column] : mb_convert_encoding($row[$column], "UTF-8", "auto");
                if ($key + 1 < $count) {
                    $str .= $value . "\t,";
                } else {
                    $str .= $value . "\t\n";
                }
            }
        }
        $data = $header . $str;
        $filename = $filename . '.csv';
        $this->writeDataToCsv($filename, $data);//将数据导出
    }

    /**
     * 输出CSV文件
     * $filename:文件名称 $data:要写入的数据
     * @param string $filename
     * @param string $data
     */
    public function writeDataToCsv(string $filename, string $data)
    {
        header("Content-type:text/csv");
        header("Content-Disposition:attachment;filename=" . $filename);
        header('Cache-Control:must-revalidate,post-check=0,pre-check=0');
        header('Expires:0');
        header('Pragma:public');
        echo "\xEF\xBB\xBF";
        echo $data;
        exit();
    }
}