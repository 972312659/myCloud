<?php
/**
 * Created by IntelliJ IDEA.
 * User: void
 * Date: 2017/9/14
 * Time: 17:03
 */

namespace App\Models;

use Phalcon\Mvc\Model;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;

class TransferPicture extends Model
{
    /**
     * 图片类型
     */
    const TYPE_CASE = 1;        //病例
    const TYPE_FEE = 2;         //费用清单
    const TYPE_THERAPIES = 3;   //治疗方案
    const TYPE_REPORT = 4;      //检查报告
    const TYPE_DIAGNOSIS = 5;   //诊断结论
    const TYPE_NAME = [self::TYPE_CASE => '病例', self::TYPE_FEE => '费用清单', self::TYPE_THERAPIES => '治疗方案', self::TYPE_REPORT => '检查报告', self::TYPE_DIAGNOSIS => '诊断结论',];

    public $Id;

    public $TransferId;

    public $Image;

    public $Type;

    public function initialize()
    {
        $this->useDynamicUpdate(true);
        $this->belongsTo('TransferId', Transfer::class, 'Id', ['alias' => 'Transfer']);
    }

    public function getSource()
    {
        return 'TransferPicture';
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->rules('TransferId', [
            new PresenceOf(['message' => '转诊单id不能为空']),
            new Digit(['message' => '诊单id格式错误']),
        ]);
        $validator->add('Image',
            new PresenceOf(['message' => '图片不能为空'])
        );
        $validator->rules('Type', [
            new PresenceOf(['message' => '类型不能为空']),
            new Digit(['message' => '类型格式错误']),
        ]);
        return $this->validate($validator);
    }
}