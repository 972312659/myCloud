<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/14
 * Time: 10:54 AM
 */

namespace App\Libs\combo;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Combo;
use App\Validators\Mobile;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;

class Verify
{
    private $validator;
    private $data;

    public function __construct($data)
    {
        $this->validator = new Validation();
        $this->data = $data;
    }

    public function verify($model, $modelName)
    {
        $exp = new ParamException(Status::BadRequest);
        switch ($modelName) {
            case 'Combo':
                $this->combo($model);
                break;
            case 'ComboOrderBatch':
                $this->comboOrderBatch($model);
                break;
        }
        try {
            $ret = $this->validator->validate($this->data);
            if ($ret->count() > 0) {
                $exp->loadFromMessage($ret);
                throw $exp;
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 验证套餐中的参数
     */
    public function combo($model)
    {
        $this->validator->rule('QuantityBuy',
            new Validation\Validator\PresenceOf(['message' => '购买数量不能为空'])
        );
        if ($model->Stock !== null) {
            $this->validator->rule('QuantityBuy',
                new Validation\Validator\Between(['minimum' => 1, 'maximum' => $model->Stock, 'message' => '购买数量不能超过库存'])
            );
        }
    }

    /**
     * 验证网点批量采购套餐单中的参数
     */
    public function comboOrderBatch($model)
    {

    }

    /**
     * 验证患者必填信息
     */
    public function patientInfo()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->validator->rules('PatientTel', [
                new PresenceOf(['message' => '手机号不能为空']),
                new Mobile(['message' => '请输入正确的手机号']),
            ]);
            $this->validator->rules('PatientName', [
                new PresenceOf(['message' => '姓名不能为空']),
            ]);
            $ret = $this->validator->validate($this->data);
            if ($ret->count() > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }


    public function create()
    {
        //验证库存
        if (!isset($this->data['Stock']) || $this->data['Stock'] == 'null' || (isset($this->data['Stock']) && empty($this->data['Stock']))) {
            $this->data['Stock'] = null;
        } elseif (isset($this->data['Stock']) && !is_numeric($this->data['Stock'])) {
            throw new LogicException('库存错误', Status::BadRequest);
        }

        //验证佣金方式
        if (!isset($this->data['Way']) || !in_array($this->data['Way'], [Combo::WAY_NOTHING, Combo::WAY_FIXED, Combo::WAY_RATIO])) {
            throw new LogicException('', Status::BadRequest);
        }

        if (!isset($this->data['Price']) || !is_numeric($this->data['Price']) || $this->data['Price'] < 0) {
            throw new LogicException('套餐价格大于等于0', Status::BadRequest);
        }

        //验证金额
        switch ($this->data['Way']) {
            case Combo::WAY_NOTHING:
                if (!isset($this->data['InvoicePrice']) || !is_numeric($this->data['InvoicePrice']) || $this->data['Price'] > $this->data['InvoicePrice']) {
                    throw new LogicException('开票价格错误', Status::BadRequest);
                }
                $this->data['Amount'] = 0;
                break;
            case Combo::WAY_FIXED:
                if (!isset($this->data['Amount']) || !is_numeric($this->data['Amount']) || $this->data['Price'] <= $this->data['Amount']) {
                    throw new LogicException('佣金设定必须小于套餐价格', Status::BadRequest);
                }
                break;
            default;
                if (!isset($this->data['Amount']) || !is_numeric($this->data['Amount']) || $this->data['Amount'] > 100) {
                    throw new LogicException('佣金比例不能大于100%', Status::BadRequest);
                }
        }
        return $this->data['Stock'];
    }
}