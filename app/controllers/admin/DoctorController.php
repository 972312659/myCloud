<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/9/25
 * Time: 下午1:33
 */

namespace App\Admin\Controllers;

use App\Enums\DoctorTitle;
use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Libs\Sphinx;
use App\Models\Illness;
use App\Models\IllnessForDoctorIdentification;
use App\Models\IllnessForDoctorIdentificationLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Section;
use App\Models\User;
use Phalcon\Paginator\Adapter\QueryBuilder;

class DoctorController extends Controller
{

    /**
     * 医生列表
     */
    public function doctorListAction()
    {
        $data = $this->request->getPost();
        $pageSize = (isset($data['PageSize']) && is_numeric($data['PageSize']) && $data['PageSize'] > 0) ? $data['PageSize'] : 10;
        $page = (isset($data['Page']) && is_numeric($data['Page']) && $data['Page'] > 0) ? $data['Page'] : 1;
        $query = $this->modelsManager->createBuilder()
            ->columns('U.Id,U.Name,OU.Label,O.Id as OrganizationId,O.Name as HospitalName,S.Name as SectionName,O.MerchantCode,if(I.IllnessId is null,0,I.IllnessId) as Hou')
            ->addFrom(OrganizationUser::class, 'OU')
            ->leftJoin(User::class, 'U.Id=OU.UserId', 'U')
            ->leftJoin(Organization::class, 'O.Id=OU.OrganizationId', 'O')
            ->leftJoin(Section::class, 'S.Id=OU.SectionId', 'S')
            ->leftJoin(IllnessForDoctorIdentification::class, 'I.UserId=U.Id and IllnessId=' . Illness::Rheumatism, 'I')
            ->where('OU.IsDoctor=1')
            ->andWhere('O.IsMain=1')
            ->orderBy('OU.OrganizationId desc,OU.Label desc');
        //医院名字
        if (!empty($data['HospitalName']) && isset($data['HospitalName'])) {
            $query->andWhere('O.Name=:HospitalName:', ['HospitalName' => $data['HospitalName']]);
        }
        //医生名字
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
        //科室
        if (!empty($data['SectionId']) && isset($data['SectionId']) && is_numeric($data['SectionId'])) {
            $query->andWhere('U.SectionId=:SectionId:', ['SectionId' => $data['SectionId']]);
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
        $result = [];
        $result['Data'] = $datas;
        $result['PageInfo'] = ['Count' => $count, 'PageSize' => $pageSize, 'TotalPage' => $totalPage, 'Page' => $page];
        $this->response->setJsonContent($result);
    }

    /**
     * 查看详情
     */
    public function readAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $organizationId = $this->request->get('OrganizationId', 'int');
            $id = $this->request->get('Id', 'int');
            /** @var OrganizationUser $user */
            $user = OrganizationUser::findFirst([
                'conditions' => 'OrganizationId=?0 and UserId=?1',
                'bind'       => [$organizationId, $id],
            ]);
            if (!$user) {
                throw $exception;
            }
            $result = $user->toArray();
            $result['Id'] = $user->UserId;
            $result['Intro'] = strip_tags($user->Intro);
            $result['Skill'] = strip_tags($user->Skill);
            $result['Direction'] = strip_tags($user->Direction);
            $result['Experience'] = strip_tags($user->Experience);
            $result['Name'] = $user->User->Name;
            $result['Phone'] = $user->User->Phone;
            $result['SectionName'] = $user->Section->Name;
            $result['TitleName'] = DoctorTitle::value($user->Title);
            //侯丽萍风湿诊疗认证
            $result['Hou'] = IllnessForDoctorIdentification::findFirst(['conditions' => 'IllnessId=?0 and UserId=?1', 'bind' => [Illness::Rheumatism, $user->UserId]]) ? Illness::Rheumatism : 0;
            //认证日志
            $result['Log'] = [];
            $illnessForDoctorIdentificationLog = IllnessForDoctorIdentificationLog::find([
                'conditions' => 'UserId=?0 and IllnessId=?1',
                'bind'       => [$user->UserId, Illness::Rheumatism],
                'order'      => 'LogTime desc',
            ]);
            if (count($illnessForDoctorIdentificationLog->toArray())) {
                foreach ($illnessForDoctorIdentificationLog as $item) {
                    /** @var  IllnessForDoctorIdentificationLog $item */
                    $result['Log'][] = date('Y-m-d H:i:s', $item->LogTime) . ' ' . $item->StaffName . IllnessForDoctorIdentificationLog::STATUS_NAME[$item->Status];
                }
            }
            $this->response->setJsonContent($result);
        } catch (ParamException $e) {
            throw $e;
        }
    }

    /**
     * 医生身份认证
     */
    public function identificationAction()
    {
        if (!$this->request->isPut()) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $exception = new ParamException(Status::BadRequest);
        try {
            $illnessId = $this->request->getPut('IllnessId');
            $userId = $this->request->getPut('Id');
            $old = IllnessForDoctorIdentification::findFirst([
                'conditions' => 'IllnessId=?0 and UserId=?1',
                'bind'       => [$illnessId, $userId],
            ]);
            if ($old) {
                $old->delete();
            } else {
                /** @var IllnessForDoctorIdentification $illnessForDoctorIdentification */
                $illnessForDoctorIdentification = new IllnessForDoctorIdentification();
                $illnessForDoctorIdentification->IllnessId = $illnessId;
                $illnessForDoctorIdentification->UserId = $userId;
                if (!$illnessForDoctorIdentification->save()) {
                    $exception->loadFromModel($illnessForDoctorIdentification);
                    throw $exception;
                }
            }
        } catch (ParamException $e) {
            throw $e;
        }
    }
}
