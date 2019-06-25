<?php

namespace App\Libs\fake\composer;

class Composer
{
    /**
     * @var Manager
     */
    protected $manager;

    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @param Image $image
     * @param Text[] $texts
     * @return mixed
     * @throws \Exception
     */
    public function compose(Image $image, $texts = [])
    {
        $fileinfo = new \SplFileInfo($image->getSrc());

        $resource = imagecreatefrompng($fileinfo->getRealPath());
        //合成文字
        foreach ($texts as $text) {
            $color = imagecolorallocate(
                $resource,
                $text->getColor()->getR(),
                $text->getColor()->getG(),
                $text->getColor()->getB()
            );

            imagettftext(
                $resource,
                $text->getSize(),
                0,
                $text->getPosition()->getX(),
                $text->getPosition()->getY(),
                $color,
                $this->manager->getFont($text->getFamily()),
                $text->getText()
            );
        }

        return $resource;
    }

    /**
     * @return Manager
     */
    public function getManager()
    {
        return $this->manager;
    }
}
