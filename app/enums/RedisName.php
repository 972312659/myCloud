<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/3
 * Time: 下午4:11
 */

namespace App\Enums;


class RedisName
{
    const Token = 'Token:';                        //网点登录
    const TokenWeb = 'Token:Web:';                 //医院web端
    const TokenPad = 'Token:Pad:';                //网点pad
    const Permission = 'auth_Permission:';         //登录权限
    const Staff = 'staff_Permission:';             //控台权限
    const Doctor = 'doctor:';                      //自有医生
    const DoctorShare = 'doctorShare:';            //共享医生
    const Section = 'section:';                    //自有科室
    const SectionShare = 'sectionShare:';          //共享科室
    const Combo = 'combo:';                        //自有套餐
    const ComboShare = 'comboShare:';              //共享套餐
    const MerchantCode = 'MerchantCode:';          //商户编码

    // 安全相关
    const SMS_TOTAL = 'sms:%s:%s';              // 某日某手机号验证码短信计数 eg. sms:20170801:18900001111
    const SMS_LAST = 'sms:last:%s';             // 某手机在TTL内是否发过短信 填充手机号
    const SMS_CAPTCHA = 'sms:captcha:%d';       // 短信验证码 填充微秒级时间戳
    const RESET_PASSWORD_TOKEN = 'reset:%s';    // 重置密码token
}