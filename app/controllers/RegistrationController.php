<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/11/22
 * Time: 下午4:13
 */

namespace App\Controllers;


use App\Enums\BillTitle;
use App\Enums\DoctorTitle;
use App\Enums\MessageTemplate;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Alipay;
use App\Libs\CompanyWechat;
use App\Libs\Curl;
use App\Libs\Excel;
use App\Libs\HashRing;
use App\Libs\Push;
use App\Libs\Sms;
use App\Models\Bill;
use App\Models\Ex114Account;
use App\Models\ExDoctor;
use App\Models\ExHospital;
use App\Models\ExSection;
use App\Models\Location;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\OrganizationExDoctor;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\Registration;
use App\Models\RegistrationLog;
use App\Models\RuleOfShare;
use App\Models\Section;
use App\Models\ServicePackage;
use App\Models\Staff;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\WechatDepartment;
use Phalcon\Db\RawValue;
use Phalcon\Paginator\Adapter\QueryBuilder;

class RegistrationController extends Controller
{
    // private $REDIS_KEY = ['114Token:15681234594'];

    // private $PASSWORD = ['15681234594' => 'dyq1988628'];

    private $REDIS_KEY = ['114Token:18118255680'];

    private $PASSWORD = ['18118255680' => 'lp715410'];

    //114登录网址
    private $URL_114 = 'http://www.scgh114.com/web/login';

    /**
     * 获取114登录之后的cookie
     */
    public function getCookieAction()
    {
        $auth = $this->session->get('auth');
        try {
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $hash = new HashRing($this->REDIS_KEY);
            $key = $hash->getNode($auth['Id']);
            $cookie = $this->redis->get($key);
            if (!$cookie) {
                $curl = new Curl();
                $tel = explode(':', $key)[1];
                $userName = $tel;
                $password = $this->PASSWORD[$tel];
                $cookie = $curl->getCookie('POST', $this->URL_114, ['operLogin' => $userName, 'operPassword' => $password], 'login');
                $this->redis->setex($key, 600, $cookie);
            }
            $this->response->setJsonContent(['JSESSIONID' => $cookie]);
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 更新cookie
     */
    public function refreshCookieAction()
    {
        $auth = $this->session->get('auth');
        try {
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $hash = new HashRing($this->REDIS_KEY);
            $key = $hash->getNode($auth['Id']);
            $curl = new Curl();
            $tel = explode(':', $key)[1];
            $userName = $tel;
            $password = $this->PASSWORD[$tel];
            $cookie = $curl->getCookie('POST', $this->URL_114, ['operLogin' => $userName, 'operPassword' => $password], 'login');
            $this->redis->setex($key, 600, $cookie);
            $this->response->setJsonContent(['JSESSIONID' => $cookie]);
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 医院列表
     */
    public function hospitalsAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = ExHospital::query();
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 科室列表
     */
    public function sectionsAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = ExSection::query()->where(sprintf('ParentId=%d', $this->request->getPost('HospitalId')));
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $this->outputPagedJson($paginator);
    }

    /**
     * 医生列表
     */
    public function doctorsAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = ExDoctor::query()->where('ParentId=:ParentId:')->bind(['ParentId' => $this->request->getPost('SectionId')]);
        $paginator = new QueryBuilder(
            [
                "builder" => $query->createBuilder(),
                "limit"   => $pageSize,
                "page"    => $page,
            ]
        );
        $pages = $paginator->getPaginate();
        $totalPage = $pages->total_pages;
        $count = $pages->total_items;
        $doctors = $pages->items->toArray();
        $hospitals = $this->modelsManager->createBuilder()
            ->columns(['OE.ExDoctorId', 'OE.UserId', 'OE.RegistrationFee', 'O.Id as ReservationHospitalId', 'O.Name as HospitalName', 'OU.SectionId', 'S.Name as SectionName', 'PL.Name as ProvinceName', 'CL.Name as CityName', 'AL.Name as AreaName', 'O.Address'])
            ->addFrom(OrganizationExDoctor::class, 'OE')
            ->join(OrganizationUser::class, 'OU.UserId=OE.UserId and OU.OrganizationId=OE.OrganizationId', 'OU', 'left')
            ->join(Organization::class, 'O.Id=OE.OrganizationId', 'O', 'left')
            ->join(Section::class, 'S.Id=OU.SectionId', 'S', 'left')
            ->join(Location::class, 'PL.Id=O.ProvinceId', 'PL', 'left')
            ->join(Location::class, 'CL.Id=O.CityId', 'CL', 'left')
            ->join(Location::class, 'AL.Id=O.AreaId', 'AL', 'left')
            ->inWhere('OE.ExDoctorId', array_column($doctors, 'Id'))
            ->getQuery()->execute();
        $hospitals_new = [];
        foreach ($hospitals as $hospital) {
            $hospitals_new[$hospital->ExDoctorId][] = $hospital->toArray();
        }
        foreach ($doctors as &$doctor) {
            $doctor['Practice'] = $hospitals_new[$doctor['Id']];
        }
        $result = [];
        $result['Data'] = $doctors;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 医生详情
     */
    public function readDoctorAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $id = $this->request->get('Id', 'int');
            $doctor = ExDoctor::findFirst(sprintf('Id=%d', $id));
            if (!$doctor) {
                throw $exception;
            }
            $this->response->setJsonContent($doctor);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 创建挂号单
     * POST 参数：SectionId DoctorId HospitalId Name CertificateId RealNameCardTel
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $registration = new Registration();
                $now = time();
                $data['Created'] = $now;
                $data['SendOrganizationId'] = $auth['OrganizationId'];
                $data['SendHospitalId'] = $auth['HospitalId'];
                $data['OrderNumber'] = $now . $auth['OrganizationId'];
                $data['DutyDate'] = $data['dutydate'];
                $data['DutyTime'] = $data['workDutyTimeNum'];
                $data['Card'] = $data['card'];
                $data['Type'] = $data['type'];
                if (isset($data['type']) && isset($data['type']) && is_numeric($data['type'])) {
                    switch ($data['type']) {
                        case 1:
                            $data['Name'] = $data['username'];
                            $data['CertificateId'] = $data['certificateid'];
                            break;
                        case 4:
                            if ($data['hospitalFlag'] == 1) {
                                $data['Name'] = $data['owner'];
                            } else {
                                $data['Name'] = $data['name'];
                                $data['CertificateId'] = $data['certificateId'];
                            }
                            break;
                    }
                }
                $data['RealNameCardTel'] = $data['realnamecardTel'];
                $data['Tel'] = $data['tel'];
                $data['Price'] = $data['ReservationPrice'] * 100;
                $data['Sex'] = $data['sex'];
                $data['Status'] = Registration::STATUS_UNPAID;
                $data['IsAllowUpdateTime'] = $data['IsAllowUpdateTime'] == true ? 1 : 0;
                $data['IsAllowUpdateDoctor'] = $data['IsAllowUpdateDoctor'] == true ? 1 : 0;
                if (isset($data['Way']) && !empty($data['Way'])) {
                    switch ($data['Way']) {
                        case Registration::WAY_HAVE:
                            //有号的时候直接 已预约
                            $data['HospitalId'] = Organization::PEACH;
                            $data['Status'] = Registration::STATUS_REGISTRATION;
                            $doctor = ExDoctor::findFirst(sprintf('Sc114Id=%d', $data['doctorid']));
                            if (!$doctor) {
                                $exception->add('card', '医生异常');
                                throw $exception;
                            }
                            $section = ExSection::findFirst(sprintf('Id=%d', $doctor->ParentId));
                            if (!$section) {
                                $exception->add('card', '科室异常');
                                throw $exception;
                            }
                            $hospital = ExHospital::findFirst(sprintf('Id=%d', $section->ParentId));
                            if (!$hospital) {
                                $exception->add('card', '医院异常');
                                throw $exception;
                            }
                            $data['ExDoctorId'] = $doctor->Id;
                            $data['ExHospitalId'] = $hospital->Id;
                            $data['ExSectionId'] = $section->Id;
                            $data['DoctorName'] = $doctor->Name;
                            $data['SectionName'] = $section->Name;
                            $data['HospitalName'] = $hospital->Name;
                            break;
                        case Registration::WAY_ADD:
                            //可以加号
                            $data['Name'] = (isset($data['username']) && !empty($data['username'])) ? $data['username'] : $data['name'];
                            $data['DoctorId'] = $data['UserId'];
                            $doctor = OrganizationUser::findFirst([
                                'conditions' => 'OrganizationId=?0 and UserId=?1',
                                'bind'       => [$data['ReservationHospitalId'], $data['DoctorId']],
                            ]);
                            if (!$doctor) {
                                throw $exception;
                            }
                            $data['HospitalId'] = $doctor->Organization->Id;
                            $data['SectionId'] = $doctor->Section->Id;
                            $data['DoctorName'] = $doctor->User->Name;
                            $data['SectionName'] = $doctor->Section->Name;
                            $data['HospitalName'] = $doctor->Organization->Name;
                            //相应医生的挂号费
                            $organizationExDoctor = OrganizationExDoctor::findFirst([
                                'conditions' => 'OrganizationId=?0 and UserId=?1',
                                'bind'       => [$data['HospitalId'], $doctor->UserId],
                            ]);
                            $data['Price'] = $organizationExDoctor->RegistrationFee;
                            break;
                        case Registration::WAY_ROB:
                            //没有号的时候
                            $data['HospitalId'] = Organization::PEACH;
                            $doctor = ExDoctor::findFirst(sprintf('Sc114Id=%d', $data['doctorid']));
                            if (!$doctor) {
                                $exception->add('card', '医生异常');
                                throw $exception;
                            }
                            $section = ExSection::findFirst(sprintf('Id=%d', $doctor->ParentId));
                            if (!$section) {
                                $exception->add('card', '科室异常');
                                throw $exception;
                            }
                            $hospital = ExHospital::findFirst(sprintf('Id=%d', $section->ParentId));
                            if (!$hospital) {
                                $exception->add('card', '医院异常');
                                throw $exception;
                            }
                            $data['ExDoctorId'] = $doctor->Id;
                            $data['ExHospitalId'] = $hospital->Id;
                            $data['ExSectionId'] = $section->Id;
                            $data['DoctorName'] = $doctor->Name;
                            $data['SectionName'] = $section->Name;
                            $data['HospitalName'] = $hospital->Name;
                            $servicePackage = ServicePackage::findFirst();
                            if ($servicePackage) {
                                $data['ServicePackageName'] = $servicePackage->Name;
                                $data['ServicePackagePrice'] = $servicePackage->Price;
                                $data['ShareToHospital'] = $servicePackage->ShareToHospital;
                                $data['ShareToSlave'] = $servicePackage->ShareToSlave;
                            }
                            break;
                        default:
                            throw new LogicException('参数错误', Status::BadRequest);
                    }
                } else {
                    throw new LogicException('参数错误', Status::BadRequest);
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if ($registration->save($data) === false) {
                $exception->loadFromModel($registration);
                throw $exception;
            }
            switch ($registration->Way) {
                case Registration::WAY_ADD:
                    //发消息给大B
                    MessageTemplate::send(
                        $this->queue,
                        null,
                        MessageTemplate::METHOD_MESSAGE,
                        Push::TITLE_REGISTRATION,
                        (int)$registration->HospitalId,
                        MessageTemplate::EVENT_REGISTRATION_CREATE,
                        'registration_add_hospital',
                        MessageLog::TYPE_REGISTRATION,
                        $registration->Name
                    );
                    break;
                case Registration::WAY_ROB:
                    //发消息给桃子互联网医院
                    $content = MessageTemplate::load('registration_add_peach', MessageTemplate::METHOD_MESSAGE);
                    $wechat = new CompanyWechat();
                    $userids = Staff::getStaffs(WechatDepartment::REGISTRATION);
                    $wechat->send($content, [WechatDepartment::REGISTRATION], $userids);
                    break;
            }
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($registration);
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 付款
     */
    public function payAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $auth = $this->session->get('auth');
            $now = time();
            if (!$auth) {
                throw new LogicException('未登录', Status::Unauthorized);
            }
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                $registration = Registration::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$registration) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            //付款
            if ($registration->Status === Registration::STATUS_UNPAID) {
                $peach = Organization::findFirst(Organization::PEACH);
                $slave = Organization::findFirst(sprintf('Id=%d', $registration->SendOrganizationId));
                if (!$peach || !$slave) {
                    throw $exception;
                }
                // 扣除可用余额
                $money = (int)($registration->Price + $registration->ServicePackagePrice);
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
                $slave_bill->Title = sprintf(BillTitle::Registration_Out, $registration->OrderNumber, Alipay::fen2yuan($money));
                $slave_bill->OrganizationId = $slave->Id;
                $slave_bill->Fee = Bill::outCome($money);
                $slave_bill->Balance = $slave->Balance;
                $slave_bill->UserId = $auth['Id'];
                $slave_bill->Type = Bill::TYPE_PAYMENT;
                $slave_bill->Created = $now;
                $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_REGISTRATION;
                $slave_bill->ReferenceId = $registration->Id;
                if ($slave_bill->save() === false) {
                    $exception->loadFromModel($slave_bill);
                    throw $exception;
                }
                //将加号费放入平台账户
                $peach->Money = new RawValue(sprintf('Money+%d', $money));
                $peach->Balance = new RawValue(sprintf('Balance+%d', $money));
                if ($peach->save() === false) {
                    $exception->loadFromModel($peach);
                    throw $exception;
                }
                $peach->refresh();
                $peach_bill = new Bill();
                $peach_bill->Title = sprintf(BillTitle::Registration_In, $registration->SendHospital->Name, $registration->SendOrganization->Name, $registration->OrderNumber, Alipay::fen2yuan($money));
                $peach_bill->OrganizationId = $peach->Id;
                $peach_bill->Fee = Bill::inCome($money);
                $peach_bill->Balance = $peach->Balance;
                $peach_bill->UserId = $auth['Id'];
                $peach_bill->Type = Bill::TYPE_PROFIT;
                $peach_bill->Created = $now;
                $peach_bill->ReferenceType = Bill::REFERENCE_TYPE_REGISTRATION;
                $peach_bill->ReferenceId = $registration->Id;
                if ($peach_bill->save() === false) {
                    $exception->loadFromModel($peach_bill);
                    throw $exception;
                }
            } else {
                return $this->response->setJsonContent(['已付款']);
            }
            $registration->Status = Registration::STATUS_PREPAID;
            if ($registration->save() === false) {
                $exception->loadFromModel($registration);
                throw $exception;
            }
            $this->db->commit();
            //付款成功，发送消息
            //发给网点
            MessageTemplate::send(
                $this->queue,
                UserEvent::user((int)$registration->SendOrganizationId),
                MessageTemplate::METHOD_MESSAGE,
                Push::TITLE_FUND,
                0,
                0,
                'registration_pay_success_slave',
                MessageLog::TYPE_ACCOUNT_OUT,
                Alipay::fen2yuan($money)
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
     * 改变状态
     */
    public function updateAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $auth = $this->session->get('auth');
            $now = time();
            if (!$auth) {
                throw new LogicException('未登录', Status::Unauthorized);
            }
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                $registration = Registration::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$registration) {
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }

            //加号确认和取消操作
            if ($registration->Way === Registration::WAY_ADD) {
                switch ($data['Status']) {
                    case Registration::STATUS_CANCEL:
                        //取消
                        $peach = Organization::findFirst(Organization::PEACH);
                        $slave = Organization::findFirst(sprintf('Id=%d', $registration->SendOrganizationId));
                        if (!$peach || !$slave) {
                            throw $exception;
                        }
                        $money = (int)($registration->Price + $registration->ServicePackagePrice);
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
                        //网点将挂号费收回
                        $slave->Money = new RawValue(sprintf('Money+%d', $money));
                        $slave->Balance = new RawValue(sprintf('Balance+%d', $money));
                        if ($slave->save() === false) {
                            $exception->loadFromModel($slave);
                            throw $exception;
                        }
                        $slave->refresh();
                        $slave_bill = new Bill();
                        $slave_bill->Title = sprintf(BillTitle::Registration_back, $registration->OrderNumber, Alipay::fen2yuan($money));
                        $slave_bill->OrganizationId = $slave->Id;
                        $slave_bill->Fee = Bill::inCome($money);
                        $slave_bill->Balance = $slave->Balance;
                        $slave_bill->UserId = $auth['Id'];
                        $slave_bill->Type = Bill::TYPE_PROFIT;
                        $slave_bill->Created = $now;
                        $slave_bill->ReferenceType = Bill::REFERENCE_TYPE_REGISTRATION;
                        $slave_bill->ReferenceId = $registration->Id;
                        if ($slave_bill->save() === false) {
                            $exception->loadFromModel($slave_bill);
                            throw $exception;
                        }
                        break;
                    case Registration::STATUS_REGISTRATION:
                        //加号成功生成转诊单
                        $transfer = new Transfer();
                        $transfer->PatientName = $registration->Name;
                        $transfer->PatientAge = null;
                        $transfer->PatientSex = $registration->Sex;
                        $transfer->PatientAddress = null;
                        $transfer->PatientId = $registration->CertificateId;
                        $transfer->PatientTel = $registration->Tel;
                        $transfer->SendHospitalId = $registration->SendHospitalId;
                        $transfer->SendOrganizationId = $registration->SendOrganizationId;
                        $transfer->SendOrganizationName = $registration->SendOrganization->Name;
                        $transfer->TranStyle = 1;
                        $transfer->OldSectionName = $registration->SectionName;
                        $transfer->OldDoctorName = $registration->DoctorName;
                        $transfer->AcceptOrganizationId = $registration->HospitalId;
                        if (isset($data['SectionId']) && !empty($data['SectionId']) && is_numeric($data['SectionId'])) {
                            $transfer->AcceptSectionId = $data['SectionId'];
                            $section = Section::findFirst(sprintf('Id=%d', $data['SectionId']));
                            if (!$section) {
                                throw $exception;
                            }
                            $transfer->AcceptSectionName = $section->Name;
                        } else {
                            $transfer->AcceptSectionId = $registration->SectionId;
                            $transfer->AcceptSectionName = $registration->SectionName;
                        }
                        if (isset($data['DoctorId']) && !empty($data['DoctorId']) && is_numeric($data['DoctorId'])) {
                            $transfer->AcceptDoctorId = $data['DoctorId'];
                            $doctor = User::findFirst(sprintf('Id=%d', $data['DoctorId']));
                            if (!$doctor) {
                                throw $exception;
                            }
                            $transfer->AcceptDoctorName = $doctor->Name;
                        } else {
                            $transfer->AcceptDoctorId = $registration->DoctorIdId;
                            $transfer->AcceptDoctorName = $registration->DoctorName;
                        }
                        $transfer->StartTime = $registration->Created;
                        $transfer->ClinicTime = $data['ClinicTime'];
                        $transfer->LeaveTime = 0;
                        $transfer->EndTime = 0;
                        $transfer->Status = Transfer::ACCEPT;
                        $transfer->OrderNumber = time() << 32 | substr('0000000' . $registration->SendOrganizationId, -7, 7);
                        $hospital = Organization::findFirst(sprintf('Id=%d', $registration->HospitalId));
                        $relation = OrganizationRelationship::findFirst([
                            'conditions' => 'MainId=?0 and MinorId=?1',
                            'bind'       => [$registration->HospitalId, $registration->SendOrganizationId],
                        ]);
                        $rule = RuleOfShare::findFirst(sprintf('Id=%d', $hospital->RuleId));
                        $transfer->CloudGenre = $rule->Type;
                        $transfer->ShareCloud = $rule->Type == RuleOfShare::RULE_FIXED ? $rule->Fixed : $rule->Ratio;
                        if (!$relation) {//共享
                            $transfer->Genre = 2;
                            $transfer->GenreOne = RuleOfShare::RULE_RATIO;
                            $transfer->ShareOne = $rule->DistributionOut;
                            $transfer->GenreTwo = RuleOfShare::RULE_RATIO;
                            $transfer->ShareTwo = $rule->DistributionOutB;
                        } else {//自有
                            $transfer->Genre = 1;
                            $transfer->GenreOne = 0;
                            $transfer->ShareOne = 0;
                            $transfer->GenreTwo = 0;
                            $transfer->ShareTwo = 0;
                        }
                        $transfer->Remake = $data['Remake'];
                        $transfer->IsEvaluate = 0;
                        $transfer->Source = Transfer::SOURCE_REGISTRATION;
                        if ($transfer->save() === false) {
                            $exception->loadFromModel($transfer);
                            throw $exception;
                        }
                        $data['TransferId'] = $transfer->Id;
                        break;
                    default :
                        throw new LogicException('状态参数错误', Status::BadRequest);
                }

            }
            $whiteList = ['Status', 'Explain', 'TransferId'];
            if ($registration->save($data, $whiteList) === false) {
                $exception->loadFromModel($registration);
                throw $exception;
            }
            $this->db->commit();
            //发送消息
            switch ($registration->Status) {
                case Registration::STATUS_CANCEL:
                    //加号失败
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
                    //发消息给大B
                    MessageTemplate::send(
                        $this->queue,
                        null,
                        MessageTemplate::METHOD_MESSAGE,
                        Push::TITLE_REGISTRATION,
                        (int)$registration->HospitalId,
                        MessageTemplate::EVENT_REGISTRATION_CANCEL,
                        'registration_add_hospital_cancel',
                        MessageLog::TYPE_REGISTRATION,
                        $registration->Name
                    );
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
                case Registration::STATUS_REGISTRATION:
                    //加号成功
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
                        $registration->DutyDate,
                        Registration::DUTY_TIME_NAME[(int)$registration->DutyTime],
                        $registration->HospitalName,
                        $registration->SectionName,
                        $registration->DoctorName
                    );
                    //发消息给大B
                    MessageTemplate::send(
                        $this->queue,
                        null,
                        MessageTemplate::METHOD_MESSAGE,
                        Push::TITLE_REGISTRATION,
                        (int)$registration->HospitalId,
                        MessageTemplate::EVENT_REGISTRATION_SUCCESS,
                        'registration_add_hospital_success',
                        MessageLog::TYPE_REGISTRATION,
                        $registration->Name
                    );
                    //发消息给患者
                    $content = MessageTemplate::load(
                        'registration_order_patient',
                        MessageTemplate::METHOD_SMS,
                        $registration->DutyDate,
                        Registration::DUTY_TIME_NAME[(int)$registration->DutyTime],
                        $registration->HospitalName,
                        $registration->SectionName,
                        $registration->DoctorName,
                        $registration->Name,
                        $registration->CertificateId,
                        Organization::findFirst(sprintf('Id=%d', $registration->HospitalId))->Tel
                    );
                    $sms = new Sms($this->queue);
                    $sms->sendMessage((string)$registration->Tel, $content);
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

    /**
     * 挂号单列表
     */
    public function listAction()
    {
        $auth = $this->session->get('auth');
        if (!$auth) {
            return $this->response->setStatusCode(Status::Unauthorized);
        }
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns([
                'R.Id', 'R.OrderNumber', 'R.Created', 'R.SendOrganizationId', 'R.HospitalId', 'R.Card', 'R.Name', 'R.CertificateId', 'R.RealNameCardTel', 'R.SectionId',
                'R.DoctorId', 'R.ExHospitalId', 'R.ExSectionId', 'R.ExDoctorId', 'R.HospitalName', 'R.SectionName', 'R.DoctorName', 'R.Price', 'R.Status',
                'R.DutyDate', 'R.DutyTime', 'R.IsAllowUpdateTime', 'R.IsAllowUpdateDoctor', 'R.BeginTime', 'R.EndTime', 'R.ServicePackageName',
                'R.ServicePackagePrice', 'R.ShareToHospital', 'R.ShareToSlave', 'R.Way', 'R.Type', 'O.Name as SlaveName', 'O.MerchantCode',
            ])
            ->addFrom(Registration::class, 'R')
            ->join(Organization::class, 'O.Id=R.SendOrganizationId', 'O', 'left');
        //在医院还是网点
        if (isset($data['IsHospital']) && !empty($data['IsHospital']) && is_numeric($data['IsHospital'])) {
            if ($data['IsHospital'] == 1) {
                $query->where('R.HospitalId=:HospitalId:', ['HospitalId' => $auth['OrganizationId']]);
            }
        } else {
            $query->where('R.SendOrganizationId=:SendOrganizationId:', ['SendOrganizationId' => $auth['OrganizationId']]);
        }
        //搜索状态
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
        $export = false;
        if (isset($data['Export']) && !empty($data['Export'])) {
            $export = true;
        }
        $query->orderBy('R.Created desc');
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
        $hospitals = Organization::query()->inWhere('Id', array_column($datas, 'HospitalId'))->execute();
        $hospitals_new = [];
        foreach ($hospitals as $hospital) {
            $hospitals_new[$hospital->Id] = $hospital->Address;
        }
        $exHospitals = ExHospital::query()->inWhere('Id', array_column($datas, 'ExHospitalId'))->execute();
        $exHospitals_new = [];
        foreach ($exHospitals as $exHospital) {
            $exHospitals_new[$exHospital->Id] = $exHospital->Address;
        }
        foreach ($datas as &$data) {
            $data['StatusName'] = Registration::STATUS_NAME[$data['Status']];
            $data['Price'] = '¥' . sprintf("%.2f", $data['Price'] / 100);
            $data['Address'] = $data['ExHospitalId'] ? $exHospitals_new[$data['ExHospitalId']] : $hospitals_new[$data['HospitalId']];
        }
        if ($export) {
            $paginator = new QueryBuilder(
                [
                    "builder" => $query,
                    "limit"   => $count,
                    "page"    => 1,
                ]
            );
            $pages = $paginator->getPaginate();
            $datas = $pages->items->toArray();
            foreach ($datas as &$data) {
                $data['Time'] = date('Y-m-d H:i:s', $data['Created']);
            }
            $head = ['挂号单号', '购买时间', '来源商户号', '商户名称', '科室', '医生', '患者姓名', '患者手机号', '身份证', '状态', '支付金额'];
            $index = ['OrderNumber', 'Time', 'MerchantCode', 'OrganizationName', 'SectionName', 'DoctorName', 'Name', 'RealNameCardTel', 'CertificateId', 'StatusName', 'Price'];
            Excel::createTable($datas, '挂号单-' . $page, $head, $index);
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        return $this->response->setJsonContent($result);
    }

    /**
     * 查看挂号单详情
     */
    public function readOneAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $registration = Registration::findFirst(sprintf('Id=%d', $this->request->get('Id')));
            if (!$registration) {
                throw $exception;
            }
            $result = $registration->toArray();
            $org = Organization::findFirst(sprintf('Id=%d', $registration->SendOrganizationId));
            $result['SlaveName'] = $org->Name;
            if ($registration->Way == Registration::WAY_ADD) {
                $hospital = Organization::findFirst(sprintf('Id=%d', $registration->HospitalId));
                $result['Province'] = $hospital->Province->Name;
                $result['City'] = $hospital->City->Name;
                $result['Area'] = $hospital->Area->Name;
                $doctor = OrganizationUser::findFirst(sprintf('UserId=%d', $registration->DoctorId));
                $result['TitleName'] = DoctorTitle::value($doctor->Title);
            } else {
                $hospital = ExHospital::findFirst(sprintf('Id=%d', $registration->ExHospitalId));
                $doctor = ExDoctor::findFirst(sprintf('Id=%d', $registration->ExDoctorId));
                $result['TitleName'] = $doctor->Degree;
            }
            $result['Image'] = $doctor->Image;
            $result['Address'] = $hospital->Address;
            $result['StatusName'] = Registration::STATUS_NAME[$result['Status']];
            $result['Price'] = (int)($registration->Price + $registration->ServicePackagePrice);
            $result['Log'] = [];
            $registrationLog = RegistrationLog::find(sprintf('RegistrationId=%d', $this->request->get('Id', 'int')));
            if ($registrationLog) {
                $logs = $registrationLog->toArray();
                foreach ($logs as &$log) {
                    $log['StatusName'] = RegistrationLog::STATUS_NAME[$log['Status']];
                }
                $result['Log'] = $logs;
            }
            if ($registration->TransferId) {
                $transfer = Transfer::findFirst(sprintf('Id=%d', $registration->TransferId));
                $result['ClinicTime'] = $transfer->ClinicTime;
                $result['AcceptSectionName'] = $transfer->AcceptSectionName;
                $result['AcceptDoctorName'] = $transfer->AcceptDoctorName;
                $result['TransferStatus'] = $transfer->Status;
            }
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 多点执业关联关系
     */
    public function relationAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            if ($this->request->isPost()) {
                $data = $this->request->getPost();
                $this->db->begin();
                $old = OrganizationExDoctor::find([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$auth['OrganizationId'], $data['DoctorId']],
                ]);
                if (count($old->toArray())) {
                    if ($data['UserIds'] === '' || !count($data['UserIds'])) {
                        if ($old->delete() === false) {
                            throw $exception;
                        }
                        $this->db->commit();
                        return $this->response->setStatusCode(Status::Created);
                    } elseif (is_array($data['UserIds'])) {
                        $exDoctorIds = array_column($old->toArray(), 'ExDoctorId');
                        foreach ($data['UserIds'] as $k => $datum) {
                            $data['UserIds'][$k] = (int)$datum;
                        }
                        asort($exDoctorIds);
                        asort($data['UserIds']);
                        if ($exDoctorIds === $data['UserIds'] && $old[0]->RegistrationFee == $data['RegistrationFee']) {
                            return $this->response->setStatusCode(Status::OK);
                        } elseif ($exDoctorIds !== $data['UserIds']) {
                            if ($old->delete() === false) {
                                throw $exception;
                            }
                        }
                    }
                }
                if (is_array($data['UserIds']) && count($data['UserIds'])) {
                    foreach ($data['UserIds'] as $id) {
                        $organizationExDoctor = new OrganizationExDoctor();
                        $organizationExDoctor->OrganizationId = $auth['OrganizationId'];
                        $organizationExDoctor->UserId = $data['DoctorId'];
                        $organizationExDoctor->ExDoctorId = $id;
                        $organizationExDoctor->RegistrationFee = (int)$data['RegistrationFee'];
                        $organizationExDoctor->setScene(OrganizationExDoctor::SCENE_REGISTRATION_RELATION);
                        if ($organizationExDoctor->save() === false) {
                            $exception->loadFromModel($organizationExDoctor);
                            throw $exception;
                        }
                    }
                }
                $this->db->commit();
                return $this->response->setStatusCode(Status::Created);
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
     * 该姓名医生的信息 如果添加过就不显示
     */
    public function doctorInfoAction()
    {
        $exDoctors = $this->modelsManager->createBuilder()
            ->columns(['D.Id', 'D.Name', 'S.Name as SectionName', 'H.Name as HospitalName'])
            ->addFrom(ExDoctor::class, 'D')
            ->join(ExSection::class, 'S.Id=D.ParentId', 'S', 'left')
            ->join(ExHospital::class, 'H.Id=S.ParentId', 'H', 'left')
            ->where('D.Name=:Name:', ['Name' => $this->request->get('Name')])
            ->getQuery()->execute();
        $result = [];
        if ($exDoctors) {
            $result = $exDoctors->toArray();
            $olds = OrganizationExDoctor::query()
                ->InWhere('ExDoctorId', array_column($result, 'Id'))
                ->Where('OrganizationId=:OrganizationId:')
                ->andWhere('UserId=:UserId:')
                ->bind(['OrganizationId' => $this->session->get('auth')['OrganizationId'], 'UserId' => $this->request->get('Id', 'int')])
                ->execute();
            if ($olds) {
                $ids = array_column($olds->toArray(), 'ExDoctorId');
                $registrationFee = $olds->toArray()[0]['RegistrationFee'];
                foreach ($result as $key => &$item) {
                    $item['RegistrationFee'] = $registrationFee;
                    $item['Selected'] = false;
                    if (in_array($item['Id'], $ids)) {
                        $item['Selected'] = true;
                    }
                }
            }
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 加号时医生所在医院列表
     */
    public function hospitalAction()
    {
        $doctors = OrganizationExDoctor::find([
            'conditions' => 'ExDoctorId=?0',
            'bind'       => [$this->request->get('DoctorId')],
        ]);
        $hospitals = Organization::query()->inWhere('Id', array_column($doctors->toArray(), 'OrganizationId'))->execute();
        $hospitals_new = [];
        foreach ($hospitals as $hospital) {
            $hospitals_new[$hospital->Id] = $hospital->Name;
        }
        foreach ($doctors as $doctor) {
            $doctor->HospitalName = $hospitals_new[$doctor->OrganizationId];
        }
        $this->response->setJsonContent($doctors);
    }

    /**
     * 读取挂号服务包
     */
    public function readServicePackageAction()
    {
        $package = ServicePackage::findFirst();
        $this->response->setJsonContent($package);
    }

    public function proxyAction()
    {
        $account = Ex114Account::findFirst([
            'conditions' => 'LastFailedTime<?0',
            'bind'       => [time() - 604800], // 一周
        ]);
        if (!$account) {
            $this->response->setStatusCode(Status::OK);
            $this->response->setJsonContent(['state' => '1', 'msg' => '挂号服务暂不可用']);
            return;
        }
        $key = '114Token:' . $account->Phone;
        $cookie = $this->redis->get($key);

        if (!$cookie) {
            $curl = new Curl();
            $cookie = $curl->getCookie('POST', $this->URL_114, [
                'operLogin'    => $account->Phone,
                'operPassword' => $account->Password,
            ], 'login');
            $this->redis->setex($key, 600, $cookie);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, urldecode($this->request->getQuery('url')));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/62.0.3202.94 Safari/537.36',
            'X-Requested-With: XMLHttpRequest',
            'Origin: http://www.scgh114.com',
            'Referer: http://www.scgh114.com/web/hospital/doctorinfoP',
            'Connection: keep-alive',
        ]);
        curl_setopt($ch, CURLOPT_COOKIE, sprintf('JSESSIONID=%s', $cookie));
        if ($body = $this->request->getRawBody()) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->request->getMethod());
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $resp = json_decode($response, true);
        if (!empty($resp['state']) && $resp['state'] == '1' && (strpos($resp['msg'], '过多') !== false || strpos($resp['msg'], '次数') !== false)) {
            $resp['msg'] = '校验码不正确!';
            $response = json_encode($resp);
            $account->LastFailedTime = time();
            $account->save();
        }
        $this->response->setStatusCode($code);
        $this->response->setContentType($type);
        $this->response->setContent($response);
    }
}
