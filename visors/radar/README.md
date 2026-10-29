# Visor de radar d'Europa

Visor web (Leaflet, sense compilació) del radar de precipitació europeu d'EUMETNET OPERA: reflectivitat
(amb llindar ajustable), intensitat i acumulada d'1 hora, animació dels últims fotogrames, quatre mapes de
fons, zones sense cobertura, valor en un punt amb un clic i descàrrega en PNG o vídeo MP4. Es refresca sol
cada 2 minuts.

## Dades que necessita

La pàgina no porta dades: les llegeix del mateix directori. Les genera `opera_radar.py` (no és en aquest
repositori):

- `radar_index.js`: script que defineix `window.RADAR_INDEX` amb `generated`, `attribution`, `bounds`,
  `coverage` (PNG de cobertura, opcional), `products` (per a cada producte: `label`, `units`, `colors`,
  `bounds` i, si els valors van codificats, `encoding` amb `vmin` i `step`) i `frames` (llista de dates
  `AAAAMMDDhhmm` per producte). És un `.js` i no un `.json` perquè així també es pot obrir amb `file://`.
- `frames/<PRODUCTE>_<AAAAMMDDhhmm>.png`: un PNG per fotograma. Els productes codificats (DBZH) porten
  el valor al canal R (valor = `vmin` + (R − 1) × `step`, A = 0 sense dada); la resta ja porten el color.

Per provar-ho en local, des de l'arrel del repositori:

```sh
python3 -m http.server 8000
# http://localhost:8000/visors/radar/   (amb les dades copiades a visors/radar/)
```

## Publicació

Les dades es generen a l'ordinador de l'Enric i es pugen al servidor d'Arsys on hi ha meteo4u.com.
Al servidor han d'anar a la mateixa carpeta que el visor: `/visors/radar/` (amb la subcarpeta `frames/`).
No es desen al repositori (el `.gitignore` les exclou).

## Paràmetres d'URL

- `#DBZH`, `#RATE` o `#ACRR`: producte inicial (per defecte, reflectivitat).
