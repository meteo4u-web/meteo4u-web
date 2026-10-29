# Pujar la web i les dades a meteo4u.com

`pujar_meteo4u.php` substitueix `pujar_sst_web.php`. Fa el mateix (puja a un nom temporal i el renomena,
puja els PNG abans que els JSON que hi apunten, reintenta, bloqueja execucions solapades i, al radar, només
puja els frames nous i esborra els vells), però amb dues diferències:

- **Les dades de cada visor van a la seva carpeta**: `/visors/sst/`, `/visors/marina/`, `/visors/arome/`,
  `/visors/icon/` i `/visors/radar/` (amb `frames/`).
- **Les pàgines ja no surten de les carpetes de dades**, sinó d'aquest repositori, amb `--web`.
  Si canvies un visor, el canvi s'ha de fer al repositori (el `visor_sst.html` local ja no es puja).
  L'única excepció és ICON, que encara no és al repositori: `--icon` continua pujant `visor_icon.html`.

Les credencials són a `~/.config/meteo4u/ftp.ini`, com ara. Només cal afegir-hi una línia (pas 2).

## Ordres

| Què | Ordre |
|---|---|
| Temperatura del mar | `php pujar_meteo4u.php --sst` |
| … amb la còpia del dia | `php pujar_meteo4u.php --sst --arxiu` (o `--date=2026-09-28`) |
| Onades i corrents | `php pujar_meteo4u.php --marina` |
| AROME | `php pujar_meteo4u.php --arome` |
| ICON-EU | `php pujar_meteo4u.php --icon` |
| Radar | `php pujar_meteo4u.php --radar` |
| Les pàgines de la web | `php pujar_meteo4u.php --web` |
| Mirar què hi ha al servidor | `php pujar_meteo4u.php --llista` |

Opcions que serveixen per a tot: `--dry-run` (ho comprova i diu què pujaria, sense pujar res),
`--arrel=/public_html/nova` (puja a una altra carpeta, per fer proves) i `--htaccess` (posa a cada
carpeta de dades un `.htaccess` que comprimeix i no deixa guardar els JSON a la memòria cau).

Les opcions de dades es poden combinar: `--sst --marina` puja les dues coses en una sola connexió.

### Equivalències amb l'script antic (per al cron)

| Abans (`pujar_sst_web.php …`) | Ara (`pujar_meteo4u.php …`) |
|---|---|
| *(sense opcions)* | `--sst` |
| `--arxiu` | `--sst --arxiu` |
| `--previsio` | `--sst --marina` |
| `--previsio --no-sst` | `--marina` |
| `--arome --no-sst` | `--arome` |
| `--icon --no-sst` | `--icon` |
| `--radar --no-sst` | `--radar` |
| `--no-html` | ja no cal (les pàgines es pugen amb `--web`) |

## Com passar de la web antiga a la nova sense trencar res

La idea és tenir les dues webs funcionant alhora fins que la nova estigui comprovada, i canviar la portada
l'últim. L'script antic i el cron que hi ha ara no es toquen fins al pas 7.

### 1. Baixa el repositori a l'ordinador

```sh
cd ~/meteo
git clone https://github.com/meteo4u-web/meteo4u-web.git
cd meteo4u-web
git pull            # cada vegada que vulguis la darrera versió
```

L'script s'executa des d'aquí (`~/meteo/meteo4u-web/tools/pujar_meteo4u.php`): així `--web` sap on són
les pàgines. Cal tenir `git` instal·lat (`sudo apt install git`) i el PHP amb l'extensió FTP, com ara.

### 2. Afegeix `web_root` al fitxer de credencials

Obre `~/.config/meteo4u/ftp.ini` i afegeix aquesta línia (deixa `remote_dir`, que l'script antic encara la fa servir):

```ini
web_root = "/public_html"
```

Hi ha un exemple complet a `tools/ftp.ini.exemple`.

### 3. Mira què hi ha ara al servidor

```sh
php tools/pujar_meteo4u.php --llista
```

Llista les carpetes i fitxers de `/public_html`. **Envia'm el resultat** abans del pas 6: si hi ha un
`index.php` (per exemple, un WordPress) o una carpeta `contacte`, cal decidir què se'n fa abans de canviar la portada.

### 4. Prova la web nova en una carpeta a part

```sh
php tools/pujar_meteo4u.php --arrel=/public_html/nova --web
php tools/pujar_meteo4u.php --arrel=/public_html/nova --sst --marina --arome --radar
```

Obre <https://meteo4u.com/nova/> i comprova la portada, el contacte i cada visor. La web d'ara no canvia.
Quan acabis, pots esborrar la carpeta `nova` amb el programa d'FTP que facis servir.

### 5. Comença a pujar les dades a les carpetes noves (en paral·lel amb les velles)

```sh
php tools/pujar_meteo4u.php --web --nomes-visors
```

Puja només `visors/` i `assets/`: la portada i el contacte d'ara no es toquen. Després afegeix al `crontab -e`
les línies noves **al costat de les velles**, amb les mateixes hores i la mateixa sortida al log. Per exemple, si ara tens

```
*/5 * * * *  php /home/enric/meteo/bin/pujar_sst_web.php --radar --no-sst >> …
```

hi afegeixes

```
*/5 * * * *  php /home/enric/meteo/meteo4u-web/tools/pujar_meteo4u.php --radar >> …
```

(fes servir la taula d'equivalències de més amunt). Durant uns dies es pujarà tot dues vegades: la web
antiga continua igual i la nova ja es pot veure a <https://meteo4u.com/visors/sst/>, `/visors/radar/`, etc.

### 6. Canvia la portada

Quan els visors nous funcionin bé:

```sh
php tools/pujar_meteo4u.php --web --dry-run     # mira què pujaria
php tools/pujar_meteo4u.php --web
```

Abans de sobreescriure res, l'script en desa una còpia a `~/.cache/meteo4u/copies/<data>-web/`. Si a
`/public_html` hi ha un `index.php` o similar, s'atura i no puja res: en aquest cas parla-ho amb mi primer
(segons com estigui configurat el servidor, la portada podria continuar sent la vella).
Si cal tornar enrere, puja l'`index.html` de la còpia amb el programa d'FTP.

### 7. Redirigeix les adreces antigues i treu el cron vell

```sh
php tools/pujar_meteo4u.php --redireccions --dry-run
php tools/pujar_meteo4u.php --redireccions
```

Posa un `.htaccess` a les carpetes antigues (`/sst/` i `/radar/`, se'n desa una còpia abans) perquè qui
tingui guardada una adreça vella arribi al visor nou:

| Adreça antiga | Va a |
|---|---|
| `/sst/visor_sst.html` | `/visors/sst/` |
| `/sst/visor_previsio.html` | `/visors/marina/` |
| `/sst/visor_arome.html` | `/visors/arome/` |
| `/sst/icon/visor_icon.html` | `/visors/icon/visor_icon.html` |
| `/radar/visor_radar.html` | `/visors/radar/` |

Els paràmetres de l'adreça (`?capa=…`, `#DBZH`…) es mantenen. Comprova un parell d'adreces antigues al
navegador i, si van bé, esborra del `crontab` les línies de `pujar_sst_web.php`.

### 8. Neteja (al cap d'unes setmanes)

Les dades velles de `/public_html/sst/` i `/public_html/radar/` ja no les fa servir ningú. Es poden esborrar
amb el programa d'FTP, però **deixa-hi el `.htaccess`** perquè les redireccions continuïn funcionant.

## Per actualitzar la web més endavant

```sh
cd ~/meteo/meteo4u-web
git pull
php tools/pujar_meteo4u.php --web
```

`--web` només puja els fitxers que segueix git (pàgines, CSS i imatges), mai les dades ni aquesta carpeta
`tools/`, i no esborra res del servidor. Avisa si no ets a la branca `main` o si tens canvis sense confirmar.

Quan el visor ICON entri al repositori (`visors/icon/index.html`), `--web` el pujarà com els altres i
n'hi haurà prou amb `--icon --no-html` per a les dades.
