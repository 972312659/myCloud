<?php
/**
 * Created by PhpStorm.
 * User: david
 * Date: 2019/3/13
 * Time: 10:10 AM
 */

namespace App\Libs\combo;


use App\Models\Combo;
use App\Models\Organization;
use App\Models\OrganizationRelationship;
use App\Models\RuleOfShare;
use Phalcon\Di\FactoryDefault;

class Read
{
    /** @var  Combo */
    private $combo;
    private $auth;
    private $result;

    public function __construct(Combo $combo)
    {
        $this->combo = $combo;
        $this->auth = FactoryDefault::getDefault()->get('session')->get('auth');
    }

    public function show()
    {
        self::base();
        if ($this->auth['HospitalId'] == $this->auth['OrganizationId']) {
            if ($this->combo->OrganizationId == $this->auth['OrganizationId']) {
                self::forHospital();
            } else {
                $supplier = self::IsSupplier();
                if ($supplier) {
                    self::forSupplier();
                } else {
                    self::forOther();
                }
            }
        } else {
            self::forSlave();
        }
        return $this->result;
    }

    public function consoleShow()
    {
        self::base();
        self::image();
        self::slavePrice();
        self::hospitalInfo();
        return $this->result;
    }

    public function forHospital()
    {
        $this->result = $this->combo->toArray();
    }

    public function forSlave()
    {
        self::image();
        self::slavePrice();
        self::hospitalInfo();
    }

    public function forSupplier()
    {
        self::supplierPrice();
        self::slavePrice();
    }

    public function forOther()
    {
        self::otherPrice();
        self::slavePrice();
    }

    public function IsSupplier()
    {
        $supplier = OrganizationRelationship::findFirst([
            "MainId=:MainId: and MinorId=:MinorId:",
            'bind' => ["MainId" => $this->combo->OrganizationId, "MinorId" => $this->auth['OrganizationId']],
        ]);
        return $supplier;
    }

    public function base()
    {
        $this->result = [
            'Id'           => $this->combo->Id,
            'Name'         => $this->combo->Name,
            'Intro'        => $this->combo->Intro,
            'Stock'        => $this->combo->Stock,
            'Price'        => $this->combo->Price,
            'InvoicePrice' => $this->combo->InvoicePrice,
            'Way'          => $this->combo->Way,
            'MoneyBack'    => $this->combo->MoneyBack,
            'CreateTime'   => $this->combo->CreateTime,
            'OffTime'      => $this->combo->OffTime,
            'Operator'     => $this->combo->Operator,
            'Reason'       => $this->combo->Reason,
        ];
    }

    public function image()
    {
        $this->result['Image'] = $this->combo->Image;
    }

    public function slavePrice()
    {
        $this->result['Amount'] = $this->combo->Amount;
    }

    public function supplierPrice()
    {
        /** @var RuleOfShare $rule */
        $rule = $supplierRule = RuleOfShare::findFirst([
            'conditions' => 'CreateOrganizationId=?0 and OrganizationId=?1 and Style=?2',
            'bind'       => [$this->combo->OrganizationId, $this->auth['OrganizationId'], RuleOfShare::STYLE_HOSPITAL_SUPPLIER],
        ]);
        $this->result['HospitalWay'] = Combo::WAY_RATIO;
        $this->result['HospitalAmount'] = $rule->DistributionOut;
    }

    public function otherPrice()
    {
        /** @var Organization $hospital */
        $hospital = Organization::findFirst(sprintf('Id=%d', $this->combo->OrganizationId));
        /** @var RuleOfShare $rule */
        $rule = RuleOfShare::findFirst(sprintf('Id=%d', $hospital->RuleId));
        $this->result['HospitalWay'] = Combo::WAY_RATIO;
        $this->result['HospitalAmount'] = $rule->DistributionOut;
    }

    public function hospitalInfo()
    {
        /** @var Organization $hospital */
        $hospital = Organization::findFirst(sprintf('Id=%d', $this->combo->OrganizationId));
        $this->result['HospitalName'] = $hospital->Name;
        $this->result['HospitalTel'] = $hospital->Tel;
    }
}