<?php
namespace App\Libs\fake\composer;

class Image
{
    protected $src;

    public function __construct($src)
    {
        $this->src = $src;
    }

    /**
     * @return mixed
     */
    public function getSrc()
    {
        return $this->src;
    }
}
