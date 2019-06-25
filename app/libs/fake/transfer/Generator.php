<?php

namespace App\Libs\fake\transfer;

use App\Libs\fake\models\Transfer;

class Generator
{
    /**
     * @var Seed $seed
     */
    protected $seed;

    public function __construct(Seed $seed)
    {
        $this->seed = $seed;
    }

    /**
     * 生成转诊单默认模板
     *
     * @return Transfer
     */
    public function createDefaultTransfer()
    {
        $transfer = new Transfer();

        $transfer->TranStyle = 1;
        $transfer->Status = 8;
        $transfer->Genre = 1;
        $transfer->Sign = 1;
        $transfer->GenreOne = 2;
        $transfer->ShareOne = Transfer::SHARE_ONE;
        $transfer->ShareCloud = 1;
        $transfer->CloudGenre = 2;
        $transfer->GenreTwo = 0;
        $transfer->ShareTwo = 0;
        $transfer->IsFake = 1;
        $transfer->IsEncash = 0;

        return $transfer;
    }

    /**
     * @return Person
     */
    public function createPerson()
    {
        return $this->seed->randPerson();
    }
}
