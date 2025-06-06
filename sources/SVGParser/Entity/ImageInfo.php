<?php

namespace LiveMapEngine\SVGParser\Entity;

/**
 * Информация об изображении с учетом image translation
 */
class ImageInfo
{
    /**
     * @var bool
     */
    public bool $is_present;

    /**
     * @var float WIDTH
     */
    public float $width;

    /**
     * @var float HEIGHT
     */
    public float $height;

    /**
     * @var float OFFSET X
     */
    public float $ox;

    /**
     * @var float OFFSET Y
     */
    public float $oy;

    /**
     * @var string
     */
    public string $xhref;

    /**
     * @var float
     */
    public float $precision;

    public function __construct($width = 0, $height = 0, $ox = 0, $oy = 0, string $xhref = '', int $precision = 4, bool $is_present = false)
    {
        $this->is_present = $is_present;

        $this->width = \round($width, $precision);
        $this->height = round($height, $precision);
        $this->ox = \round($ox, $precision);
        $this->oy = \round($oy, $precision);
        $this->xhref = $xhref;
    }

}