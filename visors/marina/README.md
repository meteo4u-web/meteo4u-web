# Visor de previsió marina

Visor web (Leaflet, sense compilació) de la previsió d'onades i corrents: altura significativa de l'onada
(total, mar de vent i mar de fons), període, i corrent superficial. Té animació horària, crestes d'onada i
partícules de corrent animades, consulta de valors amb màxim i mínim de tota la previsió en un punt, i
descàrrega en PNG o vídeo MP4.

## Dades que necessita

La pàgina no porta dades: les llegeix del mateix directori (o de l'URL indicada a `?index=`).

- `previsio_index.json`: passada (`run`), `generated` i la llista `layers` (`id`, `label`, `file`).
- Un JSON per capa amb la malla (`lat0`, `lon0`, `dlat`, `dlon`, `nlat`, `nlon`), `times`, `frames`,
  `palette`, `units`, `scale`, `max`, `source`, `dir_kind` i, opcionalment, `stats` (màxim i mínim de cada punt).
- Un PNG per hora i capa amb els valors codificats: R,G = valor en 16 bits (× `scale`),
  B = direcció (× 360/256), A = 255 mar / 0 terra. La fila 0 és el nord.

Per provar-ho en local, des de l'arrel del repositori:

```sh
python3 -m http.server 8000
# http://localhost:8000/visors/marina/   (amb les dades copiades a visors/marina/)
```

## Publicació

Les dades es generen a l'ordinador de l'Enric i es pugen al servidor d'Arsys on hi ha meteo4u.com.
Al servidor han d'anar a la mateixa carpeta que el visor: `/visors/marina/` (al costat d'aquest `index.html`).
No es desen al repositori (el `.gitignore` les exclou).

## Paràmetres d'URL

- `capa=hs|hs_ww|hs_sw|tp|cur`: capa inicial.
- `vista=lon_min,lon_max,lat_min,lat_max` o `vista=tot`: zona inicial (per defecte, Mediterrània occidental).
- `index=URL`: índex alternatiu.
