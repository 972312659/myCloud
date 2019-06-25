<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/14
 * Time: 9:05 PM
 */

namespace App\Libs\combo;


use App\Models\User;
use App\Models\Combo;
use App\Models\ComboOrder;
use App\Models\ComboOrderBatch;
use App\Models\ComboRefund;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\OrganizationUser;
use App\Models\RuleOfShare;
use Phalcon\Di\FactoryDefault;

class ReadComboOrderBatch
{
    private $comboOrderBatch;
    /** @var  array */
    private $result = [];
    private $auth;

    public function __construct(ComboOrderBatch $comboOrderBatch)
    {
        $this->comboOrderBatch = $comboOrderBatch;
        $this->auth = FactoryDefault::getDefault()->get('session')->get('auth');
    }

    public function show()
    {
        if ($this->auth['HospitalId'] == $this->auth['OrganizationId']) {
            $this->forHospital();
        } else {
            $this->forSlave();
        }
        return $this->result;
    }

    public function refundUse()
    {
        $this->base();
        $this->slaveInfo();
        $this->slaveCommission();
        $this->otherCommission();
        $this->salesman();
        return $this->result;
    }

    public function forHospital()
    {
        $this->base();
        $this->slaveInfo();
        $this->slaveCommission();
        $this->otherCommission();
        $this->salesman();
    }

    public function forSlave()
    {
        $this->base();
        $this->refundCount();
        $this->hospitalInfo();
    }

    public function base()
    {
        $this->result = [
            'Id'                   => $this->comboOrderBatch->Id,
            'OrderNumber'          => (string)$this->comboOrderBatch->OrderNumber,
            'ComboId'              => $this->comboOrderBatch->ComboId,
            'HospitalId'           => $this->comboOrderBatch->HospitalId,
            'OrganizationId'       => $this->comboOrderBatch->OrganizationId,
            'SendOrganizationName' => $this->comboOrderBatch->OrganizationName,
            'Name'                 => $this->comboOrderBatch->Name,
            'Price'                => $this->comboOrderBatch->Price,
            'TotalPrice'           => $this->comboOrderBatch->Price * $this->comboOrderBatch->QuantityBuy,
            'Way'                  => $this->comboOrderBatch->Way,
            'MoneyBack'            => $this->comboOrderBatch->MoneyBack,
            'Amount'               => $this->comboOrderBatch->Amount,
            'InvoicePrice'         => $this->comboOrderBatch->InvoicePrice,
            'QuantityBuy'          => $this->comboOrderBatch->QuantityBuy,
            'Status'               => $this->comboOrderBatch->Status,
            'CreateTime'           => $this->comboOrderBatch->CreateTime,
            'PayTime'              => $this->comboOrderBatch->PayTime,
            'FinishTime'           => $this->comboOrderBatch->FinishTime,
            'QuantityUnAllot'      => $this->comboOrderBatch->QuantityUnAllot,
            'QuantityBack'         => $this->comboOrderBatch->QuantityBack,
            'QuantityApply'        => $this->comboOrderBatch->QuantityApply,
            'Genre'                => $this->comboOrderBatch->Genre,
            'StatusName'           => ComboOrderBatch::STATUS_NAME[$this->comboOrderBatch->Status],
            'Message'              => '',
            'Image'                => $this->comboOrderBatch->Image,

        ];
    }


    public function refundCount()
    {
        //申请退款的次数
        $this->result['QuantityRefund'] = ComboRefund::count("BuyerOrganizationId={$this->comboOrderBatch->OrganizationId} and ReferenceType=1 and ReferenceId={$this->comboOrderBatch->Id}");
    }

    public function hospitalInfo()
    {
        /** @var Combo $combo */
        $combo = Combo::findFirst(sprintf('Id=%d', $this->comboOrderBatch->ComboId));
        /** @var Organization $hospital */
        $hospital = Organization::findFirst(sprintf('Id=%d', $combo->OrganizationId));
        $this->result = array_merge($this->result, [
            'HospitalName' => $hospital->Name,
            'HospitalTel'  => $hospital->Tel,
        ]);
    }

    public function slaveInfo()
    {
        /** @var OrganizationUser $slave */
        $slave = OrganizationUser::findFirst(sprintf('OrganizationId=%d', $this->comboOrderBatch->OrganizationId));
        /** @var User $user */
        $user = User::findFirst(sprintf('Id=%d', $slave->UserId));
        $this->result['SendOrganizationTel'] = $user->Phone;
        $this->result['SendOrganizationContact'] = $user->Name;
    }

    public function slaveCommission()
    {
        $this->result['SlaveShare'] = (int)($this->result['Way'] == Combo::WAY_FIXED ? $this->result['Price'] : $this->result['Price'] * $this->result['Amount'] / 100);

    }

    public function otherCommission()
    {
        /** @var Organization $hospital */
        $hospital = Organization::findFirst(sprintf('Id=%d', $this->auth['OrganizationId']));
        /** @var RuleOfShare $rule */
        $rule = RuleOfShare::findFirst(sprintf('Id=%d', $hospital->RuleId));
        $this->result['CloudShare'] = (int)$this->result['Price'] * $rule->Ratio / 100;

        if ($this->comboOrderBatch->Genre == ComboOrder::GENRE_SHARE) {
            $supplier = OrganizationRelationship::findFirst([
                "MainId=:MainId: and MinorId=:MinorId:",
                'bind' => ["MainId" => $this->comboOrderBatch->HospitalId, "MinorId" => $this->auth['OrganizationId']],
            ]);
            if ($supplier) {
                //供应商
                $rule = RuleOfShare::findFirst([
                    'conditions' => 'CreateOrganizationId=?0 and OrganizationId=?1',
                    'bind'       => [$this->comboOrderBatch->HospitalId, $this->auth['OrganizationId']],
                ]);
            }
            // $way = 2;//按比例
            // $amount = $rule->DistributionOut;
            $this->result['HospitalShare'] = (int)($this->result['Price'] * $rule->DistributionOut / 100);
        }
    }

    public function salesman()
    {
        $this->result['Salesman'] = '';
        /** @var OrganizationRelationship $organizationRelation */
        $organizationRelation = OrganizationRelationship::findFirst([
            'conditions' => 'MainId=?0 and MinorId=?1',
            'bind'       => [$this->auth['OrganizationId'], $this->comboOrderBatch->OrganizationId],
        ]);
        if ($organizationRelation) {
            /** @var User $user */
            $user = User::findFirst(sprintf('Id=%d', $organizationRelation->SalesmanId));
            $this->result['Salesman'] = $user->Name;
            $this->result['SalesmanPhone'] = $user->Phone;
        }
    }
}