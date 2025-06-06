<?php

namespace LiveMapEngine\SVGParser\Entity;

class LayerElementsTranslation
{
    /**
     * @var float
     */
    public float $ox;

    /**
     * @var int|float
     */
    public float $oy;

    /**
     * @param float $ox
     * @param float $oy
     */
    public function __construct(float $ox = 0, float $oy = 0)
    {
        $this->ox = $ox;
        $this->oy = $oy;
    }

}