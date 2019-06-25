<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/3
 * Time: 上午10:56
 */

namespace App\Controllers;

use App\Enums\AppOps;
use App\Enums\DoctorTitle;
use App\Enums\MessageTemplate;
use App\Enums\PharmacistTitle;
use App\Enums\RedisName;
use App\Enums\SmsExtend;
use App\Enums\Status;
use App\Enums\WebrtcName;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\appOps\Manager as AppOpsManager;
use App\Libs\Sms;
use App\Libs\Sphinx;
use App\Models\ApplyOfShare;
use App\Models\DoctorCase;
use App\Models\DoctorHonor;
use App\Models\DoctorIdentify;
use App\Models\DoctorOfAptitude;
use App\Models\DoctorRecommendedReason;
use App\Models\InquiryEvaluateTotal;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationAndSection;
use App\Models\OrganizationSection;
use App\Models\OrganizationSendMessageConfig;
use App\Models\OrganizationUser;
use App\Models\OrganizationUserAppOps;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Models\UserSignature;
use App\Models\UserTempCache;
use App\Validators\IDCardNo;
use App\Validators\Mobile;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\Digit;
use Phalcon\Validation\Validator\Confirmation;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\QueryBuilder;
use Phalcon\Validation;

class UserController extends Controller
{
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPost()) {
                $auth = $this->session->get('auth');
                if (!$auth) {
                    throw new LogicException('请登录', Status::Unauthorized);
                }
                $this->db->begin();
                $data = $this->request->getPost();
                $data['CreateTime'] = time();
                $data['UpdateTime'] = time();
                $data['Phone'] = trim($data['Phone']);
                $data['UseStatus'] = OrganizationUser::USESTATUS_ON;
                $data['OrganizationId'] = $auth['OrganizationId'];
                if (empty($data['Image']) || !isset($data['Image'])) {
                    $data['Image'] = '';
                }
                if (empty($data['Sort']) || !isset($data['Sort'])) {
                    if (!is_numeric($data['Sort'])) {
                        throw new LogicException('排序值必须是数字', Status::BadRequest);
                    }
                    $data['Sort'] = 0;
                }
                if (empty($data['Identified']) || !isset($data['Identified'])) {
                    $data['Identified'] = OrganizationUser::IDENTIFIED_OFF;
                }
                if (empty($data['DoctorSign']) || !isset($data['DoctorSign'])) {
                    $data['DoctorSign'] = '';
                }
                $oldUser = User::findFirst([
                    'conditions' => 'Phone=?0',
                    'bind'       => [$data['Phone']],
                ]);
                if (!$oldUser) {
                    $user = new User();
                    $data['Password'] = $this->security->hash(substr($data['Phone'], -6, 6));
                    //设置验证场景
                    $user->setScene(User::SCENE_USER_CREATE);
                    if ($user->save($data) === false) {
                        $exception->loadFromModel($user);
                        throw $exception;
                    }
                    $data['UserId'] = $user->Id;
                } else {
                    $data['UserId'] = $oldUser->Id;
                    $user = $oldUser;
                }
                $oldOrganizationUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$auth['OrganizationId'], $data['UserId']],
                ]);
                if ($oldOrganizationUser) {
                    $exception->add('Phone', '该员工已存在,不要重复注册');
                    throw $exception;
                }
                $organizationUser = new OrganizationUser();
                $organizationUser->setScene(OrganizationUser::SCENE_USER_CREATE);
                //
                // if ($organizationUser->save($data) === false) {
                //     $exception->loadFromModel($organizationUser);
                //     throw $exception;
                // }
                $organizationUser->validation();
                $organizationUser->assign($data);
                $userTempCache[] = serialize($organizationUser);
                //医生荣誉
                if (isset($data['Honors']) && is_array($data['Honors']) && count($data['Honors'])) {
                    foreach ($data['Honors'] as $honor) {
                        if (!empty($honor)) {
                            $doctorHonor = new DoctorHonor();
                            $doctorHonor->OrganizationId = $organizationUser->OrganizationId;
                            $doctorHonor->UserId = $organizationUser->UserId;
                            $doctorHonor->Content = $honor;
                            // if ($doctorHonor->save() === false) {
                            //     $exception->loadFromModel($doctorHonor);
                            //     throw $exception;
                            // }
                            $doctorHonor->validation();
                            $userTempCache[] = serialize($doctorHonor);
                        }
                    }
                }
                //医生推荐理由
                if (isset($data['Reasons']) && is_array($data['Reasons']) && count($data['Reasons'])) {
                    foreach ($data['Reasons'] as $reason) {
                        if (!empty($reason)) {
                            $doctorReason = new DoctorRecommendedReason();
                            $doctorReason->OrganizationId = $organizationUser->OrganizationId;
                            $doctorReason->UserId = $organizationUser->UserId;
                            $doctorReason->Content = $reason;
                            // if ($doctorReason->save() === false) {
                            //     $exception->loadFromModel($doctorReason);
                            //     throw $exception;
                            // }
                            $doctorReason->validation();
                            $userTempCache[] = serialize($doctorReason);
                        }
                    }
                }
                //医生案例
                if (isset($data['Cases']) && is_array($data['Cases']) && count($data['Cases'])) {
                    foreach ($data['Cases'] as $case) {
                        if (!empty($case['Title'])) {
                            $doctorCase = new DoctorCase();
                            $doctorCase->OrganizationId = $organizationUser->OrganizationId;
                            $doctorCase->UserId = $organizationUser->UserId;
                            $doctorCase->Title = $case['Title'];
                            $doctorCase->Image = $case['Image'];
                            $doctorCase->Content = $case['Content'];
                            // if ($doctorCase->save() === false) {
                            //     $exception->loadFromModel($doctorCase);
                            //     throw $exception;
                            // }
                            $doctorCase->validation();
                            $userTempCache[] = serialize($doctorCase);
                        } elseif (empty($case['Title']) && !empty($case['Content'])) {
                            throw new LogicException('请完善案例', Status::BadRequest);
                        }
                    }
                }
                foreach ($userTempCache as $value) {
                    $cache = new UserTempCache();
                    $cache->Phone = $data['Phone'];
                    $cache->MerchantCode = $auth['MerchantCode'];
                    $cache->Content = $value;
                    $cache->Code = SmsExtend::CODE_CREATE_DOCTOR;
                    if ($cache->save() === false) {
                        $exception->loadFromModel($cache);
                        throw $exception;
                    }
                }
                $this->db->commit();
                $content = sprintf(SmsExtend::CODE_CREATE_DOCTOR_MESSAGE, $auth['OrganizationName'], $auth['MerchantCode']);
                $sms = new Sms($this->queue);
                $sms->sendMessage((string)$user->Phone, $content, SmsExtend::CODE_CREATE_DOCTOR);
                //设置状态值，返回数据
                $this->response->setStatusCode(Status::Created);
                $user = array_merge($user->toArray(), $organizationUser->toArray());
                unset($user['Password']);
                $this->response->setJsonContent($user);
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

    public function updateAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $this->db->begin();
                $id = $this->request->getPut('Id');
                $user = User::findFirst(sprintf('Id=%d', $id));
                $organizationUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$this->session->get('auth')['OrganizationId'], $id],
                ]);
                if (!$user || !$organizationUser) {
                    throw $exception;
                }
                $role = $organizationUser->Role;
                $data = $this->request->getPut();
                $data['UpdateTime'] = time();
                //修改手机号码
                $fresh = false;
                // $password = $user->Password;
                // if ($data['Phone'] != $user->Phone) {
                //     $fresh = true;
                //     $oldUser = User::findFirst([
                //         'conditions' => 'Phone=?0',
                //         'bind'       => [$data['Phone']],
                //     ]);
                //     if ($oldUser) {
                //         unset($data['Password'], $data['Phone'], $data['Name']);
                //         $user = $oldUser;
                //         $oldOrganizationUser = OrganizationUser::findFirst([
                //             'conditions' => 'OrganizationId=?0 and UserId=?1',
                //             'bind'       => [$this->user->OrganizationId, $oldUser->Id],
                //         ]);
                //         if ($oldOrganizationUser) {
                //             $exception->add('Phone', '该员工已存在');
                //             throw $exception;
                //         }
                //     } else {
                //         $user = new User();
                //         //设置验证场景
                //         $user->setScene(User::SCENE_USER_UPDATE);
                //         $data['Password'] = $password;
                //     }
                // } else {
                //     unset($data['Password']);
                // }
                unset($data['Id'], $data['Password']);
                if ($user->save($data) === false) {
                    $exception->loadFromModel($user);
                    throw $exception;
                }
                $organizationUser->setScene(OrganizationUser::SCENE_USER_UPDATE);
                if ($organizationUser->save($data) === false) {
                    $exception->loadFromModel($organizationUser);
                    throw $exception;
                }
                //更新了用户id
                if ($fresh) {
                    $organizationUser_data = $organizationUser->toArray();
                    $organizationUser_data['OrganizationId'] = $this->user->OrganizationId;
                    $organizationUser_data['UserId'] = $user->Id;
                    $organizationUser->delete();
                    $organizationUser_fresh = new OrganizationUser();
                    if ($organizationUser_fresh->save($organizationUser_data) === false) {
                        $exception->loadFromModel($organizationUser_fresh);
                        throw $exception;
                    }
                }
                //删除医生荣誉、推荐理由、案例
                if ($organizationUser->IsDoctor == OrganizationUser::IS_DOCTOR_YES) {
                    $doctorHonors = DoctorHonor::find([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$organizationUser->OrganizationId, $organizationUser->UserId],
                    ]);
                    $doctorHonors->delete();
                    $doctorReasons = DoctorRecommendedReason::find([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$organizationUser->OrganizationId, $organizationUser->UserId],
                    ]);
                    $doctorReasons->delete();
                    $doctorCases = DoctorCase::find([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$organizationUser->OrganizationId, $organizationUser->UserId],
                    ]);
                    $doctorCases->delete();
                }
                //医生荣誉
                if (isset($data['Honors']) && is_array($data['Honors']) && count($data['Honors'])) {
                    foreach ($data['Honors'] as $honor) {
                        if (!empty($honor)) {
                            $doctorHonor = new DoctorHonor();
                            $doctorHonor->OrganizationId = $organizationUser->OrganizationId;
                            $doctorHonor->UserId = $organizationUser->UserId;
                            $doctorHonor->Content = $honor;
                            if ($doctorHonor->save() === false) {
                                $exception->loadFromModel($doctorHonor);
                                throw $exception;
                            }
                        }
                    }
                }
                //医生推荐理由
                if (isset($data['Reasons']) && is_array($data['Reasons']) && count($data['Reasons'])) {
                    foreach ($data['Reasons'] as $reason) {
                        if (!empty($reason)) {
                            $doctorReason = new DoctorRecommendedReason();
                            $doctorReason->OrganizationId = $organizationUser->OrganizationId;
                            $doctorReason->UserId = $organizationUser->UserId;
                            $doctorReason->Content = $reason;
                            if ($doctorReason->save() === false) {
                                $exception->loadFromModel($doctorReason);
                                throw $exception;
                            }
                        }
                    }
                }
                //医生案例
                if (isset($data['Cases']) && is_array($data['Cases']) && count($data['Cases'])) {
                    foreach ($data['Cases'] as $case) {
                        if (!empty($case['Title'])) {
                            $doctorCase = new DoctorCase();
                            $doctorCase->OrganizationId = $organizationUser->OrganizationId;
                            $doctorCase->UserId = $organizationUser->UserId;
                            $doctorCase->Title = $case['Title'];
                            $doctorCase->Image = $case['Image'];
                            $doctorCase->Content = $case['Content'];
                            if ($doctorCase->save() === false) {
                                $exception->loadFromModel($doctorCase);
                                throw $exception;
                            }
                        } elseif (empty($case['Title']) && !empty($case['Content'])) {
                            throw new LogicException('请完善案例', Status::BadRequest);
                        }
                    }
                }
                $this->db->commit();
                //清除用户角色缓存
                if ($data['Role'] != $role) {
                    $this->redis->delete(RedisName::Permission . $organizationUser->OrganizationId . '_' . $user->Id);
                }
                $user = array_merge($user->toArray(), $organizationUser->toArray());
                unset($user['Password']);
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($user);
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

    public function deleteAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isDelete()) {
                throw new LogicException('访问方式错误', Status::MethodNotAllowed);
            }
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$this->session->get('auth')['OrganizationId'], $this->request->getPut('Id')],
            ]);
            if (!$organizationUser) {
                throw $exception;
            }
            if ($organizationUser->Label === OrganizationUser::LABEL_ADMIN || $organizationUser->Role === Role::DEFAULT_B) {
                throw new LogicException('无权删除管理员', Status::Forbidden);
            }
            $doctorOfAptitude = DoctorOfAptitude::findFirst([
                'conditions' => "OrganizationId=:OrganizationId: and DoctorId=:DoctorId:",
                "bind"       => ['OrganizationId' => $organizationUser->OrganizationId, 'DoctorId' => $organizationUser->UserId],
            ]);
            if ($doctorOfAptitude) {
                $doctorOfAptitude->delete();
            }
            $apply = ApplyOfShare::findFirst([
                'conditions' => "OrganizationId=?0 and DoctorId=?1 and Status=?2",
                "bind"       => [$organizationUser->OrganizationId, $organizationUser->UserId, ApplyOfShare::WAIT],
            ]);
            if ($apply) {
                $apply->delete();
            }
            if ($organizationUser->delete()) {
                $this->response->setJsonContent(['message' => 'success']);
            }
            $this->db->commit();
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function listAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['U.Id', 'U.Name', 'U.Sex', 'U.Phone', 'U.IDnumber', 'OU.OrganizationId', 'OU.CreateTime', 'OU.UpdateTime', 'OU.LastLoginTime', 'OU.LastLoginIp', 'OU.Image', 'OU.Role', 'OU.SectionId', 'OU.Title', 'OU.Intro', 'OU.Skill', 'OU.Direction', 'OU.Experience', 'OU.IsDoctor', 'OU.Display', 'OU.Share', 'OU.Label', 'OU.Switch', 'OU.Score', 'OU.UseStatus', 'OU.Sort', 'OU.LabelName', 'OU.Identified', 'OU.IsSalesman'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->join(User::class, 'U.Id=OU.UserId', 'U', 'left')
            ->where("OU.OrganizationId=:OrganizationId:", ['OrganizationId' => $auth['OrganizationId']])
            ->andWhere(sprintf('OU.IsDelete=%d', OrganizationUser::IsDelete_No));
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'user');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ? $name : [], 'id');
            if (count($ids)) {
                $query->inWhere('U.Id', $ids);
            } else {
                $query->inWhere('U.Id', [-1]);
            }
        }
        if (!empty($data['Title']) && isset($data['Title'])) {
            $query->andWhere("OU.Title=:Title:", ['Title' => $data['Title']]);
        }
        if (!empty($data['UseStatus']) && isset($data['UseStatus'])) {
            $query->andWhere("OU.UseStatus=:UseStatus:", ['UseStatus' => $data['UseStatus']]);
        }
        if (isset($data['IsDoctor'])) {
            $query->andWhere("OU.IsDoctor=:IsDoctor:", ['IsDoctor' => $data['IsDoctor']]);
        }
        if (!empty($data['Phone']) && isset($data['Phone'])) {
            $query->andWhere("U.Phone=:Phone:", ['Phone' => $data['Phone']]);
        }
        if (!empty($data['Role']) && isset($data['Role'])) {
            $query->andWhere("OU.Role=:Role:", ['Role' => $data['Role']]);
        }
        if (!empty($data['Display']) && isset($data['Display']) && is_numeric($data['Display'])) {
            $query->andWhere("OU.Display=:Display:", ['Display' => $data['Display']]);
        }
        //是否显示自己
        if (!empty($data['Hide']) && isset($data['Hide'])) {
            if ($data['Hide'] == 2) {
                $query->andWhere("U.Id!=:Hide:", ['Hide' => $this->user->Id]);
                // 已废弃标签是10的为管理员，现在管理员是User->Phone == Organization->Phone
                // $query->andWhere("OU.Label!=:Admin:", ['Admin' => User::LABEL_ADMIN]);
            }
        }
        //开始时间
        if (!empty($data['StartTime']) && isset($data['StartTime'])) {
            $query->andWhere("OU.CreateTime>=:StartTime:", ['StartTime' => $data['StartTime']]);
        }
        //结束时间
        if (!empty($data['EndTime']) && isset($data['EndTime'])) {
            if (!empty($data['StartTime']) && !empty($data['EndTime']) && ($data['StartTime'] > $data['EndTime'])) {
                $response->setStatusCode(Status::NotFound);
                return $response;
            }
            $query->andWhere("OU.CreateTime<=:EndTime:", ['EndTime' => $data['EndTime'] + 86400]);
        }
        $query->orderBy('OU.Sort desc');
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

        $roleNames = Role::find([
            'conditions' => 'OrganizationId=?0',
            'bind'       => [$auth['OrganizationId']],
        ])->toArray();
        //角色
        $roleNames_new = [];
        foreach ($roleNames as $v) {
            $roleNames_new[$v['Id']] = $v['Name'];
        }
        if (isset($data['IsDoctor']) && $data['IsDoctor'] == OrganizationUser::IS_DOCTOR_YES) {
            //科室
            $sections = Section::query()
                ->columns('Id,Name')
                ->inWhere('Id', array_column($datas, 'SectionId'))
                ->execute()
                ->toArray();
            $sections_new = [];
            foreach ($sections as $v) {
                $sections_new[$v['Id']] = $v['Name'];
            }
            //认证
            $identify = DoctorIdentify::query()
                ->inWhere('UserId', array_column($datas, 'Id'))
                ->andWhere(sprintf('OrganizationId=%d', $auth['OrganizationId']))
                ->execute()->toArray();
            $identify_new = [];
            if (count($identify)) {
                foreach ($identify as $value) {
                    $identify_new[$value['UserId']] = [
                        'IdentifyCreateTime'    => $value['Created'],
                        'IdentifyStatus'        => $value['Status'],
                        'IdentifyAuditTime'     => $value['AuditTime'],
                        'IdentifyReason'        => $value['Reason'],
                        'IdentifyMedicineClass' => $value['MedicineClass'],
                    ];
                }
            }
            foreach ($datas as &$data) {
                $data['SectionName'] = $sections_new[$data['SectionId']];
                $data['TitleName'] = DoctorTitle::value($data['Title']);
                $data['RoleName'] = $roleNames_new[$data['Role']];
                $data['Image'] = $data['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
                $data['Intro'] = strip_tags($data['Intro']);
                $data['Skill'] = strip_tags($data['Skill']);
                $data['Direction'] = strip_tags($data['Direction']);
                $data['Experience'] = strip_tags($data['Experience']);
                $data['Identified'] = $data['Identified'] != null ?: 0;
                $data['IdentifyCreateTime'] = isset($identify_new[$data['Id']]) ? $identify_new[$data['Id']]['IdentifyCreateTime'] : null;
                $data['IdentifyStatus'] = isset($identify_new[$data['Id']]) ? $identify_new[$data['Id']]['IdentifyStatus'] : 0;
                $data['IdentifyAuditTime'] = isset($identify_new[$data['Id']]) ? $identify_new[$data['Id']]['IdentifyAuditTime'] : null;
                $data['IdentifyReason'] = isset($identify_new[$data['Id']]) ? ($identify_new[$data['Id']]['IdentifyStatus'] == DoctorIdentify::STATUS_REFUSE ? $identify_new[$data['Id']]['IdentifyReason'] : '') : '';
                $data['IdentifyMedicineClass'] = isset($identify_new[$data['Id']]) ? $identify_new[$data['Id']]['IdentifyMedicineClass'] : null;

            }
        } else {
            foreach ($datas as &$data) {
                $data['TitleName'] = DoctorTitle::value($data['Title']);
                $data['RoleName'] = $roleNames_new[$data['Role']];
                $data['Image'] = $data['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
                $data['Intro'] = strip_tags($data['Intro']);
                $data['Identified'] = $data['Identified'] != null ?: 0;
            }
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    public function readAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        if (!$auth) {
            return $this->response->setStatusCode(Status::Unauthorized);
        }
        $id = $this->request->get('Id');
        $organizationId = $this->request->get('OrganizationId') ?: $auth['OrganizationId'];
        $user = User::findFirst(sprintf('Id=%d', $id));
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
            'bind'       => [$organizationId, $id, OrganizationUser::IsDelete_No],
        ]);
        if (!$user || !$organizationUser) {
            $response->setStatusCode(Status::BadRequest);
            return $response;
        }
        $result = [];
        if ($user && $organizationUser) {
            $result = array_merge($user->toArray(), $organizationUser->toArray());
            $result['OrganizationName'] = $organizationUser->Organization->Name;
            $result['Section'] = $organizationUser->Section->Name;
            $result['TitleName'] = DoctorTitle::value($organizationUser->Title);
            $result['Image'] = $result['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
            $result['Password'] = '';
        }
        if ($organizationUser->IsDoctor === OrganizationUser::IS_DOCTOR_YES) {
            $doctorHonors = DoctorHonor::find([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$organizationUser->OrganizationId, $organizationUser->UserId],
            ])->toArray();
            $doctorReasons = DoctorRecommendedReason::find([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$organizationUser->OrganizationId, $organizationUser->UserId],
            ])->toArray();
            $doctorCases = DoctorCase::find([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$organizationUser->OrganizationId, $organizationUser->UserId],
            ])->toArray();
            $result['Honors'] = array_column($doctorHonors, 'Content');
            $result['Reasons'] = array_column($doctorReasons, 'Content');
            $result['Cases'] = $doctorCases;
        }
        $result['Number'] = '';
        if ($organizationUser->Identified == OrganizationUser::IDENTIFIED_ON) {
            $result['Number'] = DoctorIdentify::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$organizationUser->OrganizationId, $organizationUser->UserId],
            ])->Number;
        }
        if ($this->request->getHeader('Version') <= '1.0.3') {
            $result['Intro'] = strip_tags($result['Intro']);
            $result['Skill'] = strip_tags($result['Skill']);
            $result['Direction'] = strip_tags($result['Direction']);
            $result['Experience'] = strip_tags($result['Experience']);
        }
        $response->setJsonContent($result);
        return $response;
    }

    public function updatePhoneAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $data = $this->request->getPut();
                $user = User::findFirst(sprintf('Id=%d', $data['Id']));
                if (!$user) {
                    throw $exception;
                }
                unset($data['Password']);
                $user->setScene(User::SCENE_USER_UPDATEPHONE);
                if ($user->save($data) === false) {
                    $exception->loadFromModel($user);
                    throw $exception;
                }
                $this->response->setStatusCode(Status::Created);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 医院自有医生
     */
    public function doctorAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $hospitalId = $auth['HospitalId'];
        $data = $this->request->getPost();

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        if (!empty($data['Show']) && isset($data['Show'])) {
            $pageSize = $data['Show'];
        }
        $query = $this->modelsManager->createBuilder()
            ->columns('U.Id,U.Name,OU.OrganizationId,OU.SectionId,OU.Image,OU.Title,OU.Label,OU.Skill,OU.UpdateTime,OU.Display,OU.Score,OU.Sort,OU.LabelName, OU.Identified,O.Name as HospitalName,S.Name as SectionName')
            ->addFrom(OrganizationUser::class, 'OU')
            ->join(User::class, 'U.Id=OU.UserId', 'U')
            ->join(Section::class, 'S.Id=OU.SectionId', 'S')
            ->join(Organization::class, 'O.Id=OU.OrganizationId', 'O')
            ->join(OrganizationAndSection::class, 'OS.OrganizationId=OU.OrganizationId and OS.SectionId=OU.SectionId', 'OS')
            ->where('OU.OrganizationId=:OrganizationId:', ['OrganizationId' => $hospitalId])
            ->andWhere('OU.IsDoctor=1')
            ->andWhere('OU.Display=1')
            ->andWhere('OS.Display=1')
            ->orderBy('OU.Sort desc');
        //科室
        if (!empty($data['SectionId']) && isset($data['SectionId'])) {
            $query->andWhere('OU.SectionId=:SectionId:', ['SectionId' => $data['SectionId']]);
        }
        //职称
        if (!empty($data['Title']) && isset($data['Title'])) {
            $query->andWhere('OU.Title=:Title:', ['Title' => $data['Title']]);
        }
        //得分的排序
        if (!empty($data['Sort']) && isset($data['Sort'])) {
            switch ($data['Sort']) {
                case 'Desc':
                    $query->orderBy('OU.Score desc,OU.Sort desc');
                    break;
                case 'Asc':
                    $query->orderBy('OU.Score asc,OU.Sort desc');
                    break;
                default:
                    $query->orderBy('OU.Sort desc');
            }
        } else {
            $query->orderBy('OU.Sort desc');
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
        $doctors = $pages->items->toArray();
        foreach ($doctors as &$doctor) {
            $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
            $doctor['Score'] = sprintf('%.1f', $doctor['Score']);
            $doctor['Image'] = $doctor['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
            $doctor['Intro'] = strip_tags($doctor['Intro']);
            $doctor['Skill'] = strip_tags($doctor['Skill']);
            $doctor['Direction'] = strip_tags($doctor['Direction']);
            $doctor['Experience'] = strip_tags($doctor['Experience']);
        }
        if (!empty($data['Show']) && isset($data['Show'])) {
            $response->setJsonContent($doctors);
        } else {
            //分页
            $result = [];
            $result['Data'] = $doctors;
            $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
            $response->setJsonContent($result);
        }
        return $response;
    }

    /**
     * 共享医生
     */
    public function shareDoctorAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $hospitalId = $auth['HospitalId'];
        $data = $this->request->getPost();

        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        if (!empty($data['Show']) && isset($data['Show'])) {
            $pageSize = $data['Show'];
        }
        $query = $this->modelsManager->createBuilder()
            ->columns('U.Id,U.Name,OU.OrganizationId,OU.SectionId,OU.Image,OU.Title,OU.Label,OU.Skill,OU.Score,OU.LabelName, OU.Identified,O.Name as HospitalName,S.Name as SectionName')
            ->addFrom(OrganizationUser::class, 'OU')
            ->join(User::class, 'U.Id=OU.UserId', 'U')
            ->join(Section::class, 'S.Id=OU.SectionId', 'S')
            ->join(Organization::class, 'O.Id=OU.OrganizationId', 'O')
            ->join(OrganizationAndSection::class, 'OS.OrganizationId=OU.OrganizationId and OS.SectionId=OU.SectionId', 'OS')
            ->Where('OU.OrganizationId !=' . $hospitalId)
            ->andWhere('OU.Share=2')
            ->andWhere('OU.IsDoctor=1')
            ->andWhere('OU.Display=1')
            ->andWhere('OS.Display=1')
            ->andWhere('OS.Share=2')
            ->andWhere('O.Verifyed=2')
            ->orderBy('OU.OrganizationId asc,OU.Sort desc');
        //按地区
        if (!empty($data['AreaId']) && isset($data['AreaId'])) {
            $query->andWhere('O.AreaId=:AreaId:', ['AreaId' => $data['AreaId']]);
        }
        //科室
        if (!empty($data['SectionId']) && isset($data['SectionId'])) {
            $query->andWhere('OU.SectionId=:SectionId:', ['SectionId' => $data['SectionId']]);
        }
        //职称
        if (!empty($data['Title']) && isset($data['Title'])) {
            $query->andWhere('OU.Title=:Title:', ['Title' => $data['Title']]);
        }
        //得分的排序
        if (!empty($data['Sort']) && isset($data['Sort'])) {
            switch ($data['Sort']) {
                case 'Desc':
                    $query->orderBy('OU.Score desc,OU.Sort desc');
                    break;
                case 'Asc':
                    $query->orderBy('OU.Score asc,OU.Sort desc');
                    break;
                default:
                    $query->orderBy('OU.Sort desc');
            }
        } else {
            $query->orderBy('OU.Sort desc');
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
        $doctors = $pages->items->toArray();
        foreach ($doctors as &$doctor) {
            $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
            $doctor['Score'] = sprintf('%.1f', $doctor['Score']);
            $doctor['Image'] = $doctor['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
            $doctor['Intro'] = strip_tags($doctor['Intro']);
            $doctor['Skill'] = strip_tags($doctor['Skill']);
            $doctor['Direction'] = strip_tags($doctor['Direction']);
            $doctor['Experience'] = strip_tags($doctor['Experience']);
        }
        //随机

        if (!empty($data['Show']) && isset($data['Show'])) {
            $response->setJsonContent($doctors);
        } else {
            //分页
            $result = [];
            $result['Data'] = $doctors;
            $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
            $response->setJsonContent($result);
        }

        return $response;
    }

    /**
     * 登录之后修改用户密码
     */
    public function changePasswordAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $auth = $this->session->get('auth');
                $user = User::findFirst(sprintf('Id=%d', $auth['Id']));
                $oldPassword = $this->request->getPut('OldPassword');
                $password = $this->request->getPut('Password');
                $rePassword = $this->request->getPut('RePassword');
                if ($password !== $rePassword) {
                    $exception->add('RePassword', '密码不一致');
                    throw $exception;
                }
                if ($this->security->checkHash($oldPassword, $user->Password) === false) {
                    $exception->add('OldPassword', '密码错误');
                    throw $exception;
                }
                $user->Password = $this->security->hash($password);
                if ($user->save() === false) {
                    $exception->loadFromModel($user);
                    throw $exception;
                }
                $this->response->setStatusCode(Status::Created);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 上报手机信息
     */
    public function reportAction()
    {
        if ($this->request->isPost()) {
            if ($this->request->getPost('AppId')) {
                $this->user->AppId = $this->request->getPost('AppId');
            }
            if ($this->request->getPost('Factory') && $this->request->getPost('ModelNumber')) {
                $this->user->Factory = $this->request->getPost('Factory');
                $this->user->ModelNumber = $this->request->getPost('ModelNumber');
            }
            $this->user->save();
            return;
        }
        $this->response->setStatusCode(Status::MethodNotAllowed);
    }

    /**
     * 科室成员
     */
    public function sectionAction()
    {
        $response = new Response();
        if ($this->request->isPost()) {
            $pageSize = $this->request->get('PageSize', 'int', 10);
            $page = $this->request->get('Page', 'int', 1);
            $query = $this->modelsManager->createBuilder();
            $query->columns('U.Id,U.Name,U.Sex,U.TransferAmount,OU.Score,OU.OrganizationId,OU.Image,OU.Label,OU.Title,OU.Skill,OU.LabelName, OU.Identified,O.Name as HospitalName,S.Name as SectionName,O.Id as HospitalId');
            $query->addFrom(OrganizationUser::class, 'OU');
            $query->join(User::class, 'U.Id=OU.UserId', 'U', 'left');
            $query->join(Organization::class, 'O.Id=OU.OrganizationId', 'O', 'left');
            $query->join(Section::class, 'S.Id=OU.SectionId', 'S', 'left');
            $query->where("OU.OrganizationId=:OrganizationId:", ['OrganizationId' => $this->request->get('OrganizationId')]);
            $query->andWhere("OU.SectionId=:SectionId:", ['SectionId' => $this->request->get('SectionId')]);
            $query->andWhere('OU.Display=1');
            if ($this->request->get('Share')) {
                $query->andWhere('OU.Share=:Share:', ['Share' => $this->request->get('Share')]);
            }
            $query->andWhere('OU.IsDoctor=1');
            $query->orderBy('OU.Label desc,OU.Sort desc');
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
            $doctors = $pages->items->toArray();
            foreach ($doctors as &$doctor) {
                $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
                $doctor['Image'] = $doctor['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
                $doctor['Intro'] = strip_tags($doctor['Intro']);
                $doctor['Skill'] = strip_tags($doctor['Skill']);
                $doctor['Direction'] = strip_tags($doctor['Direction']);
                $doctor['Experience'] = strip_tags($doctor['Experience']);
            }
            $result = [];
            $result['Data'] = $doctors;
            $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
            $response->setJsonContent($result);

        } else {
            $response->setStatusCode(Status::MethodNotAllowed);
        }
        return $response;
    }

    /**
     * 小B修改个人信息 和 网点信息
     */
    public function editAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $auth = $this->session->get('auth');
                $data = $this->request->getPut();
                $data['UpdateTime'] = time();
                $whiteList = ['Name'];
                $this->db->begin();
                if ($this->user->save($data, $whiteList) === false) {
                    $exception->loadFromModel($this->user);
                    throw $exception;
                }
                $organizationUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$auth['OrganizationId'], $auth['Id']],
                ]);
                $whiteList = ['Image', 'UpdateTime'];
                if ($organizationUser->save($data, $whiteList) === false) {
                    $exception->loadFromModel($organizationUser);
                    throw $exception;
                }
                $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
                if (!empty($data['ProvinceId']) && isset($data['ProvinceId']) && is_numeric($data['ProvinceId'])) {
                    $organization->ProvinceId = intval($data['ProvinceId']);
                }
                if (!empty($data['CityId']) && isset($data['CityId']) && is_numeric($data['CityId'])) {
                    $organization->CityId = intval($data['CityId']);
                }
                if (!empty($data['AreaId']) && isset($data['AreaId']) && is_numeric($data['AreaId'])) {
                    $organization->AreaId = intval($data['AreaId']);
                }
                if (!empty($data['Address']) && isset($data['Address'])) {
                    $organization->Address = $data['Address'];
                }
                if (!empty($data['OrganizationName']) && isset($data['OrganizationName'])) {
                    $organization->Name = $data['OrganizationName'];
                }
                $organization->Contact = $this->user->Name;
                if ($organization->save() === false) {
                    $exception->loadFromModel($organization);
                    throw $exception;
                }
                $this->db->commit();
                $location = Location::query()
                    ->columns('Id,Name')
                    ->inWhere('Id', [$organization->ProvinceId, $organization->CityId, $organization->AreaId])
                    ->execute()
                    ->toArray();
                $location_new = [];
                foreach ($location as $v) {
                    $location_new[$v['Id']] = $v['Name'];
                }
                $hospital = Organization::findFirst(sprintf('Id=%d', $auth['HospitalId']));
                $result = $organizationUser->toArray();
                $result['Name'] = $organizationUser->User->Name;
                $result['Phone'] = $organizationUser->User->Phone;
                $result['Sex'] = $organizationUser->User->Sex;
                $result['IDnumber'] = $organizationUser->User->IDnumber;
                $result['Email'] = $organizationUser->User->Email;
                $result['AppId'] = $organizationUser->User->AppId;
                $result['Factory'] = $organizationUser->User->Factory;
                $result['ModelNumber'] = $organizationUser->User->ModelNumber;
                $result['Token'] = $this->session->getId();
                $result['HospitalName'] = $hospital->Name;
                $result['HospitalId'] = $hospital->Id;
                $result['OrganizationName'] = $organization->Name;
                $result['ProvinceId'] = $organization->ProvinceId;
                $result['CityId'] = $organization->CityId;
                $result['AreaId'] = $organization->AreaId;
                $result['Province'] = $location_new[$organization->ProvinceId];
                $result['City'] = $location_new[$organization->CityId];
                $result['Area'] = $location_new[$organization->AreaId];
                $result['Address'] = $organization->Address;
                //是否发送转诊短信给患者
                $sendMessage = OrganizationSendMessageConfig::findFirst([
                    'conditions' => 'OrganizationId=?0 and Type=?1',
                    'bind'       => [$result['OrganizationId'], OrganizationSendMessageConfig::TYPE_SEND_TO_PATIENT],
                ]);
                $result['AgreeSendMessage'] = $sendMessage ? $sendMessage->AgreeSendMessage : OrganizationSendMessageConfig::AGREE_SEND_YES;
                foreach ($auth as $k => $v) {
                    if (!isset($result[$k])) {
                        $result[$k] = $v;
                    } else {
                        $auth[$k] = $result[$k];
                    }
                }
                $this->session->set('auth', $auth);
                $this->response->setStatusCode(Status::Created);
                $this->response->setJsonContent($result);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 小b查看账户信息
     * @return Response
     */
    public function accountAction()
    {
        $response = new Response();
        $organizationId = $this->session->get('auth')['OrganizationId'];
        $organization = Organization::findFirst(sprintf('Id=%d', $organizationId));
        if ($organization->IsMain === 1) {
            $response->setStatusCode(Status::Forbidden);
            return $response;
        }
        $result['Balance'] = $organization->Balance;
        $result['Money'] = $organization->Money;
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 创建员工 修改员工
     */
    public function addStaffAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            $now = time();
            $this->db->begin();
            $post = false;
            if ($this->request->isPost()) {
                $user = new User();
                $organizationUser = new OrganizationUser();
                $data = $this->request->getPost();
                $data['OrganizationId'] = $auth['OrganizationId'];
                $data['Phone'] = trim($data['Phone']);
                $data['UseStatus'] = OrganizationUser::USESTATUS_ON;
                $data['CreateTime'] = $now;
                $data['UpdateTime'] = $now;
                $data['Display'] = OrganizationUser::DISPLAY_OFF;
                if (empty($data['Identified']) || !isset($data['Identified'])) {
                    $data['Identified'] = OrganizationUser::IDENTIFIED_OFF;
                }
                $data['Password'] = $this->security->hash(substr($data['Phone'], -6, 6));
                $oldUser = User::findFirst([
                    'conditions' => 'Phone=?0',
                    'bind'       => [$data['Phone']],
                ]);
                if ($oldUser) {
                    $post = true;
                    $data['UserId'] = $oldUser->Id;
                    $user = $oldUser;
                    $oldOrganizationUser = OrganizationUser::findFirst([
                        'conditions' => 'OrganizationId=?0 and UserId=?1',
                        'bind'       => [$auth['OrganizationId'], $data['UserId']],
                    ]);
                    if ($oldOrganizationUser) {
                        $exception->add('Phone', '该员工已存在,不要重复注册');
                        throw $exception;
                    }
                }
                $whiteList = ['Name', 'Phone', 'Password'];
            } elseif ($this->request->isPut()) {
                $data = $this->request->getPut();
                $user = User::findFirst(sprintf('Id=%d', $data['Id']));
                $organizationUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$auth['OrganizationId'], $data['Id']],
                ]);
                if (!$user || !$organizationUser) {
                    throw $exception;
                }
                unset($data['Password']);
                $data['UpdateTime'] = $now;
                $whiteList = ['Name'];
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            if (!$post) {
                $user->setScene(User::SCENE_USER_ADDSTAFF);
                if ($user->save($data, $whiteList) === false) {
                    $exception->loadFromModel($user);
                    throw $exception;
                }
                $data['UserId'] = $user->Id;
            }
            //验证是否是业务经理
            if (!isset($data['IsSalesman']) || !in_array($data['IsSalesman'], [OrganizationUser::Is_Salesman_Yes, OrganizationUser::Is_Salesman_No])) {
                throw new LogicException('请选择是否是业务经理', Status::BadRequest);
            }
            $whiteList = ['Display', 'CreateTime', 'UpdateTime', 'UseStatus', 'OrganizationId', 'UserId', 'IsSalesman'];
            if ($this->request->isPost()) {
                $organizationUser->validation();
                $organizationUser->assign($data, null, $whiteList);
                $cache = new UserTempCache();
                $cache->Phone = $data['Phone'];
                $cache->MerchantCode = $auth['MerchantCode'];
                $cache->Content = serialize($organizationUser);
                $cache->Code = SmsExtend::CODE_CREATE_STAFF;
                if ($cache->save() === false) {
                    $exception->loadFromModel($cache);
                    throw $exception;
                }
            } elseif ($this->request->isPut()) {
                if ($organizationUser->save($data, $whiteList) === false) {
                    $exception->loadFromModel($organizationUser);
                    throw $exception;
                }
            }
            //数据权限
            AppOpsManager::userAppOps((int)$user->Id, json_decode($data['AppOps'], true));

            $this->db->commit();
            if ($this->request->isPost()) {
                $content = sprintf(SmsExtend::CODE_CREATE_STAFF_MESSAGE, $auth['OrganizationName'], $auth['MerchantCode']);
                $sms = new Sms($this->queue);
                $sms->sendMessage((string)$user->Phone, $content, SmsExtend::CODE_CREATE_STAFF);
            }
            $result = array_merge($organizationUser->toArray(), $user->toArray());
            unset($result['Password']);
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 员工禁用开关
     */
    public function switchAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $id = $this->request->getPut('Id', 'int');
                $user = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
                    'bind'       => [$this->session->get('auth')['OrganizationId'], $id, OrganizationUser::IsDelete_No],
                ]);
                if (!$user) {
                    throw $exception;
                }
                if ($this->user->Id === $user->UserId) {
                    throw new LogicException('不能禁用自己', Status::BadRequest);
                }
                if ($user->Label === User::LABEL_ADMIN) {
                    throw new LogicException('不能禁用管理员', Status::BadRequest);
                }
                $status = $user->UseStatus;
                $user->UseStatus = ($status == OrganizationUser::USESTATUS_ON ? OrganizationUser::USESTATUS_OFF : OrganizationUser::USESTATUS_ON);
                if ($user->save() === false) {
                    $exception->loadFromModel($user);
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (LogicException $e) {
            throw $e;
        } catch (ParamException $e) {
            throw $e;
        }
    }

    public function batchAction()
    {
        $ids = $this->request->get('Ids');
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }
        /**
         * @var  \Phalcon\Mvc\Model\Criteria $criteria
         */
        $criteria = User::query();
        $criteria->inWhere('Id', $ids);
        $criteria->columns(['Id', 'Name']);
        $this->response->setJsonContent($criteria->execute());
    }

    /**
     * 所有医生
     */
    public function allDoctorAction()
    {
        $hospitalId = $this->session->get('auth')['HospitalId'];
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns(['U.Id', 'U.Name', 'OU.Image', 'OU.Label', 'OU.SectionId', 'OU.Share', 'OU.OrganizationId as HospitalId', 'OU.Title', 'OU.Intro', 'OU.Skill', 'OU.LabelName', 'OU.Identified', 'O.Name as HospitalName', 'S.Name as SectionName', 'O.Logo'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->join(User::class, 'U.Id=OU.UserId', 'U', 'left')
            ->join(Organization::class, 'O.Id=OU.OrganizationId', 'O', 'left')
            ->join(Section::class, 'S.Id=OU.SectionId', 'S', 'left')
            ->join(OrganizationAndSection::class, 'OS.OrganizationId=OU.OrganizationId and OS.SectionId=OU.SectionId', 'OS', 'left')
            ->where('OU.IsDoctor=1')
            ->andWhere('OU.Display=1')
            ->andWhere("if(OU.OrganizationId={$hospitalId},1=1,OU.Share=2)")
            ->andWhere('OS.Display=1')
            ->andWhere("if(OS.OrganizationId={$hospitalId},1=1,OS.Share=2)")
            ->orderBy("OS.OrganizationId<>{$hospitalId},OU.Sort desc");
        if (!empty($data['SectionId']) && isset($data['SectionId']) && is_numeric($data['SectionId'])) {
            $query->andWhere('OU.SectionId=:SectionId:', ['SectionId' => $data['SectionId']]);
        }
        if (!empty($data['HospitalId']) && isset($data['HospitalId']) && is_numeric($data['HospitalId'])) {
            $query->andWhere('OU.OrganizationId=:HospitalId:', ['HospitalId' => $data['HospitalId']]);
        }
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'user');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('U.Id', $ids);
            } else {
                $query->inWhere('U.Id', [-1]);
            }
        }
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
        $doctors = $pages->items->toArray();
        foreach ($doctors as &$doctor) {
            $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
            $doctor['Image'] = $doctor['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
            $doctor['Intro'] = strip_tags($doctor['Intro']);
            $doctor['Skill'] = strip_tags($doctor['Skill']);
            $doctor['Direction'] = strip_tags($doctor['Direction']);
            $doctor['Experience'] = strip_tags($doctor['Experience']);
        }
        //分页
        $result = [];
        $result['Data'] = $doctors;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }


    /**
     * 所有医生
     */
    public function getAllDoctorsAction()
    {
        $hospitalId = $this->session->get('auth')['HospitalId'];
        $data = $this->request->getPost();

        $query = $this->modelsManager->createBuilder()
            ->columns(['U.Id', 'U.Name', 'OU.Image', 'OU.Label', 'OU.SectionId', 'OU.Share', 'OU.OrganizationId as HospitalId', 'OU.Title', 'OU.Intro', 'OU.Skill', 'OU.LabelName', 'OU.Identified', 'OU.OnlineInquiryAmount', 'O.Name as HospitalName', 'S.Name as SectionName', 'O.Logo'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->join(User::class, 'U.Id=OU.UserId', 'U', 'left')
            ->join(Organization::class, 'O.Id=OU.OrganizationId', 'O', 'left')
            ->join(Section::class, 'S.Id=OU.SectionId', 'S', 'left')
            ->join(OrganizationAndSection::class, 'OS.OrganizationId=OU.OrganizationId and OS.SectionId=OU.SectionId', 'OS', 'left')
            ->where('OU.IsDoctor=1')
            ->andWhere('OU.Display=1')
            ->andWhere("if(OU.OrganizationId={$hospitalId},1=1,OU.Share=2)")
            ->andWhere('OS.Display=1')
            ->andWhere("if(OS.OrganizationId={$hospitalId},1=1,OS.Share=2)")
            ->orderBy("OS.OrganizationId<>{$hospitalId},OU.Sort desc");
        if (!empty($data['SectionId']) && isset($data['SectionId']) && is_numeric($data['SectionId'])) {
            $query->andWhere('OU.SectionId=:SectionId:', ['SectionId' => $data['SectionId']]);
        }
        if (!empty($data['HospitalId']) && isset($data['HospitalId']) && is_numeric($data['HospitalId'])) {
            $query->andWhere('OU.OrganizationId=:HospitalId:', ['HospitalId' => $data['HospitalId']]);
        }
        if (isset($data['UseStatus']) && is_numeric($data['UseStatus'])) {
            $query->andWhere('OU.UseStatus=:UseStatus:', ['UseStatus' => $data['UseStatus']]);
        }
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'user');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('U.Id', $ids);
            } else {
                $query->inWhere('U.Id', [-1]);
            }
        }
        if (is_numeric($data['Identified']) && isset($data['Identified'])) {
            $query->andWhere('OU.Identified=:Identified:', ['Identified' => $data['Identified']]);
        }
        $doctors = $query->getQuery()->execute()->toArray();
        if (!empty($doctors)) {
            //问诊评分
            $scores = InquiryEvaluateTotal::query()
                ->columns(['DoctorID', 'EvaluateScoreAvg', 'EvaluateScoreTimes'])
                ->inWhere('DoctorID', array_column($doctors, 'Id'))
                ->andWhere(sprintf('DoctorHospitalID=%d', $hospitalId))
                ->andWhere("EvaluateItemCode='D_CLD_INQUIRY_EVALUATE'")
                ->execute();
            $scores_time = [];
            $scores_doctor = [];
            if (!empty($scores->toArray())) {
                foreach ($scores as $score) {
                    /**@var InquiryEvaluateTotal $score */
                    $scores_doctor[$score->DoctorID] = $score->EvaluateScoreAvg;
                    $scores_time[$score->DoctorID] = $score->EvaluateScoreTimes;
                }
            }
            unset($scores);
            if (!empty($scores_time)) {
                foreach ($scores_time as $k => $item) {
                    $score = $scores_doctor[$k];
                    if ($scores_time[$k] < 20) {
                        $time = 20 - $scores_time[$k];
                        $scores_doctor[$k] = round(($score + 5 * $time) / 20, 1);
                    }
                }
            }

            foreach ($doctors as &$doctor) {
                $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
                $doctor['Image'] = $doctor['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
                $doctor['Intro'] = strip_tags($doctor['Intro']);
                $doctor['Skill'] = strip_tags($doctor['Skill']);
                $doctor['Direction'] = strip_tags($doctor['Direction']);
                $doctor['Experience'] = strip_tags($doctor['Experience']);
                $doctor['Identifier'] = WebrtcName::getHospitalDoctor($doctor['HospitalId'], $doctor['Id']);
                $doctor['Score'] = isset($scores_time[$doctor['Id']]) ? $scores_doctor[$doctor['Id']] : 5;
                $doctor['Status'] = $this->redis->get($doctor['Identifier']) ?: "offLine";
            }
        }
        //分页
        $result = [];
        $result['Data'] = $doctors;
        $this->response->setJsonContent($result);
    }

    /**
     * 执业资格证认证
     */
    public function identifyAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            if (!$this->request->isPost()) {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
            $auth = $this->session->get('auth');

            //验证
            $validator = new Validation();
            $validator->rules('IDnumber', [
                new IDCardNo(['message' => '身份证号码错误']),
            ]);
            $validator->rules('MedicineClass', [
                new PresenceOf(['message' => '认证类型不能为空']),
                new Digit(['message' => '认证类型格式错误']),
            ]);
            $validator->rules('PhysicianNumber', [
                new PresenceOf(['message' => '执业证书编码不能为空']),
            ]);

            $validator->rules('Number', [
                new PresenceOf(['message' => ' 医师资格证编码不能为空']),
            ]);
            $validator->rules('Image', [
                new PresenceOf(['message' => '医师资格证书不能为空']),
            ]);
            $validator->rules('PhysicianImage', [
                new PresenceOf(['message' => '医师执业证书不能为空']),
            ]);
            $ret = $validator->validate($this->request->getPost());
            //验证
            if ($ret->count() > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }
            $data = $this->request->getPost();
            /** @var OrganizationUser $organizationUser */
            $organizationUser = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
                'bind'       => [$auth['OrganizationId'], $data['UserId'], OrganizationUser::IsDelete_No],
            ]);
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $data['UserId']));
            if (!$organizationUser || !$user) {
                throw $exception;
            }
            /** @var DoctorIdentify $doctorIdentify */
            $doctorIdentify = DoctorIdentify::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$auth['OrganizationId'], $data['UserId']],
            ]);
            if (!$doctorIdentify) {
                $doctorIdentify = new DoctorIdentify();
            }
            $doctorIdentify->Status = DoctorIdentify::STATUS_READY;
            $doctorIdentify->OrganizationId = $auth['OrganizationId'];
            $doctorIdentify->Created = time();
            $doctorIdentify->UserId = $data['UserId'];
            $doctorIdentify->Image = $data['Image'];
            $doctorIdentify->Number = $data['Number'];
            $doctorIdentify->PhysicianNumber = $data['PhysicianNumber'];
            $doctorIdentify->PhysicianImage = $data['PhysicianImage'];
            $doctorIdentify->MedicineClass = $data['MedicineClass'];
            $doctorIdentify->IdentifyType = DoctorIdentify::IdentifyType_Physician;
            if ($doctorIdentify->save() === false) {
                $exception->loadFromModel($doctorIdentify);
                throw $exception;
            }

            $user->IDnumber = $data['IDnumber'];
            if ($user->save() === false) {
                $exception->loadFromModel($user);
                throw $exception;
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($doctorIdentify);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 职业资格证书认证详情
     */
    public function readIndentifyAction()
    {
        $userId = $this->request->get('Id', 'int');
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
            'bind'       => [$this->session->get('auth')['OrganizationId'], $userId, OrganizationUser::IsDelete_No],
        ]);
        if (!$organizationUser) {
            throw new LogicException('', Status::BadRequest);
        }
        $doctorIdentify = DoctorIdentify::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1',
            'bind'       => [$this->user->OrganizationId, $userId],
        ]);
        /** @var User $user */
        $user = User::findFirst(sprintf('Id=%d', $userId));
        if (!$user) {
            throw new LogicException('', Status::BadRequest);
        }
        $result = $doctorIdentify ? $doctorIdentify->toArray() : [];
        $result['IDnumber'] = $user->IDnumber;
        $result['Id'] = $user->Id;
        if (!$doctorIdentify) {
            $result['OrganizationId'] = $this->user->OrganizationId;
            $result['Image'] = '';
            $result['Status'] = 0;
        } else {
            if ($doctorIdentify->IdentifyType == DoctorIdentify::IdentifyType_Pharmacist) {
                $result['Image'] = $organizationUser->Image;
            }
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 2.0版本 医生列表展示
     */
    public function supplierDoctorAction()
    {
        $data = $this->request->getPost();
        $auth = $this->session->get('auth');
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;

        $query = $this->modelsManager->createBuilder()
            ->columns([
                'OS.HospitalId', 'OS.SectionId', 'U.Id', 'U.Name', 'O.Name as HospitalName', 'U.TransferAmount', 'U.EvaluateAmount', 'S.Name as SectionName', 'OU.Identified',
                'OU.Image', 'OU.Title', 'OU.Intro', 'OU.Skill', 'OU.Direction', 'OU.Experience', 'OU.Label', 'OU.Score', 'OU.LabelName',
                "if(OU.OrganizationId={$auth['HospitalId']},OU.Sort,0)*1000+((U.TransferAmount*0.7+U.EvaluateAmount*0.3+1)*500/(DATEDIFF(now(),FROM_UNIXTIME(OU.CreateTime))+100)) as Default",
            ])
            ->addFrom(OrganizationUser::class, 'OU')
            ->leftJoin(OrganizationAndSection::class, 'OAS.OrganizationId=OU.OrganizationId and OAS.SectionId=OU.SectionId', 'OAS')
            ->leftJoin(OrganizationSection::class, 'OS.HospitalId=OU.OrganizationId and OS.SectionId=OU.SectionId', 'OS')
            ->leftJoin(Organization::class, 'O.Id=OS.HospitalId', 'O')
            ->leftJoin(Section::class, 'S.Id=OS.SectionId', 'S')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->where(
                "OS.OrganizationId=:OrganizationId: and OU.IsDoctor=:IsDoctor: and OU.Display=:Display: and if(OS.HospitalId={$auth['HospitalId']},1=1,OU.Share=2)",
                ['OrganizationId' => $auth['HospitalId'], 'IsDoctor' => OrganizationUser::IS_DOCTOR_YES, 'Display' => OrganizationUser::DISPLAY_ON]
            );
        //按地区
        if (!empty($data['AreaId']) && isset($data['AreaId'])) {
            $query->andWhere('O.AreaId=:AreaId:', ['AreaId' => $data['AreaId']]);
        }
        //科室
        if (!empty($data['SectionId']) && isset($data['SectionId'])) {
            $query->andWhere('OU.SectionId=:SectionId:', ['SectionId' => $data['SectionId']]);
        }
        //职称
        if (!empty($data['Title']) && isset($data['Title'])) {
            $query->andWhere('OU.Title=:Title:', ['Title' => $data['Title']]);
        }
        //名字
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'user');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('U.Id', $ids);
            } else {
                $query->inWhere('U.Id', [-1]);
            }
        }
        switch ($data['Sort']) {
            case 'Score':
                $query->orderBy("OU.Score desc,OS.HospitalId={$auth['HospitalId']} desc,U.Name");
                break;
            default:
                $query->orderBy("Default desc,OS.HospitalId={$auth['HospitalId']} desc,U.Name");
        }
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
        $doctors = $pages->items->toArray();
        foreach ($doctors as &$doctor) {
            $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
            $doctor['Image'] = $doctor['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
            $doctor['Intro'] = strip_tags($doctor['Intro']);
            $doctor['Skill'] = strip_tags($doctor['Skill']);
            $doctor['Direction'] = strip_tags($doctor['Direction']);
            $doctor['Experience'] = strip_tags($doctor['Experience']);
        }
        $result = [];
        $result['Data'] = $doctors;
        $result['PageInfo'] = ['Count' => $count, 'TotalPage' => $totalPage, 'PageSize' => $pageSize, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 查看用户操作权限
     */
    public function appOpsAction()
    {
        $userId = $this->request->get('Id');
        $appOps = AppOps::map();
        if ($userId) {
            $userAppOps = OrganizationUserAppOps::find([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$this->session->get('auth')['OrganizationId'], $this->request->get('Id')],
            ])->toArray();
            if (count($userAppOps)) {
                $userAppOps_tmp = [];
                foreach ($userAppOps as $ops) {
                    $userAppOps_tmp[$ops['OpsType']][$ops['ParentOpsId']][] = $ops['OpsId'];
                }
                unset($userAppOps);
                foreach ($appOps as &$data) {
                    foreach ($data['Data'] as &$op) {
                        foreach ($op['Ops'] as &$item) {
                            if (isset($userAppOps_tmp[$data['Id']][$op['Id']]) && in_array($item['Id'], $userAppOps_tmp[$data['Id']][$op['Id']])) {
                                $item['Checked'] = AppOps::Checked_On;
                            }
                        }
                    }
                }
            }
        } else {
            foreach ($appOps as &$data) {
                foreach ($data['Data'] as &$op) {
                    foreach ($op['Ops'] as &$item) {
                        $item['Checked'] = AppOps::Checked_On;
                        if ($op['Type'] == AppOps::Type_Radio) break;
                    }
                }
            }
        }

        $this->response->setJsonContent($appOps);
    }

    /**
     * 新建、编辑药师
     */
    public function createPharmacistAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $auth = $this->session->get('auth');
            //验证
            $validator = new Validation();
            $validator->rules('Name', [
                new PresenceOf(['message' => '姓名不能为空']),
            ]);
            $validator->rules('IDnumber', [
                new IDCardNo(['message' => '身份证号码错误']),
            ]);
            $validator->rules('PhysicianNumber', [
                new PresenceOf(['message' => '药师资格证编码不能为空']),
            ]);

            $validator->rules('Image', [
                new PresenceOf(['message' => ' 药师照片不能为空']),
            ]);

            $validator->rules('Sex', [
                new PresenceOf(['message' => '性别不能为空']),
                new Digit(['message' => '性别格式错误']),
            ]);
            $validator->rules('MedicineClass', [
                new PresenceOf(['message' => '药师类别不能为空']),
                new Digit(['message' => '药师类别格式错误']),
            ]);
            $validator->rules('Title', [
                new PresenceOf(['message' => '药师等级不能为空']),
                new Digit(['message' => '药师等级格式错误']),
            ]);
            if ($this->request->isPost()) {
                $validator->rules('Phone', [
                    new PresenceOf(['message' => '手机号不能为空']),
                    new Mobile(['message' => '请输入正确的手机号']),
                ]);
            }
            $ret = $validator->validate($this->request->isPost() ? $this->request->getPost() : $this->request->getPut());
            //验证
            if ($ret->count() > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }

            if ($this->request->getPost()) {
                $data = $this->request->getPost();
                $doctorIdentify = new DoctorIdentify();
                $doctorIdentify->IdentifyType = DoctorIdentify::IdentifyType_Pharmacist;
            } elseif ($this->request->getPut()) {
                $data = $this->request->getPut();

                $doctorIdentify = DoctorIdentify::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$auth['OrganizationId'], $data['UserId']],
                ]);
                /** @var OrganizationUser $organizationUser */
                $organizationUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
                    'bind'       => [$auth['OrganizationId'], $data['UserId'], OrganizationUser::IsDelete_No],
                ]);
                /** @var User $user */
                $user = User::findFirst(sprintf('Id=%d', $data['UserId']));
                if (!$doctorIdentify || !$organizationUser || !$user) {
                    throw $exception;
                }

                $organizationUser->Image = $data['Image'];
                $organizationUser->Title = $data['Title'];
                if (!$organizationUser->save()) {
                    $exception->loadFromModel($organizationUser);
                    throw $exception;
                }

                $user->IDnumber = $data['IDnumber'];
                $user->Name = $data['Name'];
                $user->Sex = $data['Sex'];
                if ($user->save() === false) {
                    $exception->loadFromModel($user);
                    throw $exception;
                }
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }

            if ($this->request->isPost()) {
                $data['Phone'] = trim($data['Phone']);
                $oldUser = User::findFirst([
                    'conditions' => 'Phone=?0',
                    'bind'       => [$data['Phone']],
                ]);
                if (!$oldUser) {
                    $user = new User();
                    $user->Password = $this->security->hash(substr($data['Phone'], -6, 6));
                    $user->Phone = $data['Phone'];
                    $user->Name = $data['Name'];
                    $user->Sex = $data['Sex'];
                    $user->IDnumber = $data['IDnumber'];
                    //设置验证场景
                    $user->setScene(User::SCENE_USER_CREATE);
                    if ($user->save() === false) {
                        $exception->loadFromModel($user);
                        throw $exception;
                    }
                    $data['UserId'] = $user->Id;
                } else {
                    $data['UserId'] = $oldUser->Id;
                    $user = $oldUser;
                }
                $oldOrganizationUser = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$auth['OrganizationId'], $data['UserId']],
                ]);
                if ($oldOrganizationUser) {
                    $exception->add('Phone', '该员工已存在,不要重复注册');
                    throw $exception;
                }
                $oldIdentify = DoctorIdentify::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$auth['OrganizationId'], $data['UserId']],
                ]);
                if ($oldIdentify) {
                    $exception->add('Phone', '该药师已存在,不要重复添加');
                    throw $exception;
                }
                $organizationUser = new OrganizationUser();
                $organizationUser->CreateTime = time();
                $organizationUser->UpdateTime = time();
                $organizationUser->UseStatus = OrganizationUser::USESTATUS_ON;
                $organizationUser->OrganizationId = $auth['OrganizationId'];
                $organizationUser->UserId = $user->Id;
                $organizationUser->Sort = 0;
                $organizationUser->Image = $data['Image'];
                $organizationUser->IsDoctor = OrganizationUser::IS_DOCTOR_Pharmacist;
                $organizationUser->Identified = OrganizationUser::IDENTIFIED_OFF;
                $organizationUser->Title = $data['Title'];

                $organizationUser->setScene(OrganizationUser::SCENE_USER_CREATE);
                $organizationUser->validation();
                $cache = new UserTempCache();
                $cache->Phone = $data['Phone'];
                $cache->MerchantCode = $auth['MerchantCode'];
                $cache->Content = serialize($organizationUser);
                $cache->Code = SmsExtend::CODE_CREATE_DOCTOR;
                if ($cache->save() === false) {
                    $exception->loadFromModel($cache);
                    throw $exception;
                }

                $content = sprintf(SmsExtend::CODE_CREATE_DOCTOR_MESSAGE, $auth['OrganizationName'], $auth['MerchantCode']);
                $sms = new Sms($this->queue);
                $sms->sendMessage((string)$user->Phone, $content, SmsExtend::CODE_CREATE_DOCTOR);
            }

            $doctorIdentify->Status = DoctorIdentify::STATUS_READY;
            $doctorIdentify->OrganizationId = $auth['OrganizationId'];
            $doctorIdentify->Created = time();
            $doctorIdentify->UserId = (int)$data['UserId'];
            $doctorIdentify->Image = '';
            $doctorIdentify->Number = '';
            $doctorIdentify->PhysicianNumber = $data['PhysicianNumber'];
            $doctorIdentify->PhysicianImage = $data['PhysicianImage'];
            $doctorIdentify->MedicineClass = (int)$data['MedicineClass'];
            if ($doctorIdentify->save() === false) {
                $exception->loadFromModel($doctorIdentify);
                throw $exception;
            }
            $this->db->commit();
            $this->response->setStatusCode(Status::Created);
            $this->response->setJsonContent($doctorIdentify);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 药师等级列表
     */
    public function pharmacistTitleAction()
    {
        $this->response->setJsonContent(PharmacistTitle::map());
    }

    /**
     * 药师列表
     */
    public function pharmacistListAction()
    {
        $auth = $this->session->get('auth');
        $data = $this->request->get();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns(['U.Id as UserId', 'U.Name', 'U.Sex', 'U.IDnumber', 'OU.Image', 'OU.Title', 'OU.IsDoctor', 'OU.Identified', 'OU.UseStatus', 'D.Status', 'D.MedicineClass', 'D.PhysicianNumber', 'D.PhysicianImage', 'D.IdentifyType', 'D.Reason', 'D.Created', 'D.AuditTime', 'D.UpdateTime'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->leftJoin(DoctorIdentify::class, 'D.UserId=OU.UserId and D.OrganizationId=OU.OrganizationId', 'D')
            ->where("OU.OrganizationId=:OrganizationId:", ['OrganizationId' => $auth['OrganizationId']])
            ->andWhere(sprintf('OU.IsDoctor=%d', OrganizationUser::IS_DOCTOR_Pharmacist))
            ->andWhere(sprintf('OU.IsDelete=%d', OrganizationUser::IsDelete_No));
        $query->orderBy('OU.CreateTime desc');
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
            $data['TitleName'] = PharmacistTitle::value($data['Title']);
            $data['StatusName'] = DoctorIdentify::STATUS_NAME[$data['Status']];
            $data['MedicineClassName'] = DoctorIdentify::MedicineClassPrescriptionName[$data['MedicineClass']] . '药师';
            if ($data['Status'] != DoctorIdentify::STATUS_REFUSE) $data['Reason'] = '';
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 药师详情
     */
    public function pharmacistReadAction()
    {
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
            'bind'       => [$this->session->get('auth')['OrganizationId'], $this->request->get('UserId'), OrganizationUser::IsDelete_No],
        ]);
        if (!$organizationUser) {
            throw new LogicException('', Status::BadRequest);
        }
        /** @var User $user */
        $user = User::findFirst(sprintf('Id=%d', $organizationUser->UserId));
        /** @var DoctorIdentify $identify */
        $identify = DoctorIdentify::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1',
            'bind'       => [$this->session->get('auth')['OrganizationId'], $organizationUser->UserId],
        ]);
        if (!$user || !$identify) {
            throw new LogicException('', Status::BadRequest);
        }
        $this->response->setJsonContent([
            'UserId'          => $user->Id,
            'Name'            => $user->Name,
            'Phone'           => $user->Phone,
            'Sex'             => $user->Sex,
            'IDnumber'        => $user->IDnumber,
            'Image'           => $organizationUser->Image,
            'PhysicianNumber' => $identify->PhysicianNumber,
            'PhysicianImage'  => $identify->PhysicianImage,
            'MedicineClass'   => $identify->MedicineClass,
            'Title'           => $organizationUser->Title,
        ]);
    }

    /**
     * 删除员工
     */
    public function delAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if ($this->request->isPut()) {
                $id = $this->request->getPut('Id', 'int');
                /** @var OrganizationUser $user */
                $user = OrganizationUser::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
                    'bind'       => [$this->session->get('auth')['OrganizationId'], $id, OrganizationUser::IsDelete_No],
                ]);
                if (!$user) {
                    throw $exception;
                }
                if ($this->user->Id === $user->UserId) {
                    throw new LogicException('不能删除自己', Status::BadRequest);
                }
                if ($user->Label === User::LABEL_ADMIN) {
                    throw new LogicException('不能删除管理员', Status::BadRequest);
                }
                $user->IsDelete = OrganizationUser::IsDelete_Yes;
                if ($user->save() === false) {
                    $exception->loadFromModel($user);
                    throw $exception;
                }
                /** @var DoctorIdentify $doctorIdentify */
                $doctorIdentify = DoctorIdentify::findFirst([
                    'conditions' => 'OrganizationId=?0 and UserId=?1',
                    'bind'       => [$user->OrganizationId, $user->UserId],
                ]);
                if ($doctorIdentify) {
                    $doctorIdentify->delete();
                }
                //清除用户session
                $token = $this->redis->get(RedisName::TokenWeb . $user->UserId);
                $this->redis->delete($token, RedisName::Token . $user->UserId);
            } else {
                throw new LogicException('请求方式错误', Status::MethodNotAllowed);
            }
        } catch (LogicException $e) {
            throw $e;
        } catch (ParamException $e) {
            throw $e;
        }
    }

    public function signatureCodeAction()
    {
        $auth = $this->session->get('auth');
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2 and IsDoctor!=?3',
            'bind'       => [$auth['OrganizationId'], $auth['Id'], OrganizationUser::IsDelete_No, OrganizationUser::IS_DOCTOR_NO],
        ]);
        if (!$organizationUser) {
            throw new LogicException('发送验证码失败，请确认当前用户身份', Status::BadRequest);
        }

        $captcha = (string)random_int(1000, 9999);
        $content = MessageTemplate::load('captcha', MessageTemplate::METHOD_SMS, $captcha);
        $this->sms->send((string)$auth['Phone'], $content, 'signature', $captcha);
    }

    /**
     * 签名认证
     */
    public function signatureAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $validation = new Validation();

            $validation->rules('Password', [
                new PresenceOf(['message' => '请输入密码']),
                new Confirmation(['message' => '两次密码不一致', 'with' => 'RePassword']),
            ]);
            $validation->rules('RePassword', [
                new PresenceOf(['message' => '确认密码不能为空']),

            ]);
            $validation->rules('Captcha', [
                new PresenceOf(['message' => '请输入验证码']),
            ]);

            $ret = $validation->validate($this->request->get());
            if (count($ret) > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }
            if ($this->sms->verify('signature', $this->request->get('Captcha')) === false) {
                $exception->add('Captcha', '验证码错误');
                throw $exception;
            }

            //设置电子签名
            $password = $this->security->hash($this->request->get('Password'));

            $signature = UserSignature::findFirst([
                'conditions' => 'UserId=?0',
                'bind'       => [$this->user->Id],
            ]);
            if (!$signature) {
                $signature = new UserSignature();
                $signature->UserId = $this->user->Id;
            }
            $signature->Password = $password;
            if (!$signature->save()) {
                $exception->loadFromModel($signature);
                throw $exception;
            }

        } catch (LogicException $e) {
            throw $e;
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 验证电子签名
     */
    public function verifySignatureAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$this->request->getPost()) {
                throw new LogicException('请求方式错误', Status::BadRequest);
            }
            $validation = new Validation();

            $validation->rules('Sign', [
                new PresenceOf(['message' => '请输入签名密码']),
            ]);

            $ret = $validation->validate($this->request->getPost());
            if (count($ret) > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }
            /** @var UserSignature $signature */
            $signature = UserSignature::findFirst([
                'conditions' => 'UserId=?0',
                'bind'       => [$this->user->Id],
            ]);
            if (!$signature) {
                throw new LogicException('请先设置电子签名', Status::BadRequest);
            }
            if (!$this->security->checkHash($this->request->getPost('Sign'), $signature->Password)) {
                throw new LogicException('电子签名错误', Status::BadRequest);
            }
            $this->response->setStatusCode(Status::OK);
        } catch (LogicException $e) {
            throw $e;
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 查看医师、药师的信息
     */
    public function getUserIdentifyAction()
    {
        $auth = $this->session->get('auth');
        /** @var OrganizationUser $organizationUser */
        $organizationUser = OrganizationUser::findFirst([
            'conditions' => 'OrganizationId=?0 and UserId=?1 and IsDelete=?2',
            'bind'       => [$auth['OrganizationId'], $auth['Id'], OrganizationUser::IsDelete_No],
        ]);
        if (!$organizationUser) {
            throw new LogicException('员工禁用或不存在', Status::BadRequest);
        }
        $result['IsDoctor'] = $organizationUser->IsDoctor;
        $result['IsDoctorName'] = $organizationUser->IsDoctor == 0 ? "普通员工" : ($organizationUser->IsDoctor == 1 ? "医师" : "药师");
        if ($organizationUser->IsDoctor != OrganizationUser::IS_DOCTOR_NO) {
            /** @var DoctorIdentify $doctorIdentify */
            $doctorIdentify = DoctorIdentify::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$auth['OrganizationId'], $auth['Id']],
            ]);
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $auth['Id']));
            if (!$user) {
                throw new LogicException('用户不存在', Status::BadRequest);
            }
            $result = $doctorIdentify ? array_merge($result, $doctorIdentify->toArray()) : $result;
            $result['IDnumber'] = $user->IDnumber;
            $result['Id'] = $user->Id;

            if (!$doctorIdentify) {
                $result['Status'] = 0;
                $result['StatusName'] = "未申请认证";
            } else {
                $result['Status'] = $doctorIdentify->Status;
                $result['StatusName'] = DoctorIdentify::STATUS_NAME[$doctorIdentify->Status];
            }
        }

        $this->response->setJsonContent($result);
    }

    /**
     * 支持全科协诊的在线医生
     */
    public function generalInquiryAction()
    {
        $query = $this->modelsManager->createBuilder()
            ->columns(['U.Id', 'U.Name', 'OU.Image', 'OU.Label', 'OU.SectionId', 'OU.Share', 'OU.OrganizationId as HospitalId', 'OU.Title', 'OU.Intro', 'OU.Skill', 'OU.LabelName', 'OU.Identified'])
            ->addFrom(OrganizationUser::class, 'OU')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->leftJoin(Section::class, 'S.Id=OU.SectionId', 'S')
            ->where('OU.OrganizationId=:OrganizationId:', ['OrganizationId' => $this->session->get('auth')['HospitalId']])
            ->andWhere('OU.IsDoctor=1')
            ->andWhere('OU.Display=1')
            ->andWhere('OU.UseStatus=1');
        if (!empty($data['SectionId']) && isset($data['SectionId']) && is_numeric($data['SectionId'])) {
            $query->andWhere('OU.SectionId=:SectionId:', ['SectionId' => $data['SectionId']]);
        }
        if (!empty($data['Name']) && isset($data['Name'])) {
            $sphinx = new Sphinx($this->sphinx, 'user');
            $name = $sphinx->match($data['Name'], 'name')->fetchAll();
            $ids = array_column($name ?: [], 'id');
            if (count($ids)) {
                $query->inWhere('U.Id', $ids);
            } else {
                $query->inWhere('U.Id', [-1]);
            }
        }
        $doctors = $query->orderBy("OU.Sort desc")->getQuery()->execute()->toArray();
        if (!empty($doctors)) {
            //问诊评分
            // $scores = InquiryEvaluateTotal::query()
            //     ->columns(['DoctorID', 'EvaluateScoreAvg'])
            //     ->inWhere('DoctorID', array_column($doctors, 'Id'))
            //     ->execute();
            // $scores_tmp = [];
            // if (!empty($scores->toArray())) {
            //     foreach ($scores as $score) {
            //         /**@var InquiryEvaluateTotal $score */
            //         $scores_tmp[$score->DoctorID] = $score->EvaluateScoreAvg;
            //     }
            // }
            // unset($scores);

            foreach ($doctors as &$doctor) {
                $doctor['TitleName'] = DoctorTitle::value($doctor['Title']);
                $doctor['Image'] = $doctor['Image'] ?: OrganizationUser::DEFAULT_IMAGE;
                $doctor['Intro'] = strip_tags($doctor['Intro']);
                $doctor['Skill'] = strip_tags($doctor['Skill']);
                $doctor['Direction'] = strip_tags($doctor['Direction']);
                $doctor['Experience'] = strip_tags($doctor['Experience']);
                $doctor['Identifier'] = WebrtcName::getHospitalDoctor($doctor['HospitalId'], $doctor['Id']);
                // $doctor['Score'] = isset($scores_tmp[$doctor['Id']]) ? $scores_tmp[$doctor['Id']] : 5;
                $doctor['Status'] = $this->redis->get($doctor['Identifier']) ?: "offLine";
            }
        }
        //分页
        $result = [];
        $result['Data'] = $doctors;
        $this->response->setJsonContent($result);
    }
}
