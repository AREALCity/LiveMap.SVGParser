<?php

namespace LiveMapEngine;

use Arris\Entity\Result;
use Arris\Toolkit\XMLNavigator\Convertation\FastXmlToArray;
use Exception;
use SimpleXMLElement;
use stdClass;

/**
 *
 */
class SVGParser implements SVGParserInterface
{
    public const VERSION                       = 3.0;
    public const ROUND_PRECISION               = 4;

    /**
     * Constants for convert_SVGElement_to_Polygon()
     * see : https://www.w3.org/TR/SVG11/paths.html#InterfaceSVGPathSeg
     */
    public const PATHSEG_UNDEFINED             = 0;
    public const PATHSEG_REGULAR_KNOT          = 1;

    public const PATHSEG_MOVETO_ABS            = 2;
    public const PATHSEG_MOVETO_REL            = 3;
    public const PATHSEG_CLOSEPATH             = 4;

    public const PATHSEG_LINETO_HORIZONTAL_REL = 5;
    public const PATHSEG_LINETO_HORIZONTAL_ABS = 6;

    public const PATHSEG_LINETO_VERTICAL_REL   = 7;
    public const PATHSEG_LINETO_VERTICAL_ABS   = 8;

    public const PATHSEG_LINETO_REL            = 9;
    public const PATHSEG_LINETO_ABS            = 10;

    public const NAMESPACES = array(
        'svg'       =>  'http://www.w3.org/2000/svg',
        'xlink'     =>  'http://www.w3.org/1999/xlink',
        'inkscape'  =>  'http://www.inkscape.org/namespaces/inkscape',
        'sodipodi'  =>  'http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd',
        'rdf'       =>  'http://www.w3.org/1999/02/22-rdf-syntax-ns#'
    );

    private $svg;
    public  $svg_parsing_error = null;

    /**
     * Массив с информацией об изображениях
     *
     * @var array
     */
    private array $layer_images = [];

    /**
     * Информация о сдвиге (transform translation) контейнера с изображениями на холсте
     *
     * @var array
     */
    public array $layer_images_translation = [];


    // сдвиг слоя-контейнера с изображениями
    private $layer_images_oxy = []; // useless?

    // данные трансляции из модели CSV XY в Screen CRS
    // = layer_paths_oxy
    public $crs_translation_options = null;

    // Имя текущего слоя-контейнера с данными
    private $layer_name = '';

    // Текущий слой-контейнер с данными.
    private $layer_elements = [];

    // Сдвиг (translate) элементов на текущем слое
    private $layer_elements_translation = null;

    // Конфиг текущего слоя
    /**
     * @var stdClass null
     */
    private $layer_elements_config = null;

    /**
     * @var ?Result
     */
    public ?Result $result_state = null;

    /**
     * @inheritDoc
     */
    public function __construct( $svg_file_content )
    {
        \libxml_use_internal_errors(true);

        try {
            if (!is_readable($svg_file_content)) {
                throw new Exception("File {$svg_file_content} not readable!");
            }

            $this->svg = new SimpleXMLElement( $svg_file_content );

            foreach (self::NAMESPACES as $ns => $definition) {
                $this->svg->registerXPathNamespace( $ns, $definition );
            }

        } catch (Exception $e) {
            $this->result_state = (new Result())->error($e->getMessage())->setCode($e->getCode());
        }
    }

    /**
     * @inheritDoc
     */
    public function parseImages( $layer_name ):bool
    {
        if ($layer_name !== '') {
            $xpath_images_layer_attrs = '//svg:g[starts-with(@inkscape:label, "' . $layer_name . '")]';

            // @var SimpleXMLElement $images_layer_attrs
            $images_layer_attrs = $this->svg->xpath($xpath_images_layer_attrs);

            if ($images_layer_attrs) {
                $images_layer_attrs = $images_layer_attrs[0];
            } else {
                return false;
            }

            if ($images_layer_attrs->attributes() === null) {
                return false;
            }

            // анализируем атрибут transform="translate(?,?)"
            if (!empty($images_layer_attrs->attributes()->{'transform'})) {
                $this->layer_images_translation = $this->parseTransform( $images_layer_attrs->attributes()->{'transform'} );
            }

            $xpath_images   = '//svg:g[starts-with(@inkscape:label, "' . $layer_name . '")]/svg:image';

        } else {
            $xpath_images   = '//svg:image';
        }

        $this->layer_images = $this->svg->xpath($xpath_images);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getImagesCount(): int
    {
        return \count($this->layer_images);
    }

    /**
     * Возвращает параметры сдвига при трансформации-переносе
     * Атрибут
     *
     * transform="translate(0,1052.36)"
     *
     * @param $transform_definition
     * @return array [ ox, oy ]
     */
    private function parseTransform($transform_definition): array
    {
        $default = [
            'ox'    =>  0,
            'oy'    =>  0
        ];

        if (empty($transform_definition)) {
            return $default;
        }

        if (1 == \preg_match('/translate\(\s*([^\s,)]+)[\s,]([^\s,)]+)/', $transform_definition, $translate_matches)) {
            if (count($translate_matches) > 2) {
                return [
                    'ox'    =>  (float)$translate_matches[1],
                    'oy'    =>  (float)$translate_matches[2]
                ];
            }
        }

        return $default;
    }

    /**
     * @inheritDoc
     */
    public function getImageInfo(int $index = 0): array
    {
        if (\array_key_exists($index, $this->layer_images)) {
            /**
             * @var SimpleXMLElement $an_image
             */
            $an_image = $this->layer_images[ $index ];

            // выносим в переменные, иначе может случится херня:
            // (float)$an_image->attributes()->{'x'} ?? 0 + (float)$this->layer_images_translation['ox'] ?? 0 ;
            // трактуется как
            // (float)$an_image->attributes()->{'x'} ?? ( 0 + (float)$this->layer_images_translation['ox'] ?? 0  )
            // то есть у ?? приоритет меньше чем у +

            $an_image_offset_x = (float)$an_image->attributes()->{'x'} ?? 0;
            $an_image_offset_y = (float)$an_image->attributes()->{'y'} ?? 0;

            $an_image_translate_x = (float)$this->layer_images_translation['ox'] ?? 0;
            $an_image_translate_y = (float)$this->layer_images_translation['oy'] ?? 0;

            return [
                'width'     =>  \round((float)$an_image->attributes()->{'width'} ?? 0, self::ROUND_PRECISION),
                'height'    =>  \round((float)$an_image->attributes()->{'height'} ?? 0, self::ROUND_PRECISION),
                'ox'        =>  \round($an_image_offset_x + $an_image_translate_x, self::ROUND_PRECISION),
                'oy'        =>  \round($an_image_offset_y + $an_image_translate_y, self::ROUND_PRECISION),
                'xhref'     =>  (string)$an_image->attributes('xlink', true)->{'href'} ?? ''
            ];
        }

        return [];
    }

    /**
     * Парсит объекты на определенном слое (или по всему файлу)
     *
     * @param $layer_name
     * @return bool
     */
    public function parseLayer($layer_name):bool
    {
        if ($layer_name !== '') {
            $this->layer_name = $layer_name;

            // xpath атрибутов слоя разметки
            $xpath_paths_layer_attrs = '//svg:g[starts-with(@inkscape:label, "' . $layer_name . '")]';

            if (empty($this->svg->xpath($xpath_paths_layer_attrs))) {
                return false;
            }

            $paths_layer_attrs = $this->svg->xpath($xpath_paths_layer_attrs)[0];

            // получаем сдвиг всех объектов этого слоя
            if (!empty($paths_layer_attrs->attributes()->{'transform'})) {
                $this->layer_elements_translation = $this->parseTransform( $paths_layer_attrs->attributes()->{'transform'} );
            } else {
                $this->layer_elements_translation = null;
            }

            $xpath_paths    = '//svg:g[starts-with(@inkscape:label, "' . $layer_name . '")]'; // все возможные объекты

            // + '/*' - список объектов (но без информации об объекте)
        } else {
            $xpath_paths    = '//svg:path'; //@todo: другое определение?
        }

        $this->layer_elements  = $this->svg->xpath($xpath_paths)[0];

        return true;
    }

    /**
     * Устанавливает опции трансляции данных слоя из модели CRS.XY в модель CRS.Simple
     *
     * Если не вызывали - трансляция не производится
     * @param $ox
     * @param $oy
     * @param $image_height
     */
    public function set_CRSSimple_TranslateOptions($ox = null , $oy = null, $image_height = null)
    {
        if (!( \is_null($ox) || \is_null($oy) || \is_null($image_height))) {
            $this->crs_translation_options = [
                'ox'    =>  $ox,
                'oy'    =>  $oy,
                'height'=>  $image_height
            ];
        }
    }

    /**
     * Парсит один элемент на слое
     *
     * @param SimpleXMLElement $element
     * @param string $type
     * @return array
     */
    public function parseAloneElement(SimpleXMLElement $element, string $type)
    {
        $data = [];   // блок данных о пути

        $path_d     = (string)$element->attributes()->{'d'};
        $path_id    = (string)$element->attributes()->{'id'};
        $path_style = (string)$element->attributes()->{'style'};

        // только с помощью дополнительного парсера можно распознать расширенные свойства (потому что DTD для inkscape:* и sodipodi:* больше не работают)
        $element_as_array = FastXmlToArray::prettyPrint($element->asXML());
        $element_attributes = $element_as_array['path']['@attributes'] ?? [];

        $path_sodipodi_type = $element_attributes['sodipodi:type'] ?? 'path';
        if ($path_sodipodi_type == "spiral") {
            $type = "marker";
        }

        $data['id'] = $path_id;
        $data['label'] = $element_attributes["inkscape:label"] ?? $path_id;

        switch ($type) {
            /*
             * За POI-маркер отвечает Inkscape-элемент SPIRALE (точкой установки маркера является ЦЕНТР спирали)
             *
             * Теперь нам нужен INKSCAPE SVG файл
             */
            case 'marker': {
                $data['type'] = 'marker';
                $coords = [
                    'x'     =>  $element_attributes['sodipodi:cx'],
                    'y'     =>  $element_attributes['sodipodi:cy'],
                ];
                $coords = $this->translate_knot_from_XY_to_CRS( $coords );
                $data['coords'] = $coords;

                $data['js'] = $this->convert_knotCRS_to_JSstring( $data['coords'] );

                break;
            }

            case 'path' : {
                // кол-во путей ~
                // кол-во узлов ~
                $data['type'] = 'polygon';

                // SVG Path -> Polygon
                $coords = $this->convert_SVGElement_to_Polygon( $element );
                if (!$coords) {
                    return [];
                }

                // сдвиг координат и преобразование в CRS-модель
                $coords = $this->translate_polygon_from_XY_to_CRS( $coords );

                // convert_to_JS_style
                $data['coords'] = $coords;
                $data['js'] = $this->convert_CRS_to_JSString( $data['coords'] );

                break;
            }
            case 'circle': {
                // кол-во путей 1
                // кол-во узлов 1
                $data['type'] = 'circle';

                $r = $element->attributes()->{'r'} ?? 0;
                $data['radius'] = \round((float)$r, self::ROUND_PRECISION); //@todo: точность выносим в опции конструктора

                // SVG Path -> Polygon
                $coords = $this->convert_SVGElement_to_Circle( $element );
                if (!$coords) {
                    return [];
                }

                // сдвиг координат и преобразование в CRS-модель
                $coords = $this->translate_knot_from_XY_to_CRS( $coords );

                $data['coords'] = $coords;
                $data['js'] = $this->convert_knotCRS_to_JSstring( $data['coords'] );

                break;
            }
            case 'rect': {
                // кол-во путей 1
                // кол-во узлов 2
                // x, y
                $data['type'] = 'rect';
                $coords = $this->convert_SVGElement_to_Rect( $element );
                if (empty($coords)) {
                    return [];
                }

                $data['coords'][] = [
                    $this->translate_knot_from_XY_to_CRS( $coords[0] ),
                    $this->translate_knot_from_XY_to_CRS( $coords[1] )
                ];

                $data['js'] = $this->convert_CRS_to_JSString($data['coords'] );

                break;
            }
            case 'ellipse': {
                $data['type'] = 'circle';

                $rx = $element->attributes()->{'rx'} ?? 0;
                $ry = $element->attributes()->{'ry'} ?? 0;

                /*
                 * Эллипс мы рисовать не умеем, поэтому приводим его к окружности
                 */
                $data['radius'] = \round( ( (float)$rx + (float)$ry ) /2 , self::ROUND_PRECISION);

                // SVG Element to coords
                $coords = $this->convert_SVGElement_to_Circle( $element );
                if (!$coords) {
                    return [];
                }

                // сдвиг координат и преобразрвание в CRS-модель
                $coords = $this->translate_knot_from_XY_to_CRS( $coords );

                $data['coords'] = $coords;
                $data['js'] = $this->convert_knotCRS_to_JSstring( $data['coords'] );

                break;
            }
        }

        // получем информацию об атрибутах региона из SVG-разметки

        // получаем атрибут fillColor
        if (\preg_match('#fill:([\#\d\w]{7})#', $path_style, $path_style_fillColor) ) {
            $data['fillColor'] = $path_style_fillColor[1];
        } else {
            $data['fillColor'] = null;
        };

        // получаем атрибут fillOpacity
        if (\preg_match('#fill-opacity:([\d]?\.[\d]{0,8})#', $path_style, $path_style_fillOpacity) ) {
            $data['fillOpacity'] = round($path_style_fillOpacity[1] , self::ROUND_PRECISION);
        } else {
            $data['fillOpacity'] = null;
        };

        // получаем атрибут fillRule
        if (\preg_match('#fill-rule:(evenodd|nonzero)#', $path_style, $path_style_fillRule) ) {
            if ($path_style_fillRule[1] !== 'evenodd') {
                $data['fillRule'] = $path_style_fillRule[1];
            }
        } else {
            $data['fillRule'] = null;
        };

        // кастомные значения для пустых регионов
        if ($this->layer_elements_config) {
            if (
                self::property_exists_recursive($this->layer_elements_config, 'empty->fill') &&
                $this->layer_elements_config->empty->fill == 1
            ) {
                if (
                    self::property_exists_recursive($this->layer_elements_config, 'empty->fillColor') &&
                    $this->layer_elements_config->empty->fillColor && !$data['fillColor']
                ) {
                    $data['fillColor'] = $this->layer_elements_config->empty->fillColor;
                }

                if (
                    self::property_exists_recursive($this->layer_elements_config, 'empty->fillOpacity') &&
                    $this->layer_elements_config->empty->fillOpacity && !$data['fillOpacity']
                ) {
                    $data['fillOpacity'] = $this->layer_elements_config->empty->fillOpacity;
                }
            } // if ... $this->layer_elements_config->empty->fill == 1)

            if (
                self::property_exists_recursive($this->layer_elements_config, 'empty->stroke') &&
                $this->layer_elements_config->empty->stroke && $this->layer_elements_config->empty->stroke == 1
            ) {

                if (
                    self::property_exists_recursive($this->layer_elements_config, 'empty->borderColor') &&
                    $this->layer_elements_config->empty->borderColor && $data['borderColor']
                ) {
                    $data['borderColor'] = $this->layer_elements_config->empty->borderColor;
                }

                if (
                    self::property_exists_recursive($this->layer_elements_config, 'empty->borderWidth') &&
                    $this->layer_elements_config->empty->borderWidth && $data['borderWidth']
                ) {
                    $data['borderWidth'] = $this->layer_elements_config->empty->borderWidth;
                }

                if (
                    self::property_exists_recursive($this->layer_elements_config, 'empty->borderOpacity') &&
                    $this->layer_elements_config->empty->borderOpacity && $data['borderOpacity']
                ) {
                    $data['borderOpacity'] = $this->layer_elements_config->empty->borderOpacity;
                }

            } // if ... $this->layer_elements_config->empty->stroke == 1)

        } // if ($this->layer_elements_config)

        // получаем title узла
        $path_title = (string)$element->{'title'}[0];
        if ($path_title) {
            $data['title'] = \htmlspecialchars($path_title, ENT_QUOTES | ENT_HTML5);
        }

        // получаем description узла
        $path_desc = (string)$element->{'desc'}[0];
        if ($path_desc) {
            $data['desc'] = \htmlspecialchars($path_desc, ENT_QUOTES | ENT_HTML5);
        }

        $data['layer'] = $this->layer_name;

        // get interactive values
        $data['interactive'] = [];
        $possible_interactive_fields = [
            'onclick',
            'onmouseover',
            'onmouseout',
            'onmousedown',
            'onmousemove',
            'onfocusin',
            'onfocusout',
            'onload'
        ];
        foreach (
            $possible_interactive_fields as $interactive_field) {
            if (
                \array_key_exists($interactive_field, $element_attributes)
            ) {
                $data['interactive'][ $interactive_field ] = $element_attributes[$interactive_field];
            }
        }

        return $data;
    } // parseAloneElement

    /**
     * Получаем элементы по типу (rect, circle, path)
     *
     * @param $type
     * @return array
     */
    public function getElementsByType( $type )
    {
        /** @var SimpleXMLElement $path */
        $all_paths = [];

        foreach ($this->layer_elements->{$type} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};

            $all_paths[ $path_id ] = $this->parseAloneElement($path, $type);
        }

        return $all_paths;
    }

    /**
     * Получаем все элементы со слоя
     * Это основная "экспортная" функция
     *
     * @return array
     */
    public function getElementsAll():array
    {
        $all_paths = [];

        /** @var SimpleXMLElement $path */

        foreach ($this->layer_elements->{'path'} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};
            $element    = $this->parseAloneElement($path, 'path');
            if ($element) {
                $all_paths[ $path_id ] = $element;
            }
        }

        foreach ($this->layer_elements->{'rect'} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};
            $element    = $this->parseAloneElement($path, 'rect');
            if ($element) {
                $all_paths[ $path_id ] = $element;
            }
        }

        foreach ($this->layer_elements->{'circle'} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};
            $element    = $this->parseAloneElement($path, 'circle');
            if ($element) {
                $all_paths[ $path_id ] = $element;
            }
        }

        foreach ($this->layer_elements->{'ellipse'} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};
            $element    = $this->parseAloneElement($path, 'ellipse');
            if ($element) {
                $all_paths[ $path_id ] = $element;
            }
        }


        return $all_paths;
    }

    /**
     * Устанавливает конфигурационные значения по-умолчанию у регионов для текущего слоя
     *
     * @param stdClass $options
     * @return void
     */
    public function setLayerDefaultOptions(stdClass $options)
    {
        $this->layer_elements_config = $options;
    }


    /**
     * Получаем все элементы типа PATH
     *
     * НЕ ИСПОЛЬЗУЕТСЯ?
     *
     * @return array
     */
    private function getPaths()
    {
        return $this->getElementsByType('path');
    }


    /**
     * Получаем все элементы типа RECTANGLE
     *
     * НЕ ИСПОЛЬЗУЕТСЯ?
     *
     * @return array
     */
    private function getRects()
    {
        return $this->getElementsByType('rect');
    }

    /**
     * Получаем все элементы типа CIRCLE
     *
     * НЕ ИСПОЛЬЗУЕТСЯ?
     *
     * @return array
     */
    private function getCircles()
    {
        return $this->getElementsByType('circle');
    }

    /**
     * Получаем все элементы типа ELLIPSE
     *
     * НЕ ИСПОЛЬЗУЕТСЯ?
     *
     * @return array
     */
    private function getEllipses()
    {
        return $this->getElementsByType('ellipse');
    }

    /**
     * Применяет трансформацию к узлу. Если не заданы опции трансформации - используются данные для трансформации слоя
     *
     * @param $knot
     * @param $options
     * @return array
     */
    private function apply_transform_for_knot( $knot , $options = null)
    {

        if ($options === null) {
            $ox = $this->layer_elements_translation['ox'];
            $oy = $this->layer_elements_translation['oy'];
        } else {
            $ox = $options['ox'];
            $oy = $options['oy'];
        }

        return [
            'x' =>  $knot['x'] + $ox,
            'y' =>  $knot['y'] + $oy
        ];
    }

    /**
     * применяет трансформацию к субполигону
     *
     * @param $subpolyline
     * @param $options
     * @return array|array[]
     */
    private function apply_transform_for_subpolygon( $subpolyline, $options = null)
    {
        return \array_map( function($knot) use ($options) {
            return $this->apply_transform_for_knot( $knot );
        }, $subpolyline);
    }

    /**
     * Применяет трансформацию к мультиполигону
     *
     * @param $polygon
     * @param $options
     * @return array|array[]|\array[][]
     */
    private function apply_transform_for_polygon( $polygon, $options )
    {
        if (empty($polygon)) {
            return array();
        }

        return
            ( \count($polygon) > 1 )
                ?
                \array_map( function($subpoly) use ($options) {
                    return $this->apply_transform_for_subpolygon($subpoly, $options);
                }, $polygon )
                :
                array(
                    $this->apply_transform_for_subpolygon( \array_shift($polygon), $options)
                );
    }

    /**
     * convert CRS (SVG) to Simple
     *
     * @param $polygon
     * @return array|array[]|\array[][]
     */
    private function convert_to_SimpleCRS_polygon( $polygon )
    {
        if ( empty($polygon) ) {
            return array();
        }

        return
            ( \count($polygon) > 1 )    // если суб-полигонов больше одного
                ?
                // проходим по всем
                \array_map( function($subpath) {
                    return $this->convert_to_SimpleCRS_subpolygon( $subpath );
                }, $polygon )
                :
                // иначе возвращаем первый элемент массива субполигонов, но как единственный элемент массива!
                array(
                    $this->convert_to_SimpleCRS_subpolygon( \array_shift($polygon) )
                );
    }

    /**
     * @param $subpolygon
     * @return array|array[]
     */
    private function convert_to_SimpleCRS_subpolygon( $subpolygon )
    {
        return \array_map( function($knot) {
            return $this->convert_to_SimpleCRS_knot( $knot );
        }, $subpolygon);
    }

    /**
     * @param $knot
     * @return array
     */
    private function convert_to_SimpleCRS_knot( $knot )
    {
        $ox = 0;
        $oy = 0;
        $height = 0; // height inversion

        // (X, Y) => (Height - (Y-oY) , (X-oX)
        return [
            'x'     =>  \round( $height - ($knot['y'] - $oy), self::ROUND_PRECISION),
            'y'     =>  \round(           ($knot['x'] - $ox), self::ROUND_PRECISION)
        ];
    }

    /**
     * Выполняет трансляцию узла в CRS-модель
     *
     * Тут мы сделали важное упрощение - сдвиг объектов на слое и трансляция данных в модель CRS делаются в одной функции,
     * которая (если судить просто по имени) должна только транслировать вершину в CRS-модель.
     * Это сделано для упрощения, но потенциально здесь может крыться ошибка!
     *
     * @param array $knot
     * @return array
     */
    private function translate_knot_from_XY_to_CRS(array $knot): array
    {
        $ox = 0;
        $oy = 0;
        $height = 0;

        if ($this->layer_elements_translation) {
            $ox += $this->layer_elements_translation['ox'];
            $oy += $this->layer_elements_translation['oy'];
        }

        if ($this->crs_translation_options) {
            $ox += $this->crs_translation_options['ox'];
            $oy += $this->crs_translation_options['oy'];
            $height = $this->crs_translation_options['height'];
        }

        // (X, Y) => (Height - (Y-oY) , (X-oX)
        return [
            'x'     =>  \round( $height - ($knot['y'] - $oy) , self::ROUND_PRECISION),
            'y'     =>  \round( $knot['x'] - $ox, self::ROUND_PRECISION)
        ];
    }

    /**
     * Преобразует субполигон из XY-модели в CRS-модель
     * @param $subpolyline
     * @return array
     */
    private function translate_subpolygon_from_XY_to_CRS( $subpolyline )
    {
        return \array_map( function($knot) {
            return $this->translate_knot_from_XY_to_CRS( $knot );
        }, $subpolyline);
    }

    // преобразует полигон в CRS-модель
    /**
     * Преобразует полигон
     * [0] => массив вершин (XY) (даже если полигон один и нет субполигонов)
     * [1] => массив вершин (XY)
     *
     * @param $polygone
     * @return array
     */
    private function translate_polygon_from_XY_to_CRS( $polygone ): array
    {
        if ( empty($polygone) ) {
            return array();
        }

        return
            ( \count($polygone) > 1 )    // если суб-полигонов больше одного
                ?                           // проходим по всем
                \array_map( function($subpath) {
                    return $this->translate_subpolygon_from_XY_to_CRS( $subpath );
                }, $polygone )
                : // возвращаем первый элемент массива субполигонов, но как единственный элемент массива!
                array(
                    $this->translate_subpolygon_from_XY_to_CRS( \array_shift($polygone)
                    )
                );
    }

    /**
     * Преобразует элемент типа POLYGON в массив координат полигона.
     *
     * Возвращает массив пар координат ИЛИ false в случае невозможности преобразования.
     * Невозможно преобразовать кривые Безье любого вида. В таком случае возвращается пустой массив.
     *
     * Эта функция не выполняет сдвиг или преобразование координат! У неё нет для этого данных.
     *
     * @param SimpleXMLElement $element
     * @return array
     */
    private function convert_SVGElement_to_Polygon( $element )
    {
        // @var SimpleXMLElement $element
        // получаем значение атрибута <path d="">
        $path     = (string)$element->attributes()->{'d'};

        $xy = [];
        $is_debug = false;

        // пуст ли путь?
        if ($path === '') {
            return [];
        }

        // если путь не заканчивается на z/Z - это какая-то херня, а не путь. Отбрасываем
        //@todo: [УЛУЧШИТЬ] PARSE_SVG -- unfinished paths may be correct?
        if ( 'z' !== \strtolower(\substr($path, -1)) ) {
            return [];
        }

        // выясняем наличие атрибута transform:translate (другие варианты трансформации не обрабатываются)
        $translate = [
            'x' =>  0,
            'y' =>  0
        ];
        $transform = (string)$element->attributes()->{'transform'};

        $translate = $this->parseTransform($transform);

        //@todo: добавить обработку трансформации элемента

        // есть ли в пути управляющие последовательности кривых Безье любых видов?
        $charlist_unsupported_knots = 'CcSsQqTtAa';

        // так быстрее, чем регулярка по '#(C|c|S|s|Q|q|T|t|A|a)#'
        if (\strpbrk($path, $charlist_unsupported_knots)) {
            return [];
        }

        $path_fragments = \explode(' ', $path);

        $polygon = [];             // массив узлов полигона
        $multipolygon = [];        // массив, содержащий все полигоны. Если в нём один элемент - то у фигуры один полигон.

        $polygon_is_relative = null;    // тип координат: TRUE - Относительные, false - абсолютные, null - не определено
        $prev_knot_x = 0;               // X-координата предыдущего узла
        $prev_knot_y = 0;               // Y-координата предыдущего узла

        $path_start_x = 0;              // X-координата начала текущего пути
        $path_start_y = 0;              // Y-координата начала текущего пути

        $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

        do {
            $fragment = \array_splice($path_fragments, 0, 1)[0];

            if ($is_debug) {
                echo PHP_EOL, "Извлеченный фрагмент : ", $fragment, PHP_EOL;
            }

            if ( $fragment === 'Z') {
                $fragment = 'z';
            }

            if ( \strpbrk($fragment, 'MmZzHhVvLl') ) {    // faster than if (preg_match('/(M|m|Z|z|H|h|V|v|L|l)/', $fragment) > 0)
                switch ($fragment) {
                    case 'M' : {
                        $LOOKAHEAD_FLAG = self::PATHSEG_MOVETO_ABS;
                        break;
                    }
                    case 'm' : {
                        $LOOKAHEAD_FLAG = self::PATHSEG_MOVETO_REL;
                        break;
                    }
                    case 'z': {
                        $LOOKAHEAD_FLAG = self::PATHSEG_CLOSEPATH;
                        break;
                    }
                    case 'h': {
                        $LOOKAHEAD_FLAG = self::PATHSEG_LINETO_HORIZONTAL_REL;
                        break;
                    }
                    case 'H': {
                        $LOOKAHEAD_FLAG = self::PATHSEG_LINETO_HORIZONTAL_ABS;
                        break;
                    }
                    case 'v': {
                        $LOOKAHEAD_FLAG = self::PATHSEG_LINETO_VERTICAL_REL;
                        break;
                    }
                    case 'V': {
                        $LOOKAHEAD_FLAG = self::PATHSEG_LINETO_VERTICAL_ABS;
                        break;
                    }
                    case 'l': {
                        $LOOKAHEAD_FLAG = self::PATHSEG_LINETO_REL;
                        break;
                    }
                    case 'L': {
                        $LOOKAHEAD_FLAG = self::PATHSEG_LINETO_ABS;
                        break;
                    }
                } // switch

                // обработка управляющей последовательности Z
                if ($LOOKAHEAD_FLAG === self::PATHSEG_CLOSEPATH) {
                    $multipolygon[] = $polygon; // добавляем суб-полигон к полигону
                    $polygon = array();         // очищаем массив узлов суб-полигона
                }

                if ($is_debug) echo "Это управляющая последовательность. Параметры будут обработаны на следующей итерации.", PHP_EOL, PHP_EOL;
                continue;
            } else {
                if ($is_debug) echo "Это числовая последовательность, запускаем обработчик : ";

                /**
                 * Раньше этот блок данных обрабатывался внутри назначения обработчиков.
                 * Сейчас я его вынес наружу. Это может вызвать в перспективе некоторые глюки, нужны тесты
                 */
                if ($LOOKAHEAD_FLAG == self::PATHSEG_MOVETO_REL) {
                    if ($is_debug) echo "m : Начало полилинии с относительными координатами ", PHP_EOL;
                    $polygon_is_relative = true;

                    //@todo: Подумать над ускорением преобразования (ЧИСЛО,ЧИСЛО)

                    $pattern = '#(?<X>\-?\d+(\.\d+)?)\,(?<Y>\-?\d+(\.\d+)?)+#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    // так как путь относительный, moveto делается относительно предыдущего положения "пера"
                    // вообще, скорее всего, нам не нужны совсем переменные $path_start_x и $path_start_y
                    $path_start_x = $prev_knot_x;
                    $path_start_y = $prev_knot_y;

                    if ($matches_count > 0) {
                        //@todo: bcmath - bcadd(x, y)
                        $xy = [
                            'x' =>  (float)$path_start_x + (float)$knot['X'],
                            'y' =>  (float)$path_start_y + (float)$knot['Y']
                        ];
                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];

                        $path_start_x = $prev_knot_x;
                        $path_start_y = $prev_knot_y;
                    }

                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;
                    if ($is_debug) var_dump($xy);
                    continue; // ОБЯЗАТЕЛЬНО делаем continue, иначе управление получит следующий блок
                }

                if ($LOOKAHEAD_FLAG == self::PATHSEG_MOVETO_ABS) {
                    if ($is_debug) echo "M : Начало полилинии с абсолютными координатами ", PHP_EOL;
                    $polygon_is_relative = false;

                    //@todo: Подумать над ускорением преобразования (ЧИСЛО,ЧИСЛО)
                    $pattern = '#(?<X>\-?\d+(\.\d+)?)\,(?<Y>\-?\d+(\.\d+)?)+#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    // вообще, скорее всего, нам не нужны совсем переменные $path_start_x и $path_start_y
                    $path_start_x = 0;
                    $path_start_y = 0;

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  (float)$path_start_x + (float)$knot['X'],
                            'y' =>  (float)$path_start_y + (float)$knot['Y']
                        );
                        $polygon[] = $xy;

                        $prev_knot_x = 0;
                        $prev_knot_y = 0;
                    }

                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

                    if ($is_debug) var_dump($xy);

                    continue; // ОБЯЗАТЕЛЬНО делаем continue, иначе управление получит следующий блок
                }

                if ($LOOKAHEAD_FLAG == self::PATHSEG_UNDEFINED || $LOOKAHEAD_FLAG == self::PATHSEG_REGULAR_KNOT ) {
                    if ($is_debug) echo "Обычная числовая последовательность ", PHP_EOL;

                    // проверяем валидность пары координат
                    //@todo: Подумать над ускорением проверки (ЧИСЛО,ЧИСЛО)
                    //@todo: формат с запятыми - это inkscape-friendly запись. Стандарт считает, что запятая не нужна и числа идут просто парами через пробел.

                    $pattern = '#(?<X>\-?\d+(\.\d+)?)\,(?<Y>\-?\d+(\.\d+)?)+#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    // Если это неправильная комбинация float-чисел - пропускаем обработку и идем на след. итерацию
                    if ($matches_count == 0) continue;
                    // здесь я использую такую конструкцию чтобы не брать стену кода в IfTE-блок.

                    if (empty($polygon)) {
                        // возможно обработку первого узла следует перенести в другой блок (обработчик флага SVGPATH_START_ABSOULUTE или SVGPATH_START_RELATIVE)
                        // var_dump('Это первый узел. Он всегда задается в абсолютных координатах! ');

                        $xy = array(
                            'x' =>  (float)$prev_knot_x + (float)$knot['X'],
                            'y' =>  (float)$prev_knot_y + (float)$knot['Y']
                        );

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    } else {
                        // var_dump('Это не первый узел в мультилинии');

                        if ($polygon_is_relative) {
                            // var_dump("его координаты относительные и даны относительно предыдущего узла полилинии ");

                            $xy = array(
                                'x' =>  (float)$prev_knot_x + (float)$knot['X'],
                                'y' =>  (float)$prev_knot_y + (float)$knot['Y']
                            );

                            $polygon[] = $xy;

                            $prev_knot_x = $xy['x'];
                            $prev_knot_y = $xy['y'];

                        } else {
                            // var_dump("Его координаты абсолютные");

                            $xy = array(
                                'x' =>  $knot['X'],
                                'y' =>  $knot['Y']
                            );

                            $polygon[] = $xy;

                            // "предыдущие" координаты все равно надо хранить.
                            $prev_knot_x = $xy['x'];
                            $prev_knot_y = $xy['y'];

                        } // if()
                    } // endif (polygon)
                    if ($is_debug) \var_dump($xy);
                    unset($xy);
                } // if ($LOOKAHEAD_FLAG == SVGPATH_UNDEFINED || $LOOKAHEAD_FLAG == SVGPATH_NORMAL_KNOT )

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_HORIZONTAL_ABS) {
                    if ($is_debug) echo "Горизонтальная линия по абсолютным координатам ", PHP_EOL;

                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<X>\-?\d+(\.\d+)?)#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  $knot['X'],
                            'y' =>  $prev_knot_y
                        );

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }
                }

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_HORIZONTAL_REL) {
                    if ($is_debug) echo "Горизонтальная линия по относительным координатам ", PHP_EOL;
                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<X>\-?\d+(\.\d+)?)#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  (float)$prev_knot_x + (float)$knot['X'],
                            'y' =>  (float)$prev_knot_y
                        );

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }
                } // ($LOOKAHEAD_FLAG == SVGPATH_HORIZONTAL_RELATIVE)

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_VERTICAL_ABS) {
                    if ($is_debug) echo "Вертикальная линия по абсолютным координатам ", PHP_EOL;
                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<Y>\-?\d+(\.\d+)?)#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  $prev_knot_x,
                            'y' =>  $knot['Y']
                        );

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }
                } // ($LOOKAHEAD_FLAG == SVGPATH_VERTICAL_ABSOLUTE)

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_VERTICAL_REL) {
                    if ($is_debug) echo "Вертикальная линия по относительным координатам ", PHP_EOL;
                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<Y>\-?\d+(\.\d+)?)#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  $prev_knot_x,
                            'y' =>  (float)$prev_knot_y + (float)$knot['Y']
                        );

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }


                } // ($LOOKAHEAD_FLAG == SVGPATH_VERTICAL_RELATIVE)

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_ABS) {
                    if ($is_debug) echo "Линия по абсолютным координатам ", PHP_EOL;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<X>\-?\d+(\.\d+)?)\,(?<Y>\-?\d+(\.\d+)?)+#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  $knot['X'],
                            'y' =>  $knot['Y']
                        );

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }

                } // ($LOOKAHEAD_FLAG == SVGPATH_LINETO_ABSOLUTE)

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_REL) {
                    if ($is_debug) echo "Линия по относительным координатам ", PHP_EOL;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<X>\-?\d+(\.\d+)?)\,(?<Y>\-?\d+(\.\d+)?)+#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  (float)$prev_knot_x + (float)$knot['X'],
                            'y' =>  (float)$prev_knot_y + (float)$knot['Y']
                        );

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }
                } // ($LOOKAHEAD_FLAG == SVGPATH_LINETO_ABSOLUTE)

                if ($is_debug && isset($xy)) var_dump($xy);
            } // endif (нет, это не управляющая последовательность)

        } while (!empty($path_fragments));

        // обработка мультиполигона

        if ($is_debug) \var_dump($multipolygon);

        return $multipolygon;
    } // convert_SVGElement_to_Polygon

    /**
     * @param SimpleXMLElement $element
     * @return array
     */
    private function convert_SVGElement_to_Circle( $element )
    {
        return [
            'x' =>  (string)$element->attributes()->{'cx'} ?? 0,
            'y' =>  (string)$element->attributes()->{'cy'} ?? 0
        ];
    }

    /**
     * @param SimpleXMLElement $element
     * @return array
     */
    private function convert_SVGElement_to_Rect($element)
    {
        $x = $element->attributes()->{'x'} ?? 0;
        $y = $element->attributes()->{'y'} ?? 0;
        $w = $element->attributes()->{'width'} ?? 0;
        $h = $element->attributes()->{'height'} ?? 0;

        //@todo: правильно будет поиграться в пределах точности
        // if (\round($w + $h, self::ROUND_PRECISION) == 0)

        if (0 == ($w + $h)) {
            return [];
        }

        return [
            [ 'x' => 0 + $x, 'y' => 0 + $y ],
            [ 'x' => $x + $w, 'y' => $y + $h ]
        ];

    }

    /**
     * Преобразует массив с данными мультиполигона в JS-строку ( [ [ [][] ], [ [][][] ] ])
     *
     * @param $multicoords
     * @return array|string
     */
    private function convert_CRS_to_JSString( $multicoords )
    {
        if (empty($multicoords)) {
            return '[]';
        }

        $js_coords_string = array();

        if (\count($multicoords) > 1) {
            \array_walk( $multicoords, function($sub_coords) use (&$js_coords_string) {
                $js_coords_string[] = $this->convert_subCRS_to_JSstring( $sub_coords );
            });
            return '[ ' . \implode(', ' , $js_coords_string) . ' ]';
        }

        return $this->convert_subCRS_to_JSstring( array_shift($multicoords));
    }

    /**
     * Преобразует информацию об узле в JS-строку
     *
     * @param $knot
     * @return string
     */
    private function convert_knotCRS_to_JSstring ( $knot )
    {
        return '[' . \implode(',', [ $knot['x'], $knot['y'] ]) . ']';
    }

    /**
     * Преобразует информацию о субполигоне (одиночном полигоне) в JS-строку
     *
     * @param $coords
     * @return string
     */
    private function convert_subCRS_to_JSstring( $coords )
    {
        $js_coords_string = [];

        \array_walk( $coords, function($knot) use (&$js_coords_string) {
            $js_coords_string[] = $this->convert_knotCRS_to_JSstring( $knot );
        });

        return '[ ' . \implode(', ' , $js_coords_string) . ' ]';
    }

    /* =================== EXPORT ==================== */

    /**
     * DEPRECATED
     * Подготавливает данные для экспорта в шаблон.
     * Используется ВМЕСТО шаблонизаторов.
     *
     * @param $all_paths
     * @return string
     */
    public function exportSPaths( $all_paths )
    {
        $all_paths_text = [];

        foreach($all_paths as $path_id => $path_data )
        {
            $coords_js = $path_data['js'];

            $path_data_text = <<<PDT
        '{$path_id}': {
PDT;
            $path_data_text .= "
            'id': '{$path_id}',
            'type': '{$path_data['type']}',
            'coords': {$coords_js}";

            if (\array_key_exists('fillColor', $path_data)) {
                $path_data_text .= ', ' . PHP_EOL . "            'fillColor' : '{$path_data['fillColor']}'";
            }
            if (\array_key_exists('fillOpacity', $path_data)) {
                $path_data_text .= ', ' . PHP_EOL . "            'fillOpacity' : '{$path_data['fillOpacity']}'";
            }
            if (\array_key_exists('fillRule', $path_data)) {
                $path_data_text .= ', ' . PHP_EOL . "            'fillRule' : '{$path_data['fillRule']}'";
            }
            if (\array_key_exists('title', $path_data)) {
                $path_data_text .= ', ' . PHP_EOL . "            'title' : '{$path_data['title']}'";
            }
            if (\array_key_exists('desc', $path_data)) {
                $path_data_text .= ', ' . PHP_EOL . "            'desc' : '{$path_data['desc']}'";
            }

            if (\array_key_exists('radius', $path_data)) {
                $path_data_text .= ', ' . PHP_EOL . "            'radius' : '{$path_data['radius']}'";
            }

            $path_data_text .= PHP_EOL.'        }';

            $all_paths_text[] = $path_data_text;
        }

        // массив строк оборачиваем запятой если нужно
        return \implode(',' . PHP_EOL, $all_paths_text);
    }

    /**
     * Хэлпер: рекурсивно проверяет существование указанного property в объекте
     *
     * @param $object
     * @param string $path
     * @param string $separator
     * @return bool
     */
    private static function property_exists_recursive($object, string $path, string $separator = '->'): bool
    {
        if (!\is_object($object)) {
            return false;
        }

        $properties = \explode($separator, $path);
        $property = \array_shift($properties);
        if (!\property_exists($object, $property)) {
            return false;
        }

        try {
            $object = $object->$property;
        } catch (\Throwable $e) {
            return false;
        }

        if (empty($properties)) {
            return true;
        }

        return self::property_exists_recursive($object, \implode('->', $properties));
    }





}