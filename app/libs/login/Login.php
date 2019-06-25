<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2018/11/7
 * Time: 上午11:50
 * 自动登录
 */

namespace App\Libs\login;


use App\Controllers\Controller;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationSendMessageConfig;
use App\Models\OrganizationUser;
use App\Models\User;

class Login extends Controller
{
    /**
     * 小b激活账户自动登录
     * @param Organization $hospital
     * @param Organization $organization
     * @param User $user
     * @param OrganizationUser $organizationUser
     */
    public function slave(Organization $hospital, Organization $organization, User $user, OrganizationUser $organizationUser)
    {
        $result = array_merge($user->toArray(), $organizationUser->toArray());
        $result['OrganizationId'] = $organization->Id;
        $result['Token'] = $this->session->getId();
        $organizationUser->LastLoginTime = time();
        $organizationUser->LastLoginIp = ip2long($this->request->getClientAddress());
        $organizationUser->save();

        $result['OrganizationName'] = $organization->Name;
        $result['IsMain'] = $organization->IsMain;
        $result['Verifyed'] = $hospital->Verifyed;
        $result['OrganizationPhone'] = $organization->Phone;


        /**
         * @var  \Phalcon\Mvc\Model\Criteria $criteria
         */
        $criteria = Location::query();
        $criteria->inWhere('Id', [$organization->ProvinceId, $organization->CityId, $organization->AreaId]);
        /**
         * @var \Phalcon\Mvc\Model\Resultset\Simple $locations
         */
        $locations = $criteria->execute();

        $result['HospitalName'] = $hospital->Name;
        $result['HospitalId'] = $hospital->Id;
        $result['OrganizationName'] = $organization->Name;
        $result['ProvinceId'] = $organization->ProvinceId;
        $result['CityId'] = $organization->CityId;
        $result['AreaId'] = $organization->AreaId;
        foreach ($locations as $location) {
            /**
             * @var Location $location
             */
            if ($location->Id === $organization->ProvinceId) {
                $result['Province'] = $location->Name;
                continue;
            }
            if ($location->Id === $organization->CityId) {
                $result['City'] = $location->Name;
                continue;
            }
            if ($location->Id === $organization->AreaId) {
                $result['Area'] = $location->Name;
                continue;
            }
        }
        $result['Address'] = $organization->Address;
        $result['Easemob'] = md5($user->Password);
        $result['MachineOrgId'] = $organization->MachineOrgId;
        unset($result['Password']);
        $sendMessage = OrganizationSendMessageConfig::findFirst([
            'conditions' => 'OrganizationId=?0 and Type=?1',
            'bind'       => [$result['OrganizationId'], OrganizationSendMessageConfig::TYPE_SEND_TO_PATIENT],
        ]);
        $result['AgreeSendMessage'] = $sendMessage ? $sendMessage->AgreeSendMessage : OrganizationSendMessageConfig::AGREE_SEND_YES;
        $this->session->set('auth', $result);
        $this->response->setJsonContent($result);
    }
}