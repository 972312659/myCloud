<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/9
 * Time: 10:58 AM
 * 操作权限
 */

namespace App\Enums;


class AppOps
{
    //1=>单选 2=>多选
    const Type_Radio = 1;
    const Type_Multiple = 2;
    const Type_Value_Radio = 1;
    const Type_Value_Multiple = [];

    //0=>未选中 1=>选中
    const Checked_Off = 0;
    const Checked_On = 1;

    //0=>非必选 1=>必选
    const must_choice_no = 0;
    const must_choice_yes = 1;

    //类型
    const OpsType_View = 0;
    const OpsType_Operation = 1;

    private static $map = [
        [
            'Id'   => self::OpsType_View,
            'Name' => '数据查看权限',
            'Data' => [
                [
                    'Id'        => 1,
                    'Name'      => '转诊单管理列表',
                    'AuthSign'  => 'transfer/list',
                    'Type'      => self::Type_Radio,
                    'TypeValue' => self::Type_Value_Radio,
                    'Choice'    => self::must_choice_yes,
                    'Ops'       => [
                        [
                            'Id'      => 1,
                            'Name'    => '全部数据',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 2,
                            'Name'    => '个人相关数据',
                            'Checked' => self::Checked_Off,
                        ],
                    ],
                ],
                [
                    'Id'        => 2,
                    'Name'      => '转诊单结算数据',
                    'AuthSign'  => 'transfer/read',
                    'Type'      => self::Type_Radio,
                    'TypeValue' => self::Type_Value_Radio,
                    'Choice'    => self::must_choice_yes,
                    'Ops'       => [
                        [
                            'Id'      => 1,
                            'Name'    => '显示数据',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 2,
                            'Name'    => '不显示数据',
                            'Checked' => self::Checked_Off,
                        ],
                    ],
                ],
            ],
        ],
        [
            'Id'   => self::OpsType_Operation,
            'Name' => '数据操作权限',
            'Data' => [
                [
                    'Id'        => 1,
                    'Name'      => '转诊订单',
                    'AuthSign'  => 'transfer/operation',
                    'Type'      => self::Type_Multiple,
                    'TypeValue' => self::Type_Value_Multiple,
                    'Choice'    => self::must_choice_no,
                    'Ops'       => [
                        [
                            'Id'      => 1,
                            'Name'    => '确认接诊',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 2,
                            'Name'    => '拒绝接诊',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 3,
                            'Name'    => '确认入院',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 4,
                            'Name'    => '关闭',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 5,
                            'Name'    => '确认出院',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 6,
                            'Name'    => '重新结算',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 7,
                            'Name'    => '查看详情',
                            'Checked' => self::Checked_Off,
                        ],
                        [
                            'Id'      => 8,
                            'Name'    => '删除',
                            'Checked' => self::Checked_Off,
                        ],
                    ],
                ],
            ],
        ],
    ];

    public static function options()
    {
        return array_column(self::$map, 'Id');
    }

    public static function value($Id)
    {
        foreach (self::$map as $item) {
            if ($item['Title'] == $Id) {
                return $item['Name'];
            }
        }
        return null;
    }

    public static function map()
    {
        return self::$map;
    }
}