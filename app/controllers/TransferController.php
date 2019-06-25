<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/18
 * Time: 上午10:35
 */

namespace App\Controllers;


use App\Enums\BillTitle;
use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\appOps\Control as AppOpsControl;
use App\Libs\combo\Pay as ComboPay;
use App\Libs\csv\FrontCsv;
use App\Libs\Push;
use App\Libs\Sms;
use App\Libs\Sphinx;
use App\Libs\sphinx\TableName as SphinxTableName;
use App\Libs\transfer\Flow;
use App\Libs\transfer\Read;
use App\Libs\transfer\TransferComputing;
use App\Libs\transfer\Validate;
use App\Models\Bill;
use App\Models\ComboOrder;
use App\Models\InteriorTrade;
use App\Models\InteriorTradeAndTransfer;
use App\Models\MessageLog;
use App\Models\OnlineInquiry;
use App\Models\OnlineInquiryTransfer;
use App\Models\Organization;
use App\Models\OrganizationAndSection;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationSendMessageConfig;
use App\Models\OrganizationUser;
use App\Models\Registration;
use App\Models\RuleOfShare;
use App\Models\Section;
use App\Models\Transfer;
use App\Models\TransferFlow;
use App\Models\TransferForOnlineInquiry;
use App\Models\TransferLog;
use App\Models\TransferPicture;
use App\Models\User;
use App\Models\UserEvent;
use App\Validators\IDCardNo;
use App\Validators\Mobile;
use Phalcon\Db\RawValue;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\QueryBuilder;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class TransferController extends Controller
{
    /**
     * 初始化创建转诊记录 Status=1 待处理
     *                Status=2 发起转诊
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $this->db->begin();
                $transfer = new Transfer();
                $data = $this->request->getPost();
                $auth = $this->session->get('auth');
                $hospital = Organization::findFirst(sprintf('Id=%d', $data['AcceptOrganizationId']));
                if (!$hospital) {
                    $exception->add('AcceptOrganizationId', '医院不存在');
                    throw $exception;
                }
                if (isset($data['PatientAge']) && !empty($data['PatientAge']) && !is_numeric($data['PatientAge'])) {
                    $exception->add('PatientAge', '年龄必须是数字');
                    throw $exception;
                } elseif (isset($data['PatientAge']) && $data['PatientAge'] >= 200) {
                    $exception->add('PatientAge', '年龄数值错误');
                    throw $exception;
                } elseif (isset($data['PatientAge']) && $data['PatientAge'] == '') {
                    $data['PatientAge'] = null;
                }
                if (empty($data['PatientSex']) || !is_numeric($data['PatientSex'])) {
                    unset($data['PatientSex']);
                }
                if (!isset($data['SendMessageToPatient']) || $data['SendMessageToPatient'] != Transfer::SEND_MESSAGE_TO_PATIENT_NO) {
                    $data['SendMessageToPatient'] = Transfer::SEND_MESSAGE_TO_PATIENT_YES;
                }
                if (isset($data['PatientId']) && !empty($data['PatientId'])) {
                    $validation = new Validation();
                    $validation->rules('PatientId', [
                        new IDCardNo(['message' => '18位身份证号码错误']),
                    ]);
                    $ret = $validation->validate($this->request->get());
                    if (count($ret) > 0) {
                        $exception->loadFromMessage($ret);
                        throw $exception;
                    }
                }
                $data['SendOrganizationName'] = $auth['OrganizationName'];
                $data['SendOrganizationId'] = $auth['OrganizationId'];
                $data['SendHospitalId'] = $auth['HospitalId'];
                $data['Status'] = Transfer::CREATE;
                $data['TranStyle'] = 1;
                if (!isset($data['OutpatientOrInpatient']) || ($data['OutpatientOrInpatient'] != Transfer::OutpatientOrInpatient_In)) {
                    $data['OutpatientOrInpatient'] = Transfer::OutpatientOrInpatient_Out;
                }
                if (empty($data['AcceptOrganizationId']) || !isset($data['AcceptOrganizationId']) || !is_numeric($data['AcceptOrganizationId'])) {
                    $data['AcceptOrganizationId'] = $auth['HospitalId'];
                }
                if (!empty($data['AcceptSectionId']) && isset($data['AcceptSectionId']) && is_numeric($data['AcceptSectionId'])) {
                    $data['OldSectionName'] = $data['AcceptSectionName'];
                } else {
                    $data['AcceptSectionId'] = 0;
                    unset($data['OldSectionName']);
                }
                if (!empty($data['AcceptDoctorId']) && isset($data['AcceptDoctorId']) && is_numeric($data['AcceptDoctorId'])) {
                    $data['OldDoctorName'] = $data['AcceptDoctorName'];
                    //判断医生是否存在
                    $doctor = OrganizationUser::findFirst([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$data['AcceptOrganizationId'], $data['AcceptDoctorId']],
                    ]);
                    if (!$doctor) {
                        $exception->add('AcceptDoctorId', '医生不存在');
                        throw $exception;
                    } else {
                        if ($doctor->IsDoctor != OrganizationUser::IS_DOCTOR_YES || $doctor->Display != OrganizationUser::DISPLAY_ON) {
                            $exception->add('AcceptDoctorId', '该医生已被撤销');
                            throw $exception;
                        }
                    }
                } else {
                    $data['AcceptDoctorId'] = 0;
                    unset($data['OldDoctorName']);
                }
                $data['StartTime'] = time();
                $data['OrderNumber'] = time() << 32 | substr('0000000' . $data['SendOrganizationId'], -7, 7);
                $relation = OrganizationRelationship::findFirst([
                    "MainId=:MainId: and MinorId=:MinorId:",
                    'bind' => ["MainId" => $data['AcceptOrganizationId'], "MinorId" => $auth['OrganizationId']],
                ]);
                $hospitalRule = RuleOfShare::findFirst(sprintf('Id=%d', $hospital->RuleId));
                $data['CloudGenre'] = $hospitalRule->Type;
                $data['ShareCloud'] = $hospitalRule->Type == RuleOfShare::RULE_FIXED ? $hospitalRule->Fixed : $hospitalRule->Ratio;
                if (!$relation) {
                    $relation_slave = OrganizationRelationship::findFirst([
                        "MainId=:MainId: and MinorId=:MinorId:",
                        'bind' => ["MainId" => $auth['HospitalId'], "MinorId" => $data['AcceptOrganizationId']],
                    ]);
                    if ($relation_slave) {
                        //供应商
                        $sendHospital = Organization::findFirst(sprintf('Id=%d', $auth['HospitalId']));
                        $sendHospitalRule = RuleOfShare::findFirst(sprintf('Id=%d', $sendHospital->RuleId));
                        //平台手续费
                        $data['CloudGenre'] = $sendHospitalRule->Type;
                        $data['ShareCloud'] = $sendHospitalRule->Type == RuleOfShare::RULE_FIXED ? $sendHospitalRule->Fixed : $sendHospitalRule->Ratio;
                        $data['Genre'] = 2;
                        //小B分润
                        $supplierRule = RuleOfShare::findFirst([
                            'conditions' => 'CreateOrganizationId=?0 and OrganizationId=?1 and Style=?2',
                            'bind'       => [$auth['HospitalId'], $hospital->Id, RuleOfShare::STYLE_HOSPITAL_SUPPLIER],
                        ]);
                        $data['GenreOne'] = RuleOfShare::RULE_RATIO;
                        $data['ShareOne'] = $supplierRule->DistributionOut;
                        //上级医院分润
                        $data['GenreTwo'] = RuleOfShare::RULE_RATIO;
                        $data['ShareTwo'] = $supplierRule->Ratio;
                    } else {
                        //共享
                        $data['Genre'] = 2;
                        //按比例给小B
                        $data['GenreOne'] = RuleOfShare::RULE_RATIO;
                        $data['ShareOne'] = $hospitalRule->DistributionOut;
                        //按比例给该小B上面大B分润
                        $data['GenreTwo'] = RuleOfShare::RULE_RATIO;
                        $data['ShareTwo'] = $hospitalRule->DistributionOutB;
                    }
                } else {
                    //自有
                    $data['Genre'] = 1;
                    $data['GenreOne'] = 0;
                    $data['ShareOne'] = 0;
                    $data['GenreTwo'] = 0;
                    $data['ShareTwo'] = 0;
                    $data['SendOrganizationName'] = $relation->MinorName;
                }
                $transfer->setScene(Transfer::SCENE_CREATE);
                if ($transfer->save($data) === false) {
                    $exception->loadFromModel($transfer);
                    throw $exception;
                }
                if (!empty($data['Images']) && isset($data['Images']) && is_array($data['Images'])) {
                    foreach ((array)$data['Images'] as $v) {
                        $picture = new TransferPicture();
                        $picture->TransferId = $transfer->Id;
                        $picture->Type = TransferPicture::TYPE_CASE;
                        $picture->Image = $v;
                        if ($picture->save() === false) {
                            $exception->loadFromModel($picture);
                            throw $exception;
                        }
                    }
                }
                $this->db->commit();
                //创建转诊 小b端消息
                $acceptWay = MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH;
                MessageTemplate::send(
                    $this->queue,
                    UserEvent::user((int)$auth['OrganizationId']),
                    $acceptWay,
                    Push::TITLE_TRANSFER,
                    0,
                    0,
                    'transfer_apply',
                    MessageLog::TYPE_TRANSFER,
                    $data['PatientName'],
                    $hospital->Name,
                    $transfer->OrderNumber
                );
                //大b端消息
                MessageTemplate::send(
                    $this->queue,
                    null,
                    $acceptWay,
                    Push::TITLE_TRANSFER,
                    (int)$transfer->AcceptOrganizationId,
                    MessageTemplate::EVENT_TRANSFER_WAIT,
                    'transfer_receive',
                    MessageLog::TYPE_TRANSFER,
                    $transfer->SendOrganizationName,
                    $transfer->OrderNumber
                );

                $transfer->Status = (int)$transfer->Status;
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($transfer);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 发起转诊    Status=2 设置接诊机构
     * 接受转诊    Status=3
     * 拒绝转诊    Status=4
     * 治疗中      Status=5
     * 出院       Status=6
     * 完成未结算  Status=7
     * 结算完成    Status=8
     */
    public function statusAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                $this->db->begin();
                $auth = $this->session->get('auth');
                $now = time();
                if (!$auth) {
                    throw new LogicException('未登录', Status::Unauthorized);
                }
                /** @var Transfer $transfer */
                $transfer = Transfer::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$transfer) {
                    throw $exception;
                }
                if ($transfer->Sign) {
                    throw $exception;
                }
                $is_registration = false;
                $whiteList = ['Status'];
                switch ($data['Status']) {
                    case Transfer::ACCEPT :
                        if ($transfer->Status != Transfer::CREATE) {
                            throw new LogicException('订单状态已变化，请刷新页面', Status::BadRequest);
                        }
                        if (isset($data['PatientAge']) && !empty($data['PatientAge']) && !is_numeric($data['PatientAge'])) {
                            throw new LogicException('年龄必须是数字', Status::BadRequest);
                        } elseif (isset($data['PatientAge']) && $data['PatientAge'] >= 200) {
                            throw new LogicException('年龄数值错误', Status::BadRequest);
                        } elseif (isset($data['PatientAge']) && $data['PatientAge'] == '') {
                            $data['PatientAge'] = null;
                        }
                        $acceptSection = Section::findFirst(sprintf('Id=%d', $data['AcceptSectionId']));
                        $acceptDoctor = User::findFirst(sprintf('Id=%d', $data['AcceptDoctorId']));
                        if (!$acceptSection || !$acceptDoctor) {
                            throw $exception;
                        }
                        $data['AcceptSectionName'] = $acceptSection->Name;
                        $data['AcceptDoctorName'] = $acceptDoctor->Name;
                        //判断医生是否存在
                        $doctor = OrganizationUser::findFirst([
                            'conditions' => 'OrganizationId=?0 and UserId=?1',
                            'bind'       => [$auth['OrganizationId'], $data['AcceptDoctorId']],
                        ]);
                        if (!$doctor) {
                            $exception->add('AcceptDoctorId', '医生不存在');
                            throw $exception;
                        } else {
                            if ($doctor->IsDoctor != OrganizationUser::IS_DOCTOR_YES || $doctor->Display != OrganizationUser::DISPLAY_ON) {
                                $exception->add('AcceptDoctorId', '该医生已被撤销');
                                throw $exception;
                            }
                        }
                        if (!isset($data['OutpatientOrInpatient']) || !is_numeric($data['OutpatientOrInpatient'])) {
                            throw new LogicException('请选择覆盖范围', Status::BadRequest);
                        }
                        $data['OutpatientOrInpatient'] = $data['OutpatientOrInpatient'] == Transfer::OutpatientOrInpatient_In ? Transfer::OutpatientOrInpatient_In : Transfer::OutpatientOrInpatient_Out;
                        $whiteList = ['Status', 'ClinicTime', 'AcceptSectionId', 'AcceptDoctorId', 'AcceptSectionName', 'AcceptDoctorName', 'Remake', 'OutpatientOrInpatient'];
                        break;
                    case Transfer::REFUSE :
                        if (!in_array((int)$transfer->Status, [Transfer::CREATE, Transfer::ACCEPT])) {
                            throw new LogicException('订单状态已变化，请刷新页面', Status::BadRequest);
                        }
                        $whiteList = ['Status', 'Explain', 'EndTime'];
                        $data['EndTime'] = time();
                        if (isset($data['Closed']) && !empty($data['Closed']) && $data['Closed']) {
                            $data['Explain'] = '病人未到院';
                        }
                        break;
                    case Transfer::TREATMENT:
                        if ($transfer->Status != Transfer::ACCEPT) {
                            throw new LogicException('订单状态已变化，请刷新页面', Status::BadRequest);
                        }
                        $whiteList = ['Status', 'PatientName', 'PatientId', 'OutpatientOrInpatient'];
                        if (isset($data['PatientId']) && !empty($data['PatientId'])) {
                            $transfer->setScene(Transfer::SCENE_STATUS_TREATMENT);
                        }
                        //挂号生成的转诊单处理
                        $registration = Registration::findFirst(sprintf('TransferId=%d', $transfer->Id));
                        if ($registration) {
                            $is_registration = true;
                            //付款给医院
                            $peach = Organization::findFirst(Organization::PEACH);
                            $hospital = Organization::findFirst(sprintf('Id=%d', $registration->HospitalId));
                            if (!$peach || !$hospital) {
                                throw $exception;
                            }
                            $money = (int)($registration->Price + $registration->ServicePackagePrice ?: 0);
                            //平台账户将挂号费返回到网点账户
                            $peach->Money = new RawValue(sprintf('Money-%d', $money));
                            $peach->Balance = new RawValue(sprintf('Balance-%d', $money));
                            if ($peach->save() === false) {
                                $exception->loadFromModel($peach);
                                throw $exception;
                            }
                            // 余额不足回滚,返回false
                            $peach->refresh();
                            if ($peach->Money < 0 || $peach->Balance < 0) {
                                throw new LogicException('平台余额不足', Status::BadRequest);
                            }
                            $peach_bill = new Bill();
                            $peach_bill->Title = sprintf(BillTitle::Registration_Out, $registration->OrderNumber, Alipay::fen2yuan($money));
                            $peach_bill->OrganizationId = $peach->Id;
                            $peach_bill->Fee = Bill::outCome($money);
                            $peach_bill->Balance = $peach->Balance;
                            $peach_bill->UserId = $auth['Id'];
                            $peach_bill->Type = Bill::TYPE_PAYMENT;
                            $peach_bill->Created = $now;
                            $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_REGISTRATION;
                            $peach_bill->ReferenceId = $registration->Id;
                            if ($peach_bill->save() === false) {
                                $exception->loadFromModel($peach_bill);
                                throw $exception;
                            }
                            //挂号费到医院账户
                            $hospital->Money = new RawValue(sprintf('Money+%d', $money));
                            $hospital->Balance = new RawValue(sprintf('Balance+%d', $money));
                            if ($hospital->save() === false) {
                                $exception->loadFromModel($hospital);
                                throw $exception;
                            }
                            $hospital->refresh();
                            $hospital_bill = new Bill();
                            $hospital_bill->Title = sprintf(BillTitle::Registration_In, $registration->SendHospital->Name, $registration->SendOrganization->Name, $registration->OrderNumber, Alipay::fen2yuan($money));
                            $hospital_bill->OrganizationId = $hospital->Id;
                            $hospital_bill->Fee = Bill::inCome($money);
                            $hospital_bill->Balance = $hospital->Balance;
                            $hospital_bill->UserId = $auth['Id'];
                            $hospital_bill->Type = Bill::TYPE_PROFIT;
                            $hospital_bill->Created = $now;
                            $hospital_bill->ReferenceType = Bill::REFERENCE_TYPE_REGISTRATION;
                            $hospital_bill->ReferenceId = $registration->Id;
                            if ($hospital_bill->save() === false) {
                                $exception->loadFromModel($hospital_bill);
                                throw $exception;
                            }
                        }
                        break;
                }
                if ($transfer->save($data, $whiteList) === false) {
                    $exception->loadFromModel($transfer);
                    throw $exception;
                }
                $this->db->commit();
                //发短信给患者
                if ($transfer->Status == Transfer::ACCEPT) {
                    $hospital = Organization::findFirst(sprintf('Id=%d', $transfer->AcceptOrganizationId));
                    $address = $hospital->Province->Name . $hospital->City->Name . $hospital->Area->Name . " " . $hospital->Address;
                    $content = MessageTemplate::load('transfer_patient_dispatch', MessageTemplate::METHOD_SMS, $transfer->SendOrganizationName, $hospital->Name, $address, $transfer->OrderNumber, date('Y年m月d日H点', $transfer->ClinicTime), $transfer->AcceptDoctorName, $transfer->AcceptSectionName, $hospital->Tel);
                    //发短信给患者
                    if ($transfer->SendMessageToPatient == Transfer::SEND_MESSAGE_TO_PATIENT_YES) {
                        $sms = new Sms($this->queue);
                        $sms->sendMessage((string)$transfer->PatientTel, $content);
                    }
                    //发消息给小b
                    MessageTemplate::send(
                        $this->queue,
                        UserEvent::user((int)$transfer->SendOrganizationId),
                        MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH,
                        Push::TITLE_TRANSFER,
                        0,
                        0,
                        'transfer_slave_dispatch',
                        MessageLog::TYPE_TRANSFER,
                        $transfer->PatientName,
                        $hospital->Name,
                        $transfer->OrderNumber,
                        date('Y年m月d日H点', $transfer->ClinicTime),
                        $transfer->AcceptDoctorName,
                        $transfer->AcceptSectionName
                    );
                }
                if ($transfer->Status == Transfer::REFUSE) {
                    //发送消息给大b
                    MessageTemplate::send(
                        $this->queue,
                        null,
                        MessageTemplate::METHOD_MESSAGE,
                        Push::TITLE_TRANSFER,
                        (int)$transfer->AcceptOrganizationId,
                        MessageTemplate::EVENT_TRANSFER_WAIT,
                        'transfer_major_refuse',
                        MessageLog::TYPE_TRANSFER,
                        $transfer->SendOrganizationName,
                        $transfer->OrderNumber
                    );
                    //发送消息给小b
                    /** @var OrganizationRelationship $organizationRelation */
                    $organizationRelation = OrganizationRelationship::findFirst([
                        'conditions' => 'MainId=?0 and MinorId=?1',
                        'bind'       => [$transfer->SendHospitalId, $transfer->SendOrganizationId],
                    ]);
                    /** @var User $salesman */
                    $salesman = User::findFirst(sprintf('Id=%d', $organizationRelation->SalesmanId));
                    MessageTemplate::send(
                        $this->queue,
                        UserEvent::user((int)$transfer->SendOrganizationId),
                        MessageTemplate::METHOD_MESSAGE,
                        Push::TITLE_TRANSFER,
                        0,
                        0,
                        'transfer_slave_refuse',
                        MessageLog::TYPE_TRANSFER,
                        $transfer->Hospital->Name,
                        $transfer->PatientName,
                        $salesman->Phone
                    );
                }
                if ($transfer->Status == Transfer::TREATMENT) {
                    if ($is_registration) {
                        //挂号 病人到院 挂号费消息
                        MessageTemplate::send(
                            $this->queue,
                            null,
                            MessageTemplate::METHOD_MESSAGE,
                            Push::TITLE_REGISTRATION,
                            (int)$registration->HospitalId,
                            MessageTemplate::EVENT_REGISTRATION_FEE,
                            'registration_add_hospital_fee',
                            MessageLog::TYPE_REGISTRATION,
                            $registration->OrderNumber,
                            Alipay::fen2yuan((int)$registration->Price)
                        );
                    }
                    //发消息给小b
                    MessageTemplate::send(
                        $this->queue,
                        UserEvent::user((int)$transfer->SendOrganizationId),
                        MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH,
                        Push::TITLE_TRANSFER,
                        0,
                        0,
                        'transfer_slave_check_in',
                        MessageLog::TYPE_TRANSFER,
                        $transfer->OrderNumber,
                        $transfer->PatientName,
                        date('Y年m月d日H点')
                    );
                    //发消息给大B
                    MessageTemplate::send(
                        $this->queue,
                        null,
                        MessageTemplate::METHOD_MESSAGE,
                        Push::TITLE_TRANSFER,
                        (int)$transfer->AcceptOrganizationId,
                        MessageTemplate::EVENT_IN_HOSPITAL,
                        'transfer_major_check_in',
                        MessageLog::TYPE_TRANSFER,
                        $transfer->OrderNumber,
                        $transfer->PatientName
                    );
                }
                $transfer->Status = (int)($transfer->Status);
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($transfer);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    public function readAction($id)
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            $conditions = $auth['HospitalId'] == $auth['OrganizationId'] ? 'Id=?0 and AcceptOrganizationId=?1' : 'Id=?0 and SendOrganizationId=?1';

            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst([
                'conditions' => $conditions,
                'bind'       => [$id, $auth['OrganizationId']],
            ]);

            if (!$transfer) {
                throw $exception;
            }

            if ($transfer->Sign) {
                throw $exception;
            }

            if ($auth['HospitalId'] == $auth['OrganizationId']) {
                if ($transfer->IsDeleted) {
                    throw $exception;
                }
            } else {
                if ($transfer->IsDeletedForSendOrganization) {
                    throw $exception;
                }
            }

            $read = new Read($transfer);
            $this->response->setJsonContent($read->show());
        } catch (ParamException $e) {
            throw $e;
        }
    }

    public function listAction()
    {
        $response = new Response();
        $data = $this->request->get();
        $auth = $this->session->get('auth');
        if (!$auth) {
            $response->setStatusCode(Status::Unauthorized);
            return $response;
        }
        //todo 去掉特殊处理
        //处理
        if ($auth['HospitalId'] != $auth['OrganizationId'] && $auth['HospitalId'] == 3301) {
            return;
        }

        //数据权限
        if ($auth['HospitalId'] == $auth['OrganizationId']) {
            //判断超级管理员
            if ($auth['Phone'] != $auth['OrganizationPhone']) {
                $access = AppOpsControl::transferList();
                if (!$access['Access']) {
                    return;
                }
            }
        }

        $organization = ($auth['HospitalId'] == $auth['OrganizationId'] ? 'AcceptOrganizationId' : 'SendOrganizationId');

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder();
        $columns = 'T.Id,T.PatientName,T.PatientAge,T.PatientSex,T.PatientAddress,T.PatientId,T.PatientTel,T.SendHospitalId,T.SendOrganizationId,T.SendOrganizationName,T.TranStyle,T.AcceptOrganizationId,T.AcceptSectionId,T.AcceptDoctorId,T.AcceptSectionName,T.AcceptDoctorName,T.Disease,T.StartTime,T.ClinicTime,T.LeaveTime,T.EndTime,T.Status,T.OrderNumber,T.ShareOne,T.ShareTwo,T.ShareCloud,T.Remake,T.Genre,T.GenreOne,T.GenreTwo,T.Explain,T.Cost,T.CloudGenre,O.Phone as Come_Phone,OA.Name as HospitalName';
        $query->addFrom(Transfer::class, 'T');
        $query->where("{$organization}=:OrganizationId:", ['OrganizationId' => $auth['OrganizationId']]);
        $query->andWhere("T.Sign=0");
        if ($auth['HospitalId'] == $auth['OrganizationId']) {
            $query->andWhere("T.IsDeleted=0");
        } else {
            $query->andWhere("T.IsDeletedForSendOrganization=0");
        }
        //搜索订单号
        if (!empty($data['OrderNumber']) && isset($data['OrderNumber'])) {
            $query->andWhere('OrderNumber=:OrderNumber:', ['OrderNumber' => $data['OrderNumber']]);
        }
        //搜索网点名字，转诊来源
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'organization');
            $name = $sphinx->match($data['Name'], 'alias')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('O.Id', $ids);
            } else {
                $query->inWhere('O.Id', [-1]);
            }
            // $query->andWhere('SendOrganizationName=:SendOrganizationName:', ['SendOrganizationName' => $data['Name']]);
        }
        //医生姓名
        if (isset($data['AcceptDoctorName']) && !empty($data['AcceptDoctorName'])) {
            $sphinx = new Sphinx($this->sphinx, SphinxTableName::Transfer);
            $name = $sphinx->match($data['AcceptDoctorName'], 'doctorname')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('T.Id', $ids);
            } else {
                $query->inWhere('T.Id', [-1]);
            }
        }
        //科室
        if (isset($data['AcceptSectionId']) && is_numeric($data['AcceptSectionId'])) {
            $query->andWhere("T.AcceptSectionId=:AcceptSectionId:", ['AcceptSectionId' => $data['AcceptSectionId']]);
        }
        //是否住院
        if (isset($data['OutpatientOrInpatient']) && is_numeric($data['OutpatientOrInpatient'])) {
            $query->andWhere("T.OutpatientOrInpatient=:OutpatientOrInpatient:", ['OutpatientOrInpatient' => $data['OutpatientOrInpatient']]);
        }
        //患者姓名
        if (isset($data['PatientName']) && !empty($data['PatientName'])) {
            $sphinx = new Sphinx($this->sphinx, SphinxTableName::Transfer);
            $name = $sphinx->match($data['PatientName'], 'patientname')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('T.Id', $ids);
            } else {
                $query->inWhere('T.Id', [-1]);
            }
        }
        //患者电话
        if (isset($data['PatientTel']) && !empty($data['PatientTel'])) {
            $query->andWhere("T.PatientTel=:PatientTel:", ['PatientTel' => $data['PatientTel']]);
        }
        //待入院天数
        if (isset($data['Day']) && is_numeric($data['Day'])) {
            $dayTime = strtotime(date('Y-m-d', strtotime("-{$data['Day']} day")));
            $query->andWhere("L.LogTime<=:LogTime:", ['LogTime' => $dayTime]);
        }
        //搜索状态
        if (!empty($data['Status']) && isset($data['Status'])) {
            $query->andWhere("T.Status=:Status:", ['Status' => $data['Status']]);
        }
        //诊单类型
        if (!empty($data['Genre']) && isset($data['Genre'])) {
            $query->andWhere("T.Genre=:Genre:", ['Genre' => $data['Genre']]);
        }
        //已出院
        if (isset($data['Leave']) && is_numeric($data['Leave']) && $data['Leave']) {
            $query->inWhere('T.Status', [Transfer::LEAVE, Transfer::NOTPAY, Transfer::REPEAT]);
        }
        //哪种时间方式，默认为发起转诊时间
        $timeName = 'T.StartTime';
        if (isset($data['TimeWay']) && is_numeric($data['TimeWay'])) {
            switch ($data['TimeWay']) {
                case 1://发起转诊时间
                    $timeName = 'T.StartTime';
                    break;
                case 2://接诊时间
                    $timeName = 'L.LogTime';
                    break;
                case 3://入院时间
                    $timeName = 'T.ClinicTime';
                    break;
                case 4://出院时间
                    $timeName = 'T.LeaveTime';
                    break;
                case 5://完结时间
                    $timeName = 'T.EndTime';
                    break;
            }
        }

        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("{$timeName}>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                $response->setStatusCode(Status::BadRequest);
                return $response;
            }
            $query->andWhere("{$timeName}<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        $query->join(Organization::class, 'O.Id=SendOrganizationId', 'O', 'left');
        $query->join(Organization::class, 'OA.Id=AcceptOrganizationId', 'OA', 'left');
        if ($auth['HospitalId'] == $auth['OrganizationId']) {
            $query->join(OrganizationRelationship::class, "R.MinorId=O.Id and R.MainId={$auth['OrganizationId']}", 'R', 'left');
            $query->join(User::class, 'U.Id=R.SalesmanId', 'U', 'left');
            $query->join(TransferLog::class, 'L.TransferId=T.Id and L.Status=3', 'L', 'left');
            $columns = 'if(T.Genre=1,R.MinorName,T.SendOrganizationName) SendOrganizationName,T.Id,T.PatientName,T.PatientAge,T.PatientSex,T.PatientAddress,T.PatientId,T.PatientTel,T.SendHospitalId,T.SendOrganizationId,T.TranStyle,T.AcceptOrganizationId,T.AcceptSectionId,T.AcceptDoctorId,T.AcceptSectionName,T.AcceptDoctorName,T.Disease,T.StartTime,T.ClinicTime,T.LeaveTime,T.EndTime,T.Status,T.OrderNumber,T.ShareOne,T.ShareTwo,T.ShareCloud,T.Remake,T.Genre,T.GenreOne,T.GenreTwo,T.Explain,T.Cost,T.CloudGenre,T.OutpatientOrInpatient,O.Phone as Come_Phone,OA.Name as HospitalName,U.Name as Salesman';

            //数据权限
            //判断超级管理员
            if ($auth['Phone'] != $auth['OrganizationPhone']) {
                if (!$access['All']) {
                    $query->andWhere(sprintf('R.SalesmanId=%d', $auth['Id']));
                }
            }
        }
        $query->columns($columns);
        //时间排序的方式
        $timeSort = isset($data['TimeAsc']) && $data['TimeAsc'] ? 'asc' : 'desc';
        $query->orderBy("T.StartTime {$timeSort}");
        if (isset($data['Id']) && is_numeric($data['Id'])) {
            $query->andWhere(sprintf('T.Id<%d', $data['Id']));
            $page = 1;
        }
        //导出csv
        if (isset($data['Export']) && !empty($data['Export'])) {
            $csv = new FrontCsv($query);
            $csv->transfer();
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
            if ($data['Status'] >= Transfer::LEAVE) {
                $data['Share'] = ($data['GenreOne'] === 1 ? $data['ShareOne'] : round($data['Cost'] * $data['ShareOne'] / 100, 2));
                $Two = $data['GenreTwo'] != 0 ? ($data['GenreTwo'] === 1 ? $data['Cost'] + $data['ShareTwo'] : round($data['Cost'] * $data['ShareTwo'] / 100, 2)) : 0;
                $Cloud = ($data['CloudGenre'] === 1 ? $data['Cost'] + $data['ShareCloud'] : round($data['Cost'] * $data['ShareCloud'] / 100, 2));
                $data['Other'] = $Two + $Cloud;
                $data['Pay'] = $data['Share'] + $data['Other'];
            } else {
                $data['Share'] = 0;
            }
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
            $data['OutpatientOrInpatient'] = Transfer::OutpatientOrInpatient_Name[$data['OutpatientOrInpatient']];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    public function pictureAction()
    {
        $data = $this->request->get();
        $query = TransferPicture::query();
        $query->columns('TransferId,Image,Type');
        $query->where("TransferId=:TransferId:");
        $bind['TransferId'] = $data['TransferId'];
        if (!empty($data['Type']) && isset($data['Type']) && is_numeric($data['Type'])) {
            $query->andWhere("Type=:Type:");
            $bind['Type'] = $data['Type'];
        }
        $query->bind($bind);
        $pictures = $query->execute();
        $this->response->setJsonContent($pictures);
    }

    public function logsAction()
    {
        $response = new Response();
        $transferId = $this->request->get('TransferId');
        $logs = TransferLog::find(sprintf('TransferId=%d', $transferId))->toArray();
        if ($logs) {
            foreach ($logs as &$log) {
                $log['Info'] = Transfer::STATUS_NAME[$log['Status']];
            }
        }
        $response->setJsonContent($logs);
        return $response;
    }

    /**
     * 转账完成确认，生成分润财务审核单
     */
    public function finishAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $data = $this->request->getPut();
            $data['Cost'] = (int)explode('.', $data['Cost'])[0];
            $auth = $this->session->get('auth');
            $now = time();
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst(sprintf('Id=%d', $data['Id']));
            if (!$transfer) {
                throw $exception;
            }
            if ($transfer->Sign) {
                throw $exception;
            }
            //验证上传
            if (!$transfer->TherapiesExplain || ctype_space($transfer->TherapiesExplain)) {
                $therapiesPicture = TransferPicture::findFirst([
                    'conditions' => 'TransferId=?0 and Type=?1',
                    'bind'       => [$transfer->Id, TransferPicture::TYPE_THERAPIES]]);
                if (!$therapiesPicture) {
                    throw new LogicException('请完善治疗方案', Status::BadRequest);
                }
            }
            if (!$transfer->DiagnosisExplain || ctype_space($transfer->DiagnosisExplain)) {
                $diagnosisPicture = TransferPicture::findFirst([
                    'conditions' => 'TransferId=?0 and Type=?1',
                    'bind'       => [$transfer->Id, TransferPicture::TYPE_DIAGNOSIS]]);
                if (!$diagnosisPicture) {
                    throw new LogicException('请完善诊断结论', Status::BadRequest);
                }
            }
            if (!$transfer->FeeExplain || ctype_space($transfer->FeeExplain)) {
                $feePicture = TransferPicture::findFirst([
                    'conditions' => 'TransferId=?0 and Type=?1',
                    'bind'       => [$transfer->Id, TransferPicture::TYPE_FEE]]);
                if (!$feePicture) {
                    throw new LogicException('请完善收费汇总', Status::BadRequest);
                }
            }
            $cost = $transfer->Cost;
            switch ($transfer->Status) {
                case Transfer::TREATMENT:
                    $transfer->Status = Transfer::LEAVE;
                    $transfer->Cost = (int)($data['Cost']);
                    $transfer->EndTime = $now;
                    $transfer->LeaveTime = (int)$data['LeaveTime'];
                    $transfer->Explain = $data['Explain'];
                    //从新选择科室和医生
                    $validateDoctor = false;
                    if (isset($data['AcceptSectionId']) && is_numeric($data['AcceptSectionId'])) {
                        $validateDoctor = true;
                        if ($transfer->AcceptSectionId != $data['AcceptSectionId']) {
                            /** @var OrganizationAndSection $organizationAndSection */
                            $organizationAndSection = OrganizationAndSection::findFirst([
                                'conditions' => 'OrganizationId=?0 and SectionId=?1',
                                'bind'       => [$transfer->AcceptOrganizationId, $data['AcceptSectionId']],
                            ]);
                            if (!$organizationAndSection || $organizationAndSection->Display != OrganizationAndSection::DISPLAY_ON) {
                                throw new LogicException('必须是展示出来的科室或科室信息错误', Status::BadRequest);
                            }
                            $transfer->AcceptSectionId = $organizationAndSection->SectionId;
                            $transfer->AcceptSectionName = $organizationAndSection->Section->Name;
                        }
                    }
                    if (isset($data['AcceptDoctorId']) && is_numeric($data['AcceptDoctorId'])) {
                        $validateDoctor = true;
                        if ($transfer->AcceptDoctorId != $data['AcceptDoctorId']) {
                            /** @var OrganizationUser $organizationUser */
                            $organizationUser = OrganizationUser::findFirst([
                                'conditions' => 'OrganizationId=?0 and UserId=?1',
                                'bind'       => [$transfer->AcceptOrganizationId, $data['AcceptDoctorId']],
                            ]);
                            if (!$organizationUser || $organizationUser->Display != OrganizationUser::DISPLAY_ON) {
                                throw new LogicException('必须是展示出来的医生或医生信息错误', Status::BadRequest);
                            }
                            $transfer->AcceptDoctorId = $organizationUser->UserId;
                            $transfer->AcceptDoctorName = $organizationUser->User->Name;
                        }
                    }
                    //验证医生
                    if ($validateDoctor) {
                        $user = OrganizationUser::findFirst([
                            'conditions' => 'OrganizationId=?0 and UserId=?1 and SectionId=?2',
                            'bind'       => [$transfer->AcceptOrganizationId, $transfer->AcceptDoctorId, $transfer->AcceptSectionId],
                        ]);
                        if (!$user) {
                            throw new LogicException('医生信息错误', Status::BadRequest);
                        }
                    }
                    /** 院内流转 */
                    if (isset($data['FlowSectionId']) || isset($data['FlowDoctorId']) || isset($data['FlowOutpatientOrInpatient'])) {
                        $flow = new Flow($transfer);
                        $flow->create($data['FlowSectionId'], $data['FlowDoctorId'], $data['FlowOutpatientOrInpatient']);
                    }
                    break;
                case Transfer::NOTPAY:
                    /** 不能院内流转 */
                    if (isset($data['FlowSectionId']) || isset($data['FlowDoctorId']) || isset($data['FlowOutpatientOrInpatient'])) {
                        throw new LogicException('只有财务未审核的单子才能流转', Status::BadRequest);
                    }
                    $transfer->Status = Transfer::REPEAT;
                    $transfer->Cost = (int)($data['Cost']);
                    break;
                default:
                    throw $exception;
            }
            //如果是来自套餐的转诊，费用是固定的
            if ($transfer->Source == Transfer::SOURCE_COMBO) {
                $transfer->Cost = $cost;
            }
            //分润
            $computing = new TransferComputing();
            $result = $computing->computing($transfer, (int)$transfer->Cost);
            $money_cloud = $result['ShareNum'];
            $money_b = $result['ShareOneNum'];
            $money_b_B = $result['ShareTwoNum'];
            //自有转诊
            if ($transfer->Genre == Transfer::GENRE_SELF && $transfer->Source != Transfer::SOURCE_COMBO) {
                $transfer->GenreOne = $result['GenreOne'];
                $transfer->ShareOne = $result['ShareOne'];
            }
            $transfer->CloudGenre = Transfer::RATIO;
            $transfer->ShareCloud = $computing['Ratio'];
            if ($transfer->save() === false) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }
            $money_b = floor($money_b);
            $money_cloud = floor($money_cloud);
            $money_b_B = floor($money_b_B);
            //B应该出的费用
            $money_B = intval($money_b + $money_cloud + $money_b_B);
            //生成财务审核单
            $interiorTrade = new InteriorTrade();
            $interiorTrade->SendOrganizationId = $auth['OrganizationId'];
            $interiorTrade->SendOrganizationName = $auth['OrganizationName'];
            $interiorTrade->AcceptOrganizationId = $transfer->SendOrganizationId;
            $interiorTrade->AcceptOrganizationName = $transfer->SendOrganizationName;
            $interiorTrade->Status = InteriorTrade::STATUS_WAIT;
            $interiorTrade->Style = InteriorTrade::STYLE_TRANSFER;
            $interiorTrade->Created = $now;
            $interiorTrade->Total = $money_B;
            if ($interiorTrade->save() === false) {
                $exception->loadFromModel($interiorTrade);
                throw $exception;
            }
            $interiorTrade->refresh();
            //建立关系
            $interiorTradeTransfer = new InteriorTradeAndTransfer();
            $interiorTradeTransfer->TransferId = $transfer->Id;
            $interiorTradeTransfer->InteriorTradeId = $interiorTrade->Id;
            $interiorTradeTransfer->Amount = $money_b;
            $interiorTradeTransfer->ShareCloud = $money_cloud;
            if ($interiorTradeTransfer->save() === false) {
                $exception->loadFromModel($interiorTradeTransfer);
                throw $exception;
            }
            //将套餐费用支付给医院
            $comboOrder = ComboOrder::findFirst(sprintf('TransferId=%d', $transfer->Id));
            if ($comboOrder) {
                if ($comboOrder->Status == ComboOrder::STATUS_USED) {
                    $peach = Organization::findFirst(Organization::PEACH);
                    $hospital = Organization::findFirst(sprintf('Id=%d', $comboOrder->HospitalId));
                    if (!$peach || !$hospital) {
                        throw $exception;
                    }
                    // 扣除可用余额
                    $money = (int)($transfer->Cost);
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
                    $peach_bill->Title = sprintf(BillTitle::ComboOrder_Out, $comboOrder->OrderNumber, Alipay::fen2yuan($money));
                    $peach_bill->OrganizationId = $peach->Id;
                    $peach_bill->Fee = Bill::outCome($money);
                    $peach_bill->Balance = $peach->Balance;
                    $peach_bill->UserId = $auth['Id'];
                    $peach_bill->Type = Bill::TYPE_PAYMENT;
                    $peach_bill->Created = $now;
                    $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDER;
                    $peach_bill->ReferenceId = $comboOrder->Id;
                    if ($peach_bill->save() === false) {
                        $exception->loadFromModel($peach_bill);
                        throw $exception;
                    }
                    //套餐费收回
                    $hospital->Money = new RawValue(sprintf('Money+%d', $money));
                    $hospital->Balance = new RawValue(sprintf('Balance+%d', $money));
                    if ($hospital->save() === false) {
                        $exception->loadFromModel($hospital);
                        throw $exception;
                    }
                    $hospital->refresh();
                    $slave_bill = new Bill();
                    $slave_bill->Title = sprintf(BillTitle::ComboOrder_In, $comboOrder->SendHospital->Name, $comboOrder->SendOrganizationName, $comboOrder->OrderNumber, Alipay::fen2yuan($money), $comboOrder->PatientName);
                    $slave_bill->OrganizationId = $hospital->Id;
                    $slave_bill->Fee = Bill::inCome($money);
                    $slave_bill->Balance = $hospital->Balance;
                    $slave_bill->UserId = $auth['Id'];
                    $slave_bill->Type = Bill::TYPE_PROFIT;
                    $slave_bill->Created = $now;
                    $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_COMBOORDER;
                    $slave_bill->ReferenceId = $comboOrder->Id;
                    if ($slave_bill->save() === false) {
                        $exception->loadFromModel($slave_bill);
                        throw $exception;
                    }
                }
            }
            $this->db->commit();
            //发送出院消息
            if ($transfer->Status == Transfer::LEAVE) {
                //给医院
                MessageTemplate::send(
                    $this->queue,
                    null,
                    MessageTemplate::METHOD_MESSAGE,
                    Push::TITLE_TRANSFER,
                    (int)$transfer->AcceptOrganizationId,
                    MessageTemplate::EVENT_OUT_HOSPITAL,
                    'transfer_major_patient_leave_hospital',
                    MessageLog::TYPE_TRANSFER,
                    $transfer->OrderNumber,
                    $transfer->PatientName,
                    date('Y年m月d日H点', $transfer->LeaveTime)
                );
                //给小b
                MessageTemplate::send(
                    $this->queue,
                    UserEvent::user((int)$transfer->SendOrganizationId),
                    MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH,
                    Push::TITLE_TRANSFER,
                    0,
                    0,
                    'transfer_slave_patient_leave_hospital',
                    MessageLog::TYPE_TRANSFER,
                    $transfer->OrderNumber,
                    $transfer->PatientName,
                    date('Y年m月d日H点', $transfer->LeaveTime)
                );
            }
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 逻辑删除转诊单（医院）
     */
    public function delAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isDelete()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $hospitalId = $this->session->get('auth')['OrganizationId'];
            if (!$hospitalId) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $transfer = Transfer::findFirst(sprintf('Id=%d', $this->request->getPut('Id', 'int')));
            if (!$transfer) {
                throw $exception;
            }
            if ($transfer->AcceptOrganizationId != $hospitalId) {
                throw new LogicException('无权操作', Status::Forbidden);
            }
            if ($transfer->Genre == Transfer::GENRE_SHARE) {
                //共享转诊已接诊的转诊单不能被删除
                if ($transfer->Status == Transfer::ACCEPT || $transfer->Status >= Transfer::TREATMENT) {
                    throw new LogicException('已接诊的共享转诊单不能被删除', Status::BadRequest);
                }
            } else {
                //自有转诊单 6=>出院 7=>财务审核未通过 9=>重新提交 三个状态不能被删除
                if (in_array($transfer->Status, [Transfer::LEAVE, Transfer::NOTPAY, Transfer::REPEAT])) {
                    throw new LogicException('转诊单已进入财务审核状态，不能被删除', Status::BadRequest);
                }
            }
            $transfer->IsDeleted = Transfer::ISDELETED_YES;
            if (!$transfer->save()) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 结算时计算分润数值
     */
    public function calculatorAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $cost = (int)explode('.', $this->request->get('Cost'))[0];
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst([
                'conditions' => 'Id=?0 and AcceptOrganizationId=?1',
                'bind'       => [$this->request->get('Id', 'int'), $this->session->get('auth')['OrganizationId']],
            ]);
            if (!$transfer) {
                throw $exception;
            }
            $computing = new TransferComputing();
            $result = $computing->computing($transfer, $cost);
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 逻辑删除转诊单（网点）
     */
    public function delForSlaveAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst([
                'conditions' => 'Id=?0 and SendOrganizationId=?1',
                'bind'       => [$this->request->getPut('Id', 'int'), $this->session->get('auth')['OrganizationId']],
            ]);
            if (!$transfer) {
                throw $exception;
            }
            $transfer->IsDeletedForSendOrganization = Transfer::ISDELETED_YES;
            if (!$transfer->save()) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 修改科室
     */
    public function changeSectionAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            $auth = $this->session->get('auth');
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst([
                'conditions' => 'Id=?0 and AcceptOrganizationId=?1',
                'bind'       => [$data['Id'], $auth['OrganizationId']],
            ]);
            if (!$transfer) {
                throw $exception;
            }
            //更改是否住院
            if (isset($data['OutpatientOrInpatient']) && is_numeric($data['OutpatientOrInpatient']) && in_array($data['OutpatientOrInpatient'], [Transfer::OutpatientOrInpatient_Out, Transfer::OutpatientOrInpatient_In])) {
                $transfer->OutpatientOrInpatient = $data['OutpatientOrInpatient'];
            }
            //从新选择科室和医生
            if (isset($data['AcceptSectionId']) && is_numeric($data['AcceptSectionId'])) {
                if ($transfer->AcceptSectionId != $data['AcceptSectionId']) {
                    /** @var OrganizationAndSection $organizationAndSection */
                    $organizationAndSection = OrganizationAndSection::findFirst([
                        'conditions' => 'OrganizationId=?0 and SectionId=?1',
                        'bind'       => [$transfer->AcceptOrganizationId, $data['AcceptSectionId']],
                    ]);
                    if (!$organizationAndSection || $organizationAndSection->Display != OrganizationAndSection::DISPLAY_ON) {
                        throw new LogicException('必须是展示出来的科室或科室信息错误', Status::BadRequest);
                    }
                    $transfer->AcceptSectionId = $organizationAndSection->SectionId;
                    $transfer->AcceptSectionName = $organizationAndSection->Section->Name;
                }
            }
            if (isset($data['AcceptDoctorId']) && is_numeric($data['AcceptDoctorId'])) {
                if ($transfer->AcceptDoctorId != $data['AcceptDoctorId']) {
                    /** @var OrganizationUser $organizationUser */
                    $organizationUser = OrganizationUser::findFirst([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$transfer->AcceptOrganizationId, $data['AcceptDoctorId']],
                    ]);
                    if (!$organizationUser || $organizationUser->Display != OrganizationUser::DISPLAY_ON) {
                        throw new LogicException('必须是展示出来的医生或医生信息错误', Status::BadRequest);
                    }
                    $transfer->AcceptDoctorId = $organizationUser->UserId;
                    $transfer->AcceptDoctorName = $organizationUser->User->Name;
                }
            }
            if (!$transfer->save()) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 修改患者信息
     */
    public function updatePatientInfoAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getPut();
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst([
                'conditions' => 'Id=?0 and AcceptOrganizationId=?1',
                'bind'       => [$data['Id'], $this->session->get('auth')['OrganizationId']],
            ]);
            if (!$transfer) {
                throw $exception;
            }
            $validation = new Validation();
            //患者姓名
            $validation->rules('PatientName', [new PresenceOf(['message' => '姓名不能为空'])]);
            $transfer->PatientName = $data['PatientName'];
            //年龄
            if (isset($data['PatientAge']) && !empty($data['PatientAge'])) {
                $validation->rules('PatientAge', [
                    new Digit(['message' => '年龄必须是数字']),
                ]);
                $transfer->PatientAge = $data['PatientAge'];
            }
            //性别
            if (isset($data['PatientSex']) && !empty($data['PatientSex'])) {
                $validation->rules('PatientSex', [
                    new Digit(['message' => '性别错误']),
                ]);
                $transfer->PatientSex = $data['PatientSex'];
            }
            //地址
            if (isset($data['PatientAddress']) && !empty($data['PatientAddress'])) {
                $validation->rules('PatientAddress', [
                    new StringLength(["min" => 0, "max" => 200, "messageMaximum" => '地址最长不超过200个字符']),
                ]);
                $transfer->PatientAddress = $data['PatientAddress'];
            }
            //身份证
            if (isset($data['PatientId']) && !empty($data['PatientId'])) {
                $validation->rules('PatientId', [
                    new IDCardNo(['message' => '18位身份证号码错误']),
                ]);
                $transfer->PatientId = $data['PatientId'];
            }
            //电话
            if (isset($data['PatientTel']) && !empty($data['PatientTel'])) {
                $validation->rules('PatientTel', [
                    new Mobile(['message' => '请输入正确的手机号']),
                ]);
                $transfer->PatientTel = $data['PatientTel'];
            }
            $ret = $validation->validate($this->request->getPut());
            if (count($ret) > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }
            if (!$transfer->save()) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 院内流转
     */
    public function flowAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getJsonRawBody(true);
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst([
                'conditions' => 'Id=?0 and AcceptOrganizationId=?1',
                'bind'       => [$data['Id'], $this->session->get('auth')['OrganizationId']],
            ]);
            if (!$transfer) {
                throw $exception;
            }
            $data['Cost'] = (int)explode('.', $data['Cost'])[0];
            /** 不能院内流转 */
            if ($transfer->Status != Transfer::TREATMENT) {
                throw new LogicException('患者只有在治疗中才能流转', Status::BadRequest);
            }
            /** 来自套餐的转诊，原始单不能修改金额，流转后可以修改 */
            if (
                $transfer->Source == Transfer::SOURCE_COMBO &&
                !TransferFlow::findFirst(['conditions' => 'TransferId=?0', 'bind' => $transfer->Id]) &&
                $data['Cost'] != $transfer->Cost
            ) {
                throw new LogicException('来自套餐单的转诊单不能修改金额', Status::BadRequest);
            }

            /** @var Organization $organization */
            $organization = Organization::findFirst(sprintf('Id=%d', $transfer->AcceptOrganizationId));

            //验证其他数据
            $validate = new Validate();
            $cost = $validate->cost($data['Cost']);
            $flowOutpatientOrInpatient = $validate->outpatientOrInpatient($data['FlowOutpatientOrInpatient']);
            $flowSectionAndDoctor = $validate->sectionAndDoctor($organization, $data['FlowSectionId'], $data['FlowDoctorId'], 'flow');

            //更新医生转诊数量
            /** @var User $doctor */
            $doctor = User::findFirst(sprintf('Id=%d', $transfer->AcceptDoctorId));
            $doctor->TransferAmount = new RawValue(sprintf('TransferAmount+%d', 1));
            $doctor->save();

            //生成TransferFlow
            $flow = new Flow($transfer);
            //如果有变化，则更新transfer的基础数据
            $transferInfo = [
                'AcceptSectionId'       => $data['AcceptSectionId'],
                'AcceptDoctorId'        => $data['AcceptDoctorId'],
                'OutpatientOrInpatient' => $data['OutpatientOrInpatient'],
            ];
            $flow->updateTransferInfo($transferInfo);
            $flow->remark(['ClinicRemark' => $data['ClinicRemark'], 'FinishRemark' => $data['FinishRemark']]);

            //计算分润数据
            $computing = new TransferComputing();
            $result = $computing->computing($transfer, $cost);
            $flowData['Cost'] = $cost;
            $flowData['CloudGenre'] = $result['CloudGenre'];
            $flowData['ShareCloud'] = $result['ShareCloud'];
            $flowData['GenreOne'] = $result['GenreOne'];
            $flowData['ShareOne'] = $result['ShareOne'];
            $flowData['TherapiesExplain'] = $data['TherapiesExplain'];
            $flowData['ReportExplain'] = $data['ReportExplain'];
            $flowData['DiagnosisExplain'] = $data['DiagnosisExplain'];
            $flowData['FeeExplain'] = $data['FeeExplain'];
            $flowData['TherapiesExplainImages'] = $data['TherapiesExplainImages'];
            $flowData['ReportExplainImages'] = $data['ReportExplainImages'];
            $flowData['DiagnosisExplainImages'] = $data['DiagnosisExplainImages'];
            $flowData['FeeExplainImages'] = $data['FeeExplainImages'];
            $flowData['LeaveTime'] = $data['LeaveTime'];
            $transferFlow = $flow->createTransferFlowModel($flowData);
            //生成TransferFlow后验证补充说明
            $validate->addedExplanation($transferFlow);

            //更新Transfer
            $flow->flowOutpatientOrInpatient = $flowOutpatientOrInpatient;
            $flow->flowAcceptSectionId = $flowSectionAndDoctor['SectionId'];
            $flow->flowAcceptSectionName = $flowSectionAndDoctor['SectionName'];
            $flow->flowAcceptDoctorId = $flowSectionAndDoctor['DoctorId'];
            $flow->flowAcceptDoctorName = $flowSectionAndDoctor['DoctorName'];
            $flow->updateTransfer();

            //记录日志
            $flow->transferLog();

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
     * 病人出院，生产财务审核单
     */
    public function createInteriorTradeAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getJsonRawBody(true);
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst([
                'conditions' => 'Id=?0 and AcceptOrganizationId=?1',
                'bind'       => [$data['Id'], $this->session->get('auth')['OrganizationId']],
            ]);
            if (!$transfer) {
                throw $exception;
            }
            $data['Cost'] = (int)explode('.', $data['Cost'])[0];
            /** 不能生产财务审核单 */
            if ($transfer->Status != Transfer::TREATMENT) {
                throw new LogicException('患者只有在治疗中才能生产财务审核单', Status::BadRequest);
            }

            /** 来自套餐的转诊，原始单不能修改金额，流转后可以修改 */
            if (
                $transfer->Source == Transfer::SOURCE_COMBO &&
                !TransferFlow::findFirst(['conditions' => 'TransferId=?0', 'bind' => $transfer->Id]) &&
                $data['Cost'] != $transfer->Cost
            ) {
                throw new LogicException('来自套餐单的转诊单不能修改金额', Status::BadRequest);
            }

            //验证其他数据
            $validate = new Validate();
            $cost = $validate->cost($data['Cost']);

            //生成TransferFlow
            $flow = new Flow($transfer);
            //更新transfer的基础数据
            $transferInfo = [
                'AcceptSectionId'       => $data['AcceptSectionId'],
                'AcceptDoctorId'        => $data['AcceptDoctorId'],
                'OutpatientOrInpatient' => $data['OutpatientOrInpatient'],
            ];
            $flow->updateTransferInfo($transferInfo);
            $flow->remark(['Remark' => $data['Remark'], 'FinishRemark' => $data['FinishRemark']]);

            //计算分润数据
            $computing = new TransferComputing();
            $result = $computing->computing($transfer, $cost);
            $flowData['Cost'] = $cost;
            $flowData['CloudGenre'] = $result['CloudGenre'];
            $flowData['ShareCloud'] = $result['ShareCloud'];
            $flowData['GenreOne'] = $result['GenreOne'];
            $flowData['ShareOne'] = $result['ShareOne'];
            $flowData['TherapiesExplain'] = $data['TherapiesExplain'];
            $flowData['ReportExplain'] = $data['ReportExplain'];
            $flowData['DiagnosisExplain'] = $data['DiagnosisExplain'];
            $flowData['FeeExplain'] = $data['FeeExplain'];
            $flowData['TherapiesExplainImages'] = $data['TherapiesExplainImages'];
            $flowData['ReportExplainImages'] = $data['ReportExplainImages'];
            $flowData['DiagnosisExplainImages'] = $data['DiagnosisExplainImages'];
            $flowData['FeeExplainImages'] = $data['FeeExplainImages'];
            $flowData['LeaveTime'] = $data['LeaveTime'];
            $transferFlow = $flow->createTransferFlowModel($flowData);
            //生成TransferFlow后验证补充说明
            $validate->addedExplanation($transferFlow);

            //更新Transfer
            $totalCost = TransferComputing::totalCost($transfer);
            $transfer->Cost = $totalCost['TotalCost'];
            if ($totalCost['Count'] > 1) {
                $transfer->GenreOne = Transfer::FIXED;
                $transfer->ShareOne = $totalCost['ShareOneNum'];
            }
            $transfer->Status = Transfer::LEAVE;
            $transfer->LeaveTime = $data['LeaveTime'];
            if (!$transfer->save()) {
                $exception->loadFromModel($transfer);
                throw $exception;
            }
            //生成财务审核单
            $interiorTrade = new \App\Libs\transfer\InteriorTrade();
            $interiorTrade->create($transfer);

            //将套餐费用支付给医院
            /** @var ComboOrder $comboOrder */
            $comboOrder = ComboOrder::findFirst(sprintf('TransferId=%d', $transfer->Id));
            if ($comboOrder) {
                if ($comboOrder->Status == ComboOrder::STATUS_USED) {
                    ComboPay::peachPayHospital($comboOrder);
                }
            }
            $this->db->commit();
            //发送出院消息
            if ($transfer->Status == Transfer::LEAVE) {
                //给医院
                MessageTemplate::send(
                    $this->queue,
                    null,
                    MessageTemplate::METHOD_MESSAGE,
                    Push::TITLE_TRANSFER,
                    (int)$transfer->AcceptOrganizationId,
                    MessageTemplate::EVENT_OUT_HOSPITAL,
                    'transfer_major_patient_leave_hospital',
                    MessageLog::TYPE_TRANSFER,
                    $transfer->OrderNumber,
                    $transfer->PatientName,
                    date('Y年m月d日H点', $transfer->LeaveTime)
                );
                //给小b
                MessageTemplate::send(
                    $this->queue,
                    UserEvent::user((int)$transfer->SendOrganizationId),
                    MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH,
                    Push::TITLE_TRANSFER,
                    0,
                    0,
                    'transfer_slave_patient_leave_hospital',
                    MessageLog::TYPE_TRANSFER,
                    $transfer->OrderNumber,
                    $transfer->PatientName,
                    date('Y年m月d日H点', $transfer->LeaveTime)
                );
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
     * 财务审核未通过重新提交
     */
    public function resubmissionAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $data = $this->request->getJsonRawBody(true);
            /** @var Transfer $transfer */
            $transfer = Transfer::findFirst([
                'conditions' => 'Id=?0 and AcceptOrganizationId=?1',
                'bind'       => [$data['Id'], $this->session->get('auth')['OrganizationId']],
            ]);
            if (!$transfer) {
                throw $exception;
            }
            /** 不能生产财务审核单 */
            if ($transfer->Status != Transfer::NOTPAY) {
                throw new LogicException('此状态不能重新生产财务审核单', Status::BadRequest);
            }

            $validate = new Validate();
            /** @var Organization $organization */
            $organization = Organization::findFirst(sprintf('Id=%d', $transfer->AcceptOrganizationId));

            $interiorTrade = new \App\Libs\transfer\InteriorTrade();
            //处理TransferFlow及Transfer数据
            $interiorTrade->resubmissionForTransferFlow($organization, $transfer, $validate, $data['Flows']);
            //生成财务审核单
            $interiorTrade->create($transfer);
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function updateInquiryTransferAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPut()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $auth = $this->session->get('auth');
            /** @var OnlineInquiryTransfer $onlineInquiryTransfer */
            $onlineInquiryTransfer = OnlineInquiryTransfer::findFirst([
                'conditions' => 'KeyID=?0 and ClinicID=?1 and OrderStatus=?2',
                'bind'       => [$this->request->getPut('keyID'), $auth['OrganizationId'], OnlineInquiryTransfer::OrderStatus_Wait],
            ]);
            $status = (int)$this->request->getPut('orderStatus');
            if (!$onlineInquiryTransfer || !in_array($status, [OnlineInquiryTransfer::OrderStatus_Accept, OnlineInquiryTransfer::OrderStatus_Cancel])) {
                throw $exception;
            }
            $sendMessageToPatient = ['sendSlave' => false, 'sendPatient' => false];
            switch ($status) {
                case OnlineInquiryTransfer::OrderStatus_Cancel:
                    $onlineInquiryTransfer->OrderStatus = OnlineInquiryTransfer::OrderStatus_Cancel;
                    break;
                case OnlineInquiryTransfer::OrderStatus_Accept:
                    $onlineInquiryTransfer->OrderStatus = OnlineInquiryTransfer::OrderStatus_Accept;

                    //远程问诊
                    /** @var OnlineInquiry $onlineInquiry */
                    $onlineInquiry = OnlineInquiry::findFirst([
                        'conditions' => 'KeyID=?0',
                        'bind'       => [$onlineInquiryTransfer->OnlineInquiryID],
                    ]);

                    //医院
                    /** @var Organization $hospital */
                    $hospital = Organization::findFirst(sprintf('Id=%d', $onlineInquiry->DoctorHospitalID));
                    //网点
                    /** @var Organization $slave */
                    $slave = Organization::findFirst(sprintf('Id=%d', $onlineInquiry->ClinicID));
                    //医生
                    /** @var User $acceptDoctor */
                    $acceptDoctor = User::findFirst(sprintf('Id=%d', $onlineInquiryTransfer->DoctorID));
                    //科室
                    /** @var Section $acceptSection */
                    $acceptSection = Section::findFirst(sprintf('Id=%d', $onlineInquiryTransfer->SectionID));
                    //远程问诊医生
                    /** @var User $doctor */
                    $doctor = User::findFirst(sprintf('Id=%d', $onlineInquiry->DoctorID));
                    //消息
                    /** @var OrganizationSendMessageConfig $sendMessage */
                    $sendMessage = OrganizationSendMessageConfig::findFirst([
                        'conditions' => 'OrganizationId=?0 and Type=?1',
                        'bind'       => [$auth['OrganizationId'], OrganizationSendMessageConfig::TYPE_SEND_TO_PATIENT],
                    ]);

                    //生成对应的转诊单
                    $transfer = new TransferForOnlineInquiry();
                    $transfer->PatientName = $onlineInquiryTransfer->PatientName;
                    $transfer->PatientTel = $onlineInquiryTransfer->PatientTel;
                    $transfer->PatientSex = $onlineInquiryTransfer->PatientSex;
                    $transfer->PatientId = $onlineInquiryTransfer->PatientID;
                    $transfer->SendHospitalId = $onlineInquiryTransfer->HospitalID;
                    $transfer->Disease = $onlineInquiryTransfer->Disease;
                    $transfer->SendOrganizationId = $slave->Id;
                    $transfer->SendOrganizationName = $slave->Name;
                    $transfer->TranStyle = 1;
                    $transfer->AcceptOrganizationId = $hospital->Id;
                    $transfer->StartTime = time();
                    $transfer->ClinicTime = strtotime($onlineInquiryTransfer->ClinicTime);
                    $transfer->Status = 3;
                    $transfer->OrderNumber = time() << 32 | substr('0000000' . $slave->Id, -7, 7);;
                    $transfer->Genre = $onlineInquiryTransfer->HospitalID == $onlineInquiry->DoctorHospitalID ? Transfer::GENRE_SELF : Transfer::GENRE_SHARE;
                    $transfer->CloudGenre = 2;
                    $transfer->AcceptSectionId = $acceptSection->Id;
                    $transfer->AcceptSectionName = $acceptSection->Name;
                    $transfer->OldSectionName = $acceptSection->Name;
                    $transfer->AcceptDoctorId = $acceptDoctor->Id;
                    $transfer->AcceptDoctorName = $acceptDoctor->Name;
                    $transfer->OldDoctorName = $acceptDoctor->Name;
                    $transfer->Source = 4;
                    $transfer->SendMessageToPatient = $sendMessage ? ($sendMessage->AgreeSendMessage == OrganizationSendMessageConfig::AGREE_SEND_YES ? Transfer::SEND_MESSAGE_TO_PATIENT_YES : Transfer::SEND_MESSAGE_TO_PATIENT_NO) : Transfer::SEND_MESSAGE_TO_PATIENT_NO;
                    if ($transfer->save() === false) {
                        $exception->loadFromModel($transfer);
                        throw $exception;
                    }

                    //生成日志
                    TransferLog::addLog($hospital->Id, $hospital->Name, $doctor->Id, $doctor->Name, $transfer->Id, (int)$transfer->Status, $transfer->StartTime);

                    $address = $hospital->Province->Name . $hospital->City->Name . $hospital->Area->Name . " " . $hospital->Address;
                    $sendMessageToPatient = [
                        'sendSlave'         => true,
                        'sendPatient'       => $transfer->SendMessageToPatient == Transfer::SEND_MESSAGE_TO_PATIENT_YES ? true : false,
                        'patientName'       => $transfer->PatientName,
                        'patientTel'        => $transfer->PatientTel,
                        'orderNumber'       => $transfer->OrderNumber,
                        'hospitalName'      => $hospital->Name,
                        'content'           => MessageTemplate::load('transfer_patient_dispatch_onlineInquiry', MessageTemplate::METHOD_SMS, $hospital->Name, $doctor->Name, $address, $transfer->OrderNumber, date('Y年m月d日H点', $transfer->ClinicTime), $transfer->AcceptDoctorName, $transfer->AcceptSectionName, $hospital->Tel),
                        'clinicTime'        => date('Y年m月d日H点', $transfer->ClinicTime),
                        'acceptDoctorName'  => $transfer->AcceptDoctorName,
                        'acceptSectionName' => $transfer->AcceptSectionName,
                        'salveId'           => $transfer->SendOrganizationId,
                    ];
                    break;
            }

            if ($onlineInquiryTransfer->save() === false) {
                $exception->loadFromModel($onlineInquiryTransfer);
                throw $exception;
            }
            $this->db->commit();
            if ($sendMessageToPatient['sendSlave'] === true) {
                //发消息给小b
                MessageTemplate::send(
                    $this->queue,
                    UserEvent::user((int)$sendMessageToPatient['salveId']),
                    MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH,
                    Push::TITLE_TRANSFER,
                    0,
                    0,
                    'transfer_slave_dispatch',
                    MessageLog::TYPE_TRANSFER,
                    $sendMessageToPatient['patientName'],
                    $sendMessageToPatient['hospitalName'],
                    $sendMessageToPatient['orderNumber'],
                    $sendMessageToPatient['clinicTime'],
                    $sendMessageToPatient['acceptDoctorName'],
                    $sendMessageToPatient['acceptSectionName']
                );
            }
            if ($sendMessageToPatient['sendPatient'] === true) {
                //发短信给患者
                $sms = new Sms($this->queue);
                $sms->sendMessage((string)$sendMessageToPatient['patientTel'], $sendMessageToPatient['content']);
            }
            $this->response->setJsonContent(["message" => "ok"]);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
