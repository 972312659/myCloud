<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/16
 * Time: 10:03 AM
 */

namespace App\Libs\csv;

use App\Enums\HospitalLevel;
use App\Enums\OrganizationType;
use App\Libs\Alipay;
use App\Libs\PaymentChannel\Wxpay;
use App\Models\Combo;
use App\Models\ComboOrder;
use App\Models\Organization;
use App\Models\Trade;
use App\Models\Transfer;
use Phalcon\Di\FactoryDefault;

class AdminCsv extends Csv
{
    /**
     * 控台转诊单
     */
    public function transferOrder($query, $bind)
    {
        $filename = '转诊单数据表';
        $title = '转诊单号,创建时间,接诊商户号,接诊商户名称,来源商户号,来源商户名称,转诊来源人,转诊类型,患者姓名,联系方式,科室,医生,状态,消费金额，是否评价';
        $statusName = [2 => '待接诊', 3 => '待入院', 4 => '拒绝', 5 => '治疗中', 6 => '出院', 7 => '财务审核未通过', 8 => '结算完成', 9 => '重新提交'];
        $datas = FactoryDefault::getDefault()->get('db')->query($query, $bind)->fetchAll();
        $columns = ['OrderNumber', 'ApplyTime', 'HospitalMerchantCode', 'HospitalName', 'ComeMerchantCode', 'ComeName', 'Sender', 'GenreName', 'PatientName', 'PatientTel', 'AcceptSectionName', 'AcceptDoctorName', 'StatusName', 'CostExcel', 'IsEvaluate'];
        foreach ($datas as &$data) {
            $data['PatientId'] = (string)$data['PatientId'];
            $data['OrderNumber'] = (string)$data['OrderNumber'];
            $data['ApplyTime'] = date('Y-m-d', $data['StartTime']);
            $data['GenreName'] = $data['Genre'] == 1 ? '自有转诊' : '共享转诊';
            $data['StatusName'] = $statusName[$data['Status']];
            $data['IsEvaluate'] = $data['IsEvaluate'] == 1 ? '已评论' : '未评论';
            $data['CostExcel'] = Alipay::fen2yuan($data['Cost']);
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 控台网关财务单
     */
    public function trade()
    {
        $filename = '提现表';
        $title = '申请时间,交易时间,商户名称,联系人,联系方式,所属医院,所属业务经理,业务经理联系方式,账户,提现人姓名,提现银行,提现金额,提现状态';
        $datas = $this->builder->getQuery()->execute()->toArray();
        $columns = ['Created', 'FinishTime', 'Name', 'Contact', 'Phone', 'HospitalName', 'Salesman', 'SalesmanPhone', 'Account', 'OpName', 'Bank', 'Amount', 'AuditName'];
        foreach ($datas as &$data) {
            $data['Created'] = date('Y-m-d H:i:s', $data['Created']);
            $data['FinishTime'] = date('Y-m-d H:i:s', $data['FinishTime']);
            $data['Amount'] = Alipay::fen2yuan($data['Amount']);
            $data['AuditName'] = Trade::AUDIT_NAME[$data['Audit']];
            $way = $data['Gateway'] == Trade::GATEWAY_ALIPAY ? '支付宝' : '微信';
            if ($data['Bank']) {
                foreach (Wxpay::Banks as $bank) {
                    if ($data['Bank'] == $bank['Value']) {
                        $data['Bank'] = $bank['Name'];
                    }
                }
            }
            $data['Bank'] = $data['Bank'] ?: $way;
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 医院列表导出
     */
    public function hospital()
    {
        $filename = '医院列表';
        $title = '创建时间,商户号,医院名称,医院等级,医院类别,商户等级,服务到期时间,是否到期,是否共享,省,市,区';
        $datas = $this->builder->getQuery()->execute()->toArray();
        $columns = ['CreateTime', 'MerchantCode', 'Name', 'LevelName', 'Type', 'LevelName', 'Expire', 'ExpireStatus', 'Verifyed', 'Province', 'City', 'Area'];
        foreach ($datas as &$data) {
            $data['CreateTime'] = date('Y-m-d H:i:s', $data['CreateTime']);
            $data['LevelName'] = HospitalLevel::value($data['LevelId']);
            $data['Type'] = OrganizationType::value($data['Type']);
            $data['Verifyed'] = $data['Verifyed'] == Organization::UNVERIFY ? '自有' : '共享';
            $data['ExpireStatus'] = date('Y-m-d') > $data['Expire'] ? ($data['IsMain'] == Organization::ISMAIN_HOSPITAL ? '到期' : '正常') : '正常';

        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 网点
     */
    public function slave()
    {
        $filename = '网点列表';
        $title = '创建时间,网点名称,所属医院名称,网点类型,转诊单统计,转诊金额统计,服务费统计,开户手机号,联系人,省,市,区';
        $columns = ['CreateTime', 'Name', 'MainName', 'Type', 'TransferAmount', 'Cost', 'Platform', 'Phone', 'Contact', 'Province', 'City', 'Area'];
        $datas = $this->builder->getQuery()->execute()->toArray();
        $transferCount = Transfer::query()
            ->columns(['SendOrganizationId Id', 'sum(Cost) Cost', 'count(*)  Count', 'sum(if(CloudGenre=1,ShareCloud,Cost*ShareCloud/100)) as Platform'])
            ->inWhere('SendOrganizationId', array_column($datas, 'Id'))
            ->andWhere(sprintf('Status=%d', Transfer::FINISH))
            ->groupBy('SendOrganizationId')
            ->execute()->toArray();
        $transferCount_tmp = [];
        if (count($transferCount)) {
            foreach ($transferCount as $item) {
                $transferCount_tmp[$item['Id']] = ['Cost' => $item['Cost'], 'Count' => $item['Count'], 'Platform' => $item['Platform']];
            }
        }
        foreach ($datas as &$data) {
            $data['Cost'] = isset($transferCount_tmp[$data['Id']]) ? (isset($transferCount_tmp[$data['Id']]['Cost']) ? floor($transferCount_tmp[$data['Id']]['Cost']) : 0) : 0;
            $data['Count'] = isset($transferCount_tmp[$data['Id']]) ? (isset($transferCount_tmp[$data['Id']]['Count']) ? floor($transferCount_tmp[$data['Id']]['Count']) : 0) : 0;
            $data['Platform'] = isset($transferCount_tmp[$data['Id']]) ? (isset($transferCount_tmp[$data['Id']]['Platform']) ? floor($transferCount_tmp[$data['Id']]['Platform']) : 0) : 0;
            $data['CreateTime'] = date('Y-m-d H:i:s', $data['CreateTime']);
            $data['Type'] = OrganizationType::value($data['Type']);
            $data['Cost'] = Alipay::fen2yuan($data['Cost'] ?: 0);
            $data['Platform'] = Alipay::fen2yuan($data['Platform'] ?: 0);
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 套餐销售详情
     */
    public function saleList($query, $bind)
    {
        $filename = '套餐销售详情';
        $title = '套餐名称,销售价格,所属医院,累计销售,累计分配';
        $datas = FactoryDefault::getDefault()->get('db')->query($query, $bind)->fetchAll();
        $columns = ['Name', 'Price', 'HospitalName', 'SalesQuantity', 'Allot'];
        foreach ($datas as &$data) {
            $data['Price'] = Alipay::fen2yuan($data['Price'] ?: 0);
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 套餐销售详情
     */
    public function buyList()
    {
        $filename = '套餐销售详情表';
        $title = '网点名称,网点联系人,联系电话,业务经理,业务经理电话,购买数量,购买时间,待分配数量';
        $datas = $this->builder->getQuery()->execute()->toArray();
        $columns = ['SendOrganizationName', 'SlaveMan', 'SlaveManPhone', 'Salesman', 'SalesmanPhone', 'QuantityBuy', 'CreateTime', 'QuantityUnAllot'];
        foreach ($datas as &$data) {
            $data['CreateTime'] = date('Y-m-d H:i:s', $data['CreateTime']);
        }

        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 套餐订单列表
     */
    public function comboOrderList($sql, $bind)
    {
        $filename = '套餐订单表';
        $title = '套餐单号,套餐名称,网点名称,所属医院,套餐价格,订单状态,创建时间';
        $datas = FactoryDefault::getDefault()->get('db')->query($sql, $bind)->fetchAll();
        $columns = ['OrderNumber', 'ComboName', 'SlaveName', 'HospitalName', 'Price', 'StatusName', 'Created'];
        foreach ($datas as &$data) {
            $data['OrderNumber'] = (string)$data['OrderNumber'];
            $data['Price'] = Alipay::fen2yuan($data['Price']);
            $data['Created'] = date('Y-m-d H:i:s', $data['Created']);
            $data['StatusName'] = ComboOrder::STATUS_NAME[$data['Status']];
        }
        $this->export($filename, $title, $datas, $columns);
    }

    public function comboList()
    {
        $filename = '套餐列表';
        $title = '套餐名称,套餐价格,佣金规则,所属医院,上线时间';
        $datas = $this->builder->getQuery()->execute()->toArray();
        $columns = ['Name', 'Price', 'RuleName', 'HospitalName', 'CreateTime'];
        foreach ($datas as &$data) {
            switch ($data['Way']) {
                case Combo::WAY_NOTHING:
                    $data['RuleName'] = '无佣金';
                    break;
                case Combo::WAY_FIXED:
                    $amount = Alipay::fen2yuan($data['Amount']);
                    $data['RuleName'] = "单笔 {$amount}";
                    break;
                case Combo::WAY_RATIO:
                    $data['RuleName'] = "金额{$data['Amount']}%";
                    break;
            }
            $data['Price'] = Alipay::fen2yuan($data['Price']);
            $data['CreateTime'] = date('Y-m-d H:i:s', $data['CreateTime']);
        }

        $this->export($filename, $title, $datas, $columns);
    }
}