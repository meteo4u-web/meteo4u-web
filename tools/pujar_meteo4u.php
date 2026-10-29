#!/usr/bin/env php
<?php
/**
 * =========================================================
 * PUJAR LES DADES DELS VISORS I LA WEB A meteo4u.com (web nova)
 * =========================================================
 *
 * Substitueix pujar_sst_web.php. Les dades de cada visor van a /visors/<nom>/
 * (al costat del seu index.html) i la web mateixa es puja des d'aquest repositori.
 *
 * Ús (es poden combinar les opcions de dades):
 *   php pujar_meteo4u.php --sst                 # temperatura del mar → visors/sst/
 *   php pujar_meteo4u.php --sst --arxiu         # també la còpia amb data (sst_AAAA-MM-DD.json)
 *   php pujar_meteo4u.php --sst --arxiu --date=2026-09-28
 *   php pujar_meteo4u.php --marina              # onades i corrents → visors/marina/ (àlies: --previsio)
 *   php pujar_meteo4u.php --arome               # AROME → visors/arome/
 *   php pujar_meteo4u.php --icon                # ICON-EU → visors/icon/ (amb el seu visor_icon.html)
 *   php pujar_meteo4u.php --radar               # radar OPERA → visors/radar/ (només els frames nous)
 *   php pujar_meteo4u.php --web                 # les pàgines de la web (des del repositori)
 *   php pujar_meteo4u.php --web --nomes-visors  # només visors/ i assets/: no toca la portada ni contacte/
 *   php pujar_meteo4u.php --llista              # mostra què hi ha ara al servidor i surt
 *   php pujar_meteo4u.php --redireccions        # les adreces antigues (/sst/…, /radar/…) porten a /visors/
 *
 * Opcions comunes:
 *   --dry-run            ho comprova tot però no puja res
 *   --arrel=/public_html/nova   puja a una altra carpeta (per provar la web nova sense tocar la de debò)
 *   --htaccess           puja un .htaccess (gzip + no-cache dels JSON) a cada carpeta de dades
 *   --forca              amb --web: puja encara que a l'arrel hi hagi un index.php o similar
 *   --no-html            amb --icon: no puja visor_icon.html
 *
 * Credencials: fitxer ~/.config/meteo4u/ftp.ini (permisos 600), exemple a tools/ftp.ini.exemple:
 *
 *   host     = "ftp.meteo4u.com"
 *   user     = "usuari"
 *   password = "contrasenya"
 *   web_root = "/public_html"   ; carpeta on hi ha (o hi haurà) l'index.html de la web
 *   tls      = true
 *   port     = 21
 *
 * Codis de sortida (per al cron): 0 = tot bé, 1 = error.
 * =========================================================
 */

declare(strict_types=1);

// ---------------------------------------------------------
// CONFIGURACIÓ LOCAL
// ---------------------------------------------------------
const LOCAL_DIR    = '/home/enric/meteo/model/copernicus';                  // SST
const PREVISIO_DIR = '/home/enric/meteo/model/copernicus/previsio/web';     // sortida d'export_previsio_json.py
const AROME_DIR    = '/home/enric/meteo/web/visor_arome';                   // sortida d'arome_visor.py
const ICON_DIR     = '/home/enric/meteo/web/visor_icon';                    // sortida d'icon_visor.py
const RADAR_DIR    = '/home/enric/meteo/radar_web';                         // sortida d'opera_radar.py (--out)
const REPO_DIR     = __DIR__ . '/..';                                       // arrel del repositori (index.html)
const CONFIG_FILE  = '~/.config/meteo4u/ftp.ini';
const BACKUP_DIR   = '~/.cache/meteo4u/copies';   // còpia del que se sobreescriu amb --web i --redireccions
const LOCK_DIR     = '/tmp';
const RETRIES      = 3;          // intents per fitxer
const MAX_DAYS     = 3;          // avisa si el JSON de la SST té dades més antigues

// Carpeta remota de cada visor, relativa a web_root.
// AROME i ICON fan servir els mateixos noms de fitxer (arome_*.json): han d'anar a carpetes diferents.
const REMOT = [
    'sst'    => 'visors/sst',
    'marina' => 'visors/marina',
    'arome'  => 'visors/arome',
    'icon'   => 'visors/icon',
    'radar'  => 'visors/radar',
];

// Fitxers de la web: extensions que es pugen des del repositori (mai .md, .json, .php ni fitxers ocults).
const WEB_EXT = ['html', 'htm', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'txt', 'xml',
                 'webmanifest', 'woff', 'woff2'];

// Adreces de la web antiga → visors nous. Clau: carpeta antiga (relativa a web_root).
const REDIRECCIONS = [
    'sst' => [
        '^/sst/?$'                     => '/',
        '^/sst/visor_sst\.html$'       => '/visors/sst/',
        '^/sst/visor_previsio\.html$'  => '/visors/marina/',
        '^/sst/visor_arome\.html$'     => '/visors/arome/',
        '^/sst/icon/?$'                => '/visors/icon/visor_icon.html',
        '^/sst/icon/visor_icon\.html$' => '/visors/icon/visor_icon.html',
    ],
    'radar' => [
        '^/radar/?$'                   => '/visors/radar/',
        '^/radar/visor_radar\.html$'   => '/visors/radar/',
    ],
];

const HTACCESS = <<<HT
# Generat per pujar_meteo4u.php
AddType application/json .json
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE application/json application/javascript text/html
</IfModule>
<IfModule mod_headers.c>
  <FilesMatch "\.(json|js)$">
    Header set Cache-Control "no-cache"
  </FilesMatch>
</IfModule>
HT;

// ---------------------------------------------------------
// UTILITATS
// ---------------------------------------------------------
function logmsg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg" . PHP_EOL;
}

function fail(string $msg): never
{
    logmsg("❌ $msg");
    exit(1);
}

function expandHome(string $path): string
{
    return str_starts_with($path, '~/') ? (getenv('HOME') ?: '/home/enric') . substr($path, 1) : $path;
}

function loadConfig(string $file): array
{
    $file = expandHome($file);
    if (!is_file($file)) {
        fail("No trobo el fitxer de credencials: $file");
    }
    if ((fileperms($file) & 0o077) !== 0) {
        logmsg("⚠️  $file és llegible per altres usuaris. Fes: chmod 600 $file");
    }
    $cfg = parse_ini_file($file, false, INI_SCANNER_TYPED);
    if ($cfg === false) {
        fail("No s'ha pogut llegir $file (format INI incorrecte?)");
    }
    foreach (['host', 'user', 'password'] as $key) {
        if (empty($cfg[$key])) {
            fail("Falta '$key' a $file");
        }
    }
    return $cfg + ['tls' => true, 'port' => 21, 'timeout' => 60];
}

/** Carpeta arrel de la web al servidor: --arrel o web_root de l'ini. */
function webRoot(array $cfg, array $opt, string $file): string
{
    $root = $opt['arrel'] ?? ($cfg['web_root'] ?? '');
    if ($root === '') {
        fail("Falta 'web_root' a " . expandHome($file) . ' (p. ex. web_root = "/public_html")');
    }
    if (!str_starts_with($root, '/') || in_array('..', explode('/', $root), true)) {
        fail("web_root ha de ser una ruta absoluta sense '..': $root");
    }
    return '/' . trim($root, '/');
}

/**
 * Comprovació lleugera del JSON (sense carregar-lo sencer: pot fer desenes de MB).
 * Retorna la data de les dades (YYYY-MM-DD).
 */
function checkJson(string $path, bool $needDate = true): string
{
    if (!is_file($path)) {
        fail("No existeix: $path");
    }
    $size = filesize($path);
    if ($size < ($needDate ? 200 : 20)) {
        fail("JSON massa petit ($size bytes), probablement buit o trencat: $path");
    }
    $fh   = fopen($path, 'rb');
    $head = fread($fh, 512);
    fseek($fh, -16, SEEK_END);
    $tail = rtrim(fread($fh, 16));
    fclose($fh);

    if (!str_starts_with(ltrim($head), '{') || !str_ends_with($tail, '}')) {
        fail("JSON incomplet (no comença amb '{' o no acaba amb '}'): $path");
    }
    if (!$needDate) {
        return '';
    }
    if (!preg_match('/"date"\s*:\s*"(\d{4}-\d{2}-\d{2})"/', $head, $m)) {
        fail("El JSON no té el camp 'date' al principi: $path");
    }
    $days = (int) floor((time() - strtotime($m[1])) / 86400);
    if ($days > MAX_DAYS) {
        logmsg("⚠️  Les dades són de fa $days dies ({$m[1]}). Es pugen igualment.");
    }
    logmsg(sprintf('   %s: dades del %s, %.1f MB', basename($path), $m[1], $size / 1e6));
    return $m[1];
}

function git(string $repo, string $args): string
{
    $out = shell_exec('git -C ' . escapeshellarg($repo) . " $args 2>/dev/null");
    return is_string($out) ? $out : '';
}

// ---------------------------------------------------------
// FTP
// ---------------------------------------------------------
function ftpConnect(array $cfg): FTP\Connection
{
    $useTls = (bool) $cfg['tls'];
    $conn = $useTls
        ? @ftp_ssl_connect($cfg['host'], (int) $cfg['port'], (int) $cfg['timeout'])
        : @ftp_connect($cfg['host'], (int) $cfg['port'], (int) $cfg['timeout']);

    if (!$conn) {
        fail("No es pot connectar a {$cfg['host']}:{$cfg['port']}" . ($useTls ? ' (FTPS)' : ''));
    }
    if (!@ftp_login($conn, $cfg['user'], $cfg['password'])) {
        fail('Login incorrecte' . ($useTls ? " (si el servidor no accepta FTPS, posa tls = false a l'ini)" : ''));
    }
    ftp_pasv($conn, true);
    logmsg("🔌 Connectat a {$cfg['host']}" . ($useTls ? ' (FTPS, xifrat)' : ' (FTP sense xifrar)'));
    return $conn;
}

/** Crea (si cal) una ruta absoluta i hi entra. */
function ftpMkdirs(FTP\Connection $conn, string $dir): void
{
    $path = '';
    foreach (array_filter(explode('/', $dir), 'strlen') as $part) {
        $path .= '/' . $part;
        if (!@ftp_chdir($conn, $path)) {
            if (!@ftp_mkdir($conn, $path)) {
                fail("No es pot crear el directori remot $path");
            }
            logmsg("   Creat directori remot $path");
        }
    }
    if (!@ftp_chdir($conn, $path === '' ? '/' : $path)) {
        fail("No es pot entrar al directori remot $path");
    }
    logmsg('   Directori remot: ' . ftp_pwd($conn));
}

/** Crea subdirectoris relatius al directori actual (p. ex. "visors/arome/temp") i hi torna. */
function ftpMkdirsRel(FTP\Connection $conn, array $dirs): void
{
    $base = ftp_pwd($conn);
    foreach (array_unique($dirs) as $dir) {
        $path = $base;
        foreach (array_filter(explode('/', $dir), 'strlen') as $part) {
            $path = rtrim($path, '/') . '/' . $part;
            if (!@ftp_chdir($conn, $path)) {
                if (!@ftp_mkdir($conn, $path)) {
                    fail("No es pot crear el directori remot $path");
                }
                logmsg("   Creat directori remot $path");
            }
        }
        @ftp_chdir($conn, $base);
    }
}

/**
 * Puja a un nom temporal i després el renomena: els visitants mai veuen
 * un fitxer a mig pujar. Comprova la mida final i reintenta si cal.
 */
function ftpUpload(FTP\Connection $conn, string $local, string $remoteName, bool $quiet = false): void
{
    $tmp  = $remoteName . '.tmp';
    $size = filesize($local);

    for ($try = 1; $try <= RETRIES; $try++) {
        $t0 = microtime(true);
        if (@ftp_put($conn, $tmp, $local, FTP_BINARY)) {
            $ok = @ftp_rename($conn, $tmp, $remoteName);
            if (!$ok) {                                      // alguns servidors no sobreescriuen amb rename
                @ftp_delete($conn, $remoteName);
                $ok = @ftp_rename($conn, $tmp, $remoteName);
            }
            if ($ok) {
                $remoteSize = ftp_size($conn, $remoteName);
                if ($remoteSize === -1 || $remoteSize === $size) {
                    if (!$quiet) {
                        logmsg(sprintf('   ✅ %s (%.1f MB, %.1f s)', $remoteName, $size / 1e6, microtime(true) - $t0));
                    }
                    return;
                }
                logmsg("   ⚠️  Mida remota diferent ($remoteSize ≠ $size)");
            }
        }
        logmsg("   ⚠️  Intent $try/" . RETRIES . " fallit per a $remoteName");
        sleep(5 * $try);
    }
    @ftp_delete($conn, $tmp);
    fail("No s'ha pogut pujar $remoteName després de " . RETRIES . ' intents');
}

/** Llista remota nom => ['type' => file|dir|?, 'size' => bytes o -1]. Buida si la carpeta no existeix. */
function ftpList(FTP\Connection $conn, string $dir): array
{
    $out  = [];
    $mlsd = @ftp_mlsd($conn, $dir);
    if (is_array($mlsd)) {
        foreach ($mlsd as $e) {
            $type = $e['type'] ?? '';
            if ($type === 'file' || $type === 'dir') {
                $out[$e['name']] = ['type' => $type, 'size' => isset($e['size']) ? (int) $e['size'] : -1];
            }
        }
        return $out;
    }
    foreach (@ftp_nlist($conn, $dir) ?: [] as $r) {      // servidors sense MLSD
        $name = basename($r);
        if ($name !== '.' && $name !== '..') {
            $out[$name] = ['type' => '?', 'size' => -1];
        }
    }
    return $out;
}

/** Només els fitxers: nom => mida. */
function ftpListSizes(FTP\Connection $conn, string $dir): array
{
    $out = [];
    foreach (ftpList($conn, $dir) as $name => $e) {
        if ($e['type'] !== 'dir') {
            $out[$name] = $e['size'];
        }
    }
    return $out;
}

/** Desa una còpia local dels fitxers remots que se sobreescriuran. */
function ftpBackup(FTP\Connection $conn, array $remotes, string $label): void
{
    $byDir = [];
    foreach ($remotes as $r) {
        $byDir[dirname($r)][] = basename($r);
    }
    $dest = expandHome(BACKUP_DIR) . '/' . date('Ymd-His') . "-$label";
    $n = 0;
    foreach ($byDir as $dir => $names) {
        $remoteFiles = ftpListSizes($conn, $dir === '.' ? '.' : $dir);
        foreach ($names as $name) {
            if (!array_key_exists($name, $remoteFiles)) {
                continue;
            }
            $rel = ($dir === '.' ? '' : "$dir/") . $name;
            if (!is_dir(dirname("$dest/$rel")) && !mkdir(dirname("$dest/$rel"), 0o700, true)) {
                fail("No puc crear la carpeta de còpia " . dirname("$dest/$rel"));
            }
            if (!@ftp_get($conn, "$dest/$rel", $rel, FTP_BINARY)) {
                fail("No s'ha pogut fer la còpia de $rel (no se sobreescriu res)");
            }
            $n++;
        }
    }
    if ($n) {
        logmsg("   💾 Còpia de $n fitxers que se sobreescriuran a $dest");
    }
}

// ---------------------------------------------------------
// QUÈ CAL PUJAR
// ---------------------------------------------------------
/** Afegeix un fitxer al pla. $json: null (no és JSON), 'date' (cal camp date) o 'nodate'. */
function afegeix(array &$plan, string $local, string $remote, ?string $json = null): void
{
    $plan[] = ['local' => $local, 'remote' => $remote, 'json' => $json];
}

function planSst(array $opt, array &$plan): void
{
    $pre = REMOT['sst'] . '/';
    // PNG primer (dades) i després el JSON que hi apunta
    foreach (['sst_actual', 'sst_anom_actual'] as $stem) {
        if (is_file(LOCAL_DIR . "/$stem.png")) {
            afegeix($plan, LOCAL_DIR . "/$stem.png", "$pre$stem.png");
        }
    }
    afegeix($plan, LOCAL_DIR . '/sst_actual.json', "{$pre}sst_actual.json", 'date');

    // Anomalia: opcional (si no existeix, es puja la resta igualment)
    $anomLocal = LOCAL_DIR . '/sst_anom_actual.json';
    if (is_file($anomLocal)) {
        afegeix($plan, $anomLocal, "{$pre}sst_anom_actual.json", 'date');
    } else {
        logmsg("ℹ️  Sense anomalia ($anomLocal no existeix): el visor amagarà el selector");
    }

    if (isset($opt['arxiu'])) {
        $date = $opt['date'] ?? date('Y-m-d', strtotime('-1 day'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            fail("Data incorrecta: $date (cal AAAA-MM-DD)");
        }
        foreach (["sst_$date", "sst_anom_$date"] as $stem) {
            if (is_file(LOCAL_DIR . "/$stem.png")) {
                afegeix($plan, LOCAL_DIR . "/$stem.png", "$pre$stem.png");
            }
        }
        afegeix($plan, LOCAL_DIR . "/sst_$date.json", "{$pre}sst_$date.json", 'date');
        if (is_file(LOCAL_DIR . "/sst_anom_$date.json")) {
            afegeix($plan, LOCAL_DIR . "/sst_anom_$date.json", "{$pre}sst_anom_$date.json", 'date');
        }
    }
}

/** Previsió marina: fotogrames PNG → metadades de cada capa → índex. */
function planMarina(array &$plan): void
{
    $pre   = REMOT['marina'] . '/';
    $index = PREVISIO_DIR . '/previsio_index.json';
    if (!is_file($index)) {
        fail("No existeix $index (executa export_previsio_json.py)");
    }
    $pngs = glob(PREVISIO_DIR . '/previsio_*.png');
    foreach ($pngs as $f) {
        afegeix($plan, $f, $pre . basename($f));
    }
    logmsg('   Marina: ' . count($pngs) . ' fotogrames PNG → ' . REMOT['marina']);
    foreach (glob(PREVISIO_DIR . '/previsio_*.json') as $f) {
        if ($f !== $index) {
            afegeix($plan, $f, $pre . basename($f), 'nodate');
        }
    }
    afegeix($plan, $index, $pre . 'previsio_index.json', 'nodate');
}

/**
 * Visors de model (AROME, ICON): una subcarpeta de PNG per capa (temp/, vent/, ...),
 * un JSON per capa, la costa i l'índex. Ordre: PNG → JSON de cada capa → índex → HTML.
 */
function planModel(string $nom, string $localDir, string $script, ?string $html, array &$plan, array &$dirs): void
{
    $pre   = REMOT[strtolower($nom)] . '/';
    $index = $localDir . '/arome_index.json';
    if (!is_file($index)) {
        fail("No existeix $index (executa $script)");
    }
    $nPng = 0;
    $nCapes = 0;
    foreach (glob($localDir . '/*', GLOB_ONLYDIR) as $dir) {
        $sub  = basename($dir);
        $pngs = glob("$dir/*.png");
        if (!$pngs) {
            continue;
        }
        $dirs[] = $pre . $sub;
        $nCapes++;
        foreach ($pngs as $f) {
            afegeix($plan, $f, $pre . $sub . '/' . basename($f));
            $nPng++;
        }
    }
    logmsg("   $nom: $nPng fotogrames PNG en $nCapes capes → " . rtrim($pre, '/'));
    foreach (glob($localDir . '/arome_*.json') as $f) {
        if ($f !== $index) {
            afegeix($plan, $f, $pre . basename($f), 'nodate');
        }
    }
    foreach (glob($localDir . '/*.geojson') as $f) {          // costa i fronteres
        afegeix($plan, $f, $pre . basename($f));
    }
    afegeix($plan, $index, $pre . 'arome_index.json', 'nodate');
    if ($html !== null) {
        if (is_file("$localDir/$html")) {
            afegeix($plan, "$localDir/$html", $pre . $html);
        } else {
            logmsg("ℹ️  No trobo $html a $localDir: no es puja el visor $nom");
        }
    }
}

/** Radar OPERA: els frames van a part (només els nous); aquí, l'índex. */
function planRadar(array &$plan): array
{
    $index = RADAR_DIR . '/radar_index.js';
    if (!is_file($index)) {
        fail("No existeix $index (executa opera_radar.py)");
    }
    $ageMin = (int) ((time() - filemtime($index)) / 60);
    if ($ageMin > 60) {
        logmsg("⚠️  radar_index.js és de fa $ageMin min: opera_radar.py funciona?");
    }
    $frames = [];
    foreach (glob(RADAR_DIR . '/frames/*.png') as $f) {
        $frames[basename($f)] = $f;
    }
    if (!$frames) {
        fail('No hi ha frames de radar a ' . RADAR_DIR . '/frames');
    }
    logmsg('   Radar: ' . count($frames) . ' frames locals → ' . REMOT['radar']);
    afegeix($plan, $index, REMOT['radar'] . '/radar_index.js');
    return $frames;
}

/**
 * Pàgines de la web: els fitxers del repositori que segueix git (les dades, que el .gitignore exclou,
 * no hi surten mai). Ordre: recursos (css, imatges) → pàgines → index.html de l'arrel, l'últim.
 */
function planWeb(string $repo, bool $nomesVisors, array &$plan, array &$dirs): array
{
    if (!is_file("$repo/index.html")) {
        fail("No trobo $repo/index.html: --web s'ha d'executar des del repositori de la web");
    }
    $tracked = array_filter(explode("\0", git($repo, 'ls-files -z')), 'strlen');
    if (!$tracked) {
        fail("$repo no sembla un repositori git (cal git per saber quins fitxers són de la web)");
    }
    $web = [];
    foreach ($tracked as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (str_starts_with($f, 'tools/') || preg_match('#(^|/)\.#', $f) || !in_array($ext, WEB_EXT, true)) {
            continue;
        }
        // Dins de visors/ només les pàgines: les dades (PNG, JS de l'índex del radar) les puja la resta de l'script
        if (str_starts_with($f, 'visors/') && !in_array($ext, ['html', 'htm', 'css', 'svg'], true)) {
            continue;
        }
        if ($nomesVisors && !str_starts_with($f, 'visors/') && !str_starts_with($f, 'assets/')) {
            continue;
        }
        if (!is_file("$repo/$f")) {
            fail("Falta $repo/$f (l'esborrat no està confirmat a git?)");
        }
        $web[] = $f;
    }
    usort($web, function (string $a, string $b): int {
        $rank = fn(string $f) => $f === 'index.html' ? 2 : (preg_match('/\.html?$/', $f) ? 1 : 0);
        return [$rank($a), $a] <=> [$rank($b), $b];
    });

    $branch = trim(git($repo, 'rev-parse --abbrev-ref HEAD'));
    $commit = trim(git($repo, 'log -1 --format="%h %cd" --date=format:"%Y-%m-%d %H:%M"'));
    logmsg("   Web: " . count($web) . " fitxers de la branca $branch ($commit)");
    if ($branch !== 'main') {
        logmsg("⚠️  No ets a la branca main: es pujarà la versió de $branch");
    }
    if (trim(git($repo, 'status --porcelain --untracked-files=no')) !== '') {
        logmsg('⚠️  Hi ha canvis sense confirmar al repositori: es pujaran tal com són ara al disc');
    }
    foreach ($web as $f) {
        afegeix($plan, "$repo/$f", $f);
        if (dirname($f) !== '.') {
            $dirs[] = dirname($f);
        }
    }
    return $web;
}

function htaccessRedireccions(string $carpeta): string
{
    $txt = "# Generat per pujar_meteo4u.php --redireccions: la web antiga s'ha traslladat a /visors/\n";
    foreach (REDIRECCIONS[$carpeta] as $de => $a) {
        $txt .= "RedirectMatch 301 $de $a\n";
    }
    return $txt;
}

function fitxerTemporal(string $content): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'm4u');
    file_put_contents($tmp, $content);
    return $tmp;
}

// ---------------------------------------------------------
// MAIN
// ---------------------------------------------------------
$opt = getopt('', ['sst', 'arxiu', 'date:', 'marina', 'previsio', 'arome', 'icon', 'radar', 'web', 'llista',
                   'redireccions', 'htaccess', 'forca', 'nomes-visors', 'no-html', 'dry-run', 'arrel:']);
if (isset($opt['previsio'])) {
    $opt['marina'] = false;
}
$visors = array_values(array_filter(array_keys(REMOT), fn($v) => isset($opt[$v])));
$doWeb  = isset($opt['web']);
$doRedir = isset($opt['redireccions']);

if (!$visors && !$doWeb && !$doRedir && !isset($opt['llista'])) {
    fwrite(STDERR, "Ús: php pujar_meteo4u.php [--sst] [--marina] [--arome] [--icon] [--radar] [--web]\n"
        . "                          [--llista] [--redireccions] [--dry-run] [--arrel=/carpeta]\n"
        . "Mira la capçalera de l'script o tools/README.md\n");
    exit(1);
}
if ($doRedir && isset($opt['arrel'])) {
    fail("--redireccions és per a la web de debò: no es pot combinar amb --arrel");
}

// Evitar dues execucions alhora (per exemple, cron solapat).
// El radar sol (cada 5 min) i la web tenen bloquejos propis per no esperar la resta.
$lockName = $visors === ['radar'] && !$doWeb && !$doRedir ? 'radar' : ($visors ? 'dades' : 'web');
$lock = fopen(LOCK_DIR . "/pujar_meteo4u_$lockName.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('Ja hi ha una pujada en marxa');
}

$plan = [];          // dades i web, en l'ordre de pujada
$dirs = [];          // carpetes remotes que cal crear (relatives a web_root)
$radarFrames = [];   // nom => ruta local
$webFiles = [];

if ($visors) {
    logmsg('🔎 Preparant les dades...');
}
foreach ($visors as $v) {
    $dirs[] = REMOT[$v];
    match ($v) {
        'sst'    => planSst($opt, $plan),
        'marina' => planMarina($plan),
        'arome'  => planModel('AROME', AROME_DIR, 'arome_visor.py', null, $plan, $dirs),
        'icon'   => planModel('ICON', ICON_DIR, 'icon_visor.py', isset($opt['no-html']) ? null : 'visor_icon.html',
                              $plan, $dirs),
        'radar'  => $radarFrames = planRadar($plan),
    };
}
if ($radarFrames) {
    $dirs[] = REMOT['radar'] . '/frames';
}
if ($doWeb) {
    $repo = realpath(REPO_DIR) ?: REPO_DIR;
    $webFiles = planWeb($repo, isset($opt['nomes-visors']), $plan, $dirs);
}

// Comprovacions locals abans de connectar
foreach ($plan as $p) {
    if ($p['json'] !== null) {
        checkJson($p['local'], $p['json'] === 'date');
    } elseif (!str_ends_with($p['local'], '.png') && !is_file($p['local'])) {
        fail("No existeix: {$p['local']}");
    }
}

$cfg  = loadConfig(CONFIG_FILE);
$root = webRoot($cfg, $opt, CONFIG_FILE);

if (isset($opt['dry-run'])) {
    logmsg("🧪 Dry-run: tot correcte. Es pujaria a {$cfg['host']}:$root/");
    $nPng = [];
    foreach ($plan as $p) {
        if (str_ends_with($p['remote'], '.png') && !in_array($p['remote'], $webFiles, true)) {
            $d = dirname($p['remote']);
            $nPng[$d] = ($nPng[$d] ?? 0) + 1;
            continue;
        }
        logmsg("   - {$p['remote']}");
    }
    foreach ($nPng as $d => $n) {
        logmsg("   - $d/: $n fitxers PNG");
    }
    if ($radarFrames) {
        logmsg('   - ' . REMOT['radar'] . '/frames/: ' . count($radarFrames) . ' frames (només es pugen els nous)');
    }
    if ($doRedir) {
        foreach (array_keys(REDIRECCIONS) as $c) {
            logmsg("   - $c/.htaccess amb redireccions:");
            foreach (REDIRECCIONS[$c] as $de => $a) {
                logmsg("       $de → $a");
            }
        }
    }
    if (isset($opt['htaccess'])) {
        foreach ($visors as $v) {
            logmsg('   - ' . REMOT[$v] . '/.htaccess');
        }
    }
    exit(0);
}

$conn = ftpConnect($cfg);
try {
    // --llista: només mirar
    if (isset($opt['llista'])) {
        if (!@ftp_chdir($conn, $root)) {
            fail("La carpeta $root no existeix al servidor");
        }
        logmsg("📂 Contingut de $root:");
        $items = ftpList($conn, '.');
        ksort($items);
        foreach ($items as $name => $e) {
            if ($e['type'] === 'dir') {
                $sub = ftpList($conn, $name);
                logmsg(sprintf('   %-30s carpeta, %d elements', "$name/", count($sub)));
                $mostra = array_slice(array_keys($sub), 0, 8);
                if ($mostra) {
                    logmsg('      ' . implode(', ', $mostra) . (count($sub) > 8 ? ', …' : ''));
                }
            } else {
                logmsg(sprintf('   %-30s %s', $name, $e['size'] >= 0 ? number_format($e['size'] / 1e3, 1) . ' kB' : ''));
            }
        }
        if (!$visors && !$doWeb && !$doRedir) {
            exit(0);
        }
    }

    ftpMkdirs($conn, $root);

    // --web: no trepitjar una web que no sabem com és (WordPress, PHP...) i fer-ne còpia
    if ($doWeb) {
        $rootFiles = ftpListSizes($conn, '.');
        $altres = array_values(array_filter(['index.php', 'index.htm', 'index.shtml', 'default.html', 'default.htm'],
            fn($f) => array_key_exists($f, $rootFiles)));
        if ($altres && in_array('index.html', $webFiles, true) && !isset($opt['forca'])) {
            fail("A $root hi ha " . implode(', ', $altres) . ": segons la configuració del servidor, la portada "
                . "podria continuar sent la vella o trencar-se. Revisa-ho (tools/README.md) i torna-ho a provar "
                . "amb --forca si és el que vols.");
        }
        ftpBackup($conn, $webFiles, 'web');
    }

    if ($dirs) {
        ftpMkdirsRel($conn, $dirs);
    }
    logmsg("📤 Pujant a $root...");

    // Radar: primer els frames nous, perquè l'índex mai apunti a un PNG absent
    $staleRadar = [];
    if ($radarFrames) {
        $framesDir = REMOT['radar'] . '/frames';
        $remote = array_filter(ftpListSizes($conn, $framesDir),
            fn($n) => str_ends_with((string) $n, '.png'), ARRAY_FILTER_USE_KEY);
        $new = 0;
        $t1  = microtime(true);
        foreach ($radarFrames as $name => $local) {
            $rs = $remote[$name] ?? null;
            if ($rs !== null && $name !== 'coverage.png' && ($rs === -1 || $rs === filesize($local))) {
                continue;                                   // ja hi és
            }
            ftpUpload($conn, $local, "$framesDir/$name", true);
            $new++;
        }
        logmsg(sprintf('   ✅ Radar: %d frames pujats (%.0f s), %d ja hi eren',
            $new, microtime(true) - $t1, count($radarFrames) - $new));
        $staleRadar = array_diff_key($remote, $radarFrames);
    }

    // Dades (PNG → JSON de capa → índex) i, al final, les pàgines de la web
    $nPng = 0;
    $t0 = microtime(true);
    foreach ($plan as $p) {
        $isData = str_ends_with($p['remote'], '.png') && !in_array($p['remote'], $webFiles, true);
        ftpUpload($conn, $p['local'], $p['remote'], $isData);
        if ($isData && ++$nPng % 100 === 0) {
            logmsg("   … $nPng PNG pujats");
        }
    }
    if ($nPng) {
        logmsg(sprintf('   ✅ %d fitxers PNG (%.0f s en total)', $nPng, microtime(true) - $t0));
    }

    // Radar: esborrar els frames que ja han sortit de la finestra (després de l'índex nou)
    if ($staleRadar) {
        $nDel = 0;
        foreach (array_keys($staleRadar) as $name) {
            if (@ftp_delete($conn, REMOT['radar'] . "/frames/$name")) {
                $nDel++;
            }
        }
        logmsg("   🗑️  Radar: $nDel frames antics esborrats");
    }

    if (isset($opt['htaccess'])) {
        $tmp = fitxerTemporal(HTACCESS . PHP_EOL);
        foreach ($visors as $v) {
            ftpUpload($conn, $tmp, REMOT[$v] . '/.htaccess');
        }
        unlink($tmp);
    }

    // Redireccions de la web antiga: un .htaccess a cada carpeta antiga que existeixi
    if ($doRedir) {
        $existents = ftpList($conn, '.');
        foreach (array_keys(REDIRECCIONS) as $carpeta) {
            if (($existents[$carpeta]['type'] ?? '') === 'file' || !isset($existents[$carpeta])) {
                logmsg("ℹ️  No hi ha la carpeta $root/$carpeta: no cal redirigir-la");
                continue;
            }
            ftpBackup($conn, ["$carpeta/.htaccess"], "redireccions-$carpeta");
            $tmp = fitxerTemporal(htaccessRedireccions($carpeta));
            ftpUpload($conn, $tmp, "$carpeta/.htaccess");
            unlink($tmp);
        }
    }
} finally {
    ftp_close($conn);
}

logmsg('🎉 Pujada completada');
exit(0);
