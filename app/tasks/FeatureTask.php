<?php

use Phalcon\Cli\Task;

class FeatureTask extends Task
{
    /**
     * 作为2019-02-22发版本用
     */
    public function convertAction()
    {
        // $data = [
        //     'user'            => [35],
        //     'apply'           => [24, 42],
        //     'article'         => [21],
        //     'combo'           => [25],
        //     'evaluate'        => [39],
        //     'organization'    => [7],
        //     'permission'      => [34],
        //     'rule'            => [6, 8],
        //     'section'         => [19],
        //     'transfer'        => [38],
        //     'bill'            => [27, 28, 29],
        //     'report'          => [44],
        //     'sms'             => [36],
        //     'remote'          => [40],
        //     'forward'         => [26],
        //     'financial'       => [31],
        //     'cashier'         => [32],
        //     'doctor'          => [20],
        //     'package'         => [25],
        //     'machine'         => [41],
        //     'supplier'        => [9],
        //     'supplierapply'   => [9],
        //     'supplierseciton' => [10],
        //     'supplierpackage' => [10],
        //     'spread'          => [17],
        //     'banner'          => [18],
        //     'task'            => [36],
        //     'billincome'      => [29],
        //     'billpay'         => [29],
        //     'transfercount'   => [44],
        // ];
        //29=[51,52]
        // $data = [
        //     'user'            => [35],
        //     'apply'           => [24, 42],
        //     'article'         => [21],
        //     'combo'           => [25],
        //     'evaluate'        => [39],
        //     'organization'    => [7],
        //     'permission'      => [34],
        //     'rule'            => [6, 8],
        //     'section'         => [19],
        //     'transfer'        => [38],
        //     'bill'            => [27, 28, 51, 52],
        //     'report'          => [72],
        //     'sms'             => [36],
        //     'remote'          => [40],
        //     'forward'         => [26],
        //     'financial'       => [31],
        //     'cashier'         => [32],
        //     'doctor'          => [20],
        //     'package'         => [49],
        //     'machine'         => [41],
        //     'supplier'        => [10],
        //     'supplierapply'   => [9],
        //     'supplierseciton' => [45],
        //     'supplierpackage' => [46],
        //     'spread'          => [17],
        //     'banner'          => [18],
        //     'task'            => [36],
        //     'billincome'      => [51, 52],
        //     'billpay'         => [51, 52],
        //     'transfercount'   => [71],
        //     'queueup'         => [50],
        // ];
        // // 查询旧的resource的对应permissionId
        // $sql = 'SELECT Resource, Id FROM Permission WHERE Visiable=1';
        // $query = $this->db->query($sql);
        // $mapper = array_column($query->fetchAll(), 'Id', 'Resource');
        // // 查询permissionId已经做过哪些关联
        // $sql = 'SELECT PermissionId, RoleId FROM RolePermission';
        // $query = $this->db->query($sql);
        // $result = $query->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_GROUP);
        // foreach ($data as $resource => $datum) {
        //     if (!isset($mapper[$resource])) {
        //         echo "$resource 资源不存在\n";
        //         continue;
        //     }
        //     $permission = $mapper[$resource];
        //     if (!isset($result[$permission])) {
        //         echo "$permission 没有人绑定过\n";
        //         continue;
        //     }
        //     echo '角色id：' . implode(',', $result[$permission]) . "\t有权限：" . implode(',', $datum) . "\n";
        //     foreach ($result[$permission] as $roleId) {
        //         foreach ($datum as $featureId) {
        //             echo "插入数据RoleId: $roleId, FeatureId: $featureId \n";
        //             $sql = 'REPLACE INTO RoleFeature (RoleId, FeatureId) VALUES (?,?)';
        //             $r = $this->db->execute($sql, [$roleId, $featureId]);
        //             if (!$r) {
        //                 var_dump($sql, $r);
        //             }
        //         }
        //     }
        // }
    }
}