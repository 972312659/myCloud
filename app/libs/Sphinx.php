<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/31
 * Time: 下午3:36
 */

namespace App\Libs;


class Sphinx
{
    private $sphinx;            //sphinx

    private $tableName = '';    //表名

    private $query = [];        //query语句

    private $error = '';        //错误信息

    private $time = 0;          //第几个andWhere

    private $columns = null;    //字段名字

    private $orderBy = null;    //排序

    private $limit = null;      //限制条件

    private $where = false;

    public function __construct(\PDO $sphinx, string $tableName)
    {
        $this->sphinx = $sphinx;
        $this->tableName = $tableName;
    }

    /**
     * 添加一条数据 更新索引
     * @param array $data
     * @return boolean
     */
    public function save(array $data)
    {
        $columns = array_column($this->sphinx->query("desc `{$this->tableName}`")->fetchAll(), 'Field');
        $fields = [];
        $values = [];
        if (empty($data['id']) && !isset($data['id'])) {
            $this->error = '缺少主键,sphinx添加失败';
            return false;
        }
        foreach ($columns as $column) {
            $fields[] = $column;
            if (is_array($data[$column])) {
                $values[] = sprintf('(%s)', implode(',', $data[$column]));
            } elseif (is_float($data[$column])) {
                $data[$column] = (!empty($data[$column]) && isset($data[$column]) ? $data[$column] : null);
                $values[] = sprintf("%s", $data[$column]);
            } elseif (is_int($data[$column])) {
                $data[$column] = (!empty($data[$column]) && isset($data[$column]) ? $data[$column] : 0);
                $values[] = sprintf("%s", $data[$column]);
            } else {
                $data[$column] = (!empty($data[$column]) && isset($data[$column]) ? $data[$column] : null);
                $values[] = sprintf("'%s'", $data[$column]);
            }
        }
        $fields = sprintf("(%s)", implode(',', $fields));
        $values = implode(',', $values);
        $query = "replace into `{$this->tableName}`{$fields} VALUES ({$values})";
        $result = $this->sphinx->prepare($query)->execute();
        if (!$result) {
            $this->error = 'sphinx添加失败';
            return false;
        }
        return true;
    }

    /**
     * 更新字段
     * @param array $data
     * @param int $id
     * @return bool
     */
    public function update(array $data, int $id)
    {
        $one = $this->sphinx->query("select id from `{$this->tableName}` WHERE id={$id}")->fetch();
        $columns = array_column($this->sphinx->query("desc `{$this->tableName}`")->fetchAll(), 'Field');
        $values = [];
        if ($one) {
            foreach ($data as $k => $v) {
                if ($k != 'id') {
                    $item = $v;
                    if (is_array($v)) {
                        $v = sprintf('(%s)', implode(',', $v));
                    }
                    if (in_array($k, $columns)) {
                        if (is_int($v) || is_float($v) || is_array($item)) {
                            $v = $v ?: 0;
                            $values[] = sprintf('%s=%s', $k, $v);
                        } else {
                            $v = $v ?: '';
                            $values[] = sprintf("%s='%s'", $k, $v);
                        }
                    }
                }
            }
            $values = implode(',', $values);
            $query = "update `{$this->tableName}` set {$values} WHERE id={$id}";
            $result = $this->sphinx->prepare($query)->execute();
            if (!$result) {
                $this->error = 'sphinx修改失败';
                return false;
            }
        } else {
            $this->error = 'id对应的数据不存在';
            return false;
        }
        return true;
    }

    /**
     * 删除一条数据
     * @param int $id
     * @return boolean
     */
    public function delete(int $id)
    {

        if ($id) {
            $result = $this->sphinx->prepare("delete from `{$this->tableName}` WHERE id={$id}")->execute();
            if (!$result) {
                $this->error = '未删除成功';
                return false;
            }
        } else {
            $this->error = '未执行操作,参数错误';
            return false;
        }
        return true;
    }

    public function columns($columns)
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * match  rt_field
     * @param $column 字段名
     * @param $value  值
     * @return mixed
     */
    public function match($value, $column = null)
    {
        if ($column) {
            $condition = sprintf("'@%s %s'", $column, $value);
        } else {
            $condition = sprintf("'%s'", $value);
        }
        if ($this->where) {
            $this->query['match'] = "and MATCH ({$condition})";
        } else {
            $this->query['match'] = "WHERE MATCH ({$condition}) limit 100";
        }
        return $this;
    }

    /**
     * @param null $column   字段名
     * @param string $symbol 判断符号
     * @param $value
     * @return $this
     */
    public function where(string $symbol, $value, $column = null)
    {
        if ($column) {
            if (is_string($value)) {
                $condition = sprintf("%s%s'%s'", $column, $symbol, $value);
            } elseif (is_int($value) || is_float($value)) {
                $condition = sprintf("%s%s%s", $column, $symbol, $value);
            } elseif (is_array($value)) {
                $value = '(' . implode(',', $value) . ')';
                $condition = sprintf("%s %s %s", $column, $symbol, $value);
            }
        } else {
            $condition = sprintf("id%s%s", $symbol, (int)$value);
        }
        $this->query['where'] = "WHERE {$condition}";
        $this->where = true;
        return $this;
    }

    /**
     * @param $column
     * @param $symbol
     * @param $value
     * @return $this
     */
    public function andWhere(string $symbol, $value, string $column)
    {
        $this->time += 1;
        if (is_string($value)) {
            $condition = sprintf("%s%s'%s'", $column, $symbol, $value);
        } elseif (is_int($value)) {
            $condition = sprintf("%s%s%s", $column, $symbol, $value);
        } elseif (is_array($value)) {
            $value = $value ?: [-1];
            $value = '(' . implode(',', $value) . ')';
            $condition = sprintf("%s %s %s", $column, $symbol, $value);
        }
        $this->query['andWhere' . $this->time] = "and {$condition}";
        $this->where = true;
        return $this;
    }

    /**
     * @return mixed|null
     */
    public function fetch()
    {
        $condition = implode(' ', $this->query);
        if ($this->columns === null) {
            $query = "select * from `{$this->tableName}` {$condition}";
        } else {
            $query = "select {$this->columns} from `{$this->tableName}` {$condition}";
        }
        if ($this->orderBy !== null) {
            $query .= " $this->orderBy";
        }
        if ($this->limit !== null) {
            $query .= " $this->limit";
        }
        $result = $this->sphinx->query($query)->fetch();
        return $result ? $result : null;
    }

    /**
     * @return array|null
     */
    public function fetchAll()
    {
        $condition = implode(' ', $this->query);
        if ($this->columns === null) {
            $query = "select * from `{$this->tableName}` {$condition}";
        } else {
            $query = "select {$this->columns} from `{$this->tableName}` {$condition}";
        }
        if ($this->orderBy !== null) {
            $query .= " $this->orderBy";
        }
        if ($this->limit !== null) {
            $query .= " $this->limit";
        }
        $result = $this->sphinx->query($query)->fetchAll();
        return $result ? $result : null;
    }

    /**
     * 得分排序
     * @param int $start    开始
     * @param int $pageSize 长度
     * @return array|null   倒叙
     */
    public function score($start = 10, $pageSize = 10)
    {
        $query = "select id from {$this->tableName} where ismain=1 order by score desc limit $start,$pageSize";
        $result = $this->sphinx->query($query)->fetchAll();
        return $result ? $result : null;
    }

    /**
     * 通过参数条件  距离排序
     * @param $lat                纬度
     * @param $lng                经度
     * @param $columns            array      其他字段
     * @return array|null         升叙
     */
    public function distance($lat, $lng, $columns = [])
    {
        $this->columns = "id,GEODIST(lat, lng, {$lat}, {$lng}, {in=deg, out=km}) as dist";
        if ($columns) {
            foreach ($columns as $column) {
                $this->columns .= ',' . $column;
            }
        }
        return $this;
    }

    /**
     * 综合排序公式 :
     * 0.3*(log(dist+1)-log(minDist+1))/(log(maxDist+1)-log(minDist+1))+0.5*(log(score+1)-log(minScore+1))/(log(maxScore+1)+log(minScore+1))+0.2*(log(transferAmount+1)-log(minTransferAmount+1))/(log(maxTransferAmount+1)-log(minTransferAmount+1))
     * @param $lat                纬度
     * @param $lng                经度
     * @return array|null         降叙
     */
    public function comprehensive($lat, $lng)
    {
        $minTransferAmount = LOG($this->sphinx->query("select transferamount from {$this->tableName} where ismain=1 order by transferamount asc limit 1")->fetch()['transferamount'] + 1);
        $maxTransferAmount = LOG($this->sphinx->query("select transferamount from {$this->tableName} where ismain=1 order by transferamount desc limit 1")->fetch()['transferamount'] + 1);
        $minScore = LOG($this->sphinx->query("select score from {$this->tableName} where ismain=1 order by score asc limit 1")->fetch()['score'] + 1);
        $maxScore = LOG($this->sphinx->query("select score from {$this->tableName} where ismain=1 order by score desc limit 1")->fetch()['score'] + 1);
        $minDistance = LOG($this->sphinx->query("select GEODIST(lat, lng, {$lat}, {$lng}, {in=deg, out=km}) as dist from {$this->tableName} where ismain=1 order by dist asc limit 1")->fetch()['dist'] + 1);
        $maxDistance = LOG($this->sphinx->query("select GEODIST(lat, lng, {$lat}, {$lng}, {in=deg, out=km}) as dist from {$this->tableName} where ismain=1 order by dist desc limit 1")->fetch()['dist'] + 1);
        $this->columns = "id,((LOG2(GEODIST(lat, lng, {$lat}, {$lng}, {in=deg, out=km})+1)-$minDistance)*0.3/($maxDistance-$minDistance)+(LOG2(score+1)-$minScore)*0.5/($maxScore-$minScore)+(LOG2(transferamount+1)-$minTransferAmount)*0.2/($maxTransferAmount-$minTransferAmount)) as weight";
        return $this;
    }

    public function orderBy($condition)
    {
        $this->orderBy = 'order by ' . $condition;
        return $this;
    }

    public function limit(int $page, int $pageSize = 0)
    {
        if ($pageSize) {
            $start = ($page - 1) * $pageSize;
            $this->limit = "limit $start,$pageSize";
        } else {
            $this->limit = "limit $page";
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

}