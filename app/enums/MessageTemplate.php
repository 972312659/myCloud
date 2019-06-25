<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/22
 * Time: 11:28
 */

namespace App\Enums;


use App\Exceptions\ParamException;
use App\Libs\Push;
use App\Models\Event;
use App\Models\MessageLog;
use App\Models\UserEvent;
use Pheanstalk\Pheanstalk;

class MessageTemplate
{
    const TEL = '028-69912686';
    const SMS_PREFIX = '【云转诊】';
    const DATE_FORMAT = 'm月d日H时i分';

    // 发送形式
    const METHOD_MESSAGE = 1;   // 需要存到消息表中
    const METHOD_PUSH = 2;      // 发送通知
    const METHOD_SMS = 4;       // 发送短信

    // 类型
    const ROLE_SYSTEM = 1;      // 系统
    const ROLE_MAJOR = 2;       // 医院
    const ROLE_SLAVE = 4;       // 网点
    const ROLE_PATIENT = 8;     // 患者
    const ROLE_SUPPLIER = 16;   // 供应商

    // 事件
    const EVENT_ENCASH = 1;           //提现
    const EVENT_CHARGE = 2;           //充值
    const EVENT_CKECK_OUT = 3;        //支付
    const EVENT_PROFIT = 4;           //分润
    const EVENT_TRANSFER_WAIT = 5;    //待分诊
    const EVENT_DISPATCH = 6;         //已分诊
    const EVENT_IN_HOSPITAL = 7;      //已入院
    const EVENT_OUT_HOSPITAL = 8;     //已出院
    const EVENT_EVALUATE = 9;         //收到评价
    const EVENT_HOSPITAL_SHARE = 10;  //渠道共享审核
    const EVENT_SECTION_SHARE = 11;   //科室共享审核
    const EVENT_DOCTOR_SHARE = 12;    //医生共享审核
    const EVENT_EQUIPMENT_SHARE = 13; //设备共享审核
    const EVENT_COMBO_SHARE = 14;     //套餐共享审核
    const EVENT_REGISTRATION_CREATE = 15; //加号提醒
    const EVENT_REGISTRATION_SUCCESS = 16;//加号成功
    const EVENT_REGISTRATION_CANCEL = 17; //取消加号
    const EVENT_REGISTRATION_FEE = 18;    //收到挂号费
    const EVENT_REGISTRATION_SHARE = 19;  //挂号分润
    const EVENT_PRODUCT_STOCK = 20;  //商品库存
    const EVENT_COMBO = 21;  //套餐

    public static $map = [
        [
            'id'       => 'captcha',
            'name'     => '验证码',
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR | self::ROLE_SLAVE,
            'method'   => self::METHOD_SMS,
            'template' => '尊敬的用户，您的验证码为：%s，请于5分钟内输入，工作人员不会向您索取，请勿泄露。',
        ],
        [
            'id'       => 'account_create_major',
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_SMS,
            'template' => '尊敬的用户，您的账号已开通，商户号为%s，登录账号为%s，密码%s，为了您的账户安全，请您尽快登录并修改密码，如有疑问请致电' . self::TEL . '。',
        ],
        [
            'id'       => 'account_create_slave',
            'sender'   => self::ROLE_MAJOR,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_SMS,
            'template' => '尊敬的用户， %s为您开放了云转诊平台的账号，商户号为%s，登录账号为%s，密码%s，为了您的账户安全，请您尽快登录并修改密码，如有疑问请致电%s。',
        ],
        [
            'id'       => 'account_create_supplier',
            'sender'   => self::ROLE_MAJOR,
            'receiver' => self::ROLE_SUPPLIER,
            'method'   => self::METHOD_SMS,
            'template' => '尊敬的用户， %s为您开放了云转诊平台的账号，商户号为%s，登录账号为%s，密码%s，为了您的账户安全，如果是默认密码请您尽快登录并修改密码，如有疑问请致电%s。',
        ],
        [
            'id'       => 'account_supplier_to_major',
            'sender'   => self::ROLE_MAJOR,
            'receiver' => self::ROLE_SUPPLIER,
            'method'   => self::METHOD_SMS,
            'template' => '尊敬的用户，升级成功！您的云转诊使用权限已升级！',
        ],
        [
            'id'       => 'fund_apply_encash',
            'type'     => Event::MONEY,
            'event_id' => self::EVENT_ENCASH,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR | self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH | self::METHOD_SMS,
            // 时间 提现金额
            'template' => '尊敬的用户，您的账户于%s申请了%s元的提现，预计到账时间：3个工作日内，节假日顺延。如有疑问请致电' . self::TEL . '。',
        ],
        [
            'id'       => 'fund_complete_encash',
            'type'     => Event::MONEY,
            'event_id' => self::EVENT_ENCASH,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR | self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH | self::METHOD_SMS,
            // 时间 提现金额
            'template' => '尊敬的用户，您的账户于%s申请的%s元的提现已经审核通过，并完成打款，请尽快核对。如有疑问请致电' . self::TEL . '。',
        ],
        [
            'id'       => 'fund_complete_encash_fail',
            'type'     => Event::MONEY,
            'event_id' => self::EVENT_ENCASH,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR | self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH | self::METHOD_SMS,
            // 时间 提现金额 失败原因
            'template' => '尊敬的用户，您的账户于%s申请的%s元的提现失败，失败原因：%s。如有疑问请致电' . self::TEL . '。',
        ],
        [
            'id'       => 'fund_charge',
            'type'     => Event::MONEY,
            'event_id' => self::EVENT_CHARGE,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR | self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH | self::METHOD_SMS,
            // 时间 充值金额
            'template' => '尊敬的用户，您的账户于%s成功充值%s元，请尽快核对，如有疑问请致电' . self::TEL . '。',
        ],
        [
            'id'       => 'fund_profit',
            'type'     => Event::MONEY,
            'event_id' => self::EVENT_PROFIT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR | self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH | self::METHOD_SMS,
            // 转诊单号 分润金额
            'template' => '尊敬的用户，您获得了转诊单号为%s的系统首诊佣金%s元，请尽快核对，如有疑问请致电' . self::TEL . '。',
        ],
        [
            'id'       => 'fund_send',
            'type'     => Event::MONEY,
            'event_id' => self::EVENT_CKECK_OUT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 时间 小b名 金额
            'template' => '尊敬的用户，您于%s向%s转账：%s元。',
        ],
        [
            'id'       => 'fund_accept',
            'type'     => Event::MONEY,
            'event_id' => self::EVENT_CKECK_OUT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 大b填写的消息内容
            'template' => '%s',
        ],
        [
            'id'       => 'transfer_apply',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_TRANSFER_WAIT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 患者名 医院名 转诊单号
            'template' => '尊敬的用户，您为%s申请的%s的转诊请求，转诊单号为%s，已经提交成功，正在等待分诊。',
        ],
        [
            'id'       => 'transfer_major_refuse',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_TRANSFER_WAIT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 网点名 转诊单号
            'template' => '尊敬的用户，%s申请的转诊单号为%s的转诊请求已经被拒绝',
        ],
        [
            'id'       => 'transfer_slave_refuse',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_TRANSFER_WAIT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 医院名 患者名 市场开发人员电话
            'template' => '尊敬的用户，%s拒绝了您为%s申请的转诊请求。如有疑问，请拨打客户经理电话%s',
        ],
        [
            'id'       => 'transfer_receive',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_TRANSFER_WAIT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 网点名 转诊单号
            'template' => '尊敬的用户，您有来自%s的转诊单，转诊单号是：%s。',
        ],
        [
            'id'       => 'transfer_slave_dispatch',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_DISPATCH,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 患者名 医院名 转诊单号 时间 医生名 科室
            'template' => '尊敬的用户，您为%s申请的%s的转诊请求已经被接收，转诊单号为%s，预约时间为%s，接诊医生为%s，预约科室为%s。',
        ],
        [
            'id'       => 'transfer_major_dispatch',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_DISPATCH,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 网点名 患者名 转诊单号 医生名 科室 时间
            'template' => '尊敬的用户，%s的%s转诊单已分诊，接诊医生是%s，接诊科室%s，预约时间是%s。',
        ],
        [
            //todo 短信更改
            'id'       => 'transfer_patient_dispatch',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_DISPATCH,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_PATIENT,
            'method'   => self::METHOD_SMS,
            // 网点名 医院名 医院地址 转诊单号 时间 医生名 科室 医院电话
            'template' => '尊敬的用户，%s为您申请的%s的预约请求已经被接收，医院地址:%s, 预约单号为%s，预约时间为%s，接诊医生为%s，预约科室%s。如有疑问，请拨打电话%s。',
        ],
        [
            //todo 短信更改
            'id'       => 'transfer_patient_dispatch_onlineInquiry',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_DISPATCH,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_PATIENT,
            'method'   => self::METHOD_SMS,
            // 医院名 远程问诊医生姓名 医院地址 转诊单号 时间 医生名 科室 医院电话
            'template' => '尊敬的用户，%s医院已接收%s医生为您申请的预约就诊服务，医院地址:%s，预约单号为%s，预约时间为%s，接诊医生为%s，预约科室%s。如有疑问，请拨打电话%s。',
        ],
        [
            'id'       => 'transfer_slave_check_in',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_IN_HOSPITAL,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 转诊单号 患者名 时间
            'template' => '尊敬的用户，转诊单号为%s，患者%s已于%s到诊。',
        ],
        [
            'id'       => 'transfer_major_check_in',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_IN_HOSPITAL,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 转诊单号 患者名
            'template' => '尊敬的用户，转诊单号为%s的患者%s已经到诊。',
        ],
        [
            'id'       => 'transfer_major_patient_leave_hospital',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_OUT_HOSPITAL,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            //  转诊单号 患者名 时间
            'template' => '转诊单号为%s，患者%s已于%s治疗结束。',
        ],
        [
            'id'       => 'transfer_slave_patient_leave_hospital',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_OUT_HOSPITAL,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 转诊单号 患者名 时间
            'template' => '转诊单号为%s，患者%s已于%s治疗结束。',
        ],
        [
            'id'       => 'transfer_slave_check_out',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_PROFIT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 转诊单号 患者名 分润金额
            'template' => '转诊单%s，患者%s得到治疗，您获得首诊佣金%s元。',
        ],
        [
            'id'       => 'transfer_share_check_out',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_PROFIT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 网点名 分润金额
            'template' => '尊敬的用户，由%s发起共享转诊已完成，您获得%s元首诊佣金，请尽快核查。',
        ],
        [
            'id'       => 'transfer_major_check_out',
            'type'     => Event::TRANSFER,
            'event_id' => self::EVENT_CKECK_OUT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 转诊单号 患者名 支付金额
            'template' => '转诊单号为%s，患者%s治疗首诊佣金支出%s元。',
        ],
        [
            'id'       => 'transfer_comment',
            'type'     => Event::EVALUATE,
            'event_id' => self::EVENT_EVALUATE,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 时间 网点名
            'template' => '尊敬的用户，%s，%s评价了您。',
        ],
        [
            'id'       => 'transfer_reply',
            'type'     => Event::EVALUATE,
            'event_id' => self::EVENT_EVALUATE,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 时间 医院名
            'template' => '尊敬的用户，%s，%s回复了您的评价。',
        ],
        [
            'id'       => 'registration_share_hospital',            //医院，挂号抢号分润给网点所在医院
            'type'     => Event::REGISTRATION,
            'event_id' => self::EVENT_REGISTRATION_SHARE,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 网点 分润金额
            'template' => '尊敬的用户，您收到来自网点%s挂号的首诊佣金%s元',
        ],
        [
            'id'       => 'registration_share_slave',             //网点，挂号抢号分润给网点
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE | self::METHOD_PUSH,
            // 挂号人 分润金额
            'template' => '尊敬的用户，您收到来自%S挂号的首诊佣金%s元',
        ],
        [
            'id'       => 'registration_order_slave',            //网点，挂号预约成功
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 患者 年月日 上午/下午 医院名称  科室 医生姓名
            'template' => '【预约成功】您已为患者%s成功预约了%s【%s】%s%s%s。',
        ],
        [
            'id'       => 'registration_order_patient',           //患者，挂号预约成功
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_PATIENT,
            'method'   => self::METHOD_SMS,
            // 年月日 上午/下午 医院名称  科室 医生姓名  挂号人姓名 就诊人身份证号 电话
            'template' => '尊敬的用户，您的预约信息为：就诊时间：%s 【%s】，医院：%s，科室：%s，医生：%s，就诊人姓名：%s，就诊人身份证号：%s，请携带您的有效证件按时就诊。如果有预约问题请拨打电话%s。',
        ],
        [
            'id'       => 'registration_cancel_slave',           //网点，挂号退号成功
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 患者 年月日 上午/下午 医院名称  科室  医生姓名医师的预约
            'template' => '【取消预约】您已经取消了患者%s预约的%s（%s）%s %s %s。',
        ],
        [
            'id'       => 'registration_cancel_patient',           //患者，挂号退号成功
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_PATIENT,
            'method'   => self::METHOD_SMS,
            // 医院名称 科室名称 医生姓名
            'template' => '尊敬的用户，您预约的医院：”%s“，科室：”%s“，医师：”%s“已成功退号。',
        ],
        [
            'id'       => 'registration_pay_success_slave',           //网点，挂号支付成功（加号/抢号）
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 挂号费
            'template' => '【支付成功】您成功支付挂号费%s元。',
        ],
        [
            'id'       => 'registration_pay_failed_slave',           //网点，挂号支付失败（加号/抢号）
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 挂号费
            'template' => '【支付失败】您支付的%s元挂号费，未成功。',
        ],
        [
            'id'       => 'registration_cancel_slave',           //网点，挂号取消（加号/抢号）
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 患者 年月日 上午/下午 医院名称  科室  医生姓名
            'template' => '【挂号取消】患者%s预约的%s（%s）%s %s %s医师的挂号已取消。',
        ],
        [
            'id'       => 'registration_cancel_patient',           //患者，挂号取消（加号/抢号）
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_PATIENT,
            'method'   => self::METHOD_SMS,
            // 医院名称 科室名称 医生姓名
            'template' => '尊敬的用户，您预约的医院：”%s“，科室：”%s“，医师：”%s“已成功退号。',
        ],
        [
            'id'       => 'registration_refund_slave',           //网点，挂号退款成功（加号/抢号）
            'type'     => Event::REGISTRATION,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 挂号费
            'template' => '【退款成功】您收到了%s元退款。',
        ],
        [
            'id'       => 'registration_add_hospital',           //医院，加号
            'type'     => Event::REGISTRATION,
            'event_id' => self::EVENT_REGISTRATION_CREATE,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            //患者姓名
            'template' => '您收到（%s）的挂号请求，请尽快处理',
        ],
        [
            'id'       => 'registration_add_peach',           //平台，抢号
            'type'     => Event::REGISTRATION,
            'event_id' => self::EVENT_REGISTRATION_CREATE,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SYSTEM,
            'method'   => self::METHOD_MESSAGE,
            'template' => '您有一个抢号单要尽快处理',
        ],
        [
            'id'       => 'registration_add_hospital_success',  //医院，加号成功
            'type'     => Event::REGISTRATION,
            'event_id' => self::EVENT_REGISTRATION_SUCCESS,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            //患者姓名
            'template' => '（%s）的挂号成功',
        ],
        [
            'id'       => 'registration_add_hospital_cancel',   //医院，取消加号
            'type'     => Event::REGISTRATION,
            'event_id' => self::EVENT_REGISTRATION_CANCEL,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            //患者姓名
            'template' => '您取消了（%s）的挂号请求',
        ],
        [
            'id'       => 'registration_add_hospital_fee',   //医院，收到挂号费
            'type'     => Event::REGISTRATION,
            'event_id' => self::EVENT_REGISTRATION_FEE,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            //挂号单号 挂号费
            'template' => '您收到了挂号单%s的挂号费%s元',
        ],
        [
            'id'       => 'combo_pay_slave',           //网点，套餐支付成功
            'type'     => null,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 套餐费
            'template' => '【支付成功】您成功支付%s元。',
        ],
        [
            'id'       => 'combo_pay_patient',           //患者，套餐支付成功
            'type'     => null,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_PATIENT,
            'method'   => self::METHOD_SMS,
            // 套餐费 医院名 套餐名
            'template' => '尊敬的用户，您已成功订购价值%s元的%s的%s，套餐单号为%s，请妥善保管此短信并尽快到医院就诊。',
        ],
        [
            'id'       => 'combo_refund_slave',           //网点，套餐退款成功
            'type'     => null,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 套餐费
            'template' => '【退款成功】您收到了%s元退款，请在钱包里查看。',
        ],
        [
            'id'       => 'combo_refund_patient',           //患者，套餐退款成功
            'type'     => null,
            'event_id' => null,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_PATIENT,
            'method'   => self::METHOD_SMS,
            // 医院名 套餐费
            'template' => '尊敬的用户，您购买的%s的%s元%s已经成功退款。',
        ],
        [
            'id'       => 'product_stock_warning',           //商品库存低于警戒值的提醒
            'type'     => Event::PRODUCT,
            'event_id' => self::EVENT_PRODUCT_STOCK,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 商品名 数量
            'template' => '商品：%s的库存为%s，已低于警戒值。',
        ],
        [
            'id'       => 'combo_new_slave',           //套餐上架 发推送消息给网点app
            'type'     => Event::COMBO,
            'event_id' => self::EVENT_COMBO,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_PUSH,
            'template' => '有新的惠民套餐上线，快来围观！',
        ],
        [
            //todo
            'id'       => 'combo_create_slave',           //医院确认使用套餐：发送站内信给网点
            'type'     => Event::COMBO,
            'event_id' => self::EVENT_COMBO,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 订单号
            'template' => '编号为%s的套餐单已确认使用',
        ],
        [
            //todo
            'id'       => 'combo_to_patient',           //网点分配成功，发送短信给用户
            'type'     => Event::COMBO,
            'event_id' => self::EVENT_COMBO,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_PATIENT,
            'method'   => self::METHOD_SMS,
            // 网点名称 医院名称 套餐名 订单号
            'template' => '尊敬的用户，%s已为您成功订购%s的%s套餐，套餐单号为%s，请妥善保管此短信并尽快到院就诊！',
        ],
        [
            //todo
            'id'       => 'combo_refund_success_slave',           //网点申请退款成功，发送站内信给网点
            'type'     => Event::COMBO,
            'event_id' => self::EVENT_COMBO,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 订单号
            'template' => '编号为%s的套餐单退款成功，资金已退还至您的个人账户！',
        ],
        [
            //todo
            'id'       => 'combo_refund_failed_slave',           //网点申请退款失败：发送站内信给网点
            'type'     => Event::COMBO,
            'event_id' => self::EVENT_COMBO,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_SLAVE,
            'method'   => self::METHOD_MESSAGE,
            // 订单号 原因
            'template' => '编号为%s的套餐单退款失败，失败原因：%s。',
        ],
        [
            'id'       => 'transfer_salesman_bonus',           //转诊结算业务经理获得奖励
            'type'     => Event::COMBO,
            'event_id' => self::EVENT_PROFIT,
            'sender'   => self::ROLE_SYSTEM,
            'receiver' => self::ROLE_MAJOR,
            'method'   => self::METHOD_MESSAGE,
            // 网点名 订单标号 金额
            'template' => '来自%s的转诊单%s结算完成，获得%s元奖励。',
        ],
    ];

    private static $queue;

    public static function stack(Pheanstalk $queue, $user = null, int $acceptWay = 0, string $pushTitle = '', int $organizationId = 0, int $eventId = 0, string $id = '', int $logType = 0, ...$params)
    {
        self::$queue[] = func_get_args();
    }

    public static function flush()
    {
        foreach (self::$queue as $item) {
            self::send(...$item);
        }
    }

    /**
     * @param Pheanstalk $queue
     * @param $user               对象
     * @param int $acceptWay      接收方式
     * @param string $pushTitle   推送的标题
     * @param int $organizationId 机构的ID
     * @param int $eventId        事件订阅的ID
     * @param string $id          $map的id
     * @param int $logType        消息的类型
     * @param array ...$params    参数列表
     *                            场景A:   当单独发送给某个用户当时候必传参  $queue、$user、$acceptWay、$pushTitle、$id、$params
     *                            场景B:   当发送给机构用户当时候不用传参  $user、$acceptWay
     * @throws ParamException
     */
    public static function send(Pheanstalk $queue, $user = null, int $acceptWay = 0, string $pushTitle = '', int $organizationId = 0, int $eventId = 0, string $id = '', int $logType = 0, ...$params)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $users = UserEvent::getUsers((int)$organizationId, (int)$eventId);
            if ($user) {
                $users = is_array($user) ? $user : [$user];
                foreach ($users as $user) {
                    $user->AcceptWay = $acceptWay;
                }
            }
            if (is_array($users) && !empty($users)) {
                $now = time();
                foreach ($users as $v) {
                    $content = self::load($id, self::METHOD_MESSAGE | self::METHOD_PUSH, ...$params);
                    if (property_exists($v, 'AcceptWay')) {
                        if ($v->AcceptWay & self::METHOD_MESSAGE && self::METHOD_MESSAGE & $acceptWay) {
                            $messageLog = new MessageLog();
                            $messageLog->Type = $logType;
                            $messageLog->AcceptId = $v->UserId;
                            $messageLog->OrganizationId = $v->OrganizationId;
                            $messageLog->SendWay = MessageLog::SENDWAY_PUSH;
                            $messageLog->Content = $content;
                            $messageLog->ReleaseTime = $now;
                            $messageLog->Unread = MessageLog::UNREAD_NOT;
                            if ($messageLog->save() === false) {
                                $exception->loadFromModel($messageLog);
                                throw $exception;
                            }
                        }
                        if ($v->AcceptWay & self::METHOD_PUSH && self::METHOD_PUSH & $acceptWay) {
                            $push = new Push($queue);
                            if (!$user) {
                                $user = $v;
                            }
                            if (!APP_DEBUG) {
                                $push->send($user, $pushTitle, $content);
                            }
                        }
                        if ($v->AcceptWay & self::METHOD_SMS && self::METHOD_SMS & $acceptWay) {
                            //验证手机号码
                            if (preg_match('/^1[3456789]\d{9}$/', $v->Phone)) {
                                $content = self::load($id, self::METHOD_SMS, ...$params);
                                $data = ['mobile' => "{$v->Phone}", 'content' => $content];
                                if (!APP_DEBUG) {
                                    $queue->putInTube('sms', json_encode($data, JSON_UNESCAPED_UNICODE));
                                }
                            }
                        }
                    }
                }
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }

    public static function load(string $id, int $method, ...$params): string
    {
        foreach (self::$map as $item) {
            if ($item['id'] === $id && ($method & $item['method']) > 0) {
                if ($method === self::METHOD_SMS) {
                    return self::SMS_PREFIX . sprintf($item['template'], ...$params);
                }
                return sprintf($item['template'], ...$params);
            }
        }
        throw new \RuntimeException('错误的调用参数');
    }
}