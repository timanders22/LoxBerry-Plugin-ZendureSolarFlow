#!/usr/bin/env php
<?php
/**
 * Zendure SolarFlow - Abrufdienst
 *
 * Holt die Werte der Geraete, fuehrt sie in einem Zwischenspeicher zusammen,
 * veroeffentlicht sie ueber das LoxBerry-MQTT-Gateway und arbeitet
 * Schreibbefehle aus einer Warteschlange ab, die der Loxone-Endpunkt fuellt.
 *
 * Zwei Wege, beide lokal:
 *
 *   HTTP   GET  http://<ip>/properties/report  liefert die Messwerte
 *          POST http://<ip>/properties/write   setzt Eigenschaften
 *          (SolarFlow 800 und die AC-Reihe)
 *
 *   MQTT   Das Geraet veroeffentlicht selbst auf einem Broker, auf den es
 *          einmalig umgestellt wurde. Gehorcht wird mit mosquitto_sub,
 *          gesendet mit mosquitto_pub - ein eigener MQTT-Client waere ein
 *          nachgebautes Protokoll, das sich ohne Geraet nicht gegen das
 *          Original messen liesse.
 *
 * Drei Aufgaben, drei Dateien - dies ist der Dienst. Oberflaeche und
 * Miniserver-Endpunkt rufen nie ein Geraet auf, sondern lesen den
 * Zwischenspeicher beziehungsweise legen Befehle ab.
 *
 * Aufrufe:
 *   zendure_dienst.php               Dienst (Dauerbetrieb)
 *   zendure_dienst.php --einmal      ein Durchgang, dann Ende
 *   zendure_dienst.php --selbsttest  Pruefungen ohne Geraet, Klartextausgabe
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set(@date_default_timezone_get() ?: 'Europe/Berlin');

/* Bibliothek finden: <home>/webfrontend/html/plugins/<ordner>/zd_lib.php,
 * abgeleitet aus dem eigenen Ablageort <home>/bin/plugins/<ordner>. */
$zd_self = dirname(__FILE__);
$zd_name = basename($zd_self);
$zd_home = dirname(dirname(dirname($zd_self)));
$zd_gefunden = false;
foreach (array(
    $zd_home . '/webfrontend/html/plugins/' . $zd_name . '/zd_lib.php',
    $zd_home . '/webfrontend/htmlauth/plugins/' . $zd_name . '/zd_lib.php',
    dirname($zd_self) . '/webfrontend/html/zd_lib.php',
    dirname($zd_self) . '/../webfrontend/html/zd_lib.php',
) as $zd_kandidat) {
    if (is_file($zd_kandidat)) {
        require_once $zd_kandidat;
        $zd_gefunden = true;
        break;
    }
}
if (!$zd_gefunden) {
    fwrite(STDERR, "zd_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}

$GLOBALS['zd_lauf'] = true;
$GLOBALS['zd_zustaende'] = array();   // Nr => Rohwerte je Geraet
$GLOBALS['zd_letzte_schreibzeit'] = array();
$GLOBALS['zd_horcher'] = null;

/* ------------------------------------------------------------------
 * Kleine Helfer
 * ------------------------------------------------------------------ */

/** Zahl oder null. Ein fehlender Wert bleibt null und wird NICHT zu 0 -
 *  eine 0 waere eine stille Falschaussage. */
function zd_zahl($wert, $nachkomma = 0)
{
    if ($wert === null || $wert === '' || is_array($wert)) {
        return null;
    }
    if (!is_numeric($wert)) {
        return null;
    }
    $f = (float) $wert;
    return $nachkomma > 0 ? round($f, $nachkomma) : (int) round($f);
}

function zd_erstes(array $q, array $schluessel)
{
    foreach ($schluessel as $k) {
        if (isset($q[$k]) && $q[$k] !== '') {
            return $q[$k];
        }
    }
    return null;
}

/** Fehlermeldungen, die sagen, wer geantwortet hat. */
function zd_fehlertext($text, $errno = 0)
{
    $klein = strtolower((string) $text);
    if ($errno === 111 || strpos($klein, 'connection refused') !== false) {
        return 'Verbindung abgewiesen (ECONNREFUSED): das Geraet ist erreichbar, aber auf diesem '
             . 'Port lauscht nichts. Meist ist die lokale Schnittstelle im Geraet nicht eingeschaltet.';
    }
    if ($errno === 113 || strpos($klein, 'no route to host') !== false) {
        return 'Kein Weg zum Ziel (EHOSTUNREACH): pruefen Sie Netz, IP-Adresse und Standardroute.';
    }
    if (strpos($klein, 'timed out') !== false || strpos($klein, 'timeout') !== false) {
        return 'Zeitueberlauf: das Geraet hat nicht geantwortet. Richtige IP-Adresse? Im WLAN?';
    }
    if (strpos($klein, 'network is unreachable') !== false) {
        return 'Netz nicht erreichbar (ENETUNREACH): der LoxBerry kommt in dieses Netz gar nicht hinein. '
             . 'Liegt das Geraet in einem anderen Netzbereich oder VLAN?';
    }
    if (strpos($klein, 'name or service not known') !== false || strpos($klein, 'getaddrinfo') !== false) {
        return 'Namensaufloesung fehlgeschlagen: der Hostname ist im Netz nicht bekannt. '
             . 'Statt des Namens die IP-Adresse eintragen.';
    }
    if (strpos($klein, '<html') !== false || strpos($klein, '<!doctype') !== false) {
        return 'Es kam HTML statt JSON zurueck - geantwortet hat also ein vorgelagerter Dienst '
             . '(Router, Portal, Fehlerseite), nicht das Geraet.';
    }
    return (string) $text;
}

/* ------------------------------------------------------------------
 * Weg 1: lokale HTTP-Schnittstelle
 * ------------------------------------------------------------------ */

/** Kopfzeilen fuer jede Anfrage. Manche Gegenstellen weisen Anfragen ohne
 *  User-Agent ab; das kostet sonst eine lange Fehlersuche. */
function zd_http_kopf()
{
    return "Content-Type: application/json; charset=UTF-8\r\n"
         . "User-Agent: LoxBerry-Zendure-Plugin/0.9\r\n"
         . "Accept: application/json\r\n"
         . "Accept-Language: de,en;q=0.8\r\n"
         . "Accept-Encoding: identity\r\n";
}

/** GET http://<ip>/properties/report -> array oder array('_fehler' => Text) */
function zd_http_abruf(array $g, $tmo = 4)
{
    $url = 'http://' . $g['ip'] . '/properties/report';
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'header' => zd_http_kopf(), 'timeout' => $tmo, 'ignore_errors' => true,
    )));
    $roh = @file_get_contents($url, false, $ctx);
    if ($roh === false) {
        $e = error_get_last();
        return array('_fehler' => zd_fehlertext(isset($e['message']) ? $e['message'] : 'keine Antwort'));
    }
    $j = json_decode($roh, true);
    if (!is_array($j)) {
        return array('_fehler' => zd_fehlertext(substr($roh, 0, 200)));
    }
    return $j;
}

/** POST http://<ip>/properties/write */
function zd_http_schreiben(array $g, array $eigenschaften, $tmo = 4)
{
    static $lfd = 0;
    $lfd++;
    $koerper = json_encode(array(
        'properties' => $eigenschaften,
        'id' => $lfd,
        'sn' => $g['sn'],
    ));
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST', 'header' => zd_http_kopf(), 'content' => $koerper,
        'timeout' => $tmo, 'ignore_errors' => true,
    )));
    $roh = @file_get_contents('http://' . $g['ip'] . '/properties/write', false, $ctx);
    if ($roh === false) {
        $e = error_get_last();
        return array(0, zd_fehlertext(isset($e['message']) ? $e['message'] : 'keine Antwort'));
    }
    return array(1, trim((string) $roh));
}

/* ------------------------------------------------------------------
 * Weg 2: lokales MQTT ueber die mosquitto-Werkzeuge
 *
 * Die Zugangsdaten kommen NICHT auf die Kommandozeile - dort staenden sie in
 * der Prozessliste und waeren fuer jeden Benutzer des LoxBerry sichtbar.
 * mosquitto_sub und mosquitto_pub lesen Vorgaben aus
 * $HOME/.config/mosquitto_sub beziehungsweise .../mosquitto_pub; genau dafuer
 * ist diese Datei laut Handbuch gedacht ("Use of a config file allows you to
 * authenticate without the need to show the username and password on the
 * command line"). Das Plugin setzt HOME auf einen eigenen Ordner mit den
 * Rechten 0700 und legt die Dateien mit 0600 dort ab.
 * ------------------------------------------------------------------ */

function zd_broker()
{
    $cfg = zd_config();
    $m = zd_mqtt_zustand();
    $host = trim((string) $cfg['broker_host']);
    if ($host === '') {
        $host = $m['broker'] !== '' ? $m['broker'] : '127.0.0.1';
    }
    $port = (int) $cfg['broker_port'];
    if ($port <= 0 || $port > 65535) {
        $port = $m['brokerport'] !== '' ? (int) $m['brokerport'] : 1883;
    }
    $user = trim((string) $cfg['broker_user']);
    $pw = (string) $cfg['broker_pw'];
    if ($user === '' && $m['user'] !== '') {
        // Nichts eingetragen: die Zugangsdaten des LoxBerry-Brokers nehmen.
        $user = $m['user'];
        $pw = $m['pw'];
    }
    return array('host' => $host, 'port' => $port, 'user' => $user, 'pw' => $pw);
}

/** Legt die Vorgabedateien fuer mosquitto_sub/_pub an. Rueckgabe: HOME-Pfad. */
function zd_mosq_heim()
{
    $heim = zd_paths()['datadir'] . '/mosq';
    $cfgdir = $heim . '/.config';
    if (!is_dir($cfgdir)) {
        @mkdir($cfgdir, 0700, true);
    }
    @chmod($heim, 0700);
    @chmod($cfgdir, 0700);
    $b = zd_broker();
    $zeilen = "# Erzeugt vom LoxBerry-Plugin Zendure SolarFlow.\n"
            . "# Hier stehen die Broker-Zugangsdaten, damit sie NICHT auf der\n"
            . "# Kommandozeile und damit in der Prozessliste landen.\n"
            . '-h ' . $b['host'] . "\n"
            . '-p ' . $b['port'] . "\n";
    if ($b['user'] !== '') {
        $zeilen .= '-u ' . $b['user'] . "\n";
        if ($b['pw'] !== '') {
            $zeilen .= '-P ' . $b['pw'] . "\n";
        }
    }
    foreach (array('mosquitto_sub', 'mosquitto_pub') as $datei) {
        $pfad = $cfgdir . '/' . $datei;
        if (!is_file($pfad) || (string) @file_get_contents($pfad) !== $zeilen) {
            @file_put_contents($pfad, $zeilen);
        }
        @chmod($pfad, 0600);
    }
    return $heim;
}

function zd_mosq_vorhanden()
{
    $a = array();
    @exec('command -v mosquitto_sub 2>/dev/null', $a);
    $b = array();
    @exec('command -v mosquitto_pub 2>/dev/null', $b);
    return (count($a) > 0 && count($b) > 0) ? 1 : 0;
}

/** Startet mosquitto_sub fuer alle MQTT-Geraete. Rueckgabe: true bei Erfolg. */
function zd_horcher_starten(array $geraete)
{
    if ($GLOBALS['zd_horcher'] !== null) {
        return true;
    }
    $mqtt = array();
    foreach ($geraete as $g) {
        if ($g['art'] === 'mqtt') {
            $mqtt[] = $g;
        }
    }
    if (!$mqtt) {
        return true;   // nichts zu horchen - kein Fehler
    }
    if (!zd_mosq_vorhanden()) {
        zd_log_gebremst('mosq_fehlt',
            'mosquitto_sub fehlt. Die MQTT-Geraete koennen deshalb nicht gelesen werden. '
            . 'Abhilfe: sudo apt install mosquitto-clients');
        return false;
    }
    $heim = zd_mosq_heim();
    // Themen je Geraet, beide Schreibweisen. Die Geraete melden unter
    // /<productKey>/<deviceId>/... und unter iot/<productKey>/<deviceId>/...
    // (belegt in Zendure/Zendure-HA, api.py: mqttConnect).
    $args = array();
    foreach ($mqtt as $g) {
        $args[] = '-t ' . escapeshellarg('/' . $g['prodkey'] . '/' . $g['deviceid'] . '/#');
        $args[] = '-t ' . escapeshellarg('iot/' . $g['prodkey'] . '/' . $g['deviceid'] . '/#');
    }
    // -F '%t\t%p': Thema, Tabulator, Nutzdaten. Die Nutzdaten sind JSON und
    // damit einzeilig; der Tabulator kann in Zendure-Themen nicht vorkommen.
    /* "exec" davor, damit KEIN Shell-Zwischenprozess stehen bleibt.
     *
     * proc_open() startet ueber /bin/sh -c. proc_terminate() trifft dann nur
     * diese Shell; mosquitto_sub liefe als verwaistes Kind weiter und hinge
     * weiter am Broker. Mit "exec" ersetzt mosquitto_sub die Shell - der
     * Prozess, den proc_open verwaltet, IST dann der Horcher. */
    $befehl = 'exec mosquitto_sub -i ' . escapeshellarg('loxberry-zendure-' . getmypid())
            . ' -q 0 -F ' . escapeshellarg('%t\t%p') . ' ' . implode(' ', $args);
    $rohre = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $proc = @proc_open($befehl, $rohre, $leitungen, null, array('HOME' => $heim, 'XDG_CONFIG_HOME' => $heim . '/.config'));
    if (!is_resource($proc)) {
        zd_log('mosquitto_sub liess sich nicht starten.');
        return false;
    }
    stream_set_blocking($leitungen[1], false);
    stream_set_blocking($leitungen[2], false);
    $GLOBALS['zd_horcher'] = array('proc' => $proc, 'aus' => $leitungen[1], 'fehler' => $leitungen[2]);
    zd_log('MQTT-Horcher gestartet fuer ' . count($mqtt) . ' Geraet(e).');
    return true;
}

function zd_horcher_beenden()
{
    if ($GLOBALS['zd_horcher'] === null) {
        return;
    }
    @fclose($GLOBALS['zd_horcher']['aus']);
    @fclose($GLOBALS['zd_horcher']['fehler']);
    $proc = $GLOBALS['zd_horcher']['proc'];
    @proc_terminate($proc);            // SIGTERM
    /* Kurz nachsehen, ob er wirklich geht, und sonst nachsetzen. Ohne das
     * blieb ein haengender mosquitto_sub am Broker stehen; proc_close()
     * wartet dann unbegrenzt auf ihn und der ganze Dienst steht mit. */
    for ($i = 0; $i < 20; $i++) {
        $st = @proc_get_status($proc);
        if (!is_array($st) || !$st['running']) {
            break;
        }
        usleep(100000);
    }
    $st = @proc_get_status($proc);
    if (is_array($st) && $st['running']) {
        @proc_terminate($proc, 9);     // SIGKILL
        usleep(200000);
    }
    @proc_close($proc);
    $GLOBALS['zd_horcher'] = null;
}

/** Liest alle wartenden Zeilen des Horchers und verarbeitet sie. */
function zd_horcher_lesen(array $geraete)
{
    if ($GLOBALS['zd_horcher'] === null) {
        return;
    }
    $st = @proc_get_status($GLOBALS['zd_horcher']['proc']);
    if (is_array($st) && !$st['running']) {
        $fehler = trim((string) @stream_get_contents($GLOBALS['zd_horcher']['fehler']));
        zd_log_gebremst('horcher_tot', 'Der MQTT-Horcher ist beendet (Rueckgabewert '
            . (int) $st['exitcode'] . ')' . ($fehler !== '' ? ': ' . $fehler : '')
            . '. Haeufigste Ursache: falsche Broker-Zugangsdaten.', 300);
        zd_horcher_beenden();
        return;
    }
    /* Teilzeilen puffern.
     *
     * Die Leitung ist unblockiert. fgets() gibt dann zurueck, was gerade da
     * ist - auch ein Stueck OHNE abschliessenden Zeilenumbruch. Nachgestellt
     * mit einem Schreiber, der eine Zeile in zwei Stuecken liefert:
     *
     *   thema1 => {"a":1}            (ganz)
     *   thema2 => {"b":              <- halbe Nutzlast, JSON kaputt
     *   VERWORFEN: '2}'              <- der Rest, ohne Tabulator
     *   thema3 => {"c":3}            (ganz)
     *
     * Die Meldung war also nicht bloss verspaetet, sondern verstuemmelt -
     * und der Rest landete als eigene, unbrauchbare Zeile. Gesammelt wird
     * jetzt so lange, bis ein echter Zeilenumbruch kommt. Der Rest bleibt
     * bis zum naechsten Durchgang stehen. */
    static $rest = '';
    $zaehler = 0;
    while (($stueck = fgets($GLOBALS['zd_horcher']['aus'])) !== false && $zaehler < 500) {
        $rest .= $stueck;
        if (substr($rest, -1) !== "\n") {
            /* Noch keine ganze Zeile. Eine Notbremse gegen eine Gegenstelle,
             * die nie einen Umbruch schickt: eine Zendure-Nutzlast ist ein
             * paar hundert Byte gross, 64 kB sind keine mehr. */
            if (strlen($rest) > 65536) {
                zd_log_gebremst('pipe_lang', 'Ueber 64 kB ohne Zeilenumbruch vom '
                    . 'MQTT-Horcher - der Puffer wird verworfen.', 300);
                $rest = '';
            }
            continue;
        }
        /* Es koennen mehrere ganze Zeilen auf einmal angekommen sein - und
         * das letzte Stueck kann wieder ein Anfang sein, wenn der Puffer
         * mitten in der naechsten Zeile endet. explode liefert dann als
         * letztes Element den Rest; er wandert zurueck in $rest. */
        $zeilen = explode("\n", $rest);
        $rest = array_pop($zeilen);
        foreach ($zeilen as $zeile) {
            $zaehler++;
            $zeile = rtrim($zeile, "\r\n");
            if ($zeile === '') {
                continue;
            }
            $teile = explode("\t", $zeile, 2);
            if (count($teile) < 2) {
                continue;
            }
            zd_mqtt_nachricht($geraete, $teile[0], $teile[1]);
        }
    }
}

/** Eine eingegangene MQTT-Nachricht einsortieren. */
function zd_mqtt_nachricht(array $geraete, $thema, $nutzdaten)
{
    // Thema: [iot]/<productKey>/<deviceId>/<rest>
    $t = explode('/', ltrim($thema, '/'), 4);
    if ($t[0] === 'iot') {
        array_shift($t);
        $t = explode('/', implode('/', $t), 3);
    }
    if (count($t) < 3) {
        return;
    }
    $deviceid = $t[1];
    $rest = $t[2];
    if ($rest !== 'properties/report') {
        return;   // alles andere ist fuer dieses Plugin ohne Belang
    }
    $payload = json_decode($nutzdaten, true);
    if (!is_array($payload)) {
        return;
    }
    foreach ($geraete as $nr => $g) {
        if ($g['art'] === 'mqtt' && $g['deviceid'] === $deviceid) {
            zd_zustand_mischen($nr, $payload);
            return;
        }
    }
}

/** Sendet eine MQTT-Nachricht an ein Geraet. Rueckgabe: array(ok, Meldung) */
function zd_mqtt_an_geraet(array $g, $themenrest, array $inhalt)
{
    static $lfd = 0;
    if (!zd_mosq_vorhanden()) {
        return array(0, 'mosquitto_pub fehlt. Abhilfe: sudo apt install mosquitto-clients');
    }
    $lfd++;
    $inhalt['messageId'] = $lfd;
    $inhalt['timestamp'] = time();
    $thema = 'iot/' . $g['prodkey'] . '/' . $g['deviceid'] . '/' . $themenrest;
    $heim = zd_mosq_heim();
    $befehl = 'HOME=' . escapeshellarg($heim) . ' XDG_CONFIG_HOME=' . escapeshellarg($heim . '/.config')
            . ' mosquitto_pub -t ' . escapeshellarg($thema)
            . ' -m ' . escapeshellarg(json_encode($inhalt)) . ' 2>&1';
    $ausgabe = array();
    $code = 0;
    @exec($befehl, $ausgabe, $code);
    if ($code !== 0) {
        return array(0, 'mosquitto_pub meldet Fehler ' . $code . ': ' . implode(' ', $ausgabe));
    }
    return array(1, 'An ' . $thema . ' gesendet.');
}

/* ------------------------------------------------------------------
 * Zustand je Geraet
 * ------------------------------------------------------------------ */

/** Eine Antwort (HTTP oder MQTT) in den Zustand eines Geraets einmischen. */
function zd_zustand_mischen($nr, array $payload)
{
    $nr = (int) $nr;
    if (!isset($GLOBALS['zd_zustaende'][$nr])) {
        $GLOBALS['zd_zustaende'][$nr] = array('eigenschaften' => array(), 'packs' => array(), 'ts' => 0);
    }
    $z =& $GLOBALS['zd_zustaende'][$nr];

    if (isset($payload['properties']) && is_array($payload['properties'])) {
        foreach ($payload['properties'] as $k => $v) {
            $z['eigenschaften'][$k] = $v;
        }
        $z['ts'] = time();
    }
    // Manche Antworten liefern die Eigenschaften auch flach mit.
    foreach ($payload as $k => $v) {
        if (!is_array($v) && $k !== 'properties') {
            $z['eigenschaften'][$k] = $v;
            $z['ts'] = time();
        }
    }
    if (isset($payload['packData']) && is_array($payload['packData'])) {
        foreach ($payload['packData'] as $pack) {
            if (!is_array($pack) || !isset($pack['sn']) || $pack['sn'] === '') {
                continue;
            }
            $sn = (string) $pack['sn'];
            if (!isset($z['packs'][$sn])) {
                $z['packs'][$sn] = array();
            }
            foreach ($pack as $k => $v) {
                $z['packs'][$sn][$k] = $v;
            }
            // Wann wurde dieser Akkupack zuletzt gemeldet?
            $z['packs'][$sn]['_gesehen'] = time();
        }
        /* Akkupacks, die sich lange nicht mehr gemeldet haben, verfallen.
         *
         * Bis 0.9.0 blieb jede einmal gesehene Seriennummer fuer immer im
         * Zustand stehen. Wer einen Akku ausbaut oder tauscht, haette ihn
         * in der Oberflaeche und in Loxone weiter aufgefuehrt bekommen -
         * mit dem Ladestand, den er beim Ausbau hatte. Ein Wert, der sich
         * nie mehr aendert und trotzdem wie eine Messung aussieht, ist
         * schlimmer als gar keiner.
         *
         * Sechs Stunden, nicht sechs Minuten: ein Akkupack meldet sich
         * nicht in jedem Telegramm, und ein Speicher, der ueber Nacht
         * ruht, soll morgens nicht als verschwunden gelten. */
        $grenze = time() - 6 * 3600;
        foreach ($z['packs'] as $sn2 => $p2) {
            $gesehen = isset($p2['_gesehen']) ? (int) $p2['_gesehen'] : 0;
            if ($gesehen > 0 && $gesehen < $grenze) {
                unset($z['packs'][$sn2]);
                zd_log('Akkupack ' . $sn2 . ' hat sich seit sechs Stunden nicht '
                     . 'gemeldet und wird nicht mehr aufgefuehrt.');
            }
        }
        $z['ts'] = time();
    }
}

/**
 * Aus den Rohwerten die Felder bilden, die Loxone und MQTT bekommen.
 *
 * Alle Eigenschaftsnamen stammen aus der offiziellen Home-Assistant-
 * Integration (custom_components/zendure_ha/device.py). Ein fehlender Wert
 * bleibt null.
 */
function zd_abbilden($nr, array $g, array $z, $intervall)
{
    $e = isset($z['eigenschaften']) ? $z['eigenschaften'] : array();
    $packs = isset($z['packs']) ? $z['packs'] : array();
    $cfg = zd_config();

    $laden = zd_zahl(zd_erstes($e, array('outputPackPower')));
    $entladen = zd_zahl(zd_erstes($e, array('packInputPower')));
    $batp = null;
    if ($laden !== null || $entladen !== null) {
        // Vorzeichen wie in den Schwesterplugins: positiv = die Batterie laedt.
        $batp = (int) (($laden === null ? 0 : $laden) - ($entladen === null ? 0 : $entladen));
    }

    // Zellspannungsdifferenz und Temperatur ueber alle Akkupacks.
    $dvolt = null;
    $temp = null;
    $packliste = array();
    foreach ($packs as $sn => $p) {
        $maxv = zd_zahl(zd_erstes($p, array('maxVol')), 3);
        $minv = zd_zahl(zd_erstes($p, array('minVol')), 3);
        $d = ($maxv !== null && $minv !== null) ? round($maxv - $minv, 3) : null;
        if ($d !== null && ($dvolt === null || $d > $dvolt)) {
            $dvolt = $d;
        }
        $tRoh = zd_zahl(zd_erstes($p, array('maxTemp')), 1);
        $tAnz = zd_temperatur($tRoh, $cfg);
        if ($tAnz !== null && ($temp === null || $tAnz > $temp)) {
            $temp = $tAnz;
        }
        $packliste[$sn] = array(
            'sn'    => (string) $sn,
            'soc'   => zd_zahl(zd_erstes($p, array('socLevel'))),
            'volt'  => zd_zahl(zd_erstes($p, array('totalVol')), 3),
            'maxv'  => $maxv,
            'minv'  => $minv,
            'dvolt' => $d,
            'temp'  => $tAnz,
            'temp_roh' => $tRoh,
            'watt'  => zd_zahl(zd_erstes($p, array('power'))),
        );
    }

    $alter = (isset($z['ts']) && $z['ts'] > 0) ? max(0, time() - (int) $z['ts']) : -1;
    // Als erreichbar gilt ein Geraet, wenn seine Werte nicht aelter sind als
    // das Dreifache des Abfragetakts, mindestens aber zwei Minuten.
    $frist = max(120, 3 * (int) $intervall);
    $ok = ($alter >= 0 && $alter <= $frist) ? 1 : 0;

    return array(
        'nr'         => (int) $nr,
        'name'       => $g['name'],
        'art'        => $g['art'],
        'modell'     => $g['modell'],
        'satz'       => $g['satz'],
        'sn'         => $g['sn'],
        'ok'         => $ok,
        'alter'      => $alter,
        'soc'        => zd_zahl(zd_erstes($e, array('electricLevel'))),
        'soc_min'    => zd_zahl(zd_erstes($e, array('minSoc'))),
        'soc_max'    => zd_zahl(zd_erstes($e, array('socSet'))),
        'pv'         => zd_zahl(zd_erstes($e, array('solarInputPower'))),
        'haus'       => zd_zahl(zd_erstes($e, array('outputHomePower'))),
        'netz'       => zd_zahl(zd_erstes($e, array('gridInputPower'))),
        'laden'      => $laden,
        'entladen'   => $entladen,
        'batp'       => $batp,
        'grenze_aus' => zd_zahl(zd_erstes($e, array('outputLimit'))),
        'grenze_ein' => zd_zahl(zd_erstes($e, array('inputLimit'))),
        'acmodus'    => zd_zahl(zd_erstes($e, array('acMode'))),
        'smart'      => zd_zahl(zd_erstes($e, array('smartMode'))),
        'packs'      => count($packliste),
        'dvolt'      => $dvolt,
        'temp'       => $temp,
        'packliste'  => $packliste,
    );
}

/**
 * Temperatur umrechnen.
 *
 * Wie das Geraet die Temperatur meldet, ist NICHT belegt: die
 * Home-Assistant-Integration reicht maxTemp unveraendert durch. Verbreitet ist
 * bei Zendure die Angabe in Zehntel-Kelvin (2731 entspricht 0 Grad Celsius),
 * aber ohne Geraet laesst sich das nicht nachmessen. Deshalb steht die
 * Umrechnung auf 'roh' und muss vom Benutzer bewusst eingeschaltet werden,
 * nachdem er den Rohwert im Reiter Test angesehen hat.
 */
function zd_temperatur($roh, array $cfg)
{
    if ($roh === null) {
        return null;
    }
    $art = isset($cfg['temp_umrechnung']) ? (string) $cfg['temp_umrechnung'] : 'roh';
    if ($art === 'kelvin10') {
        return round(((float) $roh - 2731) / 10, 1);
    }
    if ($art === 'zehntel') {
        return round((float) $roh / 10, 1);
    }
    return $roh;
}

/* ------------------------------------------------------------------
 * Schreibbefehle
 *
 * Die Form der Nachricht haengt vom Befehlssatz ab. Jede Form ist der
 * offiziellen Home-Assistant-Integration entnommen; die Fundstelle steht
 * jeweils dabei. Geraten wird nichts - wo ein Geraet etwas nicht kann, wird
 * das gemeldet statt eine Ersatzform zu erfinden.
 * ------------------------------------------------------------------ */

/** Eigenschaften setzen (properties/write), unabhaengig vom Befehlssatz. */
function zd_eigenschaften_setzen(array $g, array $eigenschaften)
{
    if ($g['art'] === 'http') {
        return zd_http_schreiben($g, $eigenschaften);
    }
    return zd_mqtt_an_geraet($g, 'properties/write', array(
        'deviceId'   => $g['deviceid'],
        'properties' => $eigenschaften,
    ));
}

/** deviceAutomation aufrufen (function/invoke). Nur ueber MQTT moeglich. */
function zd_automatik(array $g, array $argument)
{
    if ($g['art'] !== 'mqtt') {
        return array(0, 'Dieser Befehlssatz laeuft ueber MQTT. Das Geraet ist aber als '
                      . 'HTTP-Geraet eingetragen. Bitte die Art im Reiter Einstellungen pruefen.');
    }
    return zd_mqtt_an_geraet($g, 'function/invoke', array(
        'deviceKey' => $g['deviceid'],
        'function'  => 'deviceAutomation',
        'arguments' => array($argument),
    ));
}

/** Laden mit der angegebenen Leistung. */
function zd_befehl_laden(array $g, $watt)
{
    if ((int) $g['max_laden'] <= 0) {
        return array(0, 'Dieses Modell kann nicht aus dem Netz laden. '
                      . 'Bei Hub 1200, Hub 2000 und AIO 2400 ist das bauartbedingt so.');
    }
    switch ($g['satz']) {
        case 'zensdk':
            return zd_eigenschaften_setzen($g, array(
                'smartMode'   => $watt == 0 ? 0 : 1,
                'acMode'      => 1,
                'outputLimit' => 0,
                'inputLimit'  => $watt,
            ));
        case 'hyper2000':
            return zd_automatik($g, array(
                'autoModelProgram' => 1,
                'autoModelValue'   => array(
                    'chargingType'  => 1,
                    'price'         => 2,
                    'chargingPower' => $watt,
                    'prices'        => array_fill(0, 24, 1),
                    'outPower'      => 0,
                    'freq'          => 0,
                ),
                'msgType'   => 1,
                'autoModel' => 8,
            ));
        case 'ace_aio':
            return zd_automatik($g, array(
                'autoModelProgram' => 2,
                'autoModelValue'   => array(
                    'chargingType'  => 1,
                    'chargingPower' => $watt,
                    'freq'          => 0,
                    'outPower'      => 0,
                ),
                'msgType'   => 1,
                'autoModel' => 8,
            ));
        case 'hub':
            return array(0, 'Die Hub-Reihe kann nicht aus dem Netz laden.');
    }
    return array(0, 'Unbekannter Befehlssatz: ' . $g['satz']);
}

/** Entladen mit der angegebenen Leistung. */
function zd_befehl_entladen(array $g, $watt)
{
    switch ($g['satz']) {
        case 'zensdk':
            return zd_eigenschaften_setzen($g, array(
                'smartMode'   => $watt == 0 ? 0 : 1,
                'acMode'      => 2,
                'outputLimit' => $watt,
                'inputLimit'  => 0,
            ));
        case 'hyper2000':
        case 'ace_aio':
            return zd_automatik($g, array(
                'autoModelProgram' => 2,
                'autoModelValue'   => array(
                    'chargingType'  => 0,
                    'chargingPower' => 0,
                    'freq'          => 0,
                    'outPower'      => max(0, (int) $watt),
                ),
                'msgType'   => 1,
                'autoModel' => 8,
            ));
        case 'hub':
            // Bei der Hub-Reihe ist autoModelValue eine blosse Zahl, kein
            // Objekt (devices/hub1200.py). Wer hier ein Objekt sendet, bekommt
            // keine Fehlermeldung - es passiert schlicht nichts.
            return zd_automatik($g, array(
                'autoModelProgram' => 2,
                'autoModelValue'   => max(0, (int) $watt),
                'msgType'          => 1,
                'autoModel'        => 8,
            ));
    }
    return array(0, 'Unbekannter Befehlssatz: ' . $g['satz']);
}

/** Regie zurueckgeben: kein Sollwert mehr, das Geraet macht wieder selbst. */
function zd_befehl_aus(array $g)
{
    switch ($g['satz']) {
        case 'zensdk':
            return zd_eigenschaften_setzen($g, array(
                'smartMode'   => 0,
                'acMode'      => 2,
                'outputLimit' => 0,
                'inputLimit'  => 0,
            ));
        case 'hyper2000':
        case 'ace_aio':
            return zd_automatik($g, array(
                'autoModelProgram' => 0,
                'autoModelValue'   => array(
                    'chargingType' => 0, 'chargingPower' => 0, 'freq' => 0, 'outPower' => 0,
                ),
                'msgType'   => 1,
                'autoModel' => 0,
            ));
        case 'hub':
            return zd_automatik($g, array(
                'autoModelProgram' => 0,
                'autoModelValue'   => 0,
                'msgType'          => 1,
                'autoModel'        => 0,
            ));
    }
    return array(0, 'Unbekannter Befehlssatz: ' . $g['satz']);
}

/**
 * Schreibbremse.
 *
 * Jeder Schreibvorgang landet bei manchen Geraeten im Flash-Speicher. Die
 * offizielle Integration merkt dazu bei der ACE 1500 ausdruecklich an, ein
 * ungebremster Regelkreis wuerde die Schreibfestigkeit binnen Monaten
 * aufbrauchen, und rastert deshalb auf 50 W und hoechstens einen Schreibgang
 * je 30 Sekunden. Dieses Plugin macht es fuer ALLE Geraete so - der Preis ist
 * eine etwas traegere Regelung, der Gewinn ein Geraet, das laenger lebt.
 *
 * Die Rasterung wird gemeldet, nicht verschwiegen: der Aufrufer erfaehrt den
 * tatsaechlich gesendeten Wert.
 */
function zd_bremse_pruefen($nr, array $cfg)
{
    $bremse = max(0, min(600, (int) $cfg['schreibbremse']));
    if ($bremse === 0) {
        return array(1, '');
    }
    $letzte = isset($GLOBALS['zd_letzte_schreibzeit'][$nr]) ? $GLOBALS['zd_letzte_schreibzeit'][$nr] : 0;
    $rest = $bremse - (time() - $letzte);
    if ($rest > 0) {
        return array(0, 'Schreibbremse: der letzte Befehl an dieses Geraet ist keine '
                      . $bremse . ' s her. Noch ' . $rest . ' s warten. '
                      . 'Die Bremse schuetzt den Flash-Speicher des Geraets und laesst sich '
                      . 'im Reiter Einstellungen aendern.');
    }
    return array(1, '');
}

function zd_rastern($watt, array $cfg)
{
    $schritt = max(1, min(500, (int) $cfg['schrittweite']));
    return (int) (floor((int) $watt / $schritt) * $schritt);
}

/* ------------------------------------------------------------------
 * Warteschlange
 * ------------------------------------------------------------------ */

function zd_antwort_schreiben($kennung, $ok, $meldung, array $zusatz = array())
{
    $ordner = zd_paths()['datadir'] . '/antworten';
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }
    zd_json_schreiben($ordner . '/' . $kennung . '.json',
        array_merge(array('ok' => (int) $ok, 'meldung' => (string) $meldung, 'ts' => time()), $zusatz));
    // Alte Antworten aufraeumen
    foreach (glob($ordner . '/*.json') ?: array() as $alt) {
        if (time() - (int) @filemtime($alt) > 900) {
            @unlink($alt);
        }
    }
}

/** Rueckgabe: array(ok, Meldung, Sofortabruf gewuenscht) */
function zd_befehl_ausfuehren(array $befehl, array $geraete, array $cfg)
{
    $aktion = isset($befehl['aktion']) ? (string) $befehl['aktion'] : '';

    if ($aktion === 'abruf') {
        return array(1, 'Sofortabruf eingeplant.', true);
    }
    if (empty($cfg['steuerung_ein'])) {
        return array(0, 'Die Steuerung ist ausgeschaltet. Reiter Einstellungen, '
                      . 'Haken Schreibende Befehle zulassen.', false);
    }

    $nr = isset($befehl['geraet']) ? (int) $befehl['geraet'] : 1;
    if (!isset($geraete[$nr])) {
        return array(0, 'Geraet ' . $nr . ' ist nicht eingerichtet. Bekannt sind '
                      . count($geraete) . ' Geraete.', false);
    }
    $g = $geraete[$nr];

    list($frei, $grund) = zd_bremse_pruefen($nr, $cfg);
    if (!$frei) {
        return array(0, $grund, false);
    }

    $zusatz = '';
    switch ($aktion) {
        case 'laden':
        case 'entladen':
            $watt = isset($befehl['watt']) ? (int) $befehl['watt'] : null;
            if ($watt === null || !is_numeric($befehl['watt'])) {
                return array(0, 'Der Leistungswert fehlt oder ist keine Zahl.', false);
            }
            $grenze = $aktion === 'laden' ? (int) $g['max_laden'] : (int) $g['max_entladen'];
            if ($aktion === 'laden' && $grenze <= 0) {
                // Eigene Meldung vor der Bereichspruefung: '0 bis 0 W' waere
                // zwar richtig, sagt aber nicht, WARUM.
                return array(0, 'Dieses Modell kann bauartbedingt nicht aus dem Netz laden. '
                              . 'Das gilt fuer Hub 1200, Hub 2000 und AIO 2400. '
                              . 'Kann Ihr Geraet es doch, tragen Sie die Ladegrenze im Reiter '
                              . 'Einstellungen von Hand ein.', false);
            }
            if ($watt < 0 || $watt > $grenze) {
                // Abweisen, nicht zurechtbiegen: ein still gekappter Sollwert
                // fuehrt zu einer Anlage, die etwas anderes tut als angezeigt.
                return array(0, $watt . ' W liegt ausserhalb der Grenzen dieses Geraets '
                              . '(0 bis ' . $grenze . ' W). Die Grenze steht im Reiter '
                              . 'Einstellungen und richtet sich nach dem Modell.', false);
            }
            $gerastert = zd_rastern($watt, $cfg);
            if ($gerastert !== $watt) {
                $zusatz = ' Auf ' . $gerastert . ' W gerastert (Schrittweite '
                        . (int) $cfg['schrittweite'] . ' W, schuetzt den Flash-Speicher).';
            }
            list($ok, $m) = $aktion === 'laden'
                ? zd_befehl_laden($g, $gerastert)
                : zd_befehl_entladen($g, $gerastert);
            break;

        case 'aus':
            list($ok, $m) = zd_befehl_aus($g);
            break;

        case 'socmin':
        case 'socmax':
            $pz = isset($befehl['prozent']) ? (int) $befehl['prozent'] : -1;
            if ($pz < 0 || $pz > 100) {
                return array(0, 'Der Prozentwert muss zwischen 0 und 100 liegen.', false);
            }
            $feld = $aktion === 'socmin' ? 'minSoc' : 'socSet';
            list($ok, $m) = zd_eigenschaften_setzen($g, array($feld => $pz));
            break;

        case 'grenzeaus':
        case 'grenzeein':
            $watt = isset($befehl['watt']) ? (int) $befehl['watt'] : -1;
            $grenze = $aktion === 'grenzeaus' ? (int) $g['max_entladen'] : (int) $g['max_laden'];
            if ($watt < 0 || $watt > $grenze) {
                return array(0, $watt . ' W liegt ausserhalb der Grenzen dieses Geraets '
                              . '(0 bis ' . $grenze . ' W).', false);
            }
            $feld = $aktion === 'grenzeaus' ? 'outputLimit' : 'inputLimit';
            list($ok, $m) = zd_eigenschaften_setzen($g, array($feld => zd_rastern($watt, $cfg)));
            break;

        default:
            return array(0, 'Unbekannte Aktion: ' . $aktion, false);
    }

    if ($ok) {
        $GLOBALS['zd_letzte_schreibzeit'][$nr] = time();
    }
    return array($ok, $m . $zusatz, false);
}

/** Alle vorliegenden Befehle abarbeiten. Rueckgabe: Sofortabruf gewuenscht? */
function zd_warteschlange(array $geraete, array $cfg)
{
    $ordner = zd_paths()['datadir'] . '/befehle';
    if (!is_dir($ordner)) {
        return false;
    }
    $sofort = false;
    foreach (glob($ordner . '/*.json') ?: array() as $datei) {
        $kennung = basename($datei, '.json');
        $b = zd_json_lesen($datei);
        @unlink($datei);
        if (!$b) {
            zd_antwort_schreiben($kennung, 0, 'Befehlsdatei war leer oder unlesbar.');
            continue;
        }
        list($ok, $meldung, $jetzt) = zd_befehl_ausfuehren($b, $geraete, $cfg);
        zd_antwort_schreiben($kennung, $ok, $meldung);
        zd_log('Befehl ' . $kennung . ' (' . (isset($b['aktion']) ? $b['aktion'] : '?') . '): ok='
             . (int) $ok . ' ' . $meldung);
        if ($jetzt) {
            $sofort = true;
        }
    }
    return $sofort;
}

/* ------------------------------------------------------------------
 * Verlauf und Abbild
 * ------------------------------------------------------------------ */

function zd_verlauf_anhaengen($nr, $soc, $batp, $tage)
{
    if ($soc === null) {
        return;
    }
    $p = zd_paths();
    $ordner = $p['datadir'] . '/verlauf';
    if (!is_dir($ordner)) {
        @mkdir($ordner, 0775, true);
    }
    $marke = $p['datadir'] . '/.verlauf_ts_' . (int) $nr;
    $letzte = is_file($marke) ? (int) @file_get_contents($marke) : 0;
    if (time() - $letzte < 240) {
        return;
    }
    @file_put_contents($ordner . '/geraet' . (int) $nr . '_' . date('Ymd') . '.csv',
        time() . ';' . $soc . ';' . ($batp === null ? '' : $batp) . "\n", FILE_APPEND);
    @file_put_contents($marke, (string) time());
    $grenze = time() - max(1, (int) $tage) * 86400;
    foreach (glob($ordner . '/geraet*_*.csv') ?: array() as $alt) {
        if ((int) @filemtime($alt) < $grenze) {
            @unlink($alt);
        }
    }
}

function zd_abbild_schreiben(array $geraete, array $cfg)
{
    $p = zd_paths();
    $werte = array();
    $irgendetwas = 0;
    foreach ($geraete as $nr => $g) {
        $z = isset($GLOBALS['zd_zustaende'][$nr]) ? $GLOBALS['zd_zustaende'][$nr] : array();
        $w = zd_abbilden($nr, $g, $z, $cfg['intervall']);
        $werte[(string) $nr] = $w;
        if ($w['ok']) {
            $irgendetwas = 1;
        }
        zd_verlauf_anhaengen($nr, $w['soc'], $w['batp'], $cfg['verlauf_tage']);
    }

    zd_json_schreiben($p['datadir'] . '/loxone.json', array(
        'ok'      => $irgendetwas,
        'ts'      => time(),
        'anzahl'  => count($werte),
        'geraete' => $werte,
    ));
    zd_json_schreiben($p['datadir'] . '/cache.json', array(
        'ts'       => time(),
        'zustaende' => $GLOBALS['zd_zustaende'],
    ));

    if (!empty($cfg['mqtt_ein'])) {
        $praefix = trim((string) $cfg['mqtt_topic'], '/');
        if ($praefix === '') {
            $praefix = 'zendure';
        }
        $paare = array('ok' => $irgendetwas, 'geraete' => count($werte));
        foreach ($werte as $nr => $w) {
            foreach (array('soc', 'pv', 'haus', 'netz', 'batp', 'laden', 'entladen',
                           'grenze_aus', 'grenze_ein', 'soc_min', 'soc_max', 'acmodus',
                           'packs', 'dvolt', 'temp') as $feld) {
                $paare['geraet' . $nr . '/' . $feld] = $w[$feld];
            }
            $paare['geraet' . $nr . '/online'] = $w['ok'];
            foreach ($w['packliste'] as $sn => $pk) {
                foreach (array('soc', 'volt', 'dvolt', 'temp', 'watt') as $feld) {
                    $paare['geraet' . $nr . '/pack/' . $sn . '/' . $feld] = $pk[$feld];
                }
            }
        }
        zd_mqtt_senden($paare, $praefix);
    }
    return $werte;
}

/* ------------------------------------------------------------------
 * Hauptschleife
 * ------------------------------------------------------------------ */

function zd_signal_behandeln($signal = 0)
{
    $GLOBALS['zd_lauf'] = false;
    zd_log('Beendigungssignal erhalten - Dienst haelt an.');
}

function zd_zustand_datei_schreiben(array $felder)
{
    $p = zd_paths();
    $z = zd_json_lesen($p['datadir'] . '/zustand.json');
    zd_json_schreiben($p['datadir'] . '/zustand.json',
        array_merge($z, $felder, array('ts' => time(), 'pid' => getmypid())));
}

/** Einen Abrufdurchgang fahren. */
function zd_durchgang(array $geraete, array $cfg)
{
    $fehler = array();
    foreach ($geraete as $nr => $g) {
        if ($g['art'] === 'http') {
            $antwort = zd_http_abruf($g);
            if (isset($antwort['_fehler'])) {
                $fehler[] = $g['name'] . ': ' . $antwort['_fehler'];
                zd_log_gebremst('abruf' . $nr, 'Abruf von ' . $g['name'] . ' (' . $g['ip'] . '): '
                    . $antwort['_fehler'], 900);
            } else {
                zd_zustand_mischen($nr, $antwort);
            }
        } else {
            // MQTT-Geraete melden von selbst. Ein Anstupser sorgt dafuer, dass
            // auch nach einem Neustart des Dienstes gleich alle Werte kommen
            // (belegt in device.py: dataRefresh sendet properties/read getAll).
            zd_mqtt_an_geraet($g, 'properties/read', array(
                'deviceId'   => $g['deviceid'],
                'properties' => array('getAll'),
            ));
        }
    }
    zd_abbild_schreiben($geraete, $cfg);
    zd_zustand_datei_schreiben(array(
        'fehler'   => implode(' | ', $fehler),
        'intervall' => (int) $cfg['intervall'],
        'geraete'  => count($geraete),
    ));
    return $fehler;
}

function zd_dienst_schleife($einmal = false)
{
    $cfg = zd_config();
    $geraete = zd_geraete();
    if (!$geraete) {
        zd_log('Es ist kein Geraet eingerichtet. Bitte die Plugin-Oberflaeche oeffnen.');
        zd_zustand_datei_schreiben(array('fehler' => 'Kein Geraet eingerichtet.'));
        return 1;
    }
    zd_log('Dienst startet: ' . count($geraete) . ' Geraet(e), Takt ' . (int) $cfg['intervall']
         . ' s, Steuerung ' . (!empty($cfg['steuerung_ein']) ? 'ein' : 'aus') . '.');

    // Frueheren Zustand uebernehmen, damit nach einem Neustart nicht alles leer ist.
    $alt = zd_cache();
    if (isset($alt['zustaende']) && is_array($alt['zustaende'])) {
        $GLOBALS['zd_zustaende'] = $alt['zustaende'];
    }

    zd_horcher_starten($geraete);
    zd_durchgang($geraete, $cfg);
    if ($einmal) {
        zd_horcher_beenden();
        return 0;
    }

    $naechster = time() + max(5, (int) $cfg['intervall']);
    while ($GLOBALS['zd_lauf']) {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        // Ohne pcntl gibt es kein Signal - dann beendet das Startskript den
        // Prozess hart. Der Merker sorgt fuer den geordneten Weg.
        if (!is_file(zd_paths()['datadir'] . '/soll_laufen')) {
            zd_log('Der Merker soll_laufen ist weg - Dienst haelt an.');
            break;
        }

        zd_horcher_lesen($geraete);
        if (zd_warteschlange($geraete, $cfg)) {
            $naechster = 0;   // Sofortabruf gewuenscht
        }

        if (time() >= $naechster) {
            $cfg = zd_config();       // Aenderungen ohne Neustart uebernehmen
            $neu = zd_geraete();
            if ($neu !== $geraete) {
                zd_log('Die Geraeteliste hat sich geaendert - Horcher wird erneuert.');
                zd_horcher_beenden();
                $geraete = $neu;
                zd_horcher_starten($geraete);
            }
            if (!$geraete) {
                zd_log('Es ist kein Geraet mehr eingerichtet.');
                break;
            }
            zd_durchgang($geraete, $cfg);
            $naechster = time() + max(5, (int) $cfg['intervall']);
        } else {
            // Der Horcher darf nicht warten muessen: kurze Runden, damit
            // MQTT-Nachrichten und Befehle zuegig durchkommen.
            usleep(200000);
        }
    }
    zd_horcher_beenden();
    zd_log('Dienst beendet.');
    return 0;
}

/* ------------------------------------------------------------------
 * Selbsttest - beantwortet ohne Loxone, ob die Einrichtung traegt
 * ------------------------------------------------------------------ */

function zd_selbsttest()
{
    $p = zd_paths();
    $cfg = zd_config();
    $geraete = zd_geraete();
    $zeilen = array();
    $fehler = 0;

    $zeilen[] = '[OK]   PHP ' . PHP_VERSION;
    foreach (array('json', 'sockets') as $erw) {
        if (extension_loaded($erw)) {
            $zeilen[] = '[OK]   PHP-Erweiterung ' . $erw . ' geladen';
        } else {
            $fehler++;
            $zeilen[] = '[FEHL] PHP-Erweiterung ' . $erw . ' fehlt';
        }
    }

    foreach (array('Konfiguration' => $p['configdir'], 'Daten' => $p['datadir'], 'Log' => $p['logdir']) as $name => $pfad) {
        $ok = is_dir($pfad) && is_writable($pfad);
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ') . 'Ordner ' . $name . ' beschreibbar: ' . $pfad;
        if (!$ok) {
            $fehler++;
        }
    }

    if (!$geraete) {
        $fehler++;
        $zeilen[] = '[FEHL] Es ist kein Geraet eingerichtet';
    } else {
        $zeilen[] = '[OK]   ' . count($geraete) . ' Geraet(e) eingerichtet';
        foreach ($geraete as $nr => $g) {
            $zeilen[] = '[INFO]   ' . $nr . ') ' . $g['name'] . ' - Art ' . $g['art']
                      . ', Befehlssatz ' . $g['satz']
                      . ', Grenzen ' . $g['max_laden'] . '/' . $g['max_entladen'] . ' W'
                      . ($g['art'] === 'http' ? ', ' . $g['ip'] : ', ' . $g['prodkey'] . '/' . $g['deviceid']);
        }
    }

    $mosq = zd_mosq_vorhanden();
    $brauchtMosq = false;
    foreach ($geraete as $g) {
        if ($g['art'] === 'mqtt') {
            $brauchtMosq = true;
        }
    }
    if ($mosq) {
        $zeilen[] = '[OK]   mosquitto_sub und mosquitto_pub vorhanden';
    } elseif ($brauchtMosq) {
        $fehler++;
        $zeilen[] = '[FEHL] mosquitto_sub/_pub fehlen, werden aber fuer die MQTT-Geraete gebraucht. '
                  . 'Abhilfe: sudo apt install mosquitto-clients';
    } else {
        $zeilen[] = '[INFO] mosquitto_sub/_pub fehlen - werden hier aber nicht gebraucht, '
                  . 'weil alle Geraete ueber HTTP laufen';
    }

    if ($brauchtMosq) {
        $b = zd_broker();
        $heim = zd_mosq_heim();
        $rechte = is_file($heim . '/.config/mosquitto_sub') ? (fileperms($heim . '/.config/mosquitto_sub') & 0777) : -1;
        $zeilen[] = '[INFO] Broker ' . $b['host'] . ':' . $b['port']
                  . ($b['user'] !== '' ? ' als ' . $b['user'] : ' ohne Anmeldung');
        // Die Form eines Geheimnisses darf beurteilt werden, sein Wert nie.
        $zeilen[] = ($b['pw'] !== '' ? '[OK]   ' : '[INFO] ') . 'Broker-Passwort: '
                  . ($b['pw'] !== '' ? strlen($b['pw']) . ' Zeichen hinterlegt (Inhalt wird nicht angezeigt)' : 'keines hinterlegt');
        $ok = $rechte >= 0 && ($rechte & 0077) === 0;
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ') . 'Rechte der mosquitto-Vorgabedatei: '
                  . ($rechte >= 0 ? '0' . decoct($rechte) : 'Datei fehlt') . ' (erwartet 0600)';
        if (!$ok) {
            $fehler++;
        }
    }

    $m = zd_mqtt_zustand();
    if (!$m['gefunden']) {
        $fehler++;
        $zeilen[] = '[FEHL] In der general.json des LoxBerry ist kein MQTT-Abschnitt zu finden';
    } elseif ($m['autostart']) {
        $zeilen[] = '[OK]   MQTT-Gateway auf Autostart, Broker ' . $m['broker'] . ':' . $m['brokerport']
                  . ', UDP-Eingang ' . $m['udpport'];
    } else {
        $fehler++;
        $zeilen[] = '[FEHL] Das MQTT-Gateway ist nicht auf Autostart gestellt (System, MQTT Gateway). '
                  . 'Ohne das kommt am Miniserver nichts an.';
    }

    $zeilen[] = '[INFO] Takt ' . (int) $cfg['intervall'] . ' s, Schreibbremse '
              . (int) $cfg['schreibbremse'] . ' s, Schrittweite ' . (int) $cfg['schrittweite'] . ' W';
    $zeilen[] = '[INFO] Schreibende Befehle: ' . (!empty($cfg['steuerung_ein']) ? 'zugelassen' : 'gesperrt');
    $zeilen[] = '[INFO] Temperaturumrechnung: '
              . (isset($cfg['temp_umrechnung']) ? $cfg['temp_umrechnung'] : 'roh');

    $alter = zd_alter();
    $zeilen[] = $alter < 0 ? '[INFO] Es hat noch kein Abruf stattgefunden'
                           : '[INFO] Letztes Abbild vor ' . $alter . ' s geschrieben';

    $zeilen[] = '';
    $zeilen[] = 'Nicht geprueft, weil dafuer ein Zendure-Geraet noetig ist:';
    $zeilen[] = '  - ob das Geraet auf die lokale HTTP-Schnittstelle antwortet';
    $zeilen[] = '  - ob die Eigenschaftsnamen dieser Firmware zu der Zuordnung hier passen';
    $zeilen[] = '  - ob die Schreibbefehle des gewaehlten Befehlssatzes am Geraet wirken';
    $zeilen[] = '  - in welcher Einheit das Geraet Temperatur und Zellspannung meldet';
    echo implode("\n", $zeilen) . "\n";
    return $fehler ? 1 : 0;
}

/* ------------------------------------------------------------------ */

/* Nur ausfuehren, wenn die Datei unmittelbar aufgerufen wurde. Wird sie
 * eingebunden - etwa zum Pruefen der einzelnen Funktionen -, soll der Dienst
 * NICHT losrennen. */
$zd_direkt = !isset($_SERVER['SCRIPT_FILENAME'])
    || realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
if (!$zd_direkt) {
    return;
}

$zd_argv = isset($argv) ? $argv : array();
if (in_array('--selbsttest', $zd_argv, true)) {
    exit(zd_selbsttest());
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'zd_signal_behandeln');
    pcntl_signal(SIGINT, 'zd_signal_behandeln');
}
exit(zd_dienst_schleife(in_array('--einmal', $zd_argv, true)));
