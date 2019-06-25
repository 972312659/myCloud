<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/8/16
 * Time: 下午5:21
 */

namespace App\Controllers;

use App\Enums\SmsExtend;
use App\Enums\SmsTemplateNo;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\login\Login;
use App\Libs\Sms;
use App\Libs\Yimei;
use App\Models\AppResource;
use App\Models\AppVersion;
use App\Models\Article;
use App\Models\Category;
use App\Models\MessageLog;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationSendMessageConfig;
use App\Models\OrganizationUser;
use App\Models\PadVersion;
use App\Models\SortMessageReceipt;
use App\Models\Transfer;
use App\Models\TransferLog;
use App\Models\User;
use App\Models\UserTempCache;
use App\Models\Version;
use Phalcon\Db\RawValue;
use Phalcon\Http\Response;
use Phalcon\Paginator\Adapter\QueryBuilder;
use App\Validators\Mobile;
use Phalcon\Validation;
use Phalcon\Validation\Validator\PresenceOf;

class MessageController extends Controller
{
    /**
     * 消息列表
     */
    public function listAction()
    {
        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new LogicException('未登陆', Status::Unauthorized);
        }
        //todo 去掉特殊处理
        //处理
        if ($auth['HospitalId'] != $auth['OrganizationId'] && $auth['HospitalId'] == 3301) {
            return;
        }
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $messages = MessageLog::query()
            ->where(sprintf('AcceptId=%d', $auth['Id']))
            ->andWhere(sprintf('OrganizationId=%d', $auth['OrganizationId']))
            ->andWhere(sprintf('IsDeleted=%d', MessageLog::IsDeleted_No))
            ->orderBy('ReleaseTime desc');
        if (isset($data['Id']) && is_numeric($data['Id'])) {
            $messages->andWhere(sprintf('Id<%d', $data['Id']));
            $page = 1;
        }
        $paginate = new QueryBuilder([
            'builder' => $messages->createBuilder(),
            'limit'   => $pageSize,
            'page'    => $page,
        ]);
        $this->outputPagedJson($paginate);
    }

    /**
     * 读一条消息并将它标记未已读
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $id = $this->request->get('Id');
            $type = $this->request->get('Type');
            switch ($type) {
                case 'message':
                    $message = MessageLog::findFirst(sprintf('Id=%d', $id));
                    if (!$message) {
                        throw $exception;
                    }
                    $message->Unread = 2;
                    if ($message->save() === false) {
                        $exception->loadFromModel($message);
                        throw $exception;
                    }
                    break;
                case 'notice':
                    $result = Article::findFirst(sprintf('Id=%d', $id));
                    if (!$result) {
                        throw $exception;
                    }
                    $message = $result->toArray();
                    $message['OrganizationName'] = $result->Organization->Name;
                    break;
                default:
                    throw $exception;
            }
            $this->response->setJsonContent($message);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 未读消息总数
     */
    public function unreadAction()
    {
        $total = $this->user ? (int)MessageLog::count(sprintf("Unread=1 and AcceptId=%d and OrganizationId=%d", $this->user->Id, $this->user->OrganizationId)) : 0;
        $this->response->setJsonContent(['UnreadTotal' => $total]);
    }

    /**
     * 关于我们
     * @return Response
     */
    public function aboutPeachAction()
    {
        $response = new Response();
        $result = Article::findFirst([
            'OrganizationId=:OrganizationId: and CategoryId=:CategoryId:',
            'bind' => ['OrganizationId' => Organization::PEACH, 'CategoryId' => Category::PEACH],
        ]);
        $peach = $result->toArray();
        $peach['Logo'] = $result->Organization->Logo;
        $response->setJsonContent($peach);
        return $response;
    }

    /**
     * 消息接收开关
     */
    public function switchAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $user = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$auth['OrganizationId'], $auth['Id']],
            ]);
            if (!$user) {
                $exception->add('message', '请重新登录');
                throw $exception;
            }
            $user->Switch = ($user->Switch === OrganizationUser::SWITCH_ON ? OrganizationUser::SWITCH_OFF : OrganizationUser::SWITCH_ON);
            if ($user->save() === false) {
                $exception->add('message', '设置失败');
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 公告列表
     * @return Response
     */
    public function noticeListAction()
    {
        $response = new Response();
        $auth = $this->session->get('auth');
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns('A.Id,A.Title,A.Author,A.Content,A.CategoryId,A.OrganizationId,A.ReleaseTime,O.Name as OrganizationName')
            ->addFrom(Article::class, 'A')
            ->inWhere('A.OrganizationId', [$auth['HospitalId'], Organization::PEACH])
            ->andWhere('A.CategoryId=' . Category::NOTICE)
            ->andWhere('A.ReleaseTime<=' . time())
            ->andWhere('A.AcceptOrganization=' . Article::ACCEPT_BOTH)
            ->join(Organization::class, 'O.Id=A.OrganizationId', 'O')
            ->orderBy('A.ReleaseTime desc');
        $query->orWhere(sprintf('A.AcceptOrganization=%d', $auth['HospitalId'] === $auth['OrganizationId'] ? Article::ACCEPT_B : Article::ACCEPT_b));
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
            $data['OrganizationName'] = $data['OrganizationId'] === 0 ? '平台' : $data['OrganizationName'];
            $data['ReleaseTime'] = date('Y-m-d H:i:s', $data['ReleaseTime']);
        }
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $response->setJsonContent($result);
        return $response;
    }

    /**
     * 批量修改未读消息
     */
    public function readAllAction()
    {
        $messages = MessageLog::find([
            'conditions' => 'AcceptId=?0 and Unread=?1',
            'bind'       => [$this->user ? $this->user->Id : 0, 1],
        ]);
        if ($messages) {
            foreach ($messages as $message) {
                $message->Unread = 2;
                $message->save();
            }
        }
    }

    /**
     * 总网点 昨日新增网点 收到自有转诊 收到共享转诊 到院患者 结算患者 今日收到转诊（自有转诊、共享转诊） 今日到院患者 今日出院患者
     */
    public function statisticsAction()
    {
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('未登录', Status::Unauthorized);
            }
            $query = OrganizationRelationship::query()
                ->columns('count(MainId) as Count')
                ->join(Organization::class, 'O.Id=MinorId', 'O', 'right')
                ->where('MainId=' . $auth['OrganizationId'])
                ->andWhere('O.Fake=0')
                ->andWhere('O.IsMain=2');
            //总网点
            $minors_total = (int)($query->execute()->toArray()[0]['Count']);

            //昨日新增网点
            $yesterday_start = strtotime(date('Y-m-d', strtotime('-1 days')));
            $yesterday_end = $yesterday_start + 86400;
            $minors_yesterday = (int)($query->andWhere('O.CreateTime>=' . $yesterday_start)->andWhere('O.CreateTime<' . $yesterday_end)->execute()->toArray()[0]['Count']);
            //收到自有转诊数
            $self_transfer = (int)Transfer::count("AcceptOrganizationId={$auth['OrganizationId']} and Genre=1 and Sign=0");
            //收到共享转诊
            $share_transfer = (int)Transfer::count("AcceptOrganizationId={$auth['OrganizationId']} and Genre=2 and Sign=0");
            //到院患者
            $patient_cure = (int)Transfer::count("AcceptOrganizationId={$auth['OrganizationId']} and Status>=5 and Sign=0");
            //结算患者
            $patient_finish = (int)Transfer::count("AcceptOrganizationId={$auth['OrganizationId']} and Status=8 and Sign=0");
            //今日收到转诊
            $today_transfer = (int)Transfer::count("AcceptOrganizationId={$auth['OrganizationId']} and StartTime>={$yesterday_end} and Sign=0");
            //今日自有转诊
            $today_transfer_self = (int)Transfer::count("AcceptOrganizationId={$auth['OrganizationId']} and StartTime>={$yesterday_end} and Genre=1 and Sign=0");
            //今日共享转诊
            $today_transfer_share = $today_transfer - $today_transfer_self;
            //今日到院患者
            $arrived = (int)Transfer::count("AcceptOrganizationId={$auth['OrganizationId']} and ClinicTime>={$yesterday_end} and Sign=0");
            //今日出院患者
            $leaved = (int)($this->modelsManager->createBuilder()
                ->columns('count(*) as count')
                ->addFrom(Transfer::class, 'T')
                ->leftJoin(TransferLog::class, 'L.TransferId=T.Id', 'L')
                ->where("T.AcceptOrganizationId={$auth['OrganizationId']}")
                ->andWhere("T.Sign=0")
                ->andWhere("L.Status=6")
                ->getQuery()
                ->execute()->toArray()[0]['Count']);
            $this->response->setJsonContent([
                'MinorTotal'         => $minors_total,
                'MinorYesterday'     => $minors_yesterday,
                'SelfTransfer'       => $self_transfer,
                'ShareTransfer'      => $share_transfer,
                'PatientCure'        => $patient_cure,
                'PatientFinish'      => $patient_finish,
                'TodayTransfer'      => $today_transfer,
                'TodayTransferSelf'  => $today_transfer_self,
                'TodayTransferShare' => $today_transfer_share,
                'Arrived'            => $arrived,
                'Leaved'             => $leaved,
            ]);
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 轮询消息
     */
    public function pollAction()
    {
        $auth = $this->session->get('auth');
        if (!$auth) {
            throw new LogicException('未登陆', Status::Unauthorized);
        }
        $messages = MessageLog::query()
            ->columns(['Type', 'Content'])
            ->where('OrganizationId=:OrganizationId:')
            ->andWhere('AcceptId=:AcceptId:')
            ->andWhere('Unread=:Unread:')
            ->andWhere('ReleaseTime>=:ReleaseTime:')
            ->bind(['OrganizationId' => $auth['OrganizationId'], 'AcceptId' => $auth['Id'], 'Unread' => MessageLog::UNREAD_NOT, 'ReleaseTime' => time() - 60])
            ->orderBy('ReleaseTime desc')
            ->execute();
        $this->response->setJsonContent($messages);
    }

    /**
     * app版本控制
     * @Anonymous
     */
    public function appVersionAction()
    {
        $version = $this->request->get('app_ver');
        $res = $this->request->get('res_ver');
        $platform = (int)$this->request->get('platform');
        // 如果$force为null表示已是当前已发布的最新版(也有可能更新的在审核)
        // 审核中的版本不应该更新资源
        $result = AppVersion::findFirst([
            'columns'    => new RawValue('max(Version) as version, max(Forced) as force'),
            'conditions' => 'Platform=?0 and Version>=?1',
            'bind'       => [$platform, $version],
        ]);

        $resource = AppResource::findFirst([
            'order'      => 'Created DESC',
            'conditions' => 'Platform=?0',
            'bind'       => [$platform],
            'limit'      => 1,
        ]);

        switch ($platform) {
            case 1:
                $applink = AppResource::IOS_URL;
                break;
            case 2:
                $applink = AppResource::ANDROID_URL;
                break;
            default:
                $applink = null;
        }
        return $this->response->setJsonContent([
            'force'    => $result->version !== $version && $result->force,
            'app_link' => $applink,
            'res_ver'  => ($result->version !== null) ? $resource->HashKey : null,
            'res_link' => ($result->version !== null) ? $resource->ResourceUrl : null,
        ]);
    }

    /**
     * pad版本控制
     * @Anonymous
     */
    public function padVersionAction()
    {
        $app_ver = $this->request->get('app_ver');
        $platform = (int)$this->request->get('platform');

        /** @var PadVersion $padVersion */
        $padVersion = PadVersion::findFirst([
            'order' => 'VersionCode desc',
        ]);
        $result = ['IsUpdate' => false, 'ApkName' => ''];
        if ($padVersion) {
            if ($padVersion->VersionCode > $app_ver) {
                $result = [
                    'IsUpdate'   => true,
                    'UpdatePath' => $padVersion->ApkUrl,
                    'ApkName'    => $padVersion->ApkName,
                ];
            }
        }
        $this->response->setJsonContent($result);
    }

    /**
     * 短信上行接口
     * @Anonymous
     */
    public function receiptAction()
    {
        $appId = $this->request->getHeader('appId');
        if ($appId !== Yimei::APP_ID) {
            $this->logger->info('APP_ID错误');
            $this->response->setContent('APP_ID错误');
            return;
        }
        $data = $this->request->get('mos');
        $this->logger->info($data);
        $items = json_decode($data, true);
        if (count($items)) {
            foreach ($items as $item) {
                //将回执信息记录到表里面
                /** @var SortMessageReceipt $sortMessageReceipt */
                $sortMessageReceipt = new SortMessageReceipt();
                $sortMessageReceipt->Created = time();
                $sortMessageReceipt->Phone = $item['mobile'];
                $sortMessageReceipt->ReceiptMessage = $item['content'];
                $sortMessageReceipt->save();

                /** @var UserTempCache $userTempCache */
                $userTempCache = UserTempCache::find([
                    'conditions' => 'Phone=?0 and MerchantCode=?1 and Code=?2',
                    'bind'       => [$item['mobile'], $item['content'], $item['extendedCode']],
                ]);
                if (count($userTempCache->toArray())) {
                    $content = '';
                    foreach ($userTempCache as $value) {
                        unserialize($value->Content)->save();
                        $content = $value->Message;
                    }
                    $userTempCache->delete();
                    //发送成功消息
                    if (in_array($item['extendedCode'], [SmsExtend::CODE_CREATE_ADMIN_HOSPITAL, SmsExtend::CODE_CREATE_ADMIN_SUPPLIER, SmsExtend::CODE_CREATE_ADMIN_SLAVE])) {
                        $sms = new Sms($this->queue);
                        $sms->sendMessage($item['mobile'], $content);
                    }
                }
            }
        }
        $this->response->setContent('success');
    }

    /**
     * 网点激活账号
     * @Anonymous
     */
    public function activationUserAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $this->db->begin();
            $validator = new Validation();
            $validator->rules('Phone', [
                new PresenceOf(['message' => '手机号不能为空']),
                new Mobile(['message' => '请输入正确的手机号']),
            ]);
            $validator->rules('Code', [
                new PresenceOf(['message' => '激活码不能为空']),
            ]);
            $ret = $validator->validate($this->request->get());
            if ($ret->count() > 0) {
                $exception->loadFromMessage($ret);
                throw $exception;
            }
            $phone = $this->request->get('Phone');
            /** @var UserTempCache $userTempCache */
            $userTempCache = UserTempCache::findFirst([
                'conditions' => 'Phone=?0 and MerchantCode=?1 and Code=?2',
                'bind'       => [$phone, $this->request->get('Code'), SmsExtend::CODE_CREATE_ADMIN_SLAVE],
            ]);
            if (!$userTempCache) {
                throw new LogicException('手机号或激活码错误，或账号已激活', Status::BadRequest);
            }
            /**
             * @var OrganizationUser $organizationUser
             */
            $organizationUser = unserialize($userTempCache->Content);
            $organizationUser->save();
            $message = $userTempCache->Message;
            $code = $userTempCache->Code;
            $userTempCache->delete();
            $this->db->commit();
            //发送成功消息
            switch ($code) {
                //发送给网点
                case 3:
                    $templateParam = SmsTemplateNo::getTemplateParam($message, $code);
                    Sms::useJavaSendMessage(
                        (string)$phone,
                        SmsTemplateNo::CREATE_SLAVE_SUCCESS,
                        $templateParam
                    );
                    break;
                default:
                    $sms = new Sms($this->queue);
                    $sms->sendMessage($phone, $message);
            }

            //自动登录
            /**
             * @var Organization $organization
             */
            $organization = Organization::findFirst(sprintf('Id=%d', $organizationUser->OrganizationId));
            /**
             * @var User $user
             */
            $user = User::findFirst(sprintf('Id=%d', $organizationUser->UserId));
            $organizationRelation = OrganizationRelationship::findFirst(['conditions' => 'MinorId=?0', 'bind' => [$organization->Id]]);
            /**
             * @var Organization $hospital
             */
            $hospital = Organization::findFirst(sprintf('Id=%d', $organizationRelation->MainId));
            $login = new Login();
            $login->slave($hospital, $organization, $user, $organizationUser);
        } catch (ParamException $e) {
            $this->db->rollback();
            throw $e;
        } catch (LogicException $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * 网点设置是否给患者发送消息开关
     */
    public function agreeSendMessageToPatientAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            if (!$auth) {
                throw new LogicException('请登录', Status::Unauthorized);
            }
            $sendMessage = OrganizationSendMessageConfig::findFirst([
                'conditions' => 'OrganizationId=?0 and Type=?1',
                'bind'       => [$auth['OrganizationId'], OrganizationSendMessageConfig::TYPE_SEND_TO_PATIENT],
            ]);
            if ($sendMessage) {
                $sendMessage->AgreeSendMessage = ($sendMessage->AgreeSendMessage == OrganizationSendMessageConfig::AGREE_SEND_YES ? OrganizationSendMessageConfig::AGREE_SEND_NO : OrganizationSendMessageConfig::AGREE_SEND_YES);
            } else {
                $sendMessage = new OrganizationSendMessageConfig();
                $sendMessage->OrganizationId = $auth['OrganizationId'];
                $sendMessage->AgreeSendMessage = OrganizationSendMessageConfig::AGREE_SEND_NO;
                $sendMessage->Type = OrganizationSendMessageConfig::TYPE_SEND_TO_PATIENT;
            }
            if ($sendMessage->save() === false) {
                $exception->add('message', '设置失败');
                throw $exception;
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }
    }

    /**
     * 删除消息
     */
    public function delMessageLogAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        /** @var MessageLog $messageLog */
        $messageLog = MessageLog::findFirst([
            'conditions' => 'Id=?0 and AcceptId=?1',
            'bind'       => [$this->request->getPut('Id'), $this->user->Id],
        ]);
        $exception = new ParamException(Status::BadRequest);
        try {
            if (!$messageLog) {
                throw $exception;
            }
            $messageLog->IsDeleted = MessageLog::IsDeleted_Yes;
            if (!$messageLog->save()) {
                $exception->loadFromModel($messageLog);
                throw $exception;
            }
            $this->response->setJsonContent(['Id' => $messageLog->Id]);
        } catch (ParamException $e) {
            throw $e;
        }
    }
}