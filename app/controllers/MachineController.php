<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/5/8
 * Time: 上午9:52
 * For: 一体机管理
 */

namespace App\Controllers;


use App\Enums\Status;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use GuzzleHttp\Client;

class MachineController extends Controller
{
    const AUTH_PATH = 'auth/checkLogin';    //一体机注册接口
    public $registerUrl;        //访问一体机管理网址
    public $linkUrl;      //修改一体机注册相关接口
    public $updateUrl;      //修改一体机注册相关接口
    public $authUrl;

    public function onConstruct()
    {
        $this->registerUrl = $this->config->get('machine')->baseUrl . 'external/register';
        $this->linkUrl = $this->config->get('machine')->linkUrl;
        $this->updateUrl = $this->config->get('machine')->baseUrl . 'external/update';
        $this->authUrl = $this->config->get('machine')->baseUrl . 'auth/checkLogin';
    }

    /**
     * 生成一体机页面链接 并定向跳转
     */
    public function linkAction()
    {
        $organization = Organization::findFirst(sprintf('Id=%d', $this->user->OrganizationId));
        if (!$organization->MachineOrgId) {
            $this->createAction();
            $organization = Organization::findFirst(sprintf('Id=%d', $this->user->OrganizationId));
        }
        //处理关联
        $slaves = $this->modelsManager->createBuilder()
            ->addFrom(Organization::class, 'O')
            ->join(OrganizationRelationship::class, 'R.MinorId=O.Id', 'R')
            ->where('R.MainId=:MainId:', ['MainId' => $organization->Id])
            ->andWhere('O.MachineOrgId!=:MachineOrgId:', ['MachineOrgId' => 0])
            ->getQuery()->execute()->toArray();
        if (count($slaves)) {
            $ids = array_column($slaves, 'MachineOrgId');
            $post = ['org_ids' => $ids, 'org_pid' => $organization->MachineOrgId];
            $client = new Client();
            $client->put($this->updateUrl, ['form_params' => $post]);
        }
        $data = [
            'doctor_id'   => $this->user->Id,
            'doctor_name' => $this->user->Name,
            'org_id'      => $organization->MachineOrgId,
            'org_name'    => $organization->Name,
        ];
        ksort($data, SORT_REGULAR);
        $str = '';
        foreach ($data as $k => $v) {
            $str .= "$k=$v&";
        }
        $sign = sha1($str . 'org_key=' . $organization->MachineOrgKey);
        $link = $this->linkUrl . '?' . $str . 'sign=' . $sign;
        $this->response->redirect($link, true);
    }

    /**
     * 创建一个一体机机构
     */
    public function createAction()
    {
        $exception = new ParamException(Status::BadRequest);
        try {
            $auth = $this->session->get('auth');
            $hospital = Organization::findFirst(sprintf('Id=%d', $auth['HospitalId']));
            $data = [
                'org_name' => $auth['OrganizationName'],
            ];
            if ($auth['HospitalId'] != $auth['OrganizationId']) {
                if ($hospital->MachineOrgId) {
                    $data['org_pid'] = $hospital->MachineOrgId;
                }
            }
            $organization = Organization::findFirst(sprintf('Id=%d', $auth['OrganizationId']));
            if (!$organization->MachineOrgId) {
                $client = new Client();
                $resp = $client->post($this->registerUrl, ['form_params' => $data]);
                $result = json_decode($resp->getBody(), true);
                if ($result['success']) {
                    $organization->MachineOrgId = $result['org_id'];
                    $organization->MachineOrgKey = $result['org_key'];
                    if ($organization->save() === false) {
                        $exception->loadFromModel($organization);
                        throw $exception;
                    }
                    $auth['MachineOrgId'] = $organization->MachineOrgId;
                    $this->session->set('auth', $auth);
                    $this->response->setStatusCode(Status::Created);
                } else {
                    $this->response->setStatusCode(Status::BadRequest);
                }
                $this->response->setJsonContent($result);
            }
        } catch (ParamException $e) {
            throw $e;
        } catch (LogicException $e) {
            throw $e;
        }

    }

    /**
     * 获取一体机token
     */
    public function getTokenAction()
    {
        $this->inject();

        $org = Organization::findFirst($this->user->OrganizationId);
        if (!$org) {
            throw new LogicException('请求方式错误', Status::MethodNotAllowed);
        }
        $data = [
            'doctor_id'   => $this->user->Id,
            'doctor_name' => $this->user->Name,
            'org_id'      => $org->MachineOrgId,
            'org_name'    => $org->Name,
        ];
        ksort($data, SORT_REGULAR);
        $str = '';
        foreach ($data as $k => $v) {
            $str .= "$k=$v&";
        }
        $data['sign'] = sha1($str . 'org_key=' . $org->MachineOrgKey);
        $client = new Client();
        $response = $client->post($this->config->machine->baseUrl . self::AUTH_PATH, ['form_params' => $data]);
        $result = json_decode($response->getBody()->getContents(), true);
        $result['Host'] = $this->config->machine->baseUrl;
        $this->response->setJsonContent($result);
    }
    
}