# Быстрая проверка на число

```php
$pattern = '#(?<Y>\-?\d+(\.\d+)?)#';
$matches_count = \preg_match($pattern, $fragment, $knot);
```

нс предлагает 
```php
filter_var($fragment, FILTER_VALIDATE_FLOAT) !== false)
```

# Эллипс

Искаропки leaflet рисовать эллипс не умеет, поэтому SVG-path типа эллипс приводится к окружности.

Рисовать эллипс можно плагинами:

https://github.com/MoebiusSolutions/leaflet-draw-ellipse

или

https://jdfergason.github.io/Leaflet.Ellipse/
https://github.com/jdfergason/Leaflet.Ellipse
```
L.ellipse( <LatLng> latlng, <Radii> radii, <Number> tilt, <Path options> options? )

var ellipse = L.ellipse([51.5, -0.09], [500, 100], 90).addTo(map);
```

latlng - The position of the center of the ellipse.
radii - The semi-major and semi-minor axis (in meters?)
tilt - The rotation of the ellipse in degrees from west


или

https://github.com/phanikmr/leaflet-draw-ellipse

Ни одну не проверял. 

Возможность рисования эллипса нужно задавать через опции конструктора.

# Кривые Безье

These three groups of commands draw curves (in SVG):
- Cubic Bézier commands (C, c, S and s). A cubic Bézier segment is defined by a start point, an end point, and two control points.
- Quadratic Bézier commands (Q, q, T and t). A quadratic Bézier segment is defined by a start point, an end point, and one control point.
- Elliptical arc commands (A and a). An elliptical arc segment draws a segment of an ellipse.

А в JS тоже рисуются плагинами:

https://jwasilgeo.github.io/Leaflet.Canvas-Flowmap-Layer/
https://github.com/elfalem/Leaflet.curve
https://github.com/lifeeka/leaflet.bezier




# Полезное про SVG

https://css-tricks.com/svg-path-syntax-illustrated-guide/
https://www.w3.org/TR/SVG/paths.html


https://www.w3.org/TR/SVG/coords.html#TermUserUnits