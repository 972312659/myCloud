<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/4/2
 * Time: 5:37 PM
 */

namespace App\Libs\salesman;


use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\SalesmanBonus;
use App\Models\Transfer;
use App\Models\User;
use Phalcon\Di\FactoryDefault;

class ReadBonus
{
    /** @var SalesmanBonus */
    private $salesmanBonus;
    /** @var  array */
    private $auth;
    /** @var  array */
    private $result = [];

    public function __construct(SalesmanBonus $salesmanBonus)
    {
        $this->salesmanBonus = $salesmanBonus;
        $this->auth = FactoryDefault::getDefault()->get('session')->get('auth');
    }

    public function show()
    {
        $this->baseInfo();
        $this->slaveInfo();
        return $this->result;
    }

    public function baseInfo()
    {
        $this->result = $this->salesmanBonus->toArray();
        $this->result['ValueName'] = $this->result['IsFixed'] == SalesmanBonus::IsFixed_Yes ? $this->result['Value'] / 100 : $this->result['Value'] . '%';
        $this->result['TypeName'] = SalesmanBonus::ReferenceType_Name[$this->result['ReferenceType']];
        $this->result = [
            'Id'            => $this->salesmanBonus->Id,
            'ReferenceType' => $this->salesmanBonus->ReferenceType,
            'ReferenceId'   => $this->salesmanBonus->ReferenceId,
            'Describe'      => $this->salesmanBonus->Describe,
            'Amount'        => $this->salesmanBonus->Amount,
            'IsFixed'       => $this->salesmanBonus->IsFixed,
            'Value'         => $this->salesmanBonus->IsFixed == SalesmanBonus::IsFixed_Yes ? $this->salesmanBonus->Value : $this->salesmanBonus->Value / 100,
            'Bonus'         => $this->salesmanBonus->Bonus,
            'Created'       => $this->salesmanBonus->Created,
            'ValueName'     => $this->salesmanBonus->IsFixed == SalesmanBonus::IsFixed_Yes ? $this->salesmanBonus->Value / 100 : ($this->salesmanBonus->Value / 100) . '%',
            'TypeName'      => SalesmanBonus::ReferenceType_Name[$this->salesmanBonus->ReferenceType],
        ];
    }

    public function slaveInfo()
    {
        switch ($this->salesmanBonus->ReferenceType) {
            case SalesmanBonus::ReferenceType_Transfer:
                /** @var Transfer $transfer */
                $transfer = Transfer::findFirst(sprintf('Id=%d', $this->salesmanBonus->ReferenceId));
                /** @var OrganizationRelationship $slave */
                $slave = OrganizationRelationship::findFirst([
                    'conditions' => 'MainId=?0 and MinorId=?1',
                    'bind'       => [$this->auth['OrganizationId'], $transfer->SendOrganizationId],
                ]);
                /** @var OrganizationUser $organizationUser */
                $organizationUser = OrganizationUser::findFirst(sprintf('OrganizationId=%d', $transfer->SendOrganizationId));
                /** @var User $slaveUser */
                $slaveUser = User::findFirst(sprintf('Id=%d', $organizationUser->UserId));
                $this->result = array_merge($this->result, [
                    'SlaveName'      => $slave->MinorName,
                    'SlaveUserName'  => $slaveUser->Name,
                    'SlaveUserPhone' => $slaveUser->Phone,
                    'OrderNumber'    => (string)$transfer->OrderNumber,
                ]);
                break;
        }
    }

}