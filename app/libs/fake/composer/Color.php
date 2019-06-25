<?php

namespace App\Libs\fake\composer;

class Color
{
    /**
     * @var int $r
     */
    protected $r;

    /**
     * @var int $g
     */
    protected $g;

    /**
     * @var int $b
     */
    protected $b;

    public function __construct(int $r = 0, int $g = 0, int $b = 0)
    {
        $this->r = $r;
        $this->g = $g;
        $this->b = $b;
    }

    /**
     * @return int
     */
    public function getR(): int
    {
        return $this->r;
    }

    /**
     * @return int
     */
    public function getG(): int
    {
        return $this->g;
    }

    /**
     * @return int
     */
    public function getB(): int
    {
        return $this->b;
    }
}
