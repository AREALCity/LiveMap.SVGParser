<?php

namespace LiveMapEngine\SVGParser\Entity;

class ParsedElement
{
    public $id;

    public bool $valid = false;

    public string $type;

    public string $layer_name;

    public array $coords = [];

    public array $interactive = [];

    public string $js = '';

    public function __construct()
    {
    }

}