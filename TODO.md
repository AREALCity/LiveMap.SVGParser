# Эллипс

Искаропки leaflet рисовать эллипс не умеет, поэтому SVG-path типа эллипс приводится к окружности.

Рисовать эллипс можно плагинами:

https://github.com/MoebiusSolutions/leaflet-draw-ellipse

или

https://jdfergason.github.io/Leaflet.Ellipse/
https://github.com/jdfergason/Leaflet.Ellipse
`L.ellipse( <LatLng> latlng, <Radii> radii, <Number> tilt, <Path options> options? )`

или

https://github.com/phanikmr/leaflet-draw-ellipse

Ни одну не проверял. 

Возможность рисования эллипса нужно задавать через опции конструктора.