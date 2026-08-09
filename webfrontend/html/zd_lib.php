<?php
/**
 * Zendure SolarFlow - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche und der Dienst. So gibt es EINE Datei statt
 * dreier Kopien, die auseinanderlaufen.
 *
 * Zendure ist lokal ansprechbar - anders als Anker SOLIX. Es gibt zwei Wege:
 *
 *   HTTP   Neuere Geraete (SolarFlow 800 und die AC-Reihe) beantworten im
 *          Heimnetz unmittelbar HTTP:
 *              GET  http://<ip>/properties/report   -> Messwerte als JSON
 *              POST http://<ip>/properties/write    -> Einstellungen setzen
 *          Weder Konto noch Cloud noetig.
 *
 *   MQTT   Aeltere Geraete (Hub 1200, Hub 2000, Hyper 2000, Ace 1500,
 *          AIO 2400) sprechen nur MQTT, lassen sich aber einmalig auf einen
 *          eigenen Broker umbiegen - etwa den des LoxBerry. Danach laeuft
 *          auch das voellig ohne Cloud.
 *
 * Alles, was hier ueber die Protokolle steht, ist der offiziellen
 * Home-Assistant-Integration von Zendure entnommen (Zendure/Zendure-HA, MIT),
 * nicht geraten. Die Fundstellen stehen bei den einzelnen Angaben.
 *
 * Praefix 'zd_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('zd_e')) {
    function zd_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function zd_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) {
                $home = $k;
                break;
            }
        }
    }
    // Der Pluginordner ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    /* Frueher wurde hier auf den festen Namen "zendure" zurueckgefallen,
     * sobald config/plugins/<ordner> noch fehlte - etwa im Augenblick der
     * Installation. Haengt LoxBerry bei einer Zweitinstallation einen Zaehler
     * an (zendure_01, weil der Name schon belegt war), zeigten deren Pfade
     * damit auf die ERSTE Installation: gemeinsame Konfiguration - und darin
     * steht das Aktionstoken, mit dem sich der Speicher schalten laesst -,
     * gemeinsame Warteschlange, gemeinsames Protokoll.
     *
     * LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und bleibt deshalb.
     * Der feste Name greift nur noch dort, wo der ermittelte nachweislich kein
     * Plugin-Ordner sein kann: aus dem ausgepackten Archiv heraus heisst er
     * "html". */
    $lbp = getenv('LBPPLUGINDIR');
    if ($lbp) {
        $dir = $lbp;
    } elseif ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html') {
        $dir = 'zendure';
    }
    if ($home) {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/zendure.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/zendure.log',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/zendure.json',
            'sicherung' => $basis . '/config/zendure.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/zendure.log',
        );
    }
    return $p;
}

/**
 * Bekannte Modelle mit Befehlssatz und Werksgrenzen.
 *
 * Jede Zeile ist im Quelltext der offiziellen Home-Assistant-Integration
 * nachgesehen (custom_components/zendure_ha/devices/*.py), keine ist geraten.
 * Modelle, die dort nicht nachgesehen wurden, stehen bewusst NICHT in dieser
 * Tabelle - sie werden in der Oberflaeche von Hand eingestellt.
 *
 * Spalten: Befehlssatz, max. Laden (W), max. Entladen (W), max. Solar (W)
 * 0 beim Laden heisst: das Geraet kann ueberhaupt nicht aus dem Netz laden.
 */
function zd_modelle()
{
    return array(
        'solarflow800'      => array('zensdk', 1000, 800,  1200),
        'solarflow800plus'  => array('zensdk', 1000, 800,  1500),
        'solarflow800pro'   => array('zensdk', 1000, 800,  1200),
        'solarflow2400ac'   => array('zensdk', 2400, 2400, 2400),
        'solarflow2400acp'  => array('zensdk', 3200, 2400, 2400),
        'solarflow2400pro'  => array('zensdk', 3200, 2400, 3000),
        'hyper2000'         => array('hyper2000', 1200, 1200, 1600),
        'ace1500'           => array('ace_aio',    900,  800,  900),
        'aio2400'           => array('ace_aio',      0, 1200, 1200),
        'hub1200'           => array('hub',       0, 1200,  800),
    );
}

/**
 * Die vier Befehlssaetze.
 *
 * Sie unterscheiden sich nicht nur im Namen, sondern in der Form der
 * Nachricht. Jede Form ist im Quelltext der offiziellen
 * Home-Assistant-Integration nachgesehen:
 *
 *   zensdk    properties/write mit smartMode, acMode, outputLimit, inputLimit
 *             (devices/solarflow800.py, solarflow2400.py ueber ZendureZenSdk)
 *   hyper2000 function/invoke deviceAutomation, autoModelValue als Objekt,
 *             Laden ueber autoModelProgram 1 mit Preisliste
 *             (devices/hyper2000.py)
 *   ace_aio   function/invoke deviceAutomation, autoModelValue als Objekt,
 *             Laden ueber autoModelProgram 2 ohne Preisliste
 *             (devices/ace1500.py, devices/aio2400.py)
 *   hub       function/invoke deviceAutomation, autoModelValue als BLOSSE
 *             ZAHL statt als Objekt; kein Laden aus dem Netz moeglich
 *             (devices/hub1200.py)
 *
 * Wer ein Modell hat, das hier nicht aufgefuehrt ist, probiert die Saetze im
 * Reiter Test durch - geraten wird nichts.
 */
function zd_befehlssaetze()
{
    return array('zensdk', 'hyper2000', 'ace_aio', 'hub');
}

function zd_vorgaben()
{
    return array(
        'geraete'        => array(),
        'intervall'      => 15,     // Sekunden; lokal, also guenstig
        'mqtt_ein'       => 0,
        'mqtt_topic'     => 'zendure',
        'broker_host'    => '',     // leer = Broker aus der general.json
        'broker_port'    => 1883,
        'broker_user'    => '',
        'broker_pw'      => '',
        'steuerung_ein'  => 0,
        'schreibbremse'  => 30,     // Sekunden zwischen zwei Schreibbefehlen je Geraet
        'schrittweite'   => 50,     // Watt; Sollwerte werden darauf gerastert
        'verlauf_tage'   => 8,
        'aktionstoken'   => '',
        'wartezeit'      => 6,
    );
}

function zd_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/** Erst in eine Nebendatei, dann umbenennen - so liest niemand eine halb
 *  geschriebene Datei. */
function zd_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    /* Die Rechte gehoeren an das ANLEGEN, nicht hinterher.
     *
     * Bis 0.9.0 wurde erst geschrieben und dann chmod gerufen. In der
     * Konfiguration steht das Broker-Passwort im Klartext; zwischen dem
     * ersten Byte und dem chmod stand die Datei mit den Vorgaben der umask
     * da, ueblicherweise 0644. Das Fenster ist kurz, aber es gibt keinen
     * Grund, es offen zu lassen.
     *
     * fopen() legt nicht mit gewaehlten Rechten an - deshalb die Datei
     * zuerst leer anlegen, sofort schuetzen und dann fuellen. */
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    if ($rechte !== null) {
        @chmod($tmp, $rechte);
    }
    $ok = ftruncate($fh, 0) && fwrite($fh, $json) !== false;
    fflush($fh);
    fclose($fh);
    if (!$ok) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function zd_config()
{
    $p = zd_paths();
    // Selbstheilung: fehlende oder leere Konfiguration aus der Sicherung holen.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    return array_merge(zd_vorgaben(), zd_json_lesen($p['config']));
}

function zd_config_speichern($cfg)
{
    $p = zd_paths();
    // Die Konfiguration enthaelt das Broker-Passwort - deshalb 0600, nicht 0644.
    if (!zd_json_schreiben($p['config'], $cfg, 0600)) {
        return false;
    }
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
    return true;
}

/**
 * Geraeteliste, 1-basiert, nur vollstaendige Eintraege.
 *
 * Ein Geraet ist entweder ueber HTTP oder ueber MQTT erreichbar:
 *   art=http  braucht ip (oder Hostnamen)
 *   art=mqtt  braucht prodkey und deviceid
 */
function zd_geraete()
{
    $cfg = zd_config();
    $modelle = zd_modelle();
    $out = array();
    $n = 0;
    foreach ((array) $cfg['geraete'] as $g) {
        if (!is_array($g)) {
            continue;
        }
        $art = (isset($g['art']) && $g['art'] === 'mqtt') ? 'mqtt' : 'http';
        $ip = trim((string) (isset($g['ip']) ? $g['ip'] : ''));
        $prodkey = trim((string) (isset($g['prodkey']) ? $g['prodkey'] : ''));
        $deviceid = trim((string) (isset($g['deviceid']) ? $g['deviceid'] : ''));
        if ($art === 'http' && $ip === '') {
            continue;
        }
        if ($art === 'mqtt' && ($prodkey === '' || $deviceid === '')) {
            continue;
        }
        $n++;
        $modell = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) (isset($g['modell']) ? $g['modell'] : '')));
        $bekannt = isset($modelle[$modell]) ? $modelle[$modell] : null;
        $satz = trim((string) (isset($g['satz']) ? $g['satz'] : ''));
        if (!in_array($satz, zd_befehlssaetze(), true)) {
            $satz = $bekannt !== null ? $bekannt[0] : 'zensdk';
        }
        $out[$n] = array(
            'nr'        => $n,
            'name'      => trim((string) (isset($g['name']) ? $g['name'] : '')) !== ''
                           ? trim((string) $g['name']) : ('Speicher ' . $n),
            'art'       => $art,
            'ip'        => $ip,
            'prodkey'   => $prodkey,
            'deviceid'  => $deviceid,
            'sn'        => trim((string) (isset($g['sn']) ? $g['sn'] : '')),
            'modell'    => $modell,
            'satz'      => $satz,
            'max_laden'    => isset($g['max_laden']) && $g['max_laden'] !== ''
                              ? max(0, min(5000, (int) $g['max_laden']))
                              : ($bekannt !== null ? $bekannt[1] : 800),
            'max_entladen' => isset($g['max_entladen']) && $g['max_entladen'] !== ''
                              ? max(0, min(5000, (int) $g['max_entladen']))
                              : ($bekannt !== null ? $bekannt[2] : 800),
        );
    }
    return $out;
}

function zd_geraet($nr)
{
    $g = zd_geraete();
    $nr = max(1, (int) $nr);
    return isset($g[$nr]) ? $g[$nr] : null;
}

/** Zufallstoken fuer den unangemeldeten Endpunkt. */
function zd_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

function zd_token()
{
    $cfg = zd_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = zd_token_erzeugen();
        zd_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Zwischenspeicher ---------------- */

function zd_loxone()
{
    return zd_json_lesen(zd_paths()['datadir'] . '/loxone.json');
}

function zd_zustand()
{
    return zd_json_lesen(zd_paths()['datadir'] . '/zustand.json');
}

function zd_cache()
{
    return zd_json_lesen(zd_paths()['datadir'] . '/cache.json');
}

function zd_werte()
{
    $l = zd_loxone();
    return isset($l['geraete']) && is_array($l['geraete']) ? $l['geraete'] : array();
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function zd_alter()
{
    $l = zd_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Protokollierung ---------------- */

function zd_log($text)
{
    $p = zd_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        // Rotation: die letzten 400 Zeilen behalten
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
 *  Logdatei durch eine Dauerstoerung unlesbar. */
function zd_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $f = zd_paths()['datadir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        zd_log($text);
    }
}

/* ---------------- Dienst ---------------- */

function zd_dienst_pid()
{
    $f = zd_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'zendure_dienst.php') !== false ? $pid : 0;
}

function zd_dienst_soll()
{
    return is_file(zd_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function zd_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = zd_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, Meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - Ergebnis unbekannt.
 * Es wird nie ein Erfolg gemeldet, den niemand geprueft hat.
 */
/**
 * Die letzten $anzahl Zeilen einer Datei, neueste zuerst.
 *
 * Bis 0.9.0 las die Oberflaeche das ganze Protokoll mit file() ein. Der
 * Hinweis auf den Speicher war berechtigt - der vorgeschlagene Weg ueber
 * exec("tail") ist aber der langsamste der drei. Gemessen an einer Datei mit
 * 12.000 Zeilen (610 kB), je 20 Durchlaeufe, Spitzenspeicher in einem eigenen
 * Prozess:
 *
 *   ganz einlesen            0,37 ms   zusaetzlich 2048 kB
 *   exec("tail -n 400")      2,17 ms   zusaetzlich    0 kB
 *   rueckwaerts mit fseek    0,05 ms   zusaetzlich    0 kB
 *
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat. Die Ausgabe
 * ist Zeile fuer Zeile dieselbe wie bisher - nachgeprueft.
 */
function zd_log_ende($datei, $anzahl = 400, $block = 8192)
{
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/** Obergrenze fuer eine Wartezeit, die aus einer Web-Anfrage kommt. */
define('ZD_WARTEN_WEB', 10);

function zd_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = zd_paths();
    $cfg = zd_config();
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    /* Bis 0.9.0 bei 20 Sekunden gedeckelt. Fuer einen Aufruf aus dem
     * Webfrontend ist das zu lang: der Webserver bricht die Anfrage
     * typischerweise nach 15 bis 30 Sekunden mit 504 ab, und der Benutzer
     * sieht einen Serverfehler statt einer Auskunft.
     *
     * Der Dienst arbeitet den Befehl trotzdem zu Ende - die Warteschlange
     * liegt im Dateisystem, nicht in dieser Anfrage. Was daraus wurde,
     * steht im Protokoll. */
    $wartezeit = max(0, min(ZD_WARTEN_WEB, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    /* json_encode gibt bei ungueltigem UTF-8 false zurueck. file_put_contents
     * macht daraus eine leere Zeichenkette, schreibt null Byte und meldet das
     * als Erfolg - der Rueckgabewert ist 0, nicht false, die Pruefung auf
     * "=== false" greift also nicht, und rename schiebt die leere Datei in die
     * Warteschlange. Der Dienst faende dort einen Befehl, den er nicht deuten
     * kann - bei einem Speicher, der geladen oder entladen werden soll, ist
     * das kein Schoenheitsfehler. Deshalb zuerst kodieren und pruefen. */
    $zd_js = json_encode($befehl);
    if ($zd_js === false) {
        return array(0, 'Der Befehl liess sich nicht als JSON darstellen (ungueltiges UTF-8).');
    }
    if (@file_put_contents($tmp, $zd_js) !== strlen($zd_js) || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = zd_json_lesen($antwort);
            /* Gelesen ist erledigt. Bis 0.9.0 blieb die Datei liegen und
             * sammelte sich im Datenordner an. */
            @unlink($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht geantwortet.');
}

/* ---------------- Verlauf ---------------- */

function zd_verlauf_lesen($nummer, $tag = '')
{
    if ($tag === '') {
        $tag = date('Ymd');
    }
    $f = zd_paths()['datadir'] . '/verlauf/geraet' . (int) $nummer . '_' . $tag . '.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            $c = explode(';', $zeile);
            if (count($c) >= 2) {
                $out[] = array((int) $c[0], (float) $c[1], isset($c[2]) && $c[2] !== '' ? (float) $c[2] : 0);
            }
        }
    }
    return $out;
}

/* ---------------- MQTT-Gateway des LoxBerry ----------------
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Es wird nicht nachinstalliert, sondern unter System -> MQTT Gateway
 * eingeschaltet.
 *
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt. Eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen -
 * massgeblich ist Gatewayautostart.
 */
function zd_mqtt_zustand()
{
    $p = zd_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0, 'broker' => '',
                  'brokerport' => '', 'user' => '', 'pw' => '', 'lokal' => 0);
    if ($p['home'] === '') {
        return $leer;
    }
    $gen = zd_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) {
        $m = $gen['Mqtt'];
    } elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) {
        $m = $gen['mqtt'];
    }
    if (!$m) {
        return $leer;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) {
            return $m[$gross];
        }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'), array('1', 'true'), true) ? 1 : 0,
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'user'       => (string) $hol('Brokeruser', 'brokeruser'),
        'pw'         => (string) $hol('Brokerpass', 'brokerpass'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/**
 * Werte ueber das LoxBerry-Gateway veroeffentlichen.
 *
 * Bewusst ueber den UDP-Eingang des Gateways und nicht mit einem eigenen
 * MQTT-Client: so muss das Plugin ueberhaupt keine Broker-Zugangsdaten
 * kennen, um zu senden. Das Gateway hat sie ohnehin.
 */
function zd_mqtt_senden(array $paare, $praefix)
{
    $z = zd_mqtt_zustand();
    if (!$z['udpport']) {
        zd_log_gebremst('mqtt_kein_port', 'MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.');
        return false;
    }
    if (!$z['autostart']) {
        zd_log_gebremst('mqtt_aus', 'MQTT: das Gateway ist nicht auf Autostart gestellt '
            . '(System, MQTT Gateway). Es wird gesendet, aber vermutlich hoert niemand zu.');
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        zd_log_gebremst('mqtt_socket', 'MQTT: Socket nicht moeglich.');
        return false;
    }
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') {
            continue;   // fehlender Wert: nichts senden statt eine erfundene 0
        }
        $msg = 'publish ' . $praefix . '/' . $k . ' ' . $v;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']);
    }
    socket_close($s);
    return true;
}

/** Alle Themen, die der Dienst veroeffentlicht, mit ihrer Bedeutung. */
function zd_mqtt_themen()
{
    return array(
        'ok'                 => 'ZD_MQTT.OK',
        'geraete'            => 'ZD_MQTT.GERAETE',
        'geraetN/soc'        => 'ZD_MQTT.SOC',
        'geraetN/pv'         => 'ZD_MQTT.PV',
        'geraetN/haus'       => 'ZD_MQTT.HAUS',
        'geraetN/netz'       => 'ZD_MQTT.NETZ',
        'geraetN/batp'       => 'ZD_MQTT.BATP',
        'geraetN/laden'      => 'ZD_MQTT.LADEN',
        'geraetN/entladen'   => 'ZD_MQTT.ENTLADEN',
        'geraetN/grenze_aus' => 'ZD_MQTT.GRENZE_AUS',
        'geraetN/grenze_ein' => 'ZD_MQTT.GRENZE_EIN',
        'geraetN/soc_min'    => 'ZD_MQTT.SOC_MIN',
        'geraetN/soc_max'    => 'ZD_MQTT.SOC_MAX',
        'geraetN/acmodus'    => 'ZD_MQTT.ACMODUS',
        'geraetN/online'     => 'ZD_MQTT.ONLINE',
        'geraetN/packs'      => 'ZD_MQTT.PACKS',
        'geraetN/dvolt'      => 'ZD_MQTT.DVOLT',
        'geraetN/temp'       => 'ZD_MQTT.TEMP',
        'geraetN/pack/<SN>/soc'   => 'ZD_MQTT.P_SOC',
        'geraetN/pack/<SN>/volt'  => 'ZD_MQTT.P_VOLT',
        'geraetN/pack/<SN>/dvolt' => 'ZD_MQTT.P_DVOLT',
        'geraetN/pack/<SN>/temp'  => 'ZD_MQTT.P_TEMP',
        'geraetN/pack/<SN>/watt'  => 'ZD_MQTT.P_WATT',
    );
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.0.0 (ap_xml_virtual_in_http) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist.
 * ================================================================== */

function zd_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function zd_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . zd_x($kopf['title']) . '" ';
    $o .= 'Comment="' . zd_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . zd_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . zd_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . zd_x($c['title']) . '" ';
        $o .= 'Comment="' . zd_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . zd_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Werte des Status-Endpunkts: Einheit und Sprachschluessel der Bedeutung.
 *
 * Die Herkunft steht jeweils im Kommentar - alle Eigenschaftsnamen stammen aus
 * der offiziellen Home-Assistant-Integration (device.py).
 */
function zd_status_felder()
{
    return array(
        'SOC'        => array('%', 'ZD_FELD.SOC'),        // electricLevel
        'SOCMIN'     => array('%', 'ZD_FELD.SOCMIN'),     // minSoc
        'SOCMAX'     => array('%', 'ZD_FELD.SOCMAX'),     // socSet
        'PV'         => array('W', 'ZD_FELD.PV'),         // solarInputPower
        'HAUS'       => array('W', 'ZD_FELD.HAUS'),       // outputHomePower
        'NETZ'       => array('W', 'ZD_FELD.NETZ'),       // gridInputPower
        'LADEN'      => array('W', 'ZD_FELD.LADEN'),      // outputPackPower
        'ENTLADEN'   => array('W', 'ZD_FELD.ENTLADEN'),   // packInputPower
        'BATP'       => array('W', 'ZD_FELD.BATP'),       // berechnet
        'GRENZEAUS'  => array('W', 'ZD_FELD.GRENZEAUS'),  // outputLimit
        'GRENZEEIN'  => array('W', 'ZD_FELD.GRENZEEIN'),  // inputLimit
        'ACMODUS'    => array('',  'ZD_FELD.ACMODUS'),    // acMode
        'PACKS'      => array('',  'ZD_FELD.PACKS'),      // Anzahl packData
        'DVOLT'      => array('V', 'ZD_FELD.DVOLT'),      // maxVol - minVol
        'TEMP'       => array('C', 'ZD_FELD.TEMP'),       // maxTemp
        'ALTER'      => array('s', 'ZD_FELD.ALTER'),
        'OK'         => array('',  'ZD_FELD.OK'),
    );
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function zd_vorlage($nummer = 1)
{
    $p = zd_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $token = zd_token();
    $cmds = array();
    foreach (zd_status_felder() as $feld => $info) {
        // Der Text laeuft gleich durch zd_x() und wuerde dort ein zweites Mal
        // maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
        // aufloesen - sonst stuende in Loxone Config wortwoertlich 'l&auml;dt'.
        $bedeutung = trim(strip_tags(html_entity_decode(zd_t($info[1]), ENT_QUOTES, 'UTF-8')));
        $cmds[] = array(
            'title'   => 'ZENDURE_' . $nummer . '_' . $feld,
            'comment' => $bedeutung . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
        );
    }
    $adresse = 'http://' . $host . '/plugins/' . $p['plugin']
             . '/index.php?token=' . $token . '&aktion=status&geraet=' . (int) $nummer;
    return array(
        'zendure_geraet' . (int) $nummer . '.xml',
        zd_xml_virtual_in_http(array(
            'title'   => 'Zendure SolarFlow ' . (int) $nummer,
            'address' => $adresse,
            'polling' => '60',
            'comment' => 'Erzeugt vom LoxBerry-Plugin Zendure SolarFlow (' . date('d.m.Y') . ')',
        ), $cmds),
    );
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein zd_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */

function zd_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function zd_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . zd_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}
