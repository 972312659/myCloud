<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/1/16
 * Time: 10:03 AM
 */

namespace App\Libs\csv;

use App\Enums\OrganizationType;
use App\Libs\Alipay;
use App\Models\Bill;
use App\Models\Combo;
use App\Models\ComboOrder;
use App\Models\ComboRefund;
use App\Models\InteriorTrade;
use App\Models\OrganizationRelationship;
use App\Models\Transfer;
use App\Models\User;
use Phalcon\Di\FactoryDefault;

class FrontCsv extends Csv
{
    /**
     * 医院网点列表
     */
    public function slaveList()
    {
        $filename = '网点列表';
        $title = '商户号,网点名称,网点类型,业务经理,创建时间,所属网点分组,转诊量';
        $columns = ['MerchantCode', 'MinorName', 'MinorTypeName', 'Salesman', 'CreateTime', 'RuleName', 'Count'];
        $datas = $this->builder->getQuery()->execute()->toArray();
        foreach ($datas as &$data) {
            $data['MinorTypeName'] = OrganizationType::value($data['MinorType']);
            $data['Count'] = $data['TransferAmount'];
            $data['Machine'] = $data['MachineOrgId'] ? '是' : '否';
            $data['CreateTime'] = date('Y-m-d H:i:s', $data['CreateTime']);
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 流水
     * @param bool $pay
     */
    public function bill($pay = true)
    {
        if ($pay) {
            $filename = '平台支出账单';
            $title = '支出,创建时间,交易类型,交易金额,账户余额';
            $columns = ['Title', 'CreateTime', 'TypeName', 'Fee', 'Balance'];
        } else {
            $filename = '平台收入账单';
            $title = '收入,创建时间,交易类型,交易金额';
            $columns = ['Title', 'CreateTime', 'TypeName', 'Fee'];
        }
        $datas = $this->builder->getQuery()->execute()->toArray();
        foreach ($datas as &$data) {
            $data['Title'] = str_replace(',', '，', $data['Title']);
            $data['CreateTime'] = date('Y-m-d H:i:s', $data['Created']);
            $data['TypeName'] = $data['Fee'] > 0 ? Bill::REFERENCE_TYPE_NAME_INCOME[$data['Type']] : Bill::REFERENCE_TYPE_NAME_PAY[$data['Type']];
            $data['Fee'] = round(Alipay::fen2yuan($data['Fee']), 2);
            $data['Balance'] = round(Alipay::fen2yuan($data['Balance']), 2);
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 转诊单列表
     */
    public function transfer()
    {
        $filename = '转诊单';
        $title = '转诊时间,转诊来源,业务经理,接诊部门,科室,医生,患者姓名,患者电话号码,诊单状态';
        $statusName = [2 => '待接诊', 3 => '待入院', 4 => '拒绝', 5 => '治疗中', 6 => '出院', 7 => '财务审核未通过', 8 => '结算完成', 9 => '重新提交'];
        $datas = $this->builder->getQuery()->execute()->toArray();
        $columns = ['StartTime', 'SendOrganizationName', 'Salesman', 'OutpatientOrInpatient', 'AcceptSectionName', 'AcceptDoctorName', 'PatientName', 'PatientTel', 'StatusName'];
        foreach ($datas as &$data) {
            $data['StartTime'] = date('Y-m-d H:i:s', $data['StartTime']);
            $data['StatusName'] = $statusName[$data['Status']];
            $data['OutpatientOrInpatient'] = Transfer::OutpatientOrInpatient_Name[$data['OutpatientOrInpatient']];
            if ($data['Genre'] == Transfer::GENRE_SHARE) {
                /** @var OrganizationRelationship $organizationRelation */
                $organizationRelation = OrganizationRelationship::findFirst([
                    'conditions' => 'MinorId=?0',
                    'bind'       => [$data['SendOrganizationId']],
                ]);
                if ($organizationRelation && $organizationRelation->SalesmanId) {
                    /** @var User $user */
                    $user = User::findFirst(sprintf('Id=%d', $organizationRelation->SalesmanId));
                    if ($user) {
                        $data['Salesman'] = $user->Name;
                    }
                }
            }
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 转诊统计
     * report/transferList
     */
    public function transferList()
    {
        $filename = '转诊统计';
        $title = '网点名称,业务经理,患者姓名,转诊时间,科室,转诊单金额,网点分润金额,订单状态';
        $datas = $this->builder->getQuery()->execute()->toArray();
        $columns = ['MinorName', 'SalesmanName', 'PatientName', 'StartTime', 'SectionName', 'Cost', 'ShareOneNum', 'StatusName'];
        foreach ($datas as &$data) {
            $data['StartTime'] = date('Y-m-d H:i:s', $data['StartTime']);
            $data['ShareOneNum'] = ($data['GenreOne'] ? ($data['GenreOne'] == 1 ? $data['ShareOne'] : (float)($data['Cost'] * $data['ShareOne'] / 100)) : 0);
            //显示形式
            $data['ShareOneNum'] = $data['Status'] == Transfer::FINISH ? ($data['ShareOneNum'] ? Alipay::fen2yuan($data['ShareOneNum']) : 0) : ($data['ShareOneNum'] ? Alipay::fen2yuan($data['ShareOneNum']) : '');
            $data['Cost'] = $data['Status'] >= Transfer::LEAVE ? ($data['Cost'] ? Alipay::fen2yuan($data['Cost']) : 0) : ($data['Cost'] ? Alipay::fen2yuan($data['Cost']) : '');
            $data['StatusName'] = Transfer::STATUS_NAME[$data['Status']];
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * interiortrade/list
     * @param string $role
     * 'Cashier'=>出纳 ，'Finance'=>财务
     */
    public function interiorTrade($sql, $bind, string $role = 'Cashier')
    {
        $filename = $role == 'Cashier' ? '付款结算表' : '付款审核表';
        $title = '名称,患者姓名,账户类型,交易金额,提交审核时间,状态';
        $datas = FactoryDefault::getDefault()->get('db')->query($sql, $bind)->fetchAll();
        $columns = ['MerchantName', 'PatientName', 'StyleName', 'Total', 'Created', 'StatusName'];
        foreach ($datas as &$data) {
            $data['StatusName'] = $data['Status'] == InteriorTrade::STATUS_PASS && $role == 'Cashier' ? '待结算' : InteriorTrade::STATUS_NAME[$data['Status']];
            $data['StyleName'] = InteriorTrade::STYLE_NAME[$data['Style']];
            $data['Total'] = Alipay::fen2yuan($data['Total']);
            $data['Created'] = date('Y-m-d H:i:s', $data['Created']);
            if ($data['MinorName']) {
                $data['AcceptOrganizationName'] = $data['MinorName'];
                $data['MerchantName'] = $data['MinorName'];
            }
        }

        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 套餐销售详情
     */
    public function comboOrderBatchDetails()
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
     * 套餐订单
     */
    public function comboOrderList()
    {
        $filename = '套餐订单表';
        $title = '套餐单号,套餐名称,套餐价格,来源网点,业务经理,客户姓名,客户电话,订单状态,创建时间';
        $datas = $this->builder->getQuery()->execute()->toArray();
        $columns = ['OrderNumber', 'ComboName', 'Price', 'SendOrganizationName', 'Salesman', 'PatientName', 'PatientTel', 'StatusName', 'Created'];
        foreach ($datas as &$data) {
            $data['OrderNumber'] = (string)$data['OrderNumber'];
            $data['Price'] = Alipay::fen2yuan($data['Price']);
            $data['Created'] = date('Y-m-d H:i:s', $data['Created']);
            $data['StatusName'] = $data['Status'] == ComboOrder::STATUS_PAYED ? '待使用' : '已使用';
        }
        $this->export($filename, $title, $datas, $columns);
    }

    /**
     * 套餐订单退款单列表
     * @param $query
     * @param $bind
     */
    public function refundList($query, $bind)
    {
        $filename = '套餐退款订单表';
        $title = '套餐单号,套餐名称,套餐价格,来源网点,业务经理,客户姓名,客户电话,状态,创建时间';
        $datas = FactoryDefault::getDefault()->get('db')->query($query, $bind)->fetchAll();
        $columns = ['OrderNumber', 'ComboName', 'Price', 'SendOrganizationName', 'Salesman', 'PatientName', 'PatientTel', 'StatusName', 'Created'];
        foreach ($datas as &$data) {
            $data['OrderNumber'] = (string)$data['OrderNumber'];
            $data['Price'] = Alipay::fen2yuan($data['Price']);
            $data['Created'] = date('Y-m-d H:i:s', $data['Created']);
            $data['StatusName'] = $data['Status'] == ComboRefund::STATUS_WAIT ? '退款中' : '已退款';
        }
        $this->export($filename, $title, $datas, $columns);
    }
}