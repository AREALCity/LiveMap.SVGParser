# SVG to JS parser/convertor for LiveMapEngine project

```php
$parser = new SVGParser($svg_file_content, $options);

```

Опции:
- allowEllipse - (false), парсить ли эллипс или трансформировать его в окружность?
- roundPrecision - (4), точность округления
- registerNamespaces - (true), регистрировать ли неймспейсы?