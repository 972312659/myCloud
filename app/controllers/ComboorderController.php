<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/1/5
 * Time: 上午9:53
 */

namespace App\Controllers;


use App\Enums\BillTitle;
use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\combo\Order;
use App\Libs\combo\Pay;
use App\Libs\combo\ReadComboOrder;
use App\Libs\combo\ReadComboOrderBatch;
use App\Libs\combo\ReadComboRefund;
use App\Libs\combo\Verify;
use App\Libs\csv\FrontCsv;
use App\Libs\Push;
use App\Libs\Sms;
use App\Libs\combo\Pay as ComboPay;
use App\Libs\transfer\Validate;
use App\Models\Bill;
use App\Models\Combo;
use App\Models\ComboAndOrder;
use App\Models\ComboOrder;
use App\Models\ComboOrderBatch;
use App\Models\ComboOrderLog;
use App\Models\ComboRefund;
use App\Models\ComboRefundLog;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\RuleOfShare;
use App\Models\Section;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserEvent;
use App\Validators\Mobile;
use Phalcon\Db\RawValue;
use Phalcon\Mvc\Model\Query\Builder;
use Phalcon\Paginator\Adapter\NativeArray;
use Phalcon\Paginator\Adapter\QueryBuilder;
use Phalcon\Validation;

class ComboorderController extends Controller
{
    /**
     * 创建套餐订单
     * 修改订单中病人信息
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $now = time();
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登陆', Status::Unauthorized);
            }
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $order = new ComboOrder();
                $data['OrderNumber'] = $now . $auth['OrganizationId'];
                $data['SendHospitalId'] = $auth['HospitalId'];
                $data['SendOrganizationId'] = $auth['OrganizationId'];
                $data['SendOrganizationName'] = $auth['OrganizationName'];
                $data['Status'] = ComboOrder::STATUS_UNPAY;
                $data['Created'] = $now;
                if (!is_numeric($data['PatientSex'])) {
                    $data['PatientSex'] = 1;
                }
                if (!is_numeric($data['PatientAge'])) {
                    $data['PatientAge'] = 0;
                }
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $order = ComboOrder::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$order) {
                    throw $exception;
                }
                unset($data['Status']);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (!isset($data['ComboId']) || empty($data['ComboId'])) {
                throw new LogicException('选择套餐错误', Status::BadRequest);
            }
            $combo = Combo::findFirst(sprintf('Id=%d', $data['ComboId']));
            if (!$combo) {
                throw $exception;
            }
            $hospital = Organization::findFirst($combo->OrganizationId);
            if (!$hospital) {
                throw $exception;
            }
            $data['HospitalId'] = $hospital->Id;
            $data['HospitalName'] = $hospital->Name;
            $data['Genre'] = ($data['SendHospitalId'] == $data['HospitalId'] ? ComboOrder::GENRE_SELF : ComboOrder::GENRE_SHARE);
            if ($order->save($data) === false) {
                $exception->loadFromModel($order);
                throw $exception;
            }
            $order->refresh();
            $old = ComboAndOrder::findFirst([
                'conditions' => 'ComboOrderId=?0',
                'bind'       => [$order->Id],
            ]);
            if (($old && $combo->Id !== $old->ComboId) || !$old) {
                if ($old) {
                    $old->delete();
                }
                $way = $combo->Way;
                $amount = $combo->Amount;
                //共享
                if ($order->Genre == ComboOrder::GENRE_SHARE) {
                    $supplier = OrganizationRelationship::findFirst([
                        "MainId=:MainId: and MinorId=:MinorId:",
                        'bind' => ["MainId" => $order->SendHospitalId, "MinorId" => $order->HospitalId],
                    ]);
                    if ($supplier) {
                        //供应商
                        $rule = RuleOfShare::findFirst([
                            'conditions' => 'CreateOrganizationId=?0 and OrganizationId=?1',
                            'bind'       => [$order->SendHospitalId, $order->HospitalId],
                        ]);
                    } else {
                        //其他共享
                        $rule = RuleOfShare::findFirst(sprintf('Id=%d', $hospital->RuleId));
                    }
                    $way = 2;
                    $amount = $rule->DistributionOut;
                }
                $comboAndOrder = new ComboAndOrder();
                $comboAndOrder->ComboOrderId = $order->Id;
                $comboAndOrder->ComboId = $combo->Id;
                $comboAndOrder->Name = $combo->Name;
                $comboAndOrder->Price = $combo->Price;
                $comboAndOrder->Way = $way;
                $comboAndOrder->Amount = $amount;
                if ($comboAndOrder->save() === false) {
                    $exception->loadFromModel($comboAndOrder);
                    throw $exception;
                }
            } else {
                $comboAndOrder = $old;
            }
            $this->db->commit();
            $this->response->setJsonContent(array_merge($order->toArray(), $comboAndOrder->toArray()));
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 套餐订单列表
     */
    public function listAction()
    {
        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new LogicException('请登陆', Status::Unauthorized);
        }
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->addFrom(ComboOrder::class, 'C')
            ->join(ComboAndOrder::class, 'A.ComboOrderId=C.Id', 'A', 'left');
        $columns = ['C.Id', 'C.OrderNumber', 'C.Created', 'C.PatientName', 'C.PatientName', 'C.PatientAge', 'C.PatientSex', 'C.PatientAddress', 'C.PatientId', 'C.PatientTel', 'C.Status', 'C.Genre', 'A.Name as ComboName', 'A.Price', 'A.Way', 'A.Amount', 'C.SendOrganizationName', 'C.Message'];
        if ($auth['OrganizationId'] == $auth['HospitalId']) {
            //医院端
            $columns = array_merge($columns, ['O.MerchantCode', 'U.Name as Salesman']);
            $query->join(Organization::class, 'O.Id=C.SendOrganizationId', 'O', 'left');
            $query->join(OrganizationRelationship::class, "R.MainId=C.SendHospitalId and R.MinorId=SendOrganizationId", 'R', 'left');
            $query->join(User::class, 'U.Id=R.SalesmanId', 'U', 'left');
            $query->where('C.HospitalId=:HospitalId:', ['HospitalId' => $auth['OrganizationId']]);
        } else {
            //商户端
            $columns = array_merge($columns, ['C.SendOrganizationName', 'O.MerchantCode', 'O.Name as HospitalName', 'B.Image', 'COL.LogTime as UsedTime']);
            $query->join(Organization::class, 'O.Id=C.HospitalId', 'O', 'left');
            $query->join(ComboOrderBatch::class, 'B.Id=A.ComboOrderBatchId', 'B', 'left');
            $query->join(ComboOrderLog::class, 'COL.ComboOrderId=C.Id and COL.Status=3', 'COL', 'left');
            $query->where('C.SendOrganizationId=:SendOrganizationId:', ['SendOrganizationId' => $auth['OrganizationId']])
                ->andWhere(sprintf('C.Status!=%d', ComboOrder::STATUS_CLOSED));
            //子订单
            if (isset($data['ComboOrderBatchId']) && is_numeric($data['ComboOrderBatchId'])) {
                $query->andWhere('A.ComboOrderBatchId=:ComboOrderBatchId:', ['ComboOrderBatchId' => $data['ComboOrderBatchId']]);
            }
        }
        $query->columns($columns);
        //套餐单号
        if (isset($data['OrderNumber']) && !empty($data['OrderNumber'])) {
            $query->andWhere('C.OrderNumber=:OrderNumber:', ['OrderNumber' => $data['OrderNumber']]);
        }
        //患者姓名
        if (isset($data['PatientName']) && !empty($data['PatientName'])) {
            $query->andWhere('C.PatientName=:PatientName:', ['PatientName' => $data['PatientName']]);
        }
        //患者手机号
        if (isset($data['PatientTel']) && !empty($data['PatientTel'])) {
            $query->andWhere('C.PatientTel=:PatientTel:', ['PatientTel' => $data['PatientTel']]);
        }
        //状态
        if (isset($data['Status']) && !empty($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere('C.Status=:Status:', ['Status' => $data['Status']]);
        }
        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("C.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('错误的时间选择', Status::BadRequest);
            }
            $query->andWhere("C.Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        //发起的网点
        if (isset($data['SendOrganizationName']) && !empty($data['SendOrganizationName'])) {
            $query->andWhere('C.SendOrganizationName=:SendOrganizationName:', ['SendOrganizationName' => $data['SendOrganizationName']]);
        }
        $query->andWhere(sprintf('C.IsDeleted=%d', ComboOrder::IsDeleted_No));
        $query->orderBy('C.Created desc');

        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new FrontCsv($query);
            $csv->comboOrderList();
        }

        $paginator = new QueryBuilder(
            [
                "builder" => $query,
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        foreach ($datas as &$data) {
            $data['OrderNumber'] = (string)$data['OrderNumber'];
            $data['StatusName'] = ComboOrder::STATUS_NAME[$data['Status']];
            $data['ShareMoney'] = $data['Way'] == Combo::WAY_FIXED ? $data['Amount'] : $data['Amount'] * $data['Price'] / 100;
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 套餐订单详情
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            /** @var ComboOrder $order */
            $order = ComboOrder::findFirst([
                'conditions' => 'Id=?0 and IsDeleted=?1',
                'bind'       => [$this->request->get('Id'), ComboOrder::IsDeleted_No],
            ]);
            if (!$order || !in_array($this->session->get('auth')['OrganizationId'], [$order->SendOrganizationId, $order->HospitalId])) {
                throw $exception;
            }
            $read = new ReadComboOrder($order);
            $this->response->setJsonContent($read->show());
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 支付套餐费 商户->平台
     * @deprecate
     */
    public function payAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $now = time();
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登陆', Status::Unauthorized);
            }
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                $order = ComboOrder::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$order) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($order->Status == ComboOrder::STATUS_UNPAY) {
                $peach = Organization::findFirst(Organization::PEACH);
                $slave = Organization::findFirst(sprintf('Id=%d', $order->SendOrganizationId));
                if (!$peach || !$slave) {
                    throw $exception;
                }
                $combos = ComboAndOrder::find([
                    "conditions" => "ComboOrderId=?0",
                    "bind"       => [$order->Id],
                ]);
                $money = 0;
                $comboName = '';
                foreach ($combos as $combo) {
                    $money += (int)$combo->Price;
                    $comboName = $combo->Name;
                }
                // 扣除可用余额
                $slave->Money = new RawValue(sprintf('Money-%d', $money));
                $slave->Balance = new RawValue(sprintf('Balance-%d', $money));
                if ($slave->save() === false) {
                    $exception->loadFromModel($slave);
                    throw $exception;
                }
                // 余额不足回滚,返回false
                $slave->refresh();
                if ($slave->Money < 0 || $slave->Balance < 0) {
                    throw new LogicException('余额不足', Status::BadRequest);
                }
                $slave_bill = new Bill();
                $slave_bill->Title = sprintf(BillTitle::ComboOrder_Out, $order->OrderNumber, Alipay::fen2yuan($money));
                $slave_bill->OrganizationId = $slave->Id;
                $slave_bill->Fee = Bill::outCome($money);
                $slave_bill->Balance = $slave->Balance;
                $slave_bill->UserId = $auth['Id'];
                $slave_bill->Type = Bill::TYPE_PAYMENT;
                $slave_bill->Created = $now;
                $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDER;
                $slave_bill->ReferenceId = $order->Id;
                if ($slave_bill->save() === false) {
                    $exception->loadFromModel($slave_bill);
                    throw $exception;
                }
                //将套餐费放入平台账户
                $peach->Money = new RawValue(sprintf('Money+%d', $money));
                $peach->Balance = new RawValue(sprintf('Balance+%d', $money));
                if ($peach->save() === false) {
                    $exception->loadFromModel($peach);
                    throw $exception;
                }
                $peach->refresh();
                $peach_bill = new Bill();
                $peach_bill->Title = sprintf(BillTitle::ComboOrder_In, $auth['HospitalName'], $auth['OrganizationName'], $order->OrderNumber, Alipay::fen2yuan($money), $order->PatientName);
                $peach_bill->OrganizationId = $peach->Id;
                $peach_bill->Fee = Bill::inCome($money);
                $peach_bill->Balance = $peach->Balance;
                $peach_bill->UserId = $auth['Id'];
                $peach_bill->Type = Bill::TYPE_PROFIT;
                $peach_bill->Created = $now;
                $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDER;
                $peach_bill->ReferenceId = $order->Id;
                if ($peach_bill->save() === false) {
                    $exception->loadFromModel($peach_bill);
                    throw $exception;
                }
            } else {
                return $this->response->setJsonContent(['已付款']);
            }
            $order->Status = ComboOrder::STATUS_PAYED;
            if ($order->save() === false) {
                $exception->loadFromModel($order);
                throw $exception;
            }
            $this->db->commit();
            if ($order->Status == ComboOrder::STATUS_PAYED) {
                //付款成功，发送消息
                //发给网点
                MessageTemplate::send(
                    $this->queue,
                    UserEvent::user((int)$order->SendOrganizationId),
                    MessageTemplate::METHOD_MESSAGE,
                    Push::TITLE_FUND,
                    0,
                    0,
                    'combo_pay_slave',
                    MessageLog::TYPE_ACCOUNT_OUT,
                    Alipay::fen2yuan($money)
                );
                //发送给患者
                $content = MessageTemplate::load(
                    'combo_pay_patient',
                    MessageTemplate::METHOD_SMS,
                    Alipay::fen2yuan($money),
                    $order->HospitalName,
                    $comboName,
                    $order->OrderNumber
                );
                $sms = new Sms($this->queue);
                $sms->sendMessage((string)$order->PatientTel, $content);
            }
            $this->response->setJsonContent(['message' => '支付成功']);
            return $this->response->setStatusCode(Status::Created);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 退回套餐费 平台->商户
     */
    public function refundAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $now = time();
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登陆', Status::Unauthorized);
            }
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                $order = ComboOrder::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$order) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($order->Status == ComboOrder::STATUS_PAYED) {
                $peach = Organization::findFirst(Organization::PEACH);
                $slave = Organization::findFirst(sprintf('Id=%d', $order->SendOrganizationId));
                if (!$peach || !$slave) {
                    throw new LogicException('数据错误', Status::BadRequest);
                }
                $combos = ComboAndOrder::find([
                    "conditions" => "ComboOrderId=?0",
                    "bind"       => [$order->Id],
                ]);
                $money = 0;
                $comboName = '';
                foreach ($combos as $combo) {
                    $money += (int)$combo->Price;
                    $comboName = $combo->Name;
                }
                // 扣除可用余额
                //平台账户将套餐费返还
                $peach->Money = new RawValue(sprintf('Money-%d', $money));
                $peach->Balance = new RawValue(sprintf('Balance-%d', $money));
                if ($peach->save() === false) {
                    $exception->loadFromModel($peach);
                    throw $exception;
                }
                // 余额不足回滚,返回false
                $peach->refresh();
                if ($peach->Money < 0 || $peach->Balance < 0) {
                    throw new LogicException('请致电桃子互联网医院', Status::BadRequest);
                }
                $peach_bill = new Bill();
                $peach_bill->Title = sprintf(BillTitle::ComboOrder_Out, $order->OrderNumber, Alipay::fen2yuan($money));
                $peach_bill->OrganizationId = $peach->Id;
                $peach_bill->Fee = Bill::outCome($money);
                $peach_bill->Balance = $peach->Balance;
                $peach_bill->UserId = $auth['Id'];
                $peach_bill->Type = Bill::TYPE_PAYMENT;
                $peach_bill->Created = $now;
                $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDER;
                $peach_bill->ReferenceId = $order->Id;
                if ($peach_bill->save() === false) {
                    $exception->loadFromModel($peach_bill);
                    throw $exception;
                }
                //套餐费收回
                $slave->Money = new RawValue(sprintf('Money+%d', $money));
                $slave->Balance = new RawValue(sprintf('Balance+%d', $money));
                if ($slave->save() === false) {
                    $exception->loadFromModel($slave);
                    throw $exception;
                }
                $slave->refresh();
                $slave_bill = new Bill();
                $slave_bill->Title = sprintf(BillTitle::ComboOrder_In, $order->SendHospital->Name, $order->SendOrganizationName, $order->OrderNumber, Alipay::fen2yuan($money), $order->PatientName);
                $slave_bill->OrganizationId = $slave->Id;
                $slave_bill->Fee = Bill::inCome($money);
                $slave_bill->Balance = $slave->Balance;
                $slave_bill->UserId = $auth['Id'];
                $slave_bill->Type = Bill::TYPE_PROFIT;
                $slave_bill->Created = $now;
                $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDER;
                $slave_bill->ReferenceId = $order->Id;
                if ($slave_bill->save() === false) {
                    $exception->loadFromModel($slave_bill);
                    throw $exception;
                }

            } else {
                throw new LogicException('无权操作', Status::Forbidden);
            }
            $order->Status = ComboOrder::STATUS_CLOSED;
            if ($order->save() === false) {
                $exception->loadFromModel($order);
                throw $exception;
            }
            $this->db->commit();
            if ($order->Status == ComboOrder::STATUS_CLOSED) {
                //退款成功，发送消息
                //发给网点
                MessageTemplate::send(
                    $this->queue,
                    UserEvent::user((int)$order->SendOrganizationId),
                    MessageTemplate::METHOD_MESSAGE,
                    Push::TITLE_FUND,
                    0,
                    0,
                    'combo_refund_slave',
                    MessageLog::TYPE_ACCOUNT_IN,
                    Alipay::fen2yuan($money)
                );
                //发送给患者
                $content = MessageTemplate::load(
                    'combo_refund_patient',
                    MessageTemplate::METHOD_SMS,
                    $order->HospitalName,
                    Alipay::fen2yuan($money),
                    $comboName
                );
                $sms = new Sms($this->queue);
                $sms->sendMessage((string)$order->PatientTel, $content);
            }

            $this->response->setJsonContent(['message' => '退款成功']);
            return $this->response->setStatusCode(Status::Created);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 医院确认到院
     */
    public function arriveAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $now = time();
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登陆', Status::Unauthorized);
            }
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                /** @var ComboOrder $order */
                $order = ComboOrder::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$order) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }

            //验证数据
            $validate = new Validate();
            /** @var Organization $organization */
            $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
            $sectionInfo = $validate->sectionAndDoctor($organization, $data['AcceptSectionId'], $data['AcceptDoctorId']);
            $outpatientOrInpatient = $validate->outpatientOrInpatient($data['OutpatientOrInpatient']);

            //生成转诊单
            $transfer = new Transfer();
            $transfer->PatientName = $data['PatientName'];
            $transfer->PatientAge = isset($data['PatientAge']) && is_numeric($data['PatientAge']) ? $data['PatientAge'] : null;
            $transfer->PatientSex = isset($data['PatientSex']) && in_array($data['PatientSex'], [1, 2]) ? $data['PatientSex'] : null;
            $transfer->PatientSex = $data['PatientSex'] ?: null;
            $transfer->PatientAddress = $data['PatientAddress'];
            $transfer->PatientId = $data['PatientId'];
            $transfer->PatientTel = $data['PatientTel'];
            $transfer->SendHospitalId = $order->SendHospitalId;
            $transfer->SendOrganizationId = $order->SendOrganizationId;
            $transfer->SendOrganizationName = $order->SendOrganizationName;
            $transfer->TranStyle = 1;
            $transfer->AcceptOrganizationId = $order->HospitalId;
            $transfer->OldSectionName = $sectionInfo['SectionName'];
            $transfer->OldDoctorName = $sectionInfo['DoctorName'];
            $transfer->AcceptSectionId = $sectionInfo['SectionId'];
            $transfer->AcceptDoctorId = $sectionInfo['DoctorId'];
            $transfer->AcceptSectionName = $sectionInfo['SectionName'];
            $transfer->AcceptDoctorName = $sectionInfo['DoctorName'];
            $transfer->StartTime = $now;
            $transfer->ClinicTime = $now;
            $transfer->Status = Transfer::TREATMENT;
            $transfer->Remake = $data['Remake'];
            $transfer->OutpatientOrInpatient = $outpatientOrInpatient;
            //转诊单号
            $transfer->OrderNumber = time() << 32 | substr('0000000' . $order->SendOrganizationId, -7, 7);
            //分润
            $hospital = Organization::findFirst(sprintf('Id=%d', $order->HospitalId));
            $hospitalRule = RuleOfShare::findFirst(sprintf('Id=%d', $hospital->RuleId));
            $transfer->CloudGenre = $hospitalRule->Type;
            $transfer->ShareCloud = $hospitalRule->Type == RuleOfShare::RULE_FIXED ? $hospitalRule->Fixed : $hospitalRule->Ratio;
            $transfer->Genre = $order->Genre;
            $combos = ComboAndOrder::find([
                "conditions" => "ComboOrderId=?0",
                "bind"       => [$order->Id],
            ]);
            $money = 0;
            $shareMoney = 0;
            // $shareOne = 0;
            foreach ($combos as $combo) {
                $money += (int)($combo->Price);
                $shareMoney += (int)($combo->Way == Combo::WAY_FIXED ? $combo->Amount : $combo->Price * $combo->Amount / 100);
                // $shareOne = $combo->Amount;
            }

            //网点分润按套餐单来进行
            $transfer->GenreOne = Transfer::FIXED;
            $transfer->ShareOne = $shareMoney;

            if ($order->Genre == ComboOrder::GENRE_SELF) {
                //自有
                $transfer->GenreTwo = 0;
                $transfer->ShareTwo = 0;
            } else {
                //共享
                $supplier = OrganizationRelationship::findFirst([
                    'conditions' => 'MainId=?0 and MinorId=?1',
                    'bind'       => [$order->SendHospitalId, $order->HospitalId],
                ]);
                if ($supplier) {
                    //供应商
                    //平台手续费
                    $sendHospitalRule = RuleOfShare::findFirst(sprintf('Id=%d', $supplier->Main->RuleId));
                    $supplierRule = RuleOfShare::findFirst([
                        'conditions' => 'CreateOrganizationId=?0 and OrganizationId=?1 and Style=?2',
                        'bind'       => [$order->SendHospitalId, $order->HospitalId, RuleOfShare::STYLE_HOSPITAL_SUPPLIER],
                    ]);
                    $transfer->CloudGenre = $sendHospitalRule->Type;
                    $transfer->ShareCloud = $sendHospitalRule->Type == RuleOfShare::RULE_FIXED ? $sendHospitalRule->Fixed : $sendHospitalRule->Ratio;
                    //按比例给上级医院
                    $transfer->GenreTwo = RuleOfShare::RULE_RATIO;
                    $transfer->ShareTwo = $supplierRule->Ratio;
                } else {
                    //其他医院采购套餐
                    //按比例给该小B上面大B分润
                    $transfer->GenreTwo = RuleOfShare::RULE_RATIO;
                    $transfer->ShareTwo = $hospitalRule->DistributionOutB;
                }
            }
            $transfer->Cost = $money;
            $transfer->Source = Transfer::SOURCE_COMBO;
            $transfer->setScene(Transfer::SCENE_COMBOORDER_ARRIVE);
            if ($transfer->save() === false) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }
            $order->Status = ComboOrder::STATUS_USED;
            $order->TransferId = $transfer->Id;
            if ($order->save() === false) {
                $exception->loadFromModel($order);
                throw $exception;
            }
            $this->db->commit();

            //发送消息
            MessageTemplate::send(
                $this->queue,
                UserEvent::user((int)$transfer->SendOrganizationId),
                MessageTemplate::METHOD_MESSAGE,
                Push::TITLE_COMBO,
                0,
                0,
                'combo_create_slave',
                0,
                $order->OrderNumber
            );

            return $this->response->setStatusCode(Status::Created);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 取消订单
     */
    public function closeAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);

            }

            $data = $this->request->getPut();
            /** @var ComboOrderBatch $comboOrderBatch */
            $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $data['Id']));
            if (!$comboOrderBatch || $comboOrderBatch->Status != ComboOrderBatch::STATUS_WAIT_PAY) {
                throw $exception;
            }
            $comboOrderBatch->Status = ComboOrderBatch::STATUS_CLOSED;
            if (!$comboOrderBatch->save()) {
                $exception->loadFromModel($comboOrderBatch);
                throw $exception;
            }
            //关闭相应的小订单
            Order::payedChangeComboOrderStatus($comboOrderBatch);
            $this->response->setJsonContent(['message' => '已取消套餐']);
            return $this->response->setStatusCode(Status::Created);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除套餐单
     */
    public function delAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /** @var ComboOrder $comboOrder */
            $comboOrder = ComboOrder::findFirst([
                'conditions' => 'Id=?0 and IsDeleted=?1',
                'bind'       => [$this->request->get('Id'), ComboOrder::IsDeleted_No],
            ]);
            if (!$comboOrder && in_array($this->session->get('auth')['OrganizationId'], [$comboOrder->SendOrganizationId, $comboOrder->HospitalId])) {
                throw $exception;
            }
            if ($comboOrder->Status != ComboOrder::STATUS_USED) {
                throw new LogicException('套餐订单使用之后才能删除', Status::BadRequest);
            }
            $comboOrder->IsDeleted = ComboOrder::IsDeleted_Yes;
            if (!$comboOrder->save()) {
                $exception->loadFromModel($comboOrder);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }


    /**
     * 创建网点订单
     */
    public function createForSlaveAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getJsonRawBody(true);

            /** @var Combo $combo */
            $combo = Combo::findFirst(sprintf('Id=%d', $data['Id']));
            if (!$combo || $combo->Status != Combo::STATUS_ON) {
                throw $exception;
            }

            //验证库存
            $quantityBuy = (int)$data['QuantityBuy'];
            if ($combo->Stock !== null && ($combo->Stock === 0 || $combo->Stock < $quantityBuy)) {
                $exception->add('QuantityBuy', '套餐数量不足');
                throw $exception;
            }

            //生成订单
            $order = new Order();
            $comboOrderBatch = $order->createComboOrderBatch($combo, $quantityBuy);

            //生成自订单
            $count = count($data['Orders']);
            $comboOrders = [];
            if ($count > 0) {
                if ($count > $comboOrderBatch->QuantityUnAllot) {
                    throw new LogicException('订单数量大于待分配套餐数', Status::BadRequest);
                }
                foreach ($data['Orders'] as $datum) {
                    $comboOrder = $order->createComboOrder($combo, $comboOrderBatch, ComboOrder::STATUS_UNPAY, $datum);
                    $comboOrders[] = $comboOrder;
                }
            }

            $this->db->commit();

            $this->response->setJsonContent(['Id' => $comboOrderBatch->Id, 'Price' => $comboOrderBatch->Price * $comboOrderBatch->QuantityBuy]);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 给患者创建订单
     */
    public function createForPatientAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getJsonRawBody(true);

            /** @var ComboOrderBatch $comboOrderBatch */
            $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $data['Id']));
            /** @var Combo $combo */
            $combo = Combo::findFirst($comboOrderBatch->ComboId);
            if (!$comboOrderBatch || !$combo) {
                throw $exception;
            }

            //验证是否支付
            if ($comboOrderBatch->Status !== ComboOrderBatch::STATUS_WAIT_ALLOT) {
                throw $exception;
            }

            //验证库存
            $quantityBuy = (int)count($data['Orders']);
            if ($comboOrderBatch->QuantityUnAllot === 0 || $comboOrderBatch->QuantityUnAllot < $quantityBuy) {
                $exception->add('QuantityBuy', '套餐数量不足');
                throw $exception;
            }

            //生成订单
            if (!$quantityBuy) {
                throw new LogicException('必须填写分配单', Status::BadRequest);
            }
            $sendMessage = [];
            foreach ($data['Orders'] as $datum) {
                $order = new Order();
                $comboOrder = $order->createComboOrder($combo, $comboOrderBatch, ComboOrder::STATUS_PAYED, $datum);
                $sendMessage[] = [
                    'SendOrganizationName' => $comboOrder->SendOrganizationName,
                    'HospitalName'         => $comboOrder->HospitalName,
                    'OrderNumber'          => $comboOrder->OrderNumber,
                    'PatientTel'           => $comboOrder->PatientTel,
                ];
            }

            $this->db->commit();
            //发送给患者
            if ($sendMessage) {
                foreach ($sendMessage as $item) {
                    $content = MessageTemplate::load(
                        'combo_to_patient',
                        MessageTemplate::METHOD_SMS,
                        $item['SendOrganizationName'],
                        $item['HospitalName'],
                        $comboOrderBatch->Name,
                        $item['OrderNumber']
                    );
                    $sms = new Sms($this->queue);
                    $sms->sendMessage((string)$item['PatientTel'], $content);
                }
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 支付套餐费 商户->平台
     */
    public function newPayAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            /** @var ComboOrderBatch $comboOrderBatch */
            $comboOrderBatch = ComboOrderBatch::findFirst(sprintf('Id=%d', $data['Id']));
            if (!$comboOrderBatch) {
                throw $exception;
            }
            //处理超时
            Order::comboOrderBatchTimeout($comboOrderBatch, time());

            if ($comboOrderBatch->Status == ComboOrderBatch::STATUS_WAIT_PAY) {
                ComboPay::slavePayPeach($comboOrderBatch);
            } else {
                return $this->response->setJsonContent(['已付款']);
            }
            $comboOrderBatch->Status = ComboOrderBatch::STATUS_WAIT_ALLOT;
            $comboOrderBatch->PayTime = time();

            //处理小订单
            Order::payedChangeComboOrderStatus($comboOrderBatch);

            //如果待分配为0
            if ($comboOrderBatch->QuantityUnAllot == 0) {
                $comboOrderBatch->FinishTime = time();
                $comboOrderBatch->Status = ComboOrderBatch::STATUS_USED;
            }

            if ($comboOrderBatch->save() === false) {
                $exception->loadFromModel($comboOrderBatch);
                throw $exception;
            }

            $this->db->commit();
            $this->response->setJsonContent(['message' => '支付成功']);
            return $this->response->setStatusCode(Status::Created);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 套餐包购买列表
     */
    public function batchListAction()
    {
        //处理超时
        $order = new Order();
        $order->comboOrderBatchTimeoutBatch();

        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['B.Id', 'B.Name', 'B.Price', 'B.InvoicePrice', 'B.Way', 'B.MoneyBack', 'B.QuantityBuy', 'B.CreateTime', 'B.QuantityUnAllot', 'B.Status', 'B.Image', 'B.QuantityBack', 'B.QuantityApply'])
            ->addFrom(ComboOrderBatch::class, 'B')
            ->where(sprintf('OrganizationId=%d', $this->session->get('auth')['OrganizationId']));

        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("B.CreateTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('结束时间不能早于开始时间', Status::BadRequest);
            }
            $query->andWhere("B.CreateTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        //状态
        if (isset($data['Status']) && !empty($data['Status'])) {
            $query->andWhere("B.Status=:Status:", ['Status' => $data['Status']]);
        }
        $query->orderBy('B.CreateTime desc');

        $paginator = new QueryBuilder(
            [
                'builder' => $query,
                'limit'   => $pageSize,
                'page'    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items->toArray();
        $now = time();
        foreach ($datas as &$data) {
            if ($data['Status'] == ComboOrderBatch::STATUS_WAIT_PAY) {
                $diffTime = ($data['CreateTime'] + 3600) - $now;
                $data['ExpireTime'] = $diffTime;
            }
            $data['Price'] = $data['Price'] * $data['QuantityBuy'];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 套餐包详情
     */
    public function batchReadAction()
    {
        /** @var ComboOrderBatch $comboOrderBatch */
        $comboOrderBatch = ComboOrderBatch::findFirst([
            'conditions' => 'Id=?0 and OrganizationId=?1',
            'bind'       => [$this->request->get('Id'), $this->session->get('auth')['OrganizationId']],
        ]);
        /** @var Combo $combo */
        $combo = Combo::findFirst(sprintf('Id=%d', $comboOrderBatch->ComboId));
        if (!$comboOrderBatch || !in_array($this->session->get('auth')['OrganizationId'], [$comboOrderBatch->OrganizationId, $combo->OrganizationId])) {
            throw new LogicException('错误', Status::BadRequest);
        }
        $read = new ReadComboOrderBatch($comboOrderBatch);
        $this->response->setJsonContent($read->show());
    }

    /**
     * 申请退款
     */
    public function refundApplyAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPost();

            $order = new Order();
            $order->createRefundOrder($data);

            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }


    /**
     * 套餐单退款单列表-医院
     */
    public function refundListAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $organizationId = $this->session->get('auth')['OrganizationId'];

        $sqlA = "select a.Id,a.OrderNumber,a.ComboName,a.Price,a.Status,a.Created ,c.MinorName SendOrganizationName,u.Name Salesman,'' PatientName,'' PatientTel
from ComboRefund a 
left join ComboOrderBatch b on b.Id=a.ReferenceId 
left join OrganizationRelationship c on c.MainId=b.HospitalId and c.MinorId=b.OrganizationId
left join User u on u.Id=c.SalesmanId
where a.ReferenceType=1 and a.SellerOrganizationId={$organizationId} ";
        $sqlB = "select a.Id,a.OrderNumber,a.ComboName,a.Price,a.Status,a.Created ,m.MinorName SendOrganizationName,n.Name Salesman,e.PatientName,e.PatientTel
from ComboRefund a 
left join ComboOrder e on e.Id=a.ReferenceId
left join ComboAndOrder f on f.ComboOrderId=e.Id
left join OrganizationRelationship m on m.MainId=e.SendHospitalId and m.MinorId=e.SendOrganizationId
left join User n on n.Id=m.SalesmanId
where a.ReferenceType=2 and a.SellerOrganizationId={$organizationId} ";

        $bindA = [];
        $bindB = [];
        //状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            $data['Status'] = $data['Status'] == 6 ? 1 : 2;
            $sqlA .= ' and a.Status=?';
            $sqlB .= ' and a.Status=?';
            $bindA[] = $data['Status'];
            $bindB[] = $data['Status'];
        }
        //来源类型
        if (isset($data['ReferenceType']) && is_numeric($data['ReferenceType'])) {
            $sqlA .= ' and a.ReferenceType=?';
            $sqlB .= ' and a.ReferenceType=?';
            $bindA[] = $data['ReferenceType'];
            $bindB[] = $data['ReferenceType'];
        }
        //来源id
        if (isset($data['ReferenceId']) && is_numeric($data['ReferenceId'])) {
            $sqlA .= ' and a.ReferenceId=?';
            $sqlB .= ' and a.ReferenceId=?';
            $bindA[] = $data['ReferenceId'];
            $bindB[] = $data['ReferenceId'];
        }

        //套餐单号
        if (isset($data['OrderNumber']) && !empty($data['OrderNumber'])) {
            $sqlA .= ' and a.OrderNumber=?';
            $sqlB .= ' and a.OrderNumber=?';
            $bindA[] = $data['OrderNumber'];
            $bindB[] = $data['OrderNumber'];
        }
        //患者姓名
        if (isset($data['PatientName']) && !empty($data['PatientName'])) {
            $sqlA .= ' and a.ReferenceId=2';
            $sqlB .= ' and e.PatientName=?';
            $bindB[] = $data['PatientName'];
        }
        //患者手机号
        if (isset($data['PatientTel']) && !empty($data['PatientTel'])) {
            $sqlA .= ' and a.ReferenceId=2';
            $sqlB .= ' and e.PatientTel=?';
            $bindB[] = $data['PatientTel'];
        }
        //时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $sqlA .= ' and a.Created>=?';
            $sqlB .= ' and a.Created>=?';
            $bindA[] = $data['StartTime'];
            $bindB[] = $data['StartTime'];
        }
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                throw new LogicException('错误的时间选择', Status::BadRequest);
            }
            $sqlA .= ' and a.Created<=?';
            $sqlB .= ' and a.Created<=?';
            $bindA[] = $data['EndTime'] + 86400;
            $bindB[] = $data['EndTime'] + 86400;
        }
        //发起的网点
        if (isset($data['SendOrganizationName']) && !empty($data['SendOrganizationName'])) {
            $sqlA .= ' and c.MinorName=?';
            $sqlB .= ' and m.MinorName=?';
            $bindA[] = $data['SendOrganizationName'];
            $bindB[] = $data['SendOrganizationName'];
        }

        $sql = '(' . $sqlA . ')' . ' union DISTINCT ' . '(' . $sqlB . ')' . '  order by Created desc';
        $bind = array_merge($bindA, $bindB);
        //导出报表
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new FrontCsv(new Builder());
            $csv->refundList($sql, $bind);
        }
        $paginator = new NativeArray([
            'data'  => $this->db->query($sql, $bind)->fetchAll(),
            'limit' => $pageSize,
            'page'  => $page,
        ]);

        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $datas = $pages->items;
        foreach ($datas as &$data) {
            $data['OrderNumber'] = (string)$data['OrderNumber'];
            switch ($data['Status']) {
                case 1:
                    $data['Status'] = 6;
                    $data['StatusName'] = '退款中';
                    break;
                case 2:
                    $data['Status'] = 5;
                    $data['StatusName'] = '已退款';
                    break;
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);


    }

    /**
     * 套餐单退款单列表-网点
     */
    public function refundListSlaveAction()
    {
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['C.Id', 'C.ComboName as Name', 'C.Price', 'C.ReferenceType', 'C.ApplyReason', 'C.RefuseReason',
                'C.ReferenceId', 'C.Created as CreateTime', 'C.Created as ApplyRefundTime',
                'C.Status', 'C.Quantity as QuantityRefund', 'C.Image', 'LA.LogTime as RefundTime', 'LB.LogTime as RefusedTime'])
            ->addFrom(ComboRefund::class, 'C')
            ->leftJoin(ComboRefundLog::class, 'LA.ComboRefundId=C.Id and LA.Status=2', 'LA')
            ->leftJoin(ComboRefundLog::class, 'LB.ComboRefundId=C.Id and LA.Status=3', 'LB')
            ->where(sprintf('C.BuyerOrganizationId=%d', $this->session->get('auth')['OrganizationId']));
        //状态
        if (isset($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere('C.Status=:Status:', ['Status' => $data['Status']]);
        }
        //来源类型
        if (isset($data['ReferenceType']) && is_numeric($data['ReferenceType'])) {
            $query->andWhere('C.ReferenceType=:ReferenceType:', ['ReferenceType' => $data['ReferenceType']]);
        }
        //来源id
        if (isset($data['ReferenceId']) && is_numeric($data['ReferenceId'])) {
            $query->andWhere('C.ReferenceId=:ReferenceId:', ['ReferenceId' => $data['ReferenceId']]);
        }
        $query->orderBy('C.Created desc');

        $paginator = new QueryBuilder(
            [
                'builder' => $query,
                'limit'   => $pageSize,
                'page'    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }


    /**
     * 套餐单退款单详情
     */
    public function refundReadAction()
    {
        /** @var ComboRefund $comboRefund */
        $comboRefund = ComboRefund::findFirst(sprintf('Id=%d', $this->request->get('Id')));
        if (!$comboRefund) {
            throw new LogicException('错误', Status::BadRequest);
        }
        $read = new ReadComboRefund($comboRefund);
        $this->response->setJsonContent($read->show());
    }

    /**
     * 处理退款单
     */
    public function manageRefundAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            /** @var ComboRefund $comboRefund */
            $comboRefund = ComboRefund::findFirst(sprintf('Id=%d', $data['Id']));

            if (!$comboRefund || $comboRefund->Status != ComboRefund::STATUS_WAIT || !in_array($data['Status'], [ComboRefund::STATUS_PASS, ComboRefund::STATUS_UNPASS])) {
                throw new LogicException('错误', Status::BadRequest);
            }
            if ($data['Status'] == ComboRefund::STATUS_UNPASS && (!isset($data['RefuseReason']) || empty($data['RefuseReason']))) {
                throw new LogicException('拒绝退款理由不能为空', Status::BadRequest);
            }
            $comboRefund->Status = $data['Status'];
            $comboRefund->RefuseReason = $data['RefuseReason'];
            if (!$comboRefund->save()) {
                $exception->loadFromModel($comboRefund);
                throw $exception;
            }

            //处理订单状态
            Order::changeStatus($comboRefund);

            if ($comboRefund->Status == ComboRefund::STATUS_PASS) {
                //将套餐金额返还网点
                Pay::refund($comboRefund);
            }

            $this->db->commit();

            switch ($comboRefund->Status) {
                case ComboRefund::STATUS_PASS:
                    //发送消息
                    MessageTemplate::send(
                        $this->queue,
                        UserEvent::user((int)$comboRefund->BuyerOrganizationId),
                        MessageTemplate::METHOD_MESSAGE,
                        Push::TITLE_COMBO,
                        0,
                        0,
                        'combo_refund_success_slave',
                        0,
                        $comboRefund->OrderNumber
                    );

                    break;
                case ComboRefund::STATUS_UNPASS:
                    //发送消息
                    MessageTemplate::send(
                        $this->queue,
                        UserEvent::user((int)$comboRefund->BuyerOrganizationId),
                        MessageTemplate::METHOD_MESSAGE,
                        Push::TITLE_COMBO,
                        0,
                        0,
                        'combo_refund_failed_slave',
                        0,
                        $comboRefund->OrderNumber,
                        $comboRefund->RefuseReason
                    );
                    break;
            }

        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }

    }
}