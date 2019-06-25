<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/21
 * Time: 上午11:35
 */

namespace App\Admin\Controllers;

use App\Enums\BillTitle;
use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\CompanyWechat;
use App\Libs\Push;
use App\Libs\Sms;
use App\Models\Bill;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\Registration;
use App\Models\RegistrationLog;
use App\Models\Staff;
use App\Models\UserEvent;
use App\Models\WechatDepartment;
use Phalcon\Db\RawValue;
use Phalcon\Paginator\Adapter\QueryBuilder;

class RegistrationorderController extends Controller
{

    /**
     * 抢号单列表
     */
    public function listAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['R.Id', 'R.OrderNumber', 'R.Created', 'R.SendOrganizationId', 'R.Card', 'R.Name', 'R.CertificateId', 'R.RealNameCardTel',
                'R.ExHospitalId', 'R.Tel', 'R.ExSectionId', 'R.ExDoctorId', 'R.HospitalName', 'R.SectionName', 'R.DoctorName', 'R.Price', 'R.Status',
                'R.DutyDate', 'R.DutyTime', 'R.IsAllowUpdateTime', 'R.IsAllowUpdateDoctor', 'R.BeginTime', 'R.EndTime', 'R.ServicePackageName',
                'R.ServicePackagePrice', 'R.ShareToHospital', 'R.ShareToSlave', 'R.Way', 'R.Type', 'O.Name as SlaveName', 'O.MerchantCode'])
            ->addFrom(Registration::class, 'R')
            ->join(Organization::class, 'O.Id=R.SendOrganizationId', 'O', 'left')
            ->orderBy('R.Created desc');
        if (isset($data['Status']) && !empty($data['Status']) && is_numeric($data['Status'])) {
            $query->andWhere('R.Status=:Status:', ['Status' => $data['Status']]);
        }
        //挂号方式
        if (isset($data['Way']) && !empty($data['Way']) && is_numeric($data['Way'])) {
            $query->andWhere('R.Way=:Way:', ['Way' => $data['Way']]);
        }
        //搜索挂号单号
        if (isset($data['OrderNumber']) && !empty($data['OrderNumber'])) {
            $query->andWhere('R.OrderNumber=:OrderNumber:', ['OrderNumber' => $data['OrderNumber']]);
        }
        //搜索患者名字
        if (isset($data['Name']) && !empty($data['Name'])) {
            $query->andWhere('R.Name=:Name:', ['Name' => $data['Name']]);
        }
        //搜索患者手机
        if (isset($data['RealNameCardTel']) && !empty($data['RealNameCardTel'])) {
            $query->andWhere('R.RealNameCardTel=:RealNameCardTel:', ['RealNameCardTel' => $data['RealNameCardTel']]);
        }
        //搜索患者身份证
        if (isset($data['CertificateId']) && !empty($data['CertificateId'])) {
            $query->andWhere('R.CertificateId=:CertificateId:', ['CertificateId' => $data['CertificateId']]);
        }
        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("R.Created>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                return $this->response->setStatusCode(Status::BadRequest);
            }
            $query->andWhere("R.Created<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
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
            $data['Price'] = $data['Price'] + $data['ServicePackagePrice'];
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 读取一条挂号信息
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $registration = Registration::findFirst(sprintf('Id=%d', $this->request->get('Id', 'int')));
            if (!$registration) {
                throw $exception;
            }
            $result = $registration->toArray();
            $result['SlaveName'] = $registration->SendOrganization->Name;
            $result['MerchantCode'] = $registration->SendOrganization->MerchantCode;
            $result['Log'] = [];
            $registrationLog = RegistrationLog::find(sprintf('Id=%d', $this->request->get('RegistrationId', 'int')));
            if ($registrationLog) {
                $result['Log'] = $registrationLog->toArray();
            }
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 处理挂号
     */
    public function updateAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $this->db->begin();
                $auth = $this->session->get('auth');
                $now = time();
                if (!$auth) {
                    throw new LogicException('未登录', Status::Unauthorized);
                }
                $data = $this->request->getPut();
                $registration = Registration::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$registration) {
                    throw $exception;
                }
                if ($registration->Status !== Registration::STATUS_PREPAID) {
                    throw new LogicException('未付款,不能操作', Status::BadRequest);
                }
                if ($registration->save($data) === false) {
                    $exception->loadFromModel($registration);
                    throw $exception;
                }

                $peach = Organization::findFirst(Organization::PEACH);
                $slave = Organization::findFirst(sprintf('Id=%d', $registration->SendOrganizationId));
                if (!$peach || !$slave) {
                    throw $exception;
                }
                $peach_bill = new Bill();
                $slave_bill = new Bill();
                switch ($registration->Status) {
                    case Registration::STATUS_CANCEL:
                        //取消 退款
                        $money = (int)($registration->Price + $registration->ServicePackagePrice);
                        $money_slave = $money;
                        $money_hospital = 0;
                        $peach_bill->Title = sprintf(BillTitle::Registration_back, $registration->OrderNumber, Alipay::fen2yuan($money));
                        //网点将挂号费收回
                        $slave_bill->Title = sprintf(BillTitle::Registration_back, $registration->OrderNumber, Alipay::fen2yuan($money_slave));
                        break;
                    case Registration::STATUS_REGISTRATION:
                        //抢号成功 完成分润
                        $money_slave = (int)($registration->ServicePackagePrice * $registration->ShareToSlave / 100);
                        $money_hospital = (int)($registration->ServicePackagePrice * $registration->ShareToHospital / 100);
                        $money = $money_slave + $money_hospital;
                        $peach_bill->Title = sprintf(BillTitle::Registration_Out, $registration->OrderNumber, Alipay::fen2yuan($money));
                        $slave_bill->Title = sprintf(BillTitle::Registration_slave, $registration->OrderNumber, Alipay::fen2yuan($money_slave));
                        break;
                    default:
                        $money = 0;
                        $money_slave = 0;
                        $money_hospital = 0;
                }
                //平台账户支出
                $peach->Money = new RawValue(sprintf('Money-%d', $money));
                $peach->Balance = new RawValue(sprintf('Balance-%d', $money));
                if ($peach->save() === false) {
                    $exception->loadFromModel($peach);
                    throw $exception;
                }
                // 余额不足回滚
                $peach->refresh();
                if ($peach->Money < 0 || $peach->Balance < 0) {
                    throw new LogicException('平台余额不足', Status::BadRequest);
                }
                $peach_bill->OrganizationId = $peach->Id;
                $peach_bill->Fee = Bill::outCome($money);
                $peach_bill->Balance = $peach->Balance;
                $peach_bill->UserId = 0;
                $peach_bill->Type = Bill::TYPE_PAYMENT;
                $peach_bill->Created = $now;
                $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_REGISTRATION;
                $peach_bill->ReferenceId = $registration->Id;
                if ($peach_bill->save() === false) {
                    $exception->loadFromModel($peach_bill);
                    throw $exception;
                }
                $slave->Money = new RawValue(sprintf('Money+%d', $money_slave));
                $slave->Balance = new RawValue(sprintf('Balance+%d', $money_slave));
                if ($slave->save() === false) {
                    $exception->loadFromModel($slave);
                    throw $exception;
                }
                $slave->refresh();
                $slave_bill->OrganizationId = $slave->Id;
                $slave_bill->Fee = Bill::inCome($money_slave);
                $slave_bill->Balance = $slave->Balance;
                $slave_bill->UserId = 0;
                $slave_bill->Type = Bill::TYPE_PROFIT;
                $slave_bill->Created = $now;
                $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_REGISTRATION;
                $slave_bill->ReferenceId = $registration->Id;
                if ($slave_bill->save() === false) {
                    $exception->loadFromModel($slave_bill);
                    throw $exception;
                }
                if ($registration->Status == Registration::STATUS_REGISTRATION) {
                    //抢号成功，网点上级分润
                    $hospital = Organization::findFirst(sprintf('Id=%d', $registration->SendHospitalId));
                    if (!$hospital) {
                        throw $exception;
                    }
                    $hospital->Money = new RawValue(sprintf('Money+%d', $money_hospital));
                    $hospital->Balance = new RawValue(sprintf('Balance+%d', $money_hospital));
                    if ($hospital->save() === false) {
                        $exception->loadFromModel($hospital);
                        throw $exception;
                    }
                    $hospital->refresh();
                    $hospital_bill = new Bill();
                    $hospital_bill->Title = sprintf(BillTitle::Registration_Hospital, $slave->Name, Alipay::fen2yuan($money_hospital));
                    $hospital_bill->OrganizationId = $hospital->Id;
                    $hospital_bill->Fee = Bill::inCome($money_hospital);
                    $hospital_bill->Balance = $hospital->Balance;
                    $hospital_bill->UserId = 0;
                    $hospital_bill->RegistrationId = $registration->Id;
                    $hospital_bill->Type = Bill::TYPE_PROFIT;
                    $hospital_bill->Created = $now;
                    $hospital_bill->Updated = $now;
                    $hospital_bill->ReferenceType = Bill::REFERENCE_TYPE_REGISTRATION;
                    $hospital_bill->ReferenceId = $registration->Id;
                    if ($hospital_bill->save() === false) {
                        $exception->loadFromModel($hospital_bill);
                        throw $exception;
                    }
                }
                $this->db->commit();
                $finalTime = $registration->FinalTime ? date('Y-m-d', $registration->FinalTime) : $registration->DutyDate;
                $finalDuty = $registration->FinalTime ? (date('Hi', $registration->FinalTime) <= 1230 ? '上午' : '下午') : Registration::DUTY_TIME_NAME[(int)($registration->DutyTime)];
                //发送消息
                switch ($registration->Status) {
                    case Registration::STATUS_REGISTRATION:
                        //挂号成功,发送消息
                        //发消息给网点
                        MessageTemplate::send(
                            $this->queue,
                            UserEvent::user((int)$registration->SendOrganizationId),
                            MessageTemplate::METHOD_MESSAGE,
                            Push::TITLE_REGISTRATION,
                            0,
                            0,
                            'registration_order_slave',
                            MessageLog::TYPE_REGISTRATION,
                            $registration->Name,
                            $finalTime,
                            $finalDuty,
                            $registration->HospitalName,
                            $registration->FinalSectionName ?: $registration->SectionName,
                            $registration->FinalDoctorName ?: $registration->DoctorName
                        );
                        if ($money > 0) {
                            //网点分润消息
                            MessageTemplate::send(
                                $this->queue,
                                UserEvent::user((int)$registration->SendOrganizationId),
                                MessageTemplate::METHOD_MESSAGE | MessageTemplate::METHOD_PUSH,
                                Push::TITLE_FUND,
                                0,
                                0,
                                'registration_share_slave',
                                MessageLog::TYPE_ACCOUNT_IN,
                                $registration->Name,
                                Alipay::fen2yuan($money_slave)
                            );
                            //医院分润消息
                            MessageTemplate::send(
                                $this->queue,
                                null,
                                MessageTemplate::METHOD_MESSAGE,
                                Push::TITLE_FUND,
                                (int)$registration->SendHospitalId,
                                MessageTemplate::EVENT_REGISTRATION_SHARE,
                                'registration_share_hospital',
                                MessageLog::TYPE_ACCOUNT_IN,
                                $slave->Name,
                                Alipay::fen2yuan($money_hospital)
                            );
                        }
                        //发消息桃子
                        $content = MessageTemplate::load('registration_add_hospital_success', MessageTemplate::METHOD_MESSAGE, $registration->Name);
                        $wechat = new CompanyWechat();
                        $userids = Staff::getStaffs(WechatDepartment::REGISTRATION);
                        $wechat->send($content, [WechatDepartment::REGISTRATION], $userids);
                        //发消息给患者
                        $content = MessageTemplate::load(
                            'registration_order_patient',
                            MessageTemplate::METHOD_SMS,
                            $finalTime,
                            $finalDuty,
                            $registration->HospitalName,
                            $registration->FinalSectionName ?: $registration->SectionName,
                            $registration->FinalDoctorName ?: $registration->DoctorName,
                            $registration->Name,
                            $registration->CertificateId,
                            MessageTemplate::TEL
                        );
                        $sms = new Sms($this->queue);
                        $sms->sendMessage((string)$registration->Tel, $content);
                        break;
                    case Registration::STATUS_CANCEL:
                        //挂号单取消，将金额返回
                        //发消息给网点
                        MessageTemplate::send(
                            $this->queue,
                            UserEvent::user((int)$registration->SendOrganizationId),
                            MessageTemplate::METHOD_MESSAGE,
                            Push::TITLE_REGISTRATION,
                            0,
                            0,
                            'registration_cancel_slave',
                            MessageLog::TYPE_REGISTRATION,
                            $registration->Name,
                            $registration->DutyDate,
                            Registration::DUTY_TIME_NAME[(int)$registration->DutyTime],
                            $registration->HospitalName,
                            $registration->SectionName,
                            $registration->DoctorName
                        );
                        //发给网点的退款消息
                        MessageTemplate::send(
                            $this->queue,
                            UserEvent::user((int)$registration->SendOrganizationId),
                            MessageTemplate::METHOD_MESSAGE,
                            Push::TITLE_FUND,
                            0,
                            0,
                            'registration_refund_slave',
                            MessageLog::TYPE_ACCOUNT_IN,
                            Alipay::fen2yuan($money)
                        );
                        //发消息给桃子
                        $content = MessageTemplate::load('registration_add_hospital_cancel', MessageTemplate::METHOD_MESSAGE, $registration->Name);
                        $wechat = new CompanyWechat();
                        $userids = Staff::getStaffs(WechatDepartment::REGISTRATION);
                        $wechat->send($content, [WechatDepartment::REGISTRATION], $userids);
                        //发消息给患者
                        $content = MessageTemplate::load(
                            'registration_cancel_patient',
                            MessageTemplate::METHOD_SMS,
                            $registration->HospitalName,
                            $registration->SectionName,
                            $registration->DoctorName
                        );
                        $sms = new Sms($this->queue);
                        $sms->sendMessage((string)$registration->Tel, $content);
                        break;
                }
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
}