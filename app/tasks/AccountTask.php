<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/7/6
 * Time: 下午3:08
 */

use Phalcon\Cli\Task;
use App\Models\Bill;

class AccountTask extends Task
{
    /**
     * 更新bill表中Balance
     */
    public function updateBillBalanceAction()
    {
        $bills = Bill::find();
        $balance = [];
        $this->db->begin();
        foreach ($bills as $bill) {
            if (isset($balance[$bill->OrganizationId])) {
                $balance[$bill->OrganizationId] += $bill->Fee;
            } else {
                $balance[$bill->OrganizationId] = $bill->Fee;
            }
            $bill->Balance = $balance[$bill->OrganizationId];
            if (!$bill->save()) {
                $this->db->rollback();
                exit('error');
            }
        }
        $this->db->commit();
        echo 'ok' . PHP_EOL;
    }
}