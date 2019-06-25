<?php

namespace App\Libs\fake\composer;

class Manager
{
    protected $fonts = [];

    protected $source_image = [];

    /**
     * @param string $name
     * @param string $fontFile
     * @return Manager
     * @throws \Exception
     */
    public function addFont(string $name, string $fontFile)
    {

        if (!file_exists($fontFile)) {
            throw new \Exception(sprintf('font file [%s] not found', $fontFile));
        }

        $this->fonts[$name] = $fontFile;

        return $this;
    }

    public function getFont($name)
    {
        return isset($this->fonts[$name]) ? $this->fonts[$name] : null;
    }
}
