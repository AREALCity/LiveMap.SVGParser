<?php

namespace LiveMapEngine\SVGParser\Entity;

/**
 *
 */
class ImageInfo
{
    public $width;
    public $height;
    public $ox;
    public $oy;
    public $xhref;

    public function __construct($width, $height, $ox, $oy, $xhref)
    {
        $this->width = $width;
        $this->height = $height;
        $this->ox = $ox;
        $this->oy = $oy;
        $this->xhref = $xhref;
    }

}