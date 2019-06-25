<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/12/12
 * Time: 下午2:06
 */

namespace App\Libs;


use App\Controllers\Controller;

class CompanyWechat extends Controller
{
    private $corpid = '';//李淼
    //通讯录
    private $scene_address_book = 'address_book';
    private $secret_address_book = '';

    //自建应用
    private $scene_self_create = 'self_create';
    private $secret_self_create = '';
    private $agentid = '';

    public function onConstruct()
    {
        $this->corpid = $this->config->companywechat->corpid;
        $this->secret_address_book = $this->config->companywechat->secretAddressBook;
        $this->agentid = $this->config->companywechat->agentid;
        $this->secret_self_create = $this->config->companywechat->secretSelfCreate;
    }

    private function curl($http = 'GET', $url, $data = [])
    {
        //初始化
        $ch = curl_init();
        //设置选项，包括URL
        curl_setopt($ch, CURLOPT_URL, $url);
        //执行并获取HTML文档内容
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $output = curl_exec($ch);
        //释放curl句柄
        curl_close($ch);
        //打印获得的数据
        return $output;
    }

    /**
     * 获取access_token
     * @return array
     */
    public function getToken($secret, $scene)
    {
        $result = $this->curl('GET', 'https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=' . $this->corpid . '&corpsecret=' . $secret);
        $access_token = json_decode($result, true)['access_token'];
        $this->redis->set('companyWechat_' . $scene, $access_token, 7200);
        return $access_token;
    }

    /**
     * 缓存失效，获取access_token
     */
    public function accessToken($secret, $scene)
    {
        $access_token = $this->redis->get('companyWechat' . $scene);
        if (!$access_token) {
            $access_token = $this->getToken($secret, $scene);
        }
        return $access_token;
    }

    /**
     * 处理返回结果
     */
    public function result($result)
    {
        if ($result['errcode'] === 0) {
            return true;
        } else {
            return $result['errmsg'];
        }
    }

    /**
     * @param string $content 消息内容
     * @param array $toparty  部门
     * @param array $touser   人员（姓名）
     * @param array $totag    标签
     * @param int $safe       是否保密消息
     * @param string $msgtype 消息类型，此时固定为：text
     * @param int $agentid    企业应用的id，整型
     * @return array|mixed|object|\stdClass
     */
    public function send(string $content, $toparty = [], $touser = [], $totag = [], $safe = 0, $msgtype = 'text')
    {
        $access_token = $this->accessToken($this->secret_self_create, $this->scene_self_create);
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token=' . $access_token;
        $toparty = implode('|', $toparty);
        $touser = implode('|', $touser);
        $totag = implode('|', $totag);
        $data = [
            'toparty' => $toparty,
            'touser'  => $touser,
            'totag'   => $totag,
            'msgtype' => $msgtype,
            'agentid' => $this->agentid,
            'safe'    => $safe,
            'text'    => ['content' => $content],

        ];
        $result = json_decode($this->curl('POST', $url, json_encode($data)), true);
        return $this->result($result);
    }

    /**
     * 创建修改部门
     * @param string $name
     * @param int $parentid
     * @param int $id
     * @return array|mixed|object|\stdClass
     */
    public function createParty(string $name, int $parentid = 1, int $id = null)
    {
        $access_token = $this->accessToken($this->secret_address_book, $this->scene_address_book);
        if ($id) {
            $url = 'https://qyapi.weixin.qq.com/cgi-bin/department/update?access_token=' . $access_token;
        } else {
            $url = 'https://qyapi.weixin.qq.com/cgi-bin/department/create?access_token=' . $access_token;
        }
        $data = [
            'id'       => $id,
            'name'     => $name,
            'parentid' => $parentid,
            'order'    => 1,
        ];
        if (!$id) {
            unset($data['id']);
        }
        $result = json_decode($this->curl('POST', $url, json_encode($data)), true);
        if ($result['errcode'] === 0) {
            if ($id) {
                return true;
            } else {
                return $result['id'];
            }
        } else {
            return $result['errmsg'];
        }
    }

    /**
     * 删除部门
     * @param $id
     * @return array|mixed|object|\stdClass
     */
    public function delParty($id)
    {
        $access_token = $this->accessToken($this->secret_address_book, $this->scene_address_book);
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/department/delete?access_token=' . $access_token . '&id=' . $id . '';
        $result = json_decode($this->curl('GET', $url), true);
        return $this->result($result);
    }

    /**
     * 获取部门列表
     * @param $id
     * @return array|mixed|object|\stdClass
     */
    public function partyList($id = '')
    {
        $access_token = $this->accessToken($this->secret_address_book, $this->scene_address_book);
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/department/list?access_token=' . $access_token . '&id=' . $id . '';
        $result = json_decode($this->curl('GET', $url), true);
        if ($result['errcode'] === 0) {
            return $result['department'];
        } else {
            return $result['errmsg'];
        }
    }

    /**
     * 创建成员
     * @param string $userid
     * @param string $name
     * @param string $mobile
     * @param array $department
     * @param bool $create
     * @return bool
     */
    public function createUser(string $userid, string $name, string $mobile, array $department, $create = true)
    {
        $access_token = $this->accessToken($this->secret_address_book, $this->scene_address_book);
        if ($create) {
            $url = 'https://qyapi.weixin.qq.com/cgi-bin/user/create?access_token=' . $access_token;
        } else {
            $url = 'https://qyapi.weixin.qq.com/cgi-bin/user/update?access_token=' . $access_token;
        }
        $data = [
            'userid'     => $userid,
            'name'       => $name,
            'mobile'     => $mobile,
            'department' => $department,
        ];
        $result = json_decode($this->curl('POST', $url, json_encode($data)), true);
        return $this->result($result);
    }

    /**
     * 删除成员
     * @param $userid
     * @return bool
     */
    public function delUser($userid)
    {
        $access_token = $this->accessToken($this->secret_address_book, $this->scene_address_book);
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/user/delete?access_token=' . $access_token . '&userid=' . $userid . '';
        $result = json_decode($this->curl('GET', $url), true);
        return $this->result($result);
    }

    /**
     * 获取部门所有用户
     * @param int $department_id 部门id
     * @param int $fetchChild    1=>递归 0=>当前部门
     * @return mixed
     */
    public function userList(int $department_id, $fetchChild = 1)
    {
        $access_token = $this->accessToken($this->secret_address_book, $this->scene_address_book);
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/user/simplelist?access_token=' . $access_token . '&department_id=' . $department_id . '&fetch_child=' . $fetchChild;
        $result = json_decode($this->curl('GET', $url), true);
        if ($result['errcode'] === 0) {
            return $result['userlist'];
        } else {
            return $result['errmsg'];
        }
    }

    /**
     * 读取成员详情
     * @param $userid
     * @return array|mixed|object|\stdClass
     */
    public function userRead($userid)
    {
        $access_token = $this->accessToken($this->secret_address_book, $this->scene_address_book);
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/user/get?access_token=' . $access_token . '&userid=' . $userid . '';
        $result = json_decode($this->curl('GET', $url), true);
        if ($result['errcode'] === 0) {
            return $result;
        } else {
            return false;
        }
    }


}