# Visor de temperatura del mar (SST)

Visor web (Leaflet, sense compilació) de la temperatura superficial del mar de Copernicus i de la seva
anomalia respecte a la mitjana 1991–2020: mapa de colors, isotermes, valors extrems (zona visible o tot
el mapa, opcionalment només Mediterrani) i consulta de valors amb el cursor o amb un clic.

## Dades que necessita

La pàgina no porta dades: les llegeix del mateix directori (o de les URL indicades a `?data=` i `?anom=`).

- `sst_actual.json`: metadades de la malla (`lat0`, `lon0`, `dlat`, `dlon`, `nlat`, `nlon`), `date`,
  `date_label`, `source`, `palette` (trams uniformes), `stats` (`mean`, `min`, `max`), `isotherms`
  i `png` (nom del PNG de valors, relatiu al JSON), `scale` i `offset`.
- `sst_anom_actual.json`: el mateix per a l'anomalia (sense isotermes). Si no hi és, s'amaga el selector.
- Els dos PNG de valors: R,G = valor en 16 bits (× `scale` + `offset`), A = 0 terra. La fila 0 és el nord.

Cal servir-ho des d'un servidor web (no funciona amb `file://`):

```sh
python3 -m http.server 8000
# http://localhost:8000/visors/sst/   (amb les dades copiades a visors/sst/)
```

## Paràmetres d'URL

- `capa=anomalia`: obre directament l'anomalia.
- `vista=lon_min,lon_max,lat_min,lat_max` o `vista=tot`: zona inicial (per defecte, Mediterrània occidental).
- `data=URL`, `anom=URL`: fitxers alternatius (per exemple, d'un dia concret).
