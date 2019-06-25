<?php

/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/14
 * Time: 4:37 PM
 */
use Phalcon\Cli\Task;

class ComboTask extends Task
{
    public function addBatchAction()
    {
        $sql = "SELECT Id,SendHospitalId,Status,Created,SendOrganizationId,SendOrganizationName,Genre FROM ComboOrder";
        $comboOrder = $this->db->query($sql)->fetchAll();
        $comboOrder_tmp = [];
        foreach ($comboOrder as $value) {
            $comboOrder_tmp[$value['Id']] = [
                'HospitalId' => $value['SendHospitalId'],
                'OrgId'      => $value['SendOrganizationId'],
                'OrgName'    => $value['SendOrganizationName'],
                'Created'    => $value['Created'],
                'Status'     => $value['Status'],
                'Genre'      => $value['Genre'],
            ];
        }

        $sql = "SELECT ComboOrderId,count(ComboOrderId) amount,max(LogTime) logTime from ComboOrderLog group by ComboOrderId";
        $amount = $this->db->query($sql)->fetchAll();
        $amount_tmp = [];
        foreach ($amount as $value) {
            $amount_tmp[$value['ComboOrderId']] = $value['amount'];
            $amount_tmp[$value['ComboOrderId']] = [
                'amount'  => $value['amount'],
                'logTime' => $value['logTime'],
            ];
        }

        $sql = "select Id,Image from Combo";
        $combo = $this->db->query($sql)->fetchAll();
        $combo_tmp = [];
        foreach ($combo as $value) {
            $image = '';
            if (!empty($value['Image'])) {
                $image = explode(',', $value['Image'])[0];
            }
            $combo_tmp[$value['Id']] = $image;
        }

        $sql = 'SELECT * FROM ComboAndOrder';
        $query = $this->db->query($sql);
        $result = $query->fetchAll();
        foreach ($result as $k => $value) {
            $id = (int)($k + 1);
            $orderNumber = mt_rand(10000000000, 999999999999);
            $quantityBack = 0;
            $status = $comboOrder_tmp[$value['ComboOrderId']]['Status'];
            $refund = false;
            if ($status == 4) {
                if (isset($amount_tmp[$value['ComboOrderId']['amount']]) && $amount_tmp[$value['ComboOrderId']['amount']] == 2) {
                    $status = 4;
                } else {
                    $refund = true;
                    $status = 3;
                    $quantityBack = 1;
                }
            }

            $bind = [
                $id, $orderNumber, $value['ComboId'],
                $comboOrder_tmp[$value['ComboOrderId']]['HospitalId'],
                $comboOrder_tmp[$value['ComboOrderId']]['OrgId'],
                $comboOrder_tmp[$value['ComboOrderId']]['OrgName'],
                $value['Name'], $value['Price'], $value['Way'], 1, $value['Amount'],
                $value['Price'], 1,
                $status,
                $comboOrder_tmp[$value['ComboOrderId']]['Created'],
                $comboOrder_tmp[$value['ComboOrderId']]['Created'],
                $comboOrder_tmp[$value['ComboOrderId']]['Created'],
                0, $quantityBack, 0,
                $comboOrder_tmp[$value['ComboOrderId']]['Genre'],
                $combo_tmp[$value['ComboId']] ?: '',
            ];
            $sql = 'REPLACE INTO ComboOrderBatch (Id,OrderNumber,ComboId,HospitalId,OrganizationId,OrganizationName,Name,Price,Way,MoneyBack,Amount,InvoicePrice,QuantityBuy,Status,CreateTime,PayTime,FinishTime,QuantityUnAllot,QuantityBack,QuantityApply,Genre,Image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $this->db->execute($sql, $bind);
            $sql = 'update ComboAndOrder set ComboOrderBatchId=? and Image=? where ComboOrderId=? and ComboId=?';
            $this->db->execute($sql, [$id, $value['ComboOrderId'], $combo_tmp[$value['ComboId']] ?: '', $value['ComboId']]);
            if ($refund) {
                $price = (int)($value['Way'] == 1 ? $value['Amount'] : $value['Price'] * $value['Amount'] / 100);
                $bind = [
                    $id, $orderNumber, $value['ComboId'], $value['Name'], 2,
                    $value['ComboOrderId'],
                    $comboOrder_tmp[$value['ComboOrderId']]['HospitalId'],
                    $comboOrder_tmp[$value['ComboOrderId']]['OrgId'],
                    $amount_tmp[$value['ComboOrderId']]['logTime'],
                    $amount_tmp[$value['ComboOrderId']]['logTime'],
                    2,
                    1,
                    $price,
                    '',
                    '',
                    $combo_tmp[$value['ComboId']] ?: '',
                ];
                $sql = 'REPLACE INTO ComboRefund (Id,OrderNumber,ComboId,ComboName,ReferenceType,ReferenceId,SellerOrganizationId,BuyerOrganizationId,Created,FinishTime,Status,Quantity,Price,ApplyReason,RefuseReason,Image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $this->db->execute($sql, $bind);
            }
        }
        echo 'ok' . PHP_EOL;
    }
}