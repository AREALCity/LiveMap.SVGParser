<?php

namespace LiveMapEngine;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;
use Exception;
use SimpleXMLElement;

use Arris\Entity\Result;
use Arris\Toolkit\XMLNavigator\Convertation\FastXmlToArray;

use LiveMapEngine\SVGParser\Entity\CRSTranslationOptions;
use LiveMapEngine\SVGParser\Entity\LayerElementsTranslation;
use LiveMapEngine\SVGParser\Entity\ImageInfo;

#[\AllowDynamicProperties]
class SVGParser implements SVGParserInterface
{
    public const VERSION                        = 4.0;
    public const GIT_VERSION                    = '1.2.0';

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

    public const NAMESPACES = [
        'svg'       =>  'http://www.w3.org/2000/svg',
        'xlink'     =>  'http://www.w3.org/1999/xlink',
        'inkscape'  =>  'http://www.inkscape.org/namespaces/inkscape',
        'sodipodi'  =>  'http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd',
        'rdf'       =>  'http://www.w3.org/1999/02/22-rdf-syntax-ns#'
    ];

    /**
     * Весь SVG-объект
     *
     * @var SimpleXMLElement|null
     */
    private ?SimpleXMLElement $svg;

    /**
     * Массив с информацией об изображениях
     *
     * @var false|null|SimpleXMLElement[]
     */
    private array|null|false $layer_images = [];

    /**
     * Информация о сдвиге (transform translation) контейнера с изображениями на холсте
     *
     * @var LayerElementsTranslation
     */
    protected LayerElementsTranslation $layer_images_translation;

    /**
     * данные трансляции из модели CSV XY в Screen CRS
     *
     * @var CRSTranslationOptions
     */
    protected CRSTranslationOptions $crs_translation_options;

    /**
     * Имя текущего слоя-контейнера с данными
     *
     * @var string
     */
    public string $layer_name = '';

    //
    /**
     * Текущий слой-контейнер с данными.
     *
     * @var SimpleXMLElement|null
     */
    public ?SimpleXMLElement $layer_elements = null;

    //
    /**
     * Сдвиг (translate) элементов на текущем слое
     *
     * @var LayerElementsTranslation
     */
    public LayerElementsTranslation $layer_elements_translation;

    /**
     * Конфиг текущего слоя
     *
     * @var stdClass|null
     */
    private ?stdClass $layer_elements_config = null;

    /**
     * Статус для анализа
     *
     * @var Result
     */
    public Result $parser_state;

    /* =========== Опции =========== */

    /**
     * Внутренние опции парсера, меняющие поведение. Задаются через конструктор.
     *
     * @var array
     */
    private array $INTERNAL_OPTIONS = [

    ];

    /**
     * Точность округления
     *
     * @var int
     */
    private int $ROUND_PRECISION = 4;

    /**
     * false: эллипсы приводятся к окружностям
     * true: парсим эллипсы, на стороне JS требуется плагин
     * @var bool
     */
    private bool $PARSER_ALLOW_ELLIPSE = false;

    /**
     * Разрешать ли возвращать пустые элементы (например, окружности с радиусом меньшим порога точности)?
     *
     * @var bool
     */
    private bool $OPTION_ALLOW_EMPTY_ELEMENTS = false;

    private LoggerInterface $logger;

    /**
     * @inheritDoc
     */
    public function __construct($svg_file_content = '', array $options = [], LoggerInterface $logger = null)
    {
        \libxml_use_internal_errors(true);
        $this->parser_state = new Result();

        if (isset($options['allowEmptyElements'])) {
            $this->OPTION_ALLOW_EMPTY_ELEMENTS = (bool)$options['allowEmptyElements'];
        }

        if (isset($options['allowEllipse'])) {
            $this->PARSER_ALLOW_ELLIPSE = (bool)$options['allowEllipse'];
        }

        if (isset($options['roundPrecision'])) {
            $this->ROUND_PRECISION = (int)$options['roundPrecision'];
        }

        $registerNamespaces = true;
        if (isset($options['registerNamespaces'])) {
            $registerNamespaces = (bool)$options['registerNamespaces'];
        }

        $this->logger = is_null($logger) ? new NullLogger() : $logger;

        try {
            if (empty($svg_file_content)) {
                throw new Exception("Given SVG content is empty");
            }

            $this->svg = new SimpleXMLElement( $svg_file_content );

            if ($registerNamespaces) {
                foreach (self::NAMESPACES as $ns => $definition) {
                    $this->svg->registerXPathNamespace( $ns, $definition );
                }
            }

            $this->layer_images_translation = new LayerElementsTranslation(0, 0);
            $this->layer_elements_translation = new LayerElementsTranslation(0, 0);

            $this->crs_translation_options = new CRSTranslationOptions();

        } catch (Exception $e) {
            $this->parser_state
                ->error()
                ->setMessage($e->getMessage())
                ->setCode( $e->getCode());
        }
    }

    /**
     * @inheritDoc
     */
    public function parseImages( $layer_name ):bool
    {
        if ($layer_name === '') {
            $xpath_images = '//svg:image';
        } else {
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
                $this->layer_images_translation = $this->parseTransform($images_layer_attrs->attributes()->{'transform'});
            }

            $xpath_images = '//svg:g[starts-with(@inkscape:label, "' . $layer_name . '")]/svg:image';

        }

        $this->layer_images = $this->svg->xpath($xpath_images);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getImagesCount(): int
    {
        return is_array($this->layer_images) ? count($this->layer_images) : 0;
    }

    /**
     * Возвращает параметры сдвига при трансформации-переносе
     * Атрибут
     *
     * transform="translate(0,1052.36)"
     *
     * @param $transform_definition
     * @return LayerElementsTranslation(ox, oy)
     */
    private function parseTransform($transform_definition): LayerElementsTranslation
    {
        if (empty($transform_definition)) {
            return new LayerElementsTranslation(0, 0);
        }

        if (preg_match('/translate\(\s*([^\s,)]+)[\s,]([^\s,)]+)/', $transform_definition, $matches) && count($matches) > 2) {
            return new LayerElementsTranslation(
                (float)$matches[1],
                (float)$matches[2]
            );
        }

        return new LayerElementsTranslation(0, 0);
    }

    /**
     * Возвращает параметры поворота при трансформации-повороте
     * Значение атрибута ожидается вида "rotate(45.8)"
     *
     * @param string $transform_definition
     * @return float
     */
    private function parseTransformRotate(string $transform_definition):float
    {
        if (empty($transform_definition)) {
            return 0;
        }

        if (1 == \preg_match('/rotate\([+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)\)/', $transform_definition, $matches)) {
            return (float)$matches[1];
        }

        return 0;
    }

    /**
     * @inheritDoc
     */
    public function getImageInfo(int $index = 0):ImageInfo
    {
        $i = new ImageInfo(is_present: false);

        if (
            is_array($this->layer_images) &&
            array_key_exists($index, $this->layer_images)
        ) {
            /**
             * @var SimpleXMLElement $an_image
             */
            $an_image = $this->layer_images[ $index ];
            $attrs = $an_image->attributes();

            $i->width = (float)$attrs->{'width'} ?? 0;
            $i->height = (float)$attrs->{'height'} ?? 0;

            $an_image_offset_x = (float)$attrs->{'x'} ?? 0;
            $an_image_offset_y = (float)$attrs->{'y'} ?? 0;

            $an_image_translate_x = (float)$this->layer_images_translation->{'ox'} ?? 0;
            $an_image_translate_y = (float)$this->layer_images_translation->{'oy'} ?? 0;

            /**
             * Выносили в переменные, иначе может случится херня:
             *
             * $a ?? 0 + $b ?? 0 ;
             *
             * трактуется как
             *
             * $a ?? ( 0 + $b ?? 0  )
             *
             * то есть у ?? приоритет меньше чем у +
             */
            $i->ox = $an_image_offset_x + $an_image_translate_x;
            $i->oy = $an_image_offset_y + $an_image_translate_y;

            $i->xhref = $an_image->attributes('xlink', true)->{'href'} ?? '';

            $i->precision = \round($i->width, $this->ROUND_PRECISION);
            $i->is_present = true;
        }

        return $i;
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
        if ($ox !== null && $oy !== null && $image_height !== null) {
            $this->crs_translation_options = new CRSTranslationOptions($ox, $oy, $image_height);
        }
    }

    /**
     * Парсит один элемент на слое
     *
     * @param SimpleXMLElement $element
     * @param string $type
     * @return array
     */
    public function parseAloneElement(SimpleXMLElement $element, string $type): array
    {
        // блок данных о пути
        $data = [
            'valid'         =>  true,
            'id'            =>  (string)$element->attributes()->{'id'},
            'type'          =>  $type,
            'layer'         =>  $this->layer_name,
            'coords'        =>  [],
            'interactive'   =>  [],
            'js'            =>  ''
        ];

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

        $data['label'] = $element_attributes["inkscape:label"] ?? $data['id'];

        // если это фигура типа ellipse И парсеру не разрешено распознавать ellipse,
        // то трактуем фигуру как окружность с приведением к эллипсу
        if ($type === "ellipse" && !$this->PARSER_ALLOW_ELLIPSE) {
            $type = "ellipse-as-circle";
        }

        switch ($type) {
            /*
             * За POI-маркер отвечает Inkscape-элемент SPIRALE (точкой установки маркера является ЦЕНТР спирали)
             * Поддерживается только в INKSCAPE-файлах с нэймспейсом sodipodi
             */
            case 'marker': {
                $data['type'] = 'marker';
                $coords = [
                    'x'     =>  $element_attributes['sodipodi:cx'],
                    'y'     =>  $element_attributes['sodipodi:cy'],
                ];
                $data['coords'] = $this->translate_knot_from_XY_to_CRS( $coords );

                $data['js'] = $this->convert_knotCRS_to_JSstring( $data['coords'] );

                break;
            }

            case 'path' : {
                // кол-во путей ~
                // кол-во узлов ~
                $data['type'] = 'polygon';

                // SVG Path -> Polygon
                $data['coords'] = $this->convert_SVGElement_to_Polygon( $element );

                if (empty($data['coords'])) {
                    $data['valid'] = false;
                    break;
                }

                // сдвиг координат и преобразование в CRS-модель
                $data['coords'] = $this->translate_polygon_from_XY_to_CRS( $data['coords'] );

                $data['js'] = $this->convert_CRS_to_JSString( $data['coords'] );

                break;
            }
            case 'circle': {
                // кол-во путей 1
                // кол-во узлов 1
                $data['type'] = 'circle';

                $r = $element->attributes()->{'r'} ?? 0;
                $data['radius'] = \round((float)$r, $this->ROUND_PRECISION);

                if ($data['radius'] == 0) {
                    $data['valid'] = false;
                    break;
                }

                // SVG Path -> Polygon
                $data['coords'] = $this->convert_SVGElement_to_Circle( $element );

                // сдвиг координат и преобразование в CRS-модель
                $data['coords'] = $this->translate_knot_from_XY_to_CRS( $data['coords'] );

                $data['js'] = $this->convert_knotCRS_to_JSstring( $data['coords'] );

                break;
            }
            case 'rect': {
                // кол-во путей 1
                // кол-во узлов 2
                // x, y
                $data['type'] = 'rect';
                $coords = $this->convert_SVGElement_to_Rect( $element );

                $data['coords'][] = [
                    $this->translate_knot_from_XY_to_CRS( $coords[0] ),
                    $this->translate_knot_from_XY_to_CRS( $coords[1] )
                ];

                $data['js'] = $this->convert_CRS_to_JSString($data['coords'] );

                break;
            }
            case 'ellipse-as-circle': {
                $data['type'] = 'circle';

                $rx = $element->attributes()->{'rx'} ?? 0;
                $ry = $element->attributes()->{'ry'} ?? 0;

                // приводим параметры эллипса к окружности, по этой же причине анализировать атрибут transform=rotate не будем
                $data['radius'] = \round( ( (float)$rx + (float)$ry ) /2 , $this->ROUND_PRECISION);

                // SVG Element to coords
                $coords = $this->convert_SVGElement_to_Circle( $element );

                // сдвиг координат и преобразрвание в CRS-модель
                $coords = $this->translate_knot_from_XY_to_CRS( $coords );

                $data['coords'] = $coords;
                $data['js'] = $this->convert_knotCRS_to_JSstring( $data['coords'] );

                break;
            }
            case 'ellipse': {
                $data['type'] = 'ellipse';

                $center = [
                    'x' =>  (string)$element->attributes()->{'cx'} ?? 0,
                    'y' =>  (string)$element->attributes()->{'cy'} ?? 0
                ];

                $rx = $element->attributes()->{'rx'} ?? 0;
                $ry = $element->attributes()->{'ry'} ?? 0;

                if (
                    (
                        \round($rx, $this->ROUND_PRECISION) +
                        \round($ry, $this->ROUND_PRECISION)
                    ) == 0
                ) {
                    return [];
                }

                //@todo: еще нужно обрабатывать атрибут поворота (нужен пример)
                // "transform="rotate(45.8)"
                $transform = $element->attributes()->{'transform'} ?? '';
                $rotate = $this->parseTransformRotate($transform);

                // сдвиг координат и преобразование в CRS-модель
                $center = $this->translate_knot_from_XY_to_CRS( $center );
                $data['coords'] = $center;

                $data['js']
                    = '[ ['
                    . implode(',', $center)
                    . '], ['
                    . implode(',', [ $rx, $ry ])
                    . '], '
                    . $rotate
                    . ']'
                ;
                break;
            }
        }

        // получем информацию об атрибутах региона из SVG-разметки
        $this->parseStyleAttributes($data, $path_style);

        // Parse titles and descriptions
        $this->parseTitlesAndDescriptions($data, $element);

        // Parse interactive attributes
        $this->parseInteractiveAttributes($data, $element_attributes);

        return $data;
    } // parseAloneElement

    private function parseInteractiveAttributes(array &$data, array $element_attributes):void
    {
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
            $possible_interactive_fields as $field) {
            if (isset($element_attributes[$field])) {
                $data['interactive'][$field] = $element_attributes[$field];
            }
        }
    }

    /**
     * Парсим атрибуты стилей для элемента
     *
     * @param array $data
     * @param string $style
     * @return void
     */
    private function parseStyleAttributes(array &$data, string $style): void
    {
        // Parse fill color
        if (preg_match('#fill:([\#\d\w]{7})#', $style, $matches)) {
            $data['fillColor'] = $matches[1];
        }

        // Parse fill opacity
        if (preg_match('#fill-opacity:([\d]?\.[\d]{0,8})#', $style, $matches)) {
            $data['fillOpacity'] = round($matches[1], $this->ROUND_PRECISION);
        }

        // Parse fill rule
        if (preg_match('#fill-rule:(evenodd|nonzero)#', $style, $matches) && $matches[1] !== 'evenodd') {
            $data['fillRule'] = $matches[1];
        }

        // // кастомные значения для пустых регионов
        if ($this->layer_elements_config) {
            $this->applyDefaultEmptyStyles($data);
        }
    }

    /**
     * Apply default empty element styles if configured
     *
     * @param array $data
     * @return void
     */
    private function applyDefaultEmptyStyles(array &$data): void
    {
        if (
            self::property_exists_recursive($this->layer_elements_config, 'empty->fill') &&
            $this->layer_elements_config->empty->fill == 1
        ) {

            if (
                self::property_exists_recursive($this->layer_elements_config, 'empty->fillColor') &&
                $this->layer_elements_config->empty->fillColor &&
                !isset($data['fillColor'])
            ) {
                $data['fillColor'] = $this->layer_elements_config->empty->fillColor;
            }

            if (
                self::property_exists_recursive($this->layer_elements_config, 'empty->fillOpacity') &&
                $this->layer_elements_config->empty->fillOpacity &&
                !isset($data['fillOpacity'])
            ) {
                $data['fillOpacity'] = $this->layer_elements_config->empty->fillOpacity;
            }
        }

        if (self::property_exists_recursive($this->layer_elements_config, 'empty->stroke') &&
            $this->layer_elements_config->empty->stroke == 1) {

            if (
                self::property_exists_recursive($this->layer_elements_config, 'empty->borderColor') &&
                $this->layer_elements_config->empty->borderColor &&
                isset($data['borderColor'])
            ) {
                $data['borderColor'] = $this->layer_elements_config->empty->borderColor;
            }

            if (
                self::property_exists_recursive($this->layer_elements_config, 'empty->borderWidth') &&
                $this->layer_elements_config->empty->borderWidth &&
                isset($data['borderWidth'])
            ) {
                $data['borderWidth'] = $this->layer_elements_config->empty->borderWidth;
            }

            if (
                self::property_exists_recursive($this->layer_elements_config, 'empty->borderOpacity') &&
                $this->layer_elements_config->empty->borderOpacity &&
                isset($data['borderOpacity'])
            ) {
                $data['borderOpacity'] = $this->layer_elements_config->empty->borderOpacity;
            }
        }
    }

    private function parseTitlesAndDescriptions(array &$data, SimpleXMLElement $element): void
    {
        if (isset($element->{'title'}[0])) {
            $data['title'] = htmlspecialchars((string)$element->{'title'}[0], ENT_QUOTES | ENT_HTML5);
        }

        if (isset($element->{'desc'}[0])) {
            $data['desc'] = htmlspecialchars((string)$element->{'desc'}[0], ENT_QUOTES | ENT_HTML5);
        }
    }

    /**
     * Получаем элементы по типу (rect, circle, path)
     *
     * @param $type
     * @return array
     */
    public function getElementsByType($type): array
    {
        /** @var SimpleXMLElement $path */
        $all_paths = [];

        if (!isset($this->layer_elements->{$type})) {
            return $all_paths;
        }

        foreach ($this->layer_elements->{$type} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};

            $parsed_element = $this->parseAloneElement($path, $type);
            $parsed_element_is_valid = $parsed_element['valid'];
            unset($parsed_element['valid']);

            if ($parsed_element_is_valid || $this->OPTION_ALLOW_EMPTY_ELEMENTS) {
                $all_paths[$path_id] = $parsed_element;
            }

            // $all_paths[ $path_id ] = $parsed_element;
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

            if ($element['valid']) {
                $all_paths[ $path_id ] = $element;
            }
        }

        foreach ($this->layer_elements->{'rect'} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};
            $element    = $this->parseAloneElement($path, 'rect');
            if ($element['valid']) {
                $all_paths[ $path_id ] = $element;
            }
        }

        foreach ($this->layer_elements->{'circle'} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};
            $element    = $this->parseAloneElement($path, 'circle');
            if ($element['valid']) {
                $all_paths[ $path_id ] = $element;
            }
        }

        foreach ($this->layer_elements->{'ellipse'} as $path) {
            $path_id    = (string)$path->attributes()->{'id'};
            $element    = $this->parseAloneElement($path, 'ellipse');
            if ($element['valid']) {
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
    public function setLayerDefaultOptions(stdClass $options): void
    {
        $this->layer_elements_config = $options;
    }

    /**
     * Применяет трансформацию к узлу. Если не заданы опции трансформации - используются данные для трансформации слоя
     *
     * Тоже не используется, поскольку не вызывается предок apply_transform_to_subpolygon()
     *
     * @param $knot
     * @param array $options - точно ли null|array ? Или класс?
     * @return array
     */
    private function apply_transform_for_knot($knot , array $options = []): array
    {
        $ox = $options['ox'] ?? $this->layer_elements_translation->ox;
        $oy = $options['oy'] ?? $this->layer_elements_translation->oy;

        return [
            'x' =>  $knot['x'] + $ox,
            'y' =>  $knot['y'] + $oy
        ];
    }

    /**
     * Применяет трансформацию к субполигону
     * Реально не используется, поскольку не вызвается родитель apply_transform_to_polygon()
     *
     * @param $subpolyline
     * @param array $options
     * @return array|array[]
     */
    private function apply_transform_to_subpolygon($subpolyline, array $options = []): array
    {
        return array_map( function($knot) use ($options) {
            return $this->apply_transform_for_knot( $knot, $options );
        }, $subpolyline);
    }

    /**
     * Применяет трансформацию к мультиполигону
     *
     * @param $polygon
     * @param $options
     * @return array|array[]|\array[][]
     */
    private function apply_transform_to_polygon( $polygon, array $options = []): array
    {
        if (empty($polygon)) {
            return array();
        }

        return
            ( count($polygon) > 1 )
                ? array_map( function($subpoly) use ($options) {
                    return $this->apply_transform_to_subpolygon($subpoly, $options);
                  }, $polygon )
                : array(
                    $this->apply_transform_to_subpolygon( array_shift($polygon), $options)
                  );
    }

    /**
     * convert CRS (SVG) to Simple
     *
     * @param $polygon
     * @return array|array[]|\array[][]
     */
    private function convert_to_SimpleCRS_polygon( $polygon ): array
    {
        if ( empty($polygon) ) {
            return array();
        }

        return
            ( \count($polygon) > 1 )    // если суб-полигонов больше одного
                ?
                // проходим по всем
                array_map( function($subpath) {
                    return $this->convert_to_SimpleCRS_subpolygon( $subpath );
                }, $polygon )
                :
                // иначе возвращаем первый элемент массива субполигонов, но как единственный элемент массива!
                array(
                    $this->convert_to_SimpleCRS_subpolygon( array_shift($polygon) )
                );
    }

    /**
     * Не используется
     *
     * @param $subpolygon
     * @return array|array[]
     */
    private function convert_to_SimpleCRS_subpolygon( $subpolygon ): array
    {
        return array_map( function($knot) {
            return $this->convert_to_SimpleCRS_knot( $knot );
        }, $subpolygon);
    }

    /**
     * @param $knot
     * @return array
     */
    private function convert_to_SimpleCRS_knot( $knot ): array
    {
        $ox = 0;
        $oy = 0;
        $height = 0; // height inversion

        // (X, Y) => (Height - (Y-oY) , (X-oX)
        return [
            'x'     =>  \round( $height - ($knot['y'] - $oy), $this->ROUND_PRECISION),
            'y'     =>  \round(           ($knot['x'] - $ox), $this->ROUND_PRECISION)
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
            $ox += $this->layer_elements_translation->ox;
            $oy += $this->layer_elements_translation->oy;
        }

        if ($this->crs_translation_options) {
            $ox += $this->crs_translation_options->ox;
            $oy += $this->crs_translation_options->oy;
            $height = $this->crs_translation_options->height;
        }

        // (X, Y) => (Height - (Y-oY) , (X-oX)
        return [
            'x'     =>  \round( $height - ($knot['y'] - $oy) , $this->ROUND_PRECISION),
            'y'     =>  \round( $knot['x'] - $ox, $this->ROUND_PRECISION)
        ];
    }

    /**
     * Преобразует субполигон из XY-модели в CRS-модель
     * @param $subpolyline
     * @return array
     */
    private function translate_subpolygon_from_XY_to_CRS( $subpolyline ): array
    {
        return array_map( function($knot) {
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
            ( count($polygone) > 1 )    // если суб-полигонов больше одного
                ?                           // проходим по всем
                array_map( function($subpath) {
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
     * Возвращает массив пар координат ИЛИ [] в случае невозможности преобразования.
     * Невозможно преобразовать кривые Безье любого вида. В таком случае возвращается пустой массив.
     *
     * Эта функция не выполняет сдвиг или преобразование координат! У неё нет для этого данных.
     *
     * @param SimpleXMLElement $element
     * @return array
     */
    private function convert_SVGElement_to_Polygon(SimpleXMLElement $element): array
    {
        // @var SimpleXMLElement $element
        $path     = (string)$element->attributes()->{'d'};    // получаем значение атрибута <path d="">
        $path     = trim($path);                        // обрезаем начальные и конечные пробелы

        $xy = [];

        // пуст ли путь?
        if (empty($path)) {
            return [];
        }

        // Если путь не заканчивается на z/Z - это какая-то херня, а не путь. Отбрасываем. Хотя символ Z опциональный,
        // мы работаем только с замкнутыми полигонами, в котором Z должен быть
        if ( 'z' !== \strtolower(\substr($path, -1)) ) {
            return [];
        }

        /*
        Z. Z (or z, it doesn’t matter) “closes” the path. Like any other command, it’s optional.
        It’s a cheap n’ easy way to draw a straight line directly back to the last place the “pen”
        was set down (probably the last M or m command). It saves you from having to repeat that
        first location and using a line command to get back there.

        Хотя в официальной документации ничего не сказано про "необязательность", только

        A closed subpath must be closed with a "closepath" command, this "joins" the first and last path segments.
        */

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

        // предполагается, что это не минифицированный SVG...
        $path_fragments = \explode(' ', $path);

        $polygon = [];             // массив узлов полигона
        $multipolygon = [];        // Массив, содержащий все полигоны. Если в нём один элемент - то у фигуры один полигон.

        $polygon_is_relative = null;    // тип координат: TRUE - Относительные, false - абсолютные, null - не определено
        $prev_knot_x = 0;               // X-координата предыдущего узла
        $prev_knot_y = 0;               // Y-координата предыдущего узла

        $path_start_x = 0;              // X-координата начала текущего пути
        $path_start_y = 0;              // Y-координата начала текущего пути

        $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

        do {
            $fragment = \array_splice($path_fragments, 0, 1)[0];

            $this->logger->debug("Извлеченный фрагмент : ", [ $fragment ]);

            if ( $fragment === 'Z') {
                $fragment = 'z';
            }

            if ( \strpbrk($fragment, 'MmZzHhVvLl') ) {
                $LOOKAHEAD_FLAG = match ($fragment) {
                    'M' => self::PATHSEG_MOVETO_ABS,
                    'm' => self::PATHSEG_MOVETO_REL,
                    'z' => self::PATHSEG_CLOSEPATH,
                    'h' => self::PATHSEG_LINETO_HORIZONTAL_REL,
                    'H' => self::PATHSEG_LINETO_HORIZONTAL_ABS,
                    'v' => self::PATHSEG_LINETO_VERTICAL_REL,
                    'V' => self::PATHSEG_LINETO_VERTICAL_ABS,
                    'l' => self::PATHSEG_LINETO_REL,
                    'L' => self::PATHSEG_LINETO_ABS,
                };

                // обработка управляющей последовательности Z
                if ($LOOKAHEAD_FLAG === self::PATHSEG_CLOSEPATH) {
                    $multipolygon[] = $polygon; // добавляем суб-полигон к полигону
                    $polygon = [];         // очищаем массив узлов суб-полигона
                }

                $this->logger->debug("Это управляющая последовательность. Параметры будут обработаны на следующей итерации.");
                continue;
            } else {
                $this->logger->debug("Это числовая последовательность, запускаем обработчик : ");

                /**
                 * Раньше этот блок данных обрабатывался внутри назначения обработчиков.
                 * Сейчас я его вынес наружу. Это может вызвать в перспективе некоторые глюки, нужны тесты
                 */
                if ($LOOKAHEAD_FLAG == self::PATHSEG_MOVETO_REL) {

                    $this->logger->debug("m : Начало поли-линии с относительными координатами ");

                    $polygon_is_relative = true;

                    //@todo: Подумать над ускорением преобразования (ЧИСЛО,ЧИСЛО)

                    $pattern = '#(?<X>\-?\d+(\.\d+)?)\,(?<Y>\-?\d+(\.\d+)?)+#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    // так как путь относительный, moveto делается относительно предыдущего положения "пера"
                    // вообще, скорее всего, нам не нужны совсем переменные $path_start_x и $path_start_y
                    $path_start_x = $prev_knot_x;
                    $path_start_y = $prev_knot_y;

                    if ($matches_count > 0) {
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

                    $this->logger->debug("XY: ", [ $xy ]);

                    continue; // ОБЯЗАТЕЛЬНО делаем continue, иначе управление получит следующий блок
                }

                if ($LOOKAHEAD_FLAG == self::PATHSEG_MOVETO_ABS) {
                    $this->logger->debug("M : Начало полилинии с абсолютными координатами ");

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

                    $this->logger->debug("XY", [ $xy ]);

                    continue; // ОБЯЗАТЕЛЬНО делаем continue, иначе управление получит следующий блок
                }

                if ($LOOKAHEAD_FLAG == self::PATHSEG_UNDEFINED || $LOOKAHEAD_FLAG == self::PATHSEG_REGULAR_KNOT ) {
                    $this->logger->debug("Обычная числовая последовательность ");

                    // проверяем валидность пары координат
                    //@todo: Подумать над ускорением проверки (ЧИСЛО,ЧИСЛО)
                    //@todo: формат с запятыми - это inkscape-friendly запись. Стандарт считает, что запятая не нужна и числа идут просто парами через пробел.

                    $pattern = '#(?<X>\-?\d+(\.\d+)?)\,(?<Y>\-?\d+(\.\d+)?)+#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    // Если это неправильная комбинация float-чисел - пропускаем обработку и идем на след. итерацию
                    if ($matches_count == 0) {
                        $this->logger->debug("... которая не содержит [float,float], пропускаем итерацию");
                        continue;
                    }
                    // здесь я использую такую конструкцию чтобы не брать стену кода в IfTE-блок.

                    if (empty($polygon)) {
                        // возможно обработку первого узла следует перенести в другой блок (обработчик флага SVGPATH_START_ABSOULUTE или SVGPATH_START_RELATIVE)
                        $this->logger->debug('Это первый узел. Он всегда задается в абсолютных координатах! ');

                        $xy = array(
                            'x' =>  (float)$prev_knot_x + (float)$knot['X'],
                            'y' =>  (float)$prev_knot_y + (float)$knot['Y']
                        );

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    } else {
                        $this->logger->debug('Это не первый узел в мультилинии');

                        if ($polygon_is_relative) {
                            $this->logger->debug("его координаты относительные и даны относительно предыдущего узла полилинии ");

                            $xy = array(
                                'x' =>  (float)$prev_knot_x + (float)$knot['X'],
                                'y' =>  (float)$prev_knot_y + (float)$knot['Y']
                            );

                            $polygon[] = $xy;

                            $prev_knot_x = $xy['x'];
                            $prev_knot_y = $xy['y'];

                        } else {
                            $this->logger->debug("Его координаты абсолютные");

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
                    $this->logger->debug("XY: ", [ $xy ]);
                    unset($xy);
                } // if ($LOOKAHEAD_FLAG == SVGPATH_UNDEFINED || $LOOKAHEAD_FLAG == SVGPATH_NORMAL_KNOT )

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_HORIZONTAL_ABS) {
                    $this->logger->debug("Горизонтальная линия по абсолютным координатам ");

                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<X>\-?\d+(\.\d+)?)#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count == 0) {
                        $this->logger->debug("... но определение координат не [float], пропускаем итерацию");
                        continue;
                    }

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  $knot['X'],
                            'y' =>  $prev_knot_y
                        );

                        $this->logger->debug("XY: ", [ $xy ]);

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }
                }

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_HORIZONTAL_REL) {
                    $this->logger->debug("Горизонтальная линия по относительным координатам ");

                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<X>\-?\d+(\.\d+)?)#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  (float)$prev_knot_x + (float)$knot['X'],
                            'y' =>  (float)$prev_knot_y
                        );

                        $this->logger->debug("XY: ", [ $xy ]);

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }
                } // ($LOOKAHEAD_FLAG == SVGPATH_HORIZONTAL_RELATIVE)

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_VERTICAL_ABS) {
                    $this->logger->debug("Вертикальная линия по абсолютным координатам ");

                    $LOOKAHEAD_FLAG = self::PATHSEG_UNDEFINED;

                    //@todo: Подумать над ускорением проверки (ЧИСЛО)
                    $pattern = '#(?<Y>\-?\d+(\.\d+)?)#';
                    $matches_count = \preg_match($pattern, $fragment, $knot);

                    if ($matches_count > 0) {
                        $xy = array(
                            'x' =>  $prev_knot_x,
                            'y' =>  $knot['Y']
                        );

                        $this->logger->debug("XY: ", [ $xy ]);

                        $polygon[] = $xy;

                        $prev_knot_x = $xy['x'];
                        $prev_knot_y = $xy['y'];
                    }
                } // ($LOOKAHEAD_FLAG == SVGPATH_VERTICAL_ABSOLUTE)

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_VERTICAL_REL) {
                    $this->logger->debug("Вертикальная линия по относительным координатам ");

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

                        $this->logger->debug("XY: ", [ $xy ]);
                    }
                } // ($LOOKAHEAD_FLAG == SVGPATH_VERTICAL_RELATIVE)

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_ABS) {
                    $this->logger->debug("Линия по абсолютным координатам ");

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

                        $this->logger->debug("XY: ", [ $xy ]);
                    }

                } // ($LOOKAHEAD_FLAG == SVGPATH_LINETO_ABSOLUTE)

                if ($LOOKAHEAD_FLAG == self::PATHSEG_LINETO_REL) {
                    $this->logger->debug("Линия по относительным координатам ");

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

                        $this->logger->debug("XY: ", [ $xy ]);
                    }
                } // ($LOOKAHEAD_FLAG == SVGPATH_LINETO_ABSOLUTE)

            } // endif (нет, это не управляющая последовательность)

        } while (!empty($path_fragments));

        // обработка мультиполигона

        $this->logger->debug("multipolygon: ", [ $multipolygon ]);

        return $multipolygon;
    } // convert_SVGElement_to_Polygon

    /**
     * @param SimpleXMLElement $element
     * @return array
     */
    private function convert_SVGElement_to_Circle(SimpleXMLElement $element): array
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
    private function convert_SVGElement_to_Rect(SimpleXMLElement $element): array
    {
        $x = $element->attributes()->{'x'} ?? 0;
        $y = $element->attributes()->{'y'} ?? 0;
        $w = $element->attributes()->{'width'} ?? 0;
        $h = $element->attributes()->{'height'} ?? 0;

        // не рисуем rect если высота или ширина меньше предела точности
        if (0 == (
            round($w, $this->ROUND_PRECISION) +
            round($h, $this->ROUND_PRECISION))
        ) {
            return [];
        }

        return [
            [
                'x' => 0 + $x,
                'y' => 0 + $y
            ],
            [
                'x' => $x + $w,
                'y' => $y + $h
            ]
        ];

    }

    /**
     * Преобразует массив с данными мультиполигона в JS-строку ( [ [ [][] ], [ [][][] ] ])
     *
     * @param array $multicoords
     * @return string
     */
    private function convert_CRS_to_JSString(array $multicoords):string
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
     * @param array $knot
     * @return string
     */
    private function convert_knotCRS_to_JSstring(array $knot): string
    {
        return '[' . \implode(',', [ $knot['x'], $knot['y'] ]) . ']';
    }

    /**
     * Преобразует информацию о субполигоне (одиночном полигоне) в JS-строку
     *
     * @param $coords
     * @return string
     */
    private function convert_subCRS_to_JSstring( $coords ): string
    {
        $js_coords_string = [];

        array_walk( $coords, function($knot) use (&$js_coords_string) {
            $js_coords_string[] = $this->convert_knotCRS_to_JSstring( $knot );
        });

        return '[ ' . implode(', ' , $js_coords_string) . ' ]';
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

        foreach ($all_paths as $path_id => $path_data )
        {
            $coords_js = $path_data['js'];

            $path_data_text  = "        '{$path_id}': {";
            $path_data_text .= "            'id': '{$path_id}',";
            $path_data_text .= "            'type': '{$path_data['type']}',";
            $path_data_text .= "            'coords': {$coords_js}";

            if (isset($path_data['fillColor'])) {
                $path_data_text .= ', ' . PHP_EOL . "            'fillColor' : '{$path_data['fillColor']}'";
            }
            if (isset($path_data['fillOpacity'])) {
                $path_data_text .= ', ' . PHP_EOL . "            'fillOpacity' : '{$path_data['fillOpacity']}'";
            }
            if (isset($path_data['fillRule'])) {
                $path_data_text .= ', ' . PHP_EOL . "            'fillRule' : '{$path_data['fillRule']}'";
            }
            if (isset($path_data['title'])) {
                $path_data_text .= ', ' . PHP_EOL . "            'title' : '{$path_data['title']}'";
            }
             if (isset($path_data['desc'])) {
                 $path_data_text .= ', ' . PHP_EOL . "            'desc' : '{$path_data['desc']}'";
             }
             if (isset($path_data['radius'])) {
                 $path_data_text .= ', ' . PHP_EOL . "            'radius' : '{$path_data['radius']}'";
             }

            $path_data_text .= PHP_EOL.'        }';

            $all_paths_text[] = $path_data_text;
        }

        // массив строк оборачиваем запятой если нужно
        return implode(',' . PHP_EOL, $all_paths_text);
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
        if (!is_object($object)) {
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

#-eof-#