<?php

namespace LiveMapEngine\Entity;

class CRSTranslationOptions
{
    public $ox;
    public $oy;
    public $height;

    public function __construct($ox = 0, $oy = 0, $height = 0)
    {
        $this->ox = $ox;
        $this->oy = $oy;
        $this->height = $height;
    }
}