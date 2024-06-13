<?php

namespace LiveMapEngine\Entity;

class LayerElementsTranslation
{
    /**
     * @var float
     */
    public $ox;

    /**
     * @var int|float
     */
    public $oy;

    /**
     * @param $ox
     * @param $oy
     */
    public function __construct($ox = 0, $oy = 0)
    {
        $this->ox = $ox;
        $this->oy = $oy;
    }

}