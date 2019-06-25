<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/8/10
 * Time: 17:39
 */

namespace App\Models;

use Phalcon\Mvc\Model;

class ProductLog extends Model
{
    //状态 0=>无 1=>审核中 2=>审核成功 3=>撤销审核  4=>上架 5=>下架 6=>删除
    const STATUS_NONE = 0;
    const STATUS_AUDITING = 1;
    const STATUS_AUDITED = 2;
    const STATUS_AUDITED_RECALL = 3;
    const STATUS_ON = 4;
    const STATUS_OFF = 5;
    const STATUS_DELETE = 6;
    const STATUS_NAME = ['无', '审核中', '审核成功', '撤销审核', '上架', '下架', '删除'];

    //备注文字内容
    const LOG_ADD = '商品（%s）新建成功，并提交审核';//(商品名)
    const LOG_AUDIT = '商品（%s）修改完成并提交审核';//(商品名)
    const LOG_AUDIT_RECALL = '商品（%s）撤销审核';//(商品名)
    const LOG_AUDIT_STATUS_PART = '商品（%s）部分%s';//部分sku上下架 (商品名 上架/下架)
    const LOG_AUDIT_STATUS_ALL = '商品（%s）全部%s';//全部sku上下架 (商品名 上架/下架)
    const LOG_DELETE_PART = '删除部分商品（%s）';//(商品名)
    const LOG_DELETE_ALL = '删除商品（%s）';//(商品名)

    public $Id;

    public $ProductId;

    public $BeforeStatus;

    public $AfterStatus;

    public $UserId;

    public $UserName;

    public $LogTime;

    public $Remark;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('ProductId', Product::class, 'Id', ['alias' => 'Product']);
        $this->belongsTo('UserId', User::class, 'Id', ['alias' => 'User']);
    }

    public function getSource()
    {
        return 'ProductLog';
    }

    public static function log(int $productId, string $productName, int $beforeStatus, int $afterStatus, int $userId, string $userName, bool $all = false)
    {
        $log = new ProductLog();
        $log->ProductId = $productId;
        $log->BeforeStatus = $beforeStatus;
        $log->AfterStatus = $afterStatus;
        $log->UserId = $userId;
        $log->UserName = $userName;
        switch ($afterStatus) {
            case self::STATUS_AUDITING:
                if ($beforeStatus === self::STATUS_NONE) {
                    $remark = sprintf(self::LOG_ADD, $productName);
                } else {
                    $remark = sprintf(self::LOG_AUDIT, $productName);
                }
                break;
            case self::STATUS_AUDITED_RECALL:
                //1->3
                $remark = sprintf(self::LOG_AUDIT_RECALL, $productName);
                break;
            case self::STATUS_DELETE:
                if ($all) {
                    $remark = sprintf(self::LOG_DELETE_ALL, $productName);
                } else {
                    $remark = sprintf(self::LOG_DELETE_PART, $productName);
                }
                break;
            default:
                //上下架 4->5 5->4
                if ($all) {
                    $remark = sprintf(self::LOG_AUDIT_STATUS_ALL, $productName, self::STATUS_NAME[$afterStatus]);
                } else {
                    $remark = sprintf(self::LOG_AUDIT_STATUS_PART, $productName, self::STATUS_NAME[$afterStatus]);
                }
        }
        $log->Remark = $remark;
        $log->save();
    }

    public function BeforeCreate()
    {
        $this->LogTime = time();
    }
}