<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2017/7/31
 * Time: 下午7:32
 */

namespace App\Controllers;

use App\Enums\WebrtcName;
use App\Exceptions\LogicException;
use App\Exceptions\ParamException;
use App\Enums\Status;
use App\Libs\tencent\Im;
use App\Models\Organization;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Confirmation;
use Phalcon\Validation\Validator\PresenceOf;
use Tencent\TLSSigAPI;

class TrtcController extends Controller
{
    /**
     * SDK获取UserSig
     */
    public function getUserSigAction()
    {
        $auth = $this->session->get('auth');
        if ($auth['OrganizationId'] == $auth['HospitalId']) {
            $identifier = WebrtcName::getHospitalDoctor($auth['OrganizationId'], $auth['Id']);
        } else {
            if (strlen($auth['Token']) == 40) {
                $identifier = WebrtcName::App . $auth['Id'];
            } else {
                $identifier = WebrtcName::Pad . $auth['Id'];
            }
        }
        $im = new Im();
        $sig = $im->createUsersig($identifier);
        $this->response->setJsonContent([
            'UserSig'    => $sig,
            'Identifier' => $identifier,
        ]);
    }


    /**
     * 客户端 获取 sign md5(API鉴权key+times)
     * @Anonymous
     */

    public function getWebrtcSignAction()
    {
        //time 默认180天过期时间
        $key = "fd9d298a3d5aa2e210264a840702192b";
        $time = time() + 15552000;
        $sign = md5($key . $time);
        $this->response->setJsonContent([
            'Sign' => $sign,
        ]);

    }


    /**
     * 自动录制视频回调
     * @Anonymous
     */
    public function videoRecordBackAction()
    {
        $data = file_get_contents("php://input");
        file_put_contents('./a.txt', $data, FILE_APPEND);
    }

    /**
     * 在线状态变更回调
     * @Anonymous
     */
    public function OnlineStatusBackAction()
    {
        $data = file_get_contents("php://input");
        if ($_GET) {
            $arr = serialize($_GET);
            file_put_contents('./c.txt', $arr, FILE_APPEND);
        }
        file_put_contents('./b.txt', $data, FILE_APPEND);
    }

    /**
     * 在登录状态下获取腾讯云通信im的登录信息（用户名+密码）
     */
    public function tencentImAction()
    {
        $auth = $this->session->get('auth');

        switch ($auth['IsMain']) {
            case Organization::ISMAIN_SLAVE:
                //网点
                $platform = $this->request->getHeader('PLATFORM');
                if (!$platform || empty($platform)) {
                    //pc端
                    $identifier = WebrtcName::getSlaveWeb($auth['OrganizationId'], $auth['Id']);
                } else {
                    switch ($platform) {
                        case 'PAD':
                            $identifier = WebrtcName::getPad($auth['OrganizationId'], $auth['Id']);
                            break;
                        default:
                            //app端
                            $identifier = WebrtcName::getApp($auth['OrganizationId'], $auth['Id']);
                    }
                }
                break;
            default:
                //医院
                $identifier = WebrtcName::getHospitalDoctor($auth['OrganizationId'], $auth['Id']);
        }

        $im = new Im();
        $sig = $im->createUsersig($identifier);
        if (empty($sig)) {
            throw new LogicException("注册云通信失败", Status::BadRequest);
        }
        $result['Identifier'] = $identifier;
        $result['UserSign'] = $sig;
        $result['Id'] = $im->getAppId();

        $this->response->setJsonContent($result);

    }

    /**
     * TODO
     * 获取腾讯云音视频
     */
    public function tencentVideoAction()
    {

    }

    /**
     * 主动请求获取状态
     */
    public function imStatusAction()
    {
        //主动获取状态
        $im = new Im();
        $im->getOnlineStatusForOne($this->redis, $this->request->get('Identifier'));
        $this->response->setStatusCode(Status::OK);
    }

    /**
     * 主动改变状态
     */
    public function changeImStatusAction()
    {
        $status = $this->request->get("OnlineStatus");
        $key = $this->request->get("Identifier");
        if (!in_array($status, ["free", "busy", "off"])) {
            throw new LogicException("在线状态变更请求参数错误", Status::BadRequest);
        }
        switch ($status) {
            case "free":
                $this->redis->getSet($key, $status);
                break;
            case "busy":
                $this->redis->getSet($key, $status);
                break;
            case "off":
                $this->redis->del($key);
                break;
        }
        $this->response->setStatusCode(Status::OK);
    }
}
