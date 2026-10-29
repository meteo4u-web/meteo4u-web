# Visor AROME

Visor web (Leaflet, sense compilació) de la previsió del model AROME: temperatura, vent, precipitació,
neu, precipitació acumulada i núvols, amb animació horària, consulta de valors, gràfica de l'evolució en
un punt i descàrrega en PNG o vídeo MP4.

## Dades que necessita

La pàgina no porta dades: les llegeix del mateix directori (o de l'URL indicada a `?index=`).
Les genera `arome_visor.py` (no és en aquest repositori):

- `arome_index.json`: passada (`run`, `run_hour`), `generated`, `coast` i la llista `layers` (`id`, `label`, `file`).
- Un JSON per capa amb la malla (`lat0`, `lon0`, `dlat`, `dlon`, `nlat`, `nlon`), `times`, `frames`,
  `palette`, `units`, `scale`, `offset`, `max`/`min` i, opcionalment, `stats`.
- Un PNG per hora i capa amb els valors codificats: R,G = valor en 16 bits (× `scale` + `offset`),
  B = direcció (× 360/256), A = 255 dada / 0 sense dada. La fila 0 és el nord.
- `costa.geojson` amb la costa i les fronteres (opcional).

Cal servir-ho des d'un servidor web (no funciona amb `file://`). Per provar-ho en local, des de l'arrel del repositori:

```sh
python3 -m http.server 8000
# http://localhost:8000/visors/arome/   (amb les dades copiades a visors/arome/)
```

## Publicació

Les dades es generen a l'ordinador de l'Enric i es pugen al servidor d'Arsys on hi ha meteo4u.com.
Al servidor han d'anar a la mateixa carpeta que el visor: `/visors/arome/` (al costat d'aquest `index.html`).
No es desen al repositori (el `.gitignore` les exclou).

## Paràmetres d'URL

- `capa=temp|vent|precip|neu|acum|nuvols`: capa inicial.
- `vista=lon_min,lon_max,lat_min,lat_max` o `vista=tot`: zona inicial.
- `unitats=km/h|kt`: unitats del vent.
- `index=URL`: índex alternatiu.
