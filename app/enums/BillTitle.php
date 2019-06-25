<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/5/7
 * Time: 上午11:24
 */

namespace App\Enums;


class BillTitle
{
    /**
     * 网关：充值
     */
    const Trade_In = '%s';
    /**
     * 网关：提现
     */
    const Trade_Out = '%s';
    /**
     * 网关：提现手续费
     */
    const Trade_Out_Fee = '支付提现%s元手续费%s元';
    /**
     * 内部转账：收入
     */
    const InteriorTrade_In = '收到【%s】转账%s元';
    /**
     * 内部转账：支出
     */
    const InteriorTrade_Out = '向【%s】转账%s元';
    /**
     * 转诊：平台手续费收入
     */
    const Transfer_Platform = '【医院：%s】%s转诊首诊佣金收益%s元';
    /**
     * 转诊：医院支付
     */
    //转诊单号 患者姓名 网点上级医院 金额 网点 金额 平台手续费 合计金额
    const Transfer_Hospital = '转诊单：%s，患者：%s，【%s】首诊佣金：%s元，【%s】首诊佣金：%s元，平台手续费：%s元，合计支出：%s元';
    /**
     * 转诊：医院支付 (有业务经理奖励时)
     */
    //转诊单号 患者姓名 网点上级医院 金额 网点 金额 业务经理 奖金 平台手续费 合计金额
    const Transfer_Hospital_SalesmanBonus = '转诊单：%s，患者：%s，【%s】首诊佣金：%s元，【%s】首诊佣金：%s元，业务经理【%s】奖金：%s元，平台手续费：%s元，合计支出：%s元';
    /**
     * 转诊：网点收入
     */
    const Transfer_Slave = '【%s】首诊佣金收益%s元';
    /**
     * 转诊：共享医院收入
     */
    const Transfer_ShareHospital = '【%s】向【%s】共享首诊佣金收益%s元';
    /**
     * 挂号：医院收入
     */
    const Registration_In = '来自医院【%s】所属网点【%s】的挂号单：%s，收入%s元';
    /**
     * 挂号：网点支出
     */
    const Registration_Out = '挂号单：%s，支出%s元';
    /**
     * 挂号：网点收入退款
     */
    const Registration_back = '挂号单：%s，未成功退回%s元';
    /**
     * 挂号：网点抢号佣金收入
     */
    const Registration_slave = '挂号单：%s，首诊佣金收入%s元';
    /**
     * 挂号：网点抢号上级医院佣金收入
     */
    const Registration_Hospital = '%s挂号成功，首诊佣金收入%s元';
    /**
     * 套餐：收入
     */
    //网点上级医院  网点 订单号 金额 患者姓名
    const ComboOrder_In = '来自医院【%s】所属网点【%s】的套餐订单：%s，收入%s元，患者姓名：%s';
    /**
     * 套餐：支出
     */
    const ComboOrder_Out = '套餐订单：%s,支出%s元';
    /**
     * 购买服务费：医院支出
     */
    const PlatformLicensing_Hospital = '医院【%s】%s 购买了平台使用服务：%s，价格:%s元';
    /**
     * 购买服务费：平台收入
     */
    const PlatformLicensing_Platform = '医院【%s】%s 购买了平台使用服务：%s，价格:%s元';
    /**
     * 线下充值成功：医院收入
     */
    const OfflinePay_Success = '平台线下充值：%s元';
    /**
     * 商城订单：买家支付到平台
     */
    //订单号 金额
    const Product_BuyerToPlatform_Buyer = '订单%s支付%s';
    const Product_BuyerToPlatform_Platform = '收入订单%s金额%s元';
    /**
     * 商城订单：平台将买家的钱付给卖家
     */
    //订单号 金额 卖家名
    const Product_PlatformToSeller_Platform = '订单%s支付%s元到%s';
    //订单号 金额
    const Product_PlatformToSeller_Seller = '收入订单%s货款%s元';
    /**
     * 商城订单：平台将买家的钱退还给买家
     */
    //订单号 金额
    const Product_PlatformToBuyer_Platform = '退款-订单%s，金额：%s元';
    const Product_PlatformToBuyer_Buyer = '退款-订单%s，金额：%s元';

    /**
     * 套餐订单ComboOrderBatch 收入
     */
    //网点上级医院  网点 订单号 金额
    const ComboOrderBatch_Hospital_In = '来自医院【%s】所属网点【%s】的套餐订单：%s，收入%s元，患者姓名：%s';
    /**
     * 套餐订单ComboOrderBatch 支出
     */
    const ComboOrderBatch_Slave_In = '套餐：%s,订单号：%s,退还%s元';
    /**
     * 套餐订单ComboOrderBatch 支出
     */
    const ComboOrderBatch_Slave_Out = '套餐：%s,订单号：%s,支出%s元';
    /**
     * 平台ComboOrderBatch 收入
     */
    const ComboOrderBatch_Peach_In = '来自医院【%s】所属网点【%s】的套餐订单：%s，收入%s元';
    /**
     * 平台ComboOrderBatch 支出
     */
    const ComboOrderBatch_Peach_Out = '套餐订单：%s，支出%s元给医院';
    /**
     * 医院退款ComboRefund=>ComboOrderBatch
     */
    const ComboRefund_Peach_ComboOrderBatch_Out = '套餐退款订单：%s，支出%s元';
    /**
     * 医院退款ComboRefund=>ComboOrder
     */
    const ComboRefund_Slave_In = '套餐退款订单：%s，收入%s元';


    /**
     * 转诊单结算完成：业务经理 收入
     */
    //网点名 奖金
    const Salesman_Bonus_TransferCost = '【%s】转诊单%s，奖金：%s元';
}
