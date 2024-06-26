<?php

namespace LiveMapEngine\SVGParser\Entity;

/**
 * Информация об изображении с учетом image translation
 */
class ImageInfo
{
    public float $width;
    public float $height;
    public float $ox;
    public float $oy;
    public string $xhref;

    /**
     * @var bool
     */
    public bool $is_present;

    public function __construct($width = 0, $height = 0, $ox = 0, $oy = 0, $xhref = '', $precision = 4, $is_present = false)
    {
        $this->is_present = $is_present;

        $this->width = \round($width, $precision);
        $this->height = round($height, $precision);
        $this->ox = \round($ox, $precision);
        $this->oy = \round($oy, $precision);
        $this->xhref = $xhref;
    }

}