<?php

namespace LiveMapEngine;

use LiveMapEngine\SVGParser\Entity\ImageInfo;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use stdClass;

interface SVGParserInterface
{
    /**
     * Создает экземпляр класса
     *
     * @param $svg_file_content
     * @param array $options
     */
    public function __construct( $svg_file_content, array $options = [], LoggerInterface $logger = null );

    /**
     * Парсит информацию об изображениях. Передается имя слоя (в противном случае изображения ищутся по всей SVG)
     * Изображения у нас обычно задают фон (изображение с индексом 0)
     *
     * @param $layer_name
     * @return bool
     */
    public function parseImages( $layer_name ):bool;

    /**
     * Возвращает количество изображений на слое
     *
     * @return int
     */
    public function getImagesCount(): int;

    /**
     * Возвращает информацию об изображении с переданным индексом как структуру ImageInfo
     *
     * @param int $index
     * @return ImageInfo
     */
    public function getImageInfo(int $index = 0):ImageInfo;

    /**
     * Парсит объекты на определенном слое (или по всему файлу)
     *
     * @param $layer_name
     * @return bool
     */
    public function parseLayer($layer_name): bool;

    /**
     * Устанавливает опции трансляции данных слоя из модели CRS.XY в модель CRS.Simple
     *
     * Если не вызывали - трансляция не производится
     * @param $ox
     * @param $oy
     * @param $image_height
     */
    public function set_CRSSimple_TranslateOptions($ox = null , $oy = null, $image_height = null);

    /**
     * Парсит один элемент на слое
     *
     * @param SimpleXMLElement $element
     * @param string $type
     * @return array
     */
    public function parseAloneElement(SimpleXMLElement $element, string $type):array;

    /**
     * Получаем элементы по типу (rect, circle, path)
     *
     * @param $type
     * @return array
     */
    public function getElementsByType( $type );

    /**
     * Устанавливает конфигурационные значения по-умолчанию у регионов для текущего слоя
     *
     * @param stdClass $options
     * @return void
     */
    public function setLayerDefaultOptions(stdClass $options);

    /**
     * Получаем все элементы со слоя
     * Это основная "экспортная" функция
     *
     * @return array
     */
    public function getElementsAll():array;

}