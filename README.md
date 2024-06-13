# SVG to JS parser/convertor for LiveMapEngine project

```php
$svg_content = \file_get_contents( $svg_filename );

$parser = new SVGParser($svg_file_content, $options);

//@todo: переделать под RESULT
if ($_svgParserClass->parser_state->isError) {
    throw new RuntimeException( "[JS Builder] SVG Parsing error " . $_svgParserClass->parser_state->getMessage() );
}

$layer_name = "Image";
$_svgParserClass->parseImages( $layer_name );

if ($json->type === "bitmap" && $_svgParserClass->getImagesCount()) {
    $image_info = $_svgParserClass->getImageInfo();

    // использовать параметры из файла карты НЕЛЬЗЯ, потому что размеры слоя разметки привязаны к размеру карты в файле
    // если мы изменим размеры (maxBounds) до размеров оригинальной картинки - все сломается :(
    // $image_info['width'] = $json->image->width;
    // $image_info['height'] = $json->image->height;


    $_svgParserClass->set_CRSSimple_TranslateOptions( $image_info['ox'], $image_info['oy'], $image_info['height'] );
} else {
    $_svgParserClass->set_CRSSimple_TranslateOptions( 0, 0, $image_info['height'] );
}

foreach($json->layout->layers as $layer) {
    $layer_config = null;
    
    if (!empty($json->layers->{$layer})) {
        $layer_config = $json->layers->{$layer};
    }
    
    $_svgParserClass->parseLayer($layer);   // парсит слой (определяет атрибут трансформации слоя и конвертит в объекты все элементы)

    // установим конфигурационные значения для пустых регионов для текущего слоя
    $_svgParserClass->setLayerDefaultOptions($layer_config);

    // получаем все элементы на слое
    $paths_at_layer = $_svgParserClass->getElementsAll();

    // Всё, на этом работа парсера закончена
}
```

Опции:
- allowEllipse - (false), парсить ли эллипс или трансформировать его в окружность?
- roundPrecision - (4), точность округления
- registerNamespaces - (true), регистрировать ли неймспейсы?