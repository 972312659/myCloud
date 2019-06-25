<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/9/26
 * Time: 下午3:45
 */

namespace App\Enums;


class SmsExtend
{
    const SMS_PREFIX = '【云转诊】';
    /**
     * 创建医院管理员
     */
    const CODE_CREATE_ADMIN_HOSPITAL = 1;
    const CODE_CREATE_ADMIN_HOSPITAL_MESSAGE = self::SMS_PREFIX . '尊敬的用户，云转诊平台已为您开通医院账号，回复「%s」激活账号，否则请忽略';
    /**
     * 创建二级供应商管理员
     */
    const CODE_CREATE_ADMIN_SUPPLIER = 2;
    const CODE_CREATE_ADMIN_SUPPLIER_MESSAGE = self::SMS_PREFIX . '尊敬的用户，%s已为已您开通合作账号，回复「%s」激活账号，否则请忽略';
    /**
     * 创建网点管理员
     */
    //todo 短信更改
    const CODE_CREATE_ADMIN_SLAVE = 3;
    const CODE_CREATE_ADMIN_SLAVE_MESSAGE = self::SMS_PREFIX . '尊敬的用户，%s已为您开通网点账号，打开云转诊app，输入激活码「%s」激活账号，否则请忽略';
    /**
     * 创建医院医生
     */
    const CODE_CREATE_DOCTOR = 4;
    const CODE_CREATE_DOCTOR_MESSAGE = self::SMS_PREFIX . '尊敬的用户，%s已为您开通医生账号，回复「%s」激活账号，否则请忽略';
    /**
     * 创建医院员工
     */
    //todo 短信更改
    const CODE_CREATE_STAFF = 5;
    const CODE_CREATE_STAFF_MESSAGE = self::SMS_PREFIX . '尊敬的用户，%s已为您开通员工账号，回复「%s」激活账号，否则请忽略';
    /**
     * 账号已存在，创建小b与大b的关联关系
     */
    const CODE_CREATE_RELATION_SLAVE_MESSAGE = self::SMS_PREFIX . '尊敬的用户， %s为您开放了云转诊平台的账号，商户号为%s，登录账号及密码未变';
}