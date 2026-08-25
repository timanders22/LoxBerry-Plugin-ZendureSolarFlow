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
$GLOBALS['zd_antwortzeit'] = array();   // Geraetenummer => Millisekunden

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
        return zd_t('DIENST.F_ECONNREFUSED');
    }
    if ($errno === 113 || strpos($klein, 'no route to host') !== false) {
        return zd_t('DIENST.F_EHOSTUNREACH');
    }
    if (strpos($klein, 'timed out') !== false || strpos($klein, 'timeout') !== false) {
        return zd_t('DIENST.F_TIMEOUT');
    }
    if (strpos($klein, 'network is unreachable') !== false) {
        return zd_t('DIENST.F_ENETUNREACH');
    }
    if (strpos($klein, 'name or service not known') !== false || strpos($klein, 'getaddrinfo') !== false) {
        return zd_t('DIENST.F_DNS');
    }
    if (strpos($klein, '<html') !== false || strpos($klein, '<!doctype') !== false) {
        return zd_t('DIENST.F_HTML');
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

/**
 * Den Statuscode aus $http_response_header holen.
 *
 * ignore_errors => true ist richtig: ohne das kaeme bei einem Fehlerstatus
 * gar kein Koerper an, und die Fehlermeldung des Geraets waere fort. Dann
 * MUSS der Status aber selbst geprueft werden - sonst ist eine Absage
 * ununterscheidbar von einer Zusage.
 *
 * Bis 0.9.8 geschah das nirgends. Gemessen gegen eine Gegenstelle
 * (Pruefstand p2_http.php):
 *
 *     schreiben auf HTTP 500 -> ok=1, an Loxone geht SET;OK=1
 *     lesen     auf HTTP 403 -> der Fehlerkoerper wandert ueber die
 *                               Flachverschmelzung als "Messwert" in den
 *                               Speicher: OK=1, ALTER=0, kein Eintrag in
 *                               zustand.json
 *
 * Rueckgabe: Statuszahl, oder 0 wenn keine Kopfzeile vorliegt.
 */
function zd_http_status($kopfzeilen)
{
    if (!is_array($kopfzeilen)) {
        return 0;
    }
    // Bei einer Umleitung stehen mehrere Statuszeilen darin; massgeblich ist
    // die LETZTE, denn sie gehoert zu der Antwort, die im Koerper steht.
    $code = 0;
    foreach ($kopfzeilen as $z) {
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', (string) $z, $t)) {
            $code = (int) $t[1];
        }
    }
    return $code;
}

/** GET http://<ip>/properties/report -> array oder array('_fehler' => Text) */
function zd_http_abruf(array $g, $tmo = 4)
{
    $url = 'http://' . $g['ip'] . '/properties/report';
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'header' => zd_http_kopf(), 'timeout' => $tmo, 'ignore_errors' => true,
    )));
    /* Antwortzeit mitmessen. Sie kostet nichts - der Abruf laeuft ohnehin -
     * und beantwortet eine Frage, die sonst niemand beantwortet: WIE gut
     * antwortet das Geraet? Ein Speicher, dessen Antwortzeit von 40 auf
     * 3000 ms steigt, hat ein Problem, lange bevor er ganz ausfaellt. */
    $t0 = microtime(true);
    $roh = @file_get_contents($url, false, $ctx);
    $GLOBALS['zd_antwortzeit'][(int) (isset($g['nr']) ? $g['nr'] : 0)]
        = (int) round((microtime(true) - $t0) * 1000);
    // $http_response_header wird von file_get_contents im AUFRUFENDEN
    // Gueltigkeitsbereich angelegt - es gibt sie hier, aber nur nach einem
    // Aufruf, der wirklich eine Antwort bekommen hat.
    $status = isset($http_response_header) ? zd_http_status($http_response_header) : 0;
    if ($roh === false) {
        $e = error_get_last();
        /* Zwei Fassungen desselben Fehlers, und beide werden gebraucht:
         *   _fehler  die ERKLAERUNG fuer den Bediener - uebersetzt
         *   _roh     die Systemmeldung fuer das Protokoll - unveraendert
         * Ohne die Trennung waere das Protokoll sprachabhaengig geworden, und
         * genau das hat die Pruefzeile LG aufgedeckt. Eine Systemmeldung ist
         * fuer die Fehlersuche ohnehin das bessere Protokoll: sie steht so
         * auch in jedem Forumsbeitrag und in jeder Handbuchseite. */
        $zd_roh = isset($e['message']) ? (string) $e['message'] : '';
        return array('_fehler' => zd_fehlertext($zd_roh !== '' ? $zd_roh
                                                              : zd_t('DIENST.M_KEINE_ANTWORT')),
                     '_roh' => $zd_roh);
    }
    if ($status >= 400) {
        return array('_fehler' => sprintf(zd_t('DIENST.M_HTTP_LESEN'), $status)
            . ($status === 401 || $status === 403
               ? ' ' . zd_t('DIENST.M_HTTP_403')
               : ($status === 404
                  ? ' ' . zd_t('DIENST.M_HTTP_404')
                  : '.'))
            . ' ' . sprintf(zd_t('DIENST.M_ANTWORT_WAR'), zd_fehlertext(substr((string) $roh, 0, 160))));
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
    $status = isset($http_response_header) ? zd_http_status($http_response_header) : 0;
    if ($roh === false) {
        $e = error_get_last();
        return array(0, zd_fehlertext(isset($e['message']) ? $e['message']
                                                            : zd_t('DIENST.M_KEINE_ANTWORT')));
    }
    if ($status >= 400) {
        /* Ein abgelehnter Schreibbefehl ist kein Erfolg. Bis 0.9.8 ging an
         * Loxone SET;OK=1, obwohl das Geraet mit 500 geantwortet hatte -
         * gegen die eigene Zusage in zd_lib.php: "Es wird nie ein Erfolg
         * gemeldet, den niemand geprueft hat." */
        return array(0, sprintf(zd_t('DIENST.M_HTTP_SCHREIBEN'), $status) . ' '
                      . zd_fehlertext(trim(substr((string) $roh, 0, 160))));
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
    /* Die Kennung traegt den ORDNERNAMEN, nicht das feste Wort "zendure".
     * dienst.sh und preupgrade.sh sammeln herrenlose Horcher mit einem pkill
     * auf diese Zeichenkette ein; stuende dort ueberall "zendure", traefe ein
     * Stopp in der Installation "zendure" auch den Horcher von "zendure_01". */
    $befehl = 'exec mosquitto_sub -i ' . escapeshellarg('loxberry-' . zd_paths()['plugin'] . '-' . getmypid())
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

/** Den Teilzeilenpuffer verwerfen - er gehoert zu genau einem Horcherprozess. */
function zd_horcher_puffer_leeren()
{
    $GLOBALS['zd_horcher_rest'] = '';
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
    /* Der Teilzeilenpuffer gehoert zum GESTORBENEN Horcher. Bleibt er
     * stehen, klebt sein angefangenes Stueck vor der ersten ganzen Zeile des
     * naechsten - beide sind dann verloren. */
    zd_horcher_puffer_leeren();
}

/**
 * Sorgt dafuer, dass ein Horcher laeuft, wenn es MQTT-Geraete gibt.
 *
 * Bis 0.9.8 gab es diese Funktion nicht. zd_horcher_lesen() erkannte den
 * toten Prozess, meldete ihn und raeumte ihn weg - und neu gestartet wurde
 * nur einmal vor der Schleife und danach ausschliesslich dann, wenn sich die
 * GERAETELISTE aenderte. Stirbt mosquitto_sub (Broker-Neustart, Netzhaenger,
 * ein fremdes pkill), lieferten alle MQTT-Geraete bis zum naechsten
 * Dienstneustart nichts mehr. Der Waechter im Cron merkte davon nichts - der
 * PHP-Prozess lief ja.
 *
 * Der Wiederanlauf ist gebremst: ein Broker, der die Anmeldung ablehnt,
 * wuerde sonst in jeder Runde einen neuen Prozess bekommen, also fuenfmal je
 * Sekunde. Nach einem misslungenen Versuch wird die Wartezeit verdoppelt,
 * bis hoechstens fuenf Minuten; ein geglueckter Start setzt sie zurueck.
 */
function zd_horcher_sicherstellen(array $geraete)
{
    if ($GLOBALS['zd_horcher'] !== null) {
        $GLOBALS['zd_horcher_wartezeit'] = 0;
        return true;
    }
    $braucht = false;
    foreach ($geraete as $g) {
        if ($g['art'] === 'mqtt') {
            $braucht = true;
            break;
        }
    }
    if (!$braucht) {
        return true;
    }
    $wartezeit = isset($GLOBALS['zd_horcher_wartezeit']) ? (int) $GLOBALS['zd_horcher_wartezeit'] : 0;
    $naechster = isset($GLOBALS['zd_horcher_naechster']) ? (int) $GLOBALS['zd_horcher_naechster'] : 0;
    if (time() < $naechster) {
        return false;
    }
    if (zd_horcher_starten($geraete)) {
        if ($wartezeit > 0) {
            zd_log('Der MQTT-Horcher laeuft wieder.');
        }
        $GLOBALS['zd_horcher_wartezeit'] = 0;
        $GLOBALS['zd_horcher_naechster'] = 0;
        return true;
    }
    $wartezeit = $wartezeit > 0 ? min(300, $wartezeit * 2) : 5;
    $GLOBALS['zd_horcher_wartezeit'] = $wartezeit;
    $GLOBALS['zd_horcher_naechster'] = time() + $wartezeit;
    zd_log_gebremst('horcher_anlauf', 'Der MQTT-Horcher liess sich nicht starten. '
        . 'Naechster Versuch in ' . $wartezeit . ' s.', 300);
    return false;
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
     * bis zum naechsten Durchgang stehen.
     *
     * Der Puffer stand bis 0.9.8 als "static" in dieser Funktion und ueberlebte
     * damit den Wechsel des Horchers. Beim Wiederanlauf klebte das
     * angefangene Stueck des alten Prozesses vor der ersten ganzen Zeile des
     * neuen - beide waren verloren. Jetzt haengt er global und wird von
     * zd_horcher_beenden() geleert. */
    $rest =& $GLOBALS['zd_horcher_rest'];
    if (!isset($rest)) {
        $rest = '';
    }
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
    /* Thema: [iot/]<productKey>/<deviceId>/<rest>
     *
     * Beide Schreibweisen werden abonniert (zd_horcher_starten), beide sind in
     * Zendure/Zendure-HA belegt - also muessen auch beide zerlegt werden.
     *
     * Bis 0.9.8 stand hier zuerst ein explode mit der Grenze 4. Ohne das
     * fuehrende "iot/" landete damit nur "properties" in $t[2], der Vergleich
     * mit "properties/report" scheiterte, und die Nachricht fiel lautlos
     * heraus. Gemessen (Pruefstand p1_mqtt.php):
     *
     *     /PK/DEV/properties/report      -> VERWORFEN
     *     iot/PK/DEV/properties/report   -> 42
     *
     * Ein Geraet, das unter der ersten Form meldet, blieb dauerhaft ok=0 -
     * ohne eine Zeile im Protokoll, waehrend das Schreiben weiter ging, weil
     * das immer ueber "iot/" laeuft.
     *
     * Jetzt wird das Praefix zuerst abgeschnitten und erst DANACH zerlegt,
     * und zwar mit der Grenze 3 - damit bleibt der Rest in einem Stueck. */
    $pfad = ltrim((string) $thema, '/');
    if (strncmp($pfad, 'iot/', 4) === 0) {
        $pfad = substr($pfad, 4);
    }
    $t = explode('/', $pfad, 3);
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
        return array(0, zd_t('DIENST.M_MOSQ_PUB_FEHLT'));
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
    return array(1, sprintf(zd_t('DIENST.M_GESENDET_AN'), $thema));
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

    // Die Zuordnung steht an einer Stelle (zd_feldkarte) und laesst sich im
    // Reiter Einstellungen ueberschreiben - siehe zd_zuordnung().
    $k = zd_zuordnung($cfg);
    $kp = zd_zuordnung($cfg, true);
    $hol = function ($feld, $nachkomma = 0) use ($e, $k) {
        return isset($k[$feld]) && isset($e[$k[$feld]]) ? zd_zahl($e[$k[$feld]], $nachkomma) : null;
    };

    $laden = $hol('laden');
    $entladen = $hol('entladen');
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
        $holp = function ($feld, $nachkomma = 0) use ($p, $kp) {
            return isset($kp[$feld]) && isset($p[$kp[$feld]]) ? zd_zahl($p[$kp[$feld]], $nachkomma) : null;
        };
        $maxv = $holp('maxv', 3);
        $minv = $holp('minv', 3);
        $d = ($maxv !== null && $minv !== null) ? round($maxv - $minv, 3) : null;
        if ($d !== null && ($dvolt === null || $d > $dvolt)) {
            $dvolt = $d;
        }
        $tRoh = $holp('temp', 1);
        $tAnz = zd_temperatur($tRoh, $cfg);
        if ($tAnz !== null && ($temp === null || $tAnz > $temp)) {
            $temp = $tAnz;
        }
        $packliste[$sn] = array(
            'sn'    => (string) $sn,
            'soc'   => $holp('soc'),
            'volt'  => $holp('volt', 3),
            'maxv'  => $maxv,
            'minv'  => $minv,
            'dvolt' => $d,
            'temp'  => $tAnz,
            'temp_roh' => $tRoh,
            'watt'  => $holp('watt'),
        );
    }

    $alter = (isset($z['ts']) && $z['ts'] > 0) ? max(0, time() - (int) $z['ts']) : -1;
    // Als erreichbar gilt ein Geraet, wenn seine Werte nicht aelter sind als
    // das Dreifache des Abfragetakts, mindestens aber zwei Minuten.
    $frist = max(120, 3 * (int) $intervall);
    $ok = ($alter >= 0 && $alter <= $frist) ? 1 : 0;

    /* Die Quittung: hat das Geraet den zuletzt gesetzten Wert uebernommen?
     * Drei Antworten - 1 ja, 0 nein, null noch keine Aussage. */
    $soll = zd_soll_lesen($nr);
    $sollok = zd_soll_quittung($soll, $e, isset($z['ts']) ? (int) $z['ts'] : 0, $cfg);

    return array(
        'nr'         => (int) $nr,
        'name'       => $g['name'],
        'art'        => $g['art'],
        'modell'     => $g['modell'],
        'satz'       => $g['satz'],
        'sn'         => $g['sn'],
        'ok'         => $ok,
        'alter'      => $alter,
        'soc'        => $hol('soc'),
        'soc_min'    => $hol('soc_min'),
        'soc_max'    => $hol('soc_max'),
        'pv'         => $hol('pv'),
        'haus'       => $hol('haus'),
        'netz'       => $hol('netz'),
        'laden'      => $laden,
        'entladen'   => $entladen,
        'batp'       => $batp,
        'grenze_aus' => $hol('grenze_aus'),
        'grenze_ein' => $hol('grenze_ein'),
        'acmodus'    => $hol('acmodus'),
        'smart'      => $hol('smart'),
        'packs'      => count($packliste),
        'dvolt'      => $dvolt,
        'temp'       => $temp,
        // Antwortzeit nur fuer HTTP: ein MQTT-Geraet meldet von selbst, da
        // gibt es keine Anfrage, deren Dauer sich messen liesse.
        'ms'         => ($g['art'] === 'http' && isset($GLOBALS['zd_antwortzeit'][(int) $nr]))
                        ? (int) $GLOBALS['zd_antwortzeit'][(int) $nr] : null,
        'firmware'   => $hol('firmware'),
        'rueckrest'  => zd_rueckfall_rest($soll, $cfg),
        'soll'       => isset($soll['wert']) ? $soll['wert'] : null,
        'soll_aktion'=> isset($soll['aktion']) ? $soll['aktion'] : '',
        'soll_alter' => isset($soll['ts']) ? max(0, time() - (int) $soll['ts']) : -1,
        'sollok'     => $sollok,
        'packliste'  => $packliste,
    );
}

/* ------------------------------------------------------------------
 * Schreibbefehle
 *
 * Die Form der Nachricht haengt vom Befehlssatz ab. Jede Form ist der
 * offiziellen Home-Assistant-Integration entnommen; die Fundstelle steht
 * jeweils dabei. Geraten wird nichts - wo ein Geraet etwas nicht kann, wird
 * das gemeldet statt eine Ersatzform zu erfinden.
 * ------------------------------------------------------------------ */

/**
 * EINEN BEFEHL BAUEN, BEVOR MAN IHN SENDET
 *
 * Bis 0.9.9 bauten und sendeten dieselben Funktionen in einem Zug. Damit war
 * dreierlei nicht moeglich, was gerade dieses Plugin dringend braucht - es
 * wurde ohne Zendure-Geraet gebaut, und drei seiner vier Befehlssaetze sind am
 * Geraet nie gemessen worden:
 *
 *   Trockenlauf     zeigen, was gesendet WUERDE, ohne zu senden
 *   Quittung        nach dem Senden nachsehen, ob das Geraet den Wert
 *                   uebernommen hat
 *   Satz-Assistent  die Saetze der Reihe nach probieren und messen, welcher
 *                   am Geraet ueberhaupt ankommt
 *
 * Ein gebauter Befehl ist ein Feld:
 *
 *   ok        1 baubar, 0 nicht - dann steht der Grund in meldung
 *   weg       'http' oder 'mqtt'
 *   ziel      die Adresse: URL beziehungsweise MQTT-Thema
 *   inhalt    die Nutzlast als Feld (erst beim Senden wird JSON daraus)
 *   erwartet  Eigenschaften, die das Geraet danach zurueckmelden MUSS, wenn
 *             der Befehl gewirkt hat - leer, wo das nicht belegt ist
 *   art       'properties' oder 'invoke'
 */

/** properties/write - dieselbe Nutzlast ueber HTTP wie ueber MQTT. */
function zd_bau_eigenschaften(array $g, array $eigenschaften)
{
    /* Bei properties/write IST die gesetzte Eigenschaft zugleich die, die das
     * Geraet danach meldet. Die Quittung darf hier also geprueft werden, ohne
     * dass etwas geraten wird. */
    if ($g['art'] === 'http') {
        return array(
            'ok' => 1, 'weg' => 'http', 'art' => 'properties',
            'ziel' => 'http://' . $g['ip'] . '/properties/write',
            'inhalt' => array('properties' => $eigenschaften, 'sn' => $g['sn']),
            'erwartet' => $eigenschaften,
        );
    }
    return array(
        'ok' => 1, 'weg' => 'mqtt', 'art' => 'properties',
        'ziel' => 'iot/' . $g['prodkey'] . '/' . $g['deviceid'] . '/properties/write',
        'inhalt' => array('deviceId' => $g['deviceid'], 'properties' => $eigenschaften),
        'erwartet' => $eigenschaften,
    );
}

/** deviceAutomation aufrufen (function/invoke). Nur ueber MQTT moeglich. */
function zd_bau_automatik(array $g, array $argument)
{
    if ($g['art'] !== 'mqtt') {
        return array('ok' => 0, 'meldung' => zd_t('DIENST.M_SATZ_BRAUCHT_MQTT'));
    }
    return array(
        'ok' => 1, 'weg' => 'mqtt', 'art' => 'invoke',
        'ziel' => 'iot/' . $g['prodkey'] . '/' . $g['deviceid'] . '/function/invoke',
        'inhalt' => array(
            'deviceKey' => $g['deviceid'],
            'function'  => 'deviceAutomation',
            'arguments' => array($argument),
        ),
        /* BEWUSST LEER. In welcher Eigenschaft sich ein deviceAutomation-Aufruf
         * niederschlaegt, ist nirgends belegt - die offizielle Integration
         * sagt dazu nichts. Eine geratene Zuordnung waere eine Quittung, die
         * richtig aussieht und nichts misst. Wer an seinem Geraet gesehen hat,
         * welches Feld sich bewegt, traegt es im Reiter Einstellungen als
         * Quittungsfeld ein - der Satz-Assistent schlaegt es sogar vor. */
        'erwartet' => array(),
    );
}

/** Laden mit der angegebenen Leistung. */
function zd_bau_laden(array $g, $watt)
{
    if ((int) $g['max_laden'] <= 0) {
        return array('ok' => 0, 'meldung' => zd_t('DIENST.M_KEIN_LADEN'));
    }
    switch ($g['satz']) {
        case 'zensdk':
            return zd_bau_eigenschaften($g, array(
                'smartMode'   => $watt == 0 ? 0 : 1,
                'acMode'      => 1,
                'outputLimit' => 0,
                'inputLimit'  => $watt,
            ));
        case 'hyper2000':
            return zd_bau_automatik($g, array(
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
            return zd_bau_automatik($g, array(
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
            return array('ok' => 0, 'meldung' => zd_t('DIENST.M_HUB_KEIN_LADEN'));
    }
    return array('ok' => 0, 'meldung' => sprintf(zd_t('DIENST.M_SATZ_UNBEKANNT'), $g['satz']));
}

/** Entladen mit der angegebenen Leistung. */
function zd_bau_entladen(array $g, $watt)
{
    switch ($g['satz']) {
        case 'zensdk':
            return zd_bau_eigenschaften($g, array(
                'smartMode'   => $watt == 0 ? 0 : 1,
                'acMode'      => 2,
                'outputLimit' => $watt,
                'inputLimit'  => 0,
            ));
        case 'hyper2000':
        case 'ace_aio':
            return zd_bau_automatik($g, array(
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
            return zd_bau_automatik($g, array(
                'autoModelProgram' => 2,
                'autoModelValue'   => max(0, (int) $watt),
                'msgType'          => 1,
                'autoModel'        => 8,
            ));
    }
    return array('ok' => 0, 'meldung' => 'Unbekannter Befehlssatz: ' . $g['satz']);
}

/** Regie zurueckgeben: kein Sollwert mehr, das Geraet macht wieder selbst. */
function zd_bau_aus(array $g)
{
    switch ($g['satz']) {
        case 'zensdk':
            return zd_bau_eigenschaften($g, array(
                'smartMode'   => 0,
                'acMode'      => 2,
                'outputLimit' => 0,
                'inputLimit'  => 0,
            ));
        case 'hyper2000':
        case 'ace_aio':
            return zd_bau_automatik($g, array(
                'autoModelProgram' => 0,
                'autoModelValue'   => array(
                    'chargingType' => 0, 'chargingPower' => 0, 'freq' => 0, 'outPower' => 0,
                ),
                'msgType'   => 1,
                'autoModel' => 0,
            ));
        case 'hub':
            return zd_bau_automatik($g, array(
                'autoModelProgram' => 0,
                'autoModelValue'   => 0,
                'msgType'          => 1,
                'autoModel'        => 0,
            ));
    }
    return array('ok' => 0, 'meldung' => 'Unbekannter Befehlssatz: ' . $g['satz']);
}

/**
 * Einen gebauten Befehl senden. Rueckgabe: array(ok, Meldung)
 *
 * Der EINZIGE Weg, auf dem dieses Plugin ein Geraet beschreibt. Wer eine
 * zweite Sendestelle baut, umgeht Trockenlauf, Quittung und Satz-Assistent
 * auf einen Schlag.
 */
function zd_befehl_senden(array $bau, array $g)
{
    if (empty($bau['ok'])) {
        return array(0, isset($bau['meldung']) ? $bau['meldung'] : zd_t('DIENST.M_NICHT_BAUBAR'));
    }
    if ($bau['weg'] === 'http') {
        return zd_http_schreiben($g, $bau['inhalt']['properties']);
    }
    // zd_mqtt_an_geraet() setzt das Themenpraefix selbst wieder davor - hier
    // wird deshalb nur der Rest hinter der Geraetekennung uebergeben.
    $rest = $bau['art'] === 'invoke' ? 'function/invoke' : 'properties/write';
    return zd_mqtt_an_geraet($g, $rest, $bau['inhalt']);
}

/** Klartext eines gebauten Befehls - fuer den Trockenlauf. */
function zd_bau_text(array $bau)
{
    if (empty($bau['ok'])) {
        return isset($bau['meldung']) ? $bau['meldung'] : zd_t('DIENST.M_NICHT_BAUBAR_KURZ');
    }
    $js = json_encode($bau['inhalt'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return strtoupper($bau['weg']) . ' ' . $bau['ziel'] . ' ' . ($js === false ? '(unlesbar)' : $js);
}

/**
 * Einen Befehl bauen - eine Stelle fuer alle Aktionen.
 *
 * $wert ist Watt (laden, entladen, grenzeaus, grenzeein) beziehungsweise
 * Prozent (socmin, socmax); bei 'aus' wird er nicht benutzt.
 */
function zd_befehl_bauen(array $g, $aktion, $wert = 0)
{
    switch ($aktion) {
        case 'laden':     return zd_bau_laden($g, (int) $wert);
        case 'entladen':  return zd_bau_entladen($g, (int) $wert);
        case 'aus':       return zd_bau_aus($g);
        case 'socmin':    return zd_bau_eigenschaften($g, array('minSoc' => (int) $wert));
        case 'socmax':    return zd_bau_eigenschaften($g, array('socSet' => (int) $wert));
        case 'grenzeaus': return zd_bau_eigenschaften($g, array('outputLimit' => (int) $wert));
        case 'grenzeein': return zd_bau_eigenschaften($g, array('inputLimit' => (int) $wert));
    }
    return array('ok' => 0, 'meldung' => 'Unbekannte Aktion: ' . $aktion);
}

/* ------------------------------------------------------------------
 * Geraetesuche
 *
 * Zwei Wege, zwei Suchen - und die zweite ist der eigentliche Gewinn.
 *
 *   HTTP  Den eigenen Netzbereich absuchen: wer auf /properties/report mit
 *         JSON antwortet, ist ein Kandidat. Ersetzt den Gang in die
 *         Geraeteliste des Routers.
 *
 *   MQTT  Ein paar Sekunden auf # horchen und die gesehenen Paare aus
 *         Produktschluessel und Geraetekennung auflisten. Diese beiden
 *         Angaben sind sonst nur schwer zu bekommen - und die Selbstpruefung
 *         fragte bis 0.9.11 danach ("stimmen Produktschluessel und
 *         Geraetekennung?"), ohne einen Weg zur Antwort zu nennen.
 *
 * Sie laeuft im Dienst, nicht in der Oberflaeche: nur der Dienst spricht mit
 * Geraeten. Die Oberflaeche reiht einen Auftrag ein und liest das Ergebnis.
 * ------------------------------------------------------------------ */

function zd_suche_datei()
{
    return zd_paths()['datadir'] . '/suche.json';
}

/**
 * Den eigenen Netzbereich ermitteln.
 *
 * Aus der Adresse der eigenen Netzkarte, nicht geraten. Rueckgabe:
 * array(praefix, eigene) oder array('', '') - eine erfundene 192.168.1.x
 * durchzusuchen waere Arbeit fuer nichts.
 */
function zd_eigenes_netz()
{
    $aus = array();
    @exec('ip -4 -o addr show scope global 2>/dev/null', $aus);
    foreach ($aus as $z) {
        if (preg_match('#inet (\d{1,3}\.\d{1,3}\.\d{1,3})\.(\d{1,3})/(\d{1,2})#', $z, $m)) {
            // Nur /24 und enger: bei /16 waeren es 65.000 Adressen, und das
            // ist keine Suche mehr, sondern ein Netzscan.
            if ((int) $m[3] >= 24) {
                return array($m[1], $m[1] . '.' . $m[2]);
            }
        }
    }
    return array('', '');
}

/**
 * Alle Adressen eines /24 gleichzeitig anklopfen.
 *
 * Nacheinander waeren das bei 254 Adressen und einer Sekunde Zeitschranke
 * vier Minuten - der Dienst stuende so lange. Mit nicht blockierenden
 * Verbindungen und stream_select sind es wenige Sekunden.
 *
 * Geprueft wird nur, ob Port 80 offen ist; wer antwortet, bekommt danach
 * einen richtigen Abruf. Ein offener Port 80 ist noch kein Zendure-Geraet.
 */
function zd_suche_http($praefix, $eigene, $tmo = 2.0, $port = 80)
{
    /* Der Port ist ein Parameter, obwohl er im Betrieb immer 80 ist: die
     * Adressen dieses Plugins lauten http://<ip>/properties/report. Ohne ihn
     * liesse sich diese Funktion nicht pruefen - ein Pruefstand darf keinen
     * Dienst auf Port 80 starten, dafuer braeuchte er root. */
    if ($praefix === '') {
        return array();
    }
    $offen = array();
    $sockets = array();
    for ($i = 1; $i <= 254; $i++) {
        $ip = $praefix . '.' . $i;
        if ($ip === $eigene) {
            continue;
        }
        $s = @stream_socket_client('tcp://' . $ip . ':' . (int) $port, $e1, $e2, 0,
                                   STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
        if ($s) {
            $sockets[$ip] = $s;
        }
    }
    $ende = microtime(true) + $tmo;
    while ($sockets && microtime(true) < $ende) {
        $schreib = array_values($sockets);
        $lese = null;
        $fehl = array();
        $rest = max(0.0, $ende - microtime(true));
        $n = @stream_select($lese, $schreib, $fehl, (int) $rest,
                            (int) (($rest - (int) $rest) * 1000000));
        if ($n === false || $n === 0) {
            break;
        }
        foreach ($schreib as $s) {
            $ip = array_search($s, $sockets, true);
            if ($ip === false) {
                continue;
            }
            // Verbunden heisst hier: die Gegenstelle ist benennbar.
            if (@stream_socket_get_name($s, true) !== false) {
                $offen[] = $ip;
            }
            @fclose($s);
            unset($sockets[$ip]);
        }
    }
    foreach ($sockets as $s) {
        @fclose($s);
    }
    return $offen;
}

/** Einen Kandidaten wirklich fragen. Rueckgabe: array oder null. */
function zd_suche_pruefen($ip, $port = 80)
{
    $ziel = (int) $port === 80 ? $ip : $ip . ':' . (int) $port;
    $a = zd_http_abruf(array('ip' => $ziel, 'nr' => 0), 2);
    if (isset($a['_fehler'])) {
        return null;
    }
    $eig = isset($a['properties']) && is_array($a['properties']) ? $a['properties'] : $a;
    if (!is_array($eig) || !$eig) {
        return null;
    }
    /* Als Zendure-Geraet gilt, wer mindestens eine der Eigenschaften meldet,
     * auf die dieses Plugin baut. Das ist bewusst weit gefasst: die Namen
     * sind ohnehin nicht belegt, und eine zu enge Erkennung uebersaehe genau
     * die Firmware, wegen der es den Feld-Erkunder gibt. Deshalb wird auch
     * ein Kandidat OHNE Treffer aufgefuehrt, nur eben als solcher benannt. */
    $treffer = array();
    foreach (zd_feldkarte() as $feld => $name) {
        if ($name !== '' && array_key_exists($name, $eig)) {
            $treffer[] = $name;
        }
    }
    return array(
        'ip'      => $ip,
        'felder'  => count($eig),
        'bekannt' => $treffer,
        'sn'      => isset($eig['sn']) ? (string) $eig['sn'] : '',
        'zendure' => count($treffer) > 0 ? 1 : 0,
    );
}

/** Auf dem Broker horchen und Produktschluessel/Geraetekennung sammeln. */
function zd_suche_mqtt($sekunden = 12)
{
    if (!zd_mosq_vorhanden()) {
        return array('fehler' => zd_t('DIENST.M_MOSQ_SUB_FEHLT'),
                     'paare' => array());
    }
    $heim = zd_mosq_heim();
    $befehl = 'HOME=' . escapeshellarg($heim)
            . ' XDG_CONFIG_HOME=' . escapeshellarg($heim . '/.config')
            . ' mosquitto_sub -W ' . max(3, min(60, (int) $sekunden))
            . ' -i ' . escapeshellarg('loxberry-' . zd_paths()['plugin'] . '-suche-' . getmypid())
            . " -t '#' -t 'iot/#' -F '%t' 2>&1";
    $aus = array();
    @exec($befehl, $aus);
    $paare = array();
    foreach ($aus as $thema) {
        $pfad = ltrim((string) $thema, '/');
        if (strncmp($pfad, 'iot/', 4) === 0) {
            $pfad = substr($pfad, 4);
        }
        $t = explode('/', $pfad);
        if (count($t) < 3) {
            continue;
        }
        // Nur Themen, die aussehen wie ein Geraet: Produktschluessel und
        // Kennung sind Kennungen, keine Woerter mit Sonderzeichen.
        if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $t[0])
            || !preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $t[1])) {
            continue;
        }
        $k = $t[0] . '/' . $t[1];
        if (!isset($paare[$k])) {
            $paare[$k] = array('prodkey' => $t[0], 'deviceid' => $t[1], 'themen' => 0);
        }
        $paare[$k]['themen']++;
    }
    return array('fehler' => '', 'paare' => array_values($paare));
}

/** Den Suchauftrag ausfuehren. Rueckgabe wie zd_befehl_ausfuehren. */
function zd_suche_ausfuehren(array $befehl, array $cfg)
{
    $art = (isset($befehl['art']) && $befehl['art'] === 'mqtt') ? 'mqtt' : 'http';
    $start = microtime(true);
    $erg = array('art' => $art, 'ts' => time(), 'http' => array(), 'mqtt' => array(),
                 'fehler' => '', 'netz' => '', 'dauer' => 0);
    if ($art === 'http') {
        list($praefix, $eigene) = zd_eigenes_netz();
        $erg['netz'] = $praefix === '' ? '' : $praefix . '.0/24';
        if ($praefix === '') {
            $erg['fehler'] = zd_t('DIENST.M_KEIN_NETZBEREICH');
        } else {
            zd_log('Geraetesuche: ' . $praefix . '.1 bis .254 werden angeklopft.');
            foreach (zd_suche_http($praefix, $eigene) as $ip) {
                $k = zd_suche_pruefen($ip);
                if ($k !== null) {
                    $erg['http'][] = $k;
                }
            }
        }
    } else {
        $m = zd_suche_mqtt(isset($befehl['sekunden']) ? (int) $befehl['sekunden'] : 12);
        $erg['mqtt'] = $m['paare'];
        $erg['fehler'] = $m['fehler'];
    }
    $erg['dauer'] = round(microtime(true) - $start, 1);
    zd_json_schreiben(zd_suche_datei(), $erg);
    zd_log('Geraetesuche (' . $art . ') fertig nach ' . $erg['dauer'] . ' s: '
         . count($erg['http']) . ' HTTP, ' . count($erg['mqtt']) . ' MQTT.');
    return array(1, sprintf(zd_t('DIENST.M_SUCHE_FERTIG'),
                           count($erg['http']), count($erg['mqtt'])),
                 false);
}

/* ------------------------------------------------------------------
 * Satz-Assistent
 *
 * Das README dieses Plugins sagt zu den vier Befehlssaetzen: "Ein falscher
 * Satz fuehrt nicht zu einer Fehlermeldung - es passiert schlicht nichts."
 * Fuer Hub 2000, SolarFlow 1600 AC+ und 4000 AC+ steht ausdruecklich kein
 * Satz in der Modelltabelle; sie seien "im Reiter Test durchzuprobieren".
 *
 * Bis 0.9.9 hiess durchprobieren: raten, senden, in der Zendure-App nachsehen.
 * Der Assistent macht daraus eine Messung. Je Satz:
 *
 *     1. alle Eigenschaften des Geraets aufnehmen
 *     2. EINEN Entladebefehl mit diesem Satz senden
 *     3. warten, bis das Geraet wieder gemeldet hat
 *     4. erneut aufnehmen und vergleichen
 *
 * Was sich geaendert hat, ist die Antwort - und zwar zweifach: WELCHER Satz
 * ankommt und WELCHE Eigenschaft ihn quittiert. Die zweite Auskunft ist die
 * Voraussetzung dafuer, dass die Soll/Ist-Quittung bei den invoke-Saetzen
 * ueberhaupt etwas sagen kann.
 *
 * Er laeuft im Dienst und ueber mehrere Durchgaenge, nicht in einer
 * Web-Anfrage: nur der Dienst spricht mit Geraeten, und zwischen Senden und
 * Messen muss echte Zeit vergehen.
 *
 * ER SCHREIBT INS GERAET. Deshalb: nur auf ausdruecklichen Knopfdruck,
 * hoechstens ein Schreibvorgang je Satz, und am Ende gibt er die Regie
 * zurueck.
 * ------------------------------------------------------------------ */

function zd_satztest_datei()
{
    return zd_paths()['datadir'] . '/satztest.json';
}

/** Welche Saetze lassen sich an diesem Geraet ueberhaupt probieren? */
function zd_satztest_saetze(array $g)
{
    if ($g['art'] !== 'mqtt') {
        /* Drei der vier Saetze laufen ueber function/invoke, und das gibt es
         * nur ueber MQTT. An einem HTTP-Geraet bleibt genau einer uebrig -
         * dann ist nichts zu probieren, und das gehoert gesagt, statt drei
         * Fehlschlaege vorzufuehren. */
        return array('zensdk');
    }
    return zd_befehlssaetze();
}

/** Den Auftrag annehmen. Rueckgabe wie zd_befehl_ausfuehren. */
function zd_satztest_annehmen(array $befehl, array $geraete, array $cfg)
{
    if (empty($cfg['steuerung_ein'])) {
        return array(0, zd_t('DIENST.M_SATZ_GESPERRT'), false);
    }
    $nr = isset($befehl['geraet']) ? (int) $befehl['geraet'] : 1;
    if (!isset($geraete[$nr])) {
        return array(0, sprintf(zd_t('DIENST.M_GERAET_UNBEKANNT'), $nr), false);
    }
    $g = $geraete[$nr];
    $watt = isset($befehl['watt']) ? (int) $befehl['watt'] : 0;
    $grenze = (int) $g['max_entladen'];
    if ($watt < 0 || $watt > $grenze) {
        return array(0, sprintf(zd_t('DIENST.M_AUSSERHALB'), $watt, $grenze), false);
    }
    $alt = zd_json_lesen(zd_satztest_datei());
    if ($alt && isset($alt['phase']) && $alt['phase'] !== 'fertig') {
        return array(0, sprintf(zd_t('DIENST.M_SATZ_LAEUFT'), (int) $alt['geraet']), false);
    }
    $saetze = zd_satztest_saetze($g);
    zd_json_schreiben(zd_satztest_datei(), array(
        'geraet'    => $nr,
        'name'      => $g['name'],
        'art'       => $g['art'],
        'watt'      => $watt,
        'saetze'    => array_values($saetze),
        'schritt'   => 0,
        'phase'     => 'senden',
        'ts'        => time(),
        'gestartet' => time(),
        'vorher'    => array(),
        'ergebnis'  => array(),
    ));
    zd_log('Satz-Assistent gestartet fuer Geraet ' . $nr . ' (' . $g['name'] . '), '
         . count($saetze) . ' Satz/Saetze, ' . $watt . ' W.');
    return array(1, sprintf(zd_t('DIENST.M_SATZ_START'), count($saetze)), true);
}

/** Wie lange zwischen Senden und Messen gewartet wird. */
function zd_satztest_wartezeit(array $cfg)
{
    // Mindestens 30 s, damit auch die Schreibbremse nicht dazwischenfunkt,
    // und mindestens zwei Abfragetakte, damit wirklich neu gemessen wurde.
    return max(30, 2 * max(5, (int) $cfg['intervall']));
}

/**
 * Felder, die ein Schreibbefehl setzt - die Stellgroessen.
 *
 * Sie stammen aus den vier Befehlssaetzen selbst (zd_bau_*): was dort
 * geschrieben wird, muss sich hier wiederfinden. Eine Aenderung an einem
 * dieser Felder ist ein starker Hinweis auf eine Wirkung; eine Aenderung an
 * solarInputPower dagegen ist nur Sonne.
 */
function zd_stellfelder()
{
    return array(
        'outputLimit' => 1, 'inputLimit' => 1, 'acMode' => 1, 'smartMode' => 1,
        'autoModel'   => 1, 'autoModelProgram' => 1, 'autoModelValue' => 1,
        'minSoc'      => 1, 'socSet' => 1,
    );
}

/** Einen Schritt weiterkommen. Wird in jedem Durchgang aufgerufen. */
function zd_satztest_schritt(array $geraete, array $cfg)
{
    $plan = zd_json_lesen(zd_satztest_datei());
    if (!$plan || !isset($plan['phase']) || $plan['phase'] === 'fertig') {
        return;
    }
    $nr = (int) $plan['geraet'];
    if (!isset($geraete[$nr])) {
        $plan['phase'] = 'fertig';
        $plan['abbruch'] = zd_t('DIENST.M_GERAET_WEG');
        zd_json_schreiben(zd_satztest_datei(), $plan);
        return;
    }
    $g = $geraete[$nr];
    $z = isset($GLOBALS['zd_zustaende'][$nr]) ? $GLOBALS['zd_zustaende'][$nr] : array();
    $jetzt = isset($z['eigenschaften']) && is_array($z['eigenschaften']) ? $z['eigenschaften'] : array();

    if ($plan['phase'] === 'senden') {
        if ($plan['schritt'] >= count($plan['saetze'])) {
            // Alle durch: Regie zurueckgeben und abschliessen.
            $gAus = $g;
            $gAus['satz'] = isset($plan['bester']) ? $plan['bester'] : $g['satz'];
            $bau = zd_befehl_bauen($gAus, 'aus');
            if (!empty($bau['ok'])) {
                zd_befehl_senden($bau, $gAus);
                $GLOBALS['zd_letzte_schreibzeit'][$nr] = time();
            }
            $plan['phase'] = 'fertig';
            $plan['fertig_ts'] = time();
            zd_json_schreiben(zd_satztest_datei(), $plan);
            zd_log('Satz-Assistent fertig fuer Geraet ' . $nr . '.');
            return;
        }
        $satz = $plan['saetze'][$plan['schritt']];
        $gTest = $g;
        $gTest['satz'] = $satz;
        $plan['vorher'] = $jetzt;
        $bau = zd_befehl_bauen($gTest, 'entladen', (int) $plan['watt']);
        if (empty($bau['ok'])) {
            $plan['ergebnis'][] = array(
                'satz' => $satz, 'gesendet' => 0,
                'meldung' => isset($bau['meldung']) ? $bau['meldung'] : zd_t('DIENST.M_NICHT_BAUBAR_KURZ'),
                'geaendert' => array(), 'stell' => array(),
            );
            $plan['schritt']++;
            zd_json_schreiben(zd_satztest_datei(), $plan);
            return;
        }
        list($ok, $m) = zd_befehl_senden($bau, $gTest);
        $GLOBALS['zd_letzte_schreibzeit'][$nr] = time();
        /* Die Nutzlast als Fingerabdruck mitfuehren.
         *
         * hyper2000 und ace_aio bauen fuer ENTLADEN dieselbe Nachricht - sie
         * unterscheiden sich nur beim Laden (Preisliste ja/nein). Ein
         * Assistent, der danach einen von beiden benennt, taeuscht eine
         * Unterscheidung vor, die er gar nicht getroffen hat. Gleiche
         * Signatur heisst: nicht unterscheidbar - und das gehoert gesagt. */
        $plan['laufend'] = array(
            'satz' => $satz, 'gesendet' => (int) $ok, 'meldung' => (string) $m,
            'sig'  => md5((string) json_encode($bau['inhalt'])),
        );
        $plan['phase'] = 'messen';
        $plan['ts'] = time();
        zd_json_schreiben(zd_satztest_datei(), $plan);
        zd_log('Satz-Assistent: Satz ' . $satz . ' gesendet (ok=' . (int) $ok . ').');
        return;
    }

    if ($plan['phase'] === 'messen') {
        if (time() - (int) $plan['ts'] < zd_satztest_wartezeit($cfg)) {
            return;                       // noch nicht genug Zeit vergangen
        }
        $vorher = isset($plan['vorher']) && is_array($plan['vorher']) ? $plan['vorher'] : array();
        $geaendert = array();
        foreach ($jetzt as $feld => $wert) {
            $alt = array_key_exists($feld, $vorher) ? $vorher[$feld] : null;
            if ((string) $alt !== (string) $wert) {
                $geaendert[$feld] = array('vorher' => $alt, 'nachher' => $wert);
            }
        }
        /* Nicht jede Aenderung ist eine Wirkung: Leistungswerte schwanken von
         * selbst. Deshalb wird zusaetzlich vermerkt, ob eine STELLGROESSE
         * darunter ist. Die Entscheidung trifft trotzdem der Mensch; das
         * Plugin zeigt beides nebeneinander. */
        $stell = array();
        foreach (array_keys(zd_stellfelder()) as $sf) {
            if (isset($geaendert[$sf])) {
                $stell[] = $sf;
            }
        }
        $lauf = isset($plan['laufend']) ? $plan['laufend']
              : array('satz' => '?', 'gesendet' => 0, 'meldung' => '');
        $plan['ergebnis'][] = array(
            'satz'      => $lauf['satz'],
            'gesendet'  => $lauf['gesendet'],
            'meldung'   => $lauf['meldung'],
            'geaendert' => $geaendert,
            'stell'     => $stell,
            'sig'       => isset($lauf['sig']) ? $lauf['sig'] : '',
        );
        if ($stell && !isset($plan['bester'])) {
            $plan['bester'] = $lauf['satz'];
            $plan['quittungsfeld'] = $stell[0];
        }
        /* Wer trug dieselbe Nutzlast? Diese Saetze sind fuer den geprueften
         * Befehl nicht auseinanderzuhalten - weder von diesem Assistenten
         * noch von irgendeinem anderen Verfahren, denn am Geraet kommt
         * buchstaeblich dieselbe Nachricht an. */
        if (isset($plan['bester'])) {
            $gleich = array();
            $bestsig = '';
            foreach ($plan['ergebnis'] as $er) {
                if ($er['satz'] === $plan['bester']) {
                    $bestsig = $er['sig'];
                }
            }
            foreach ($plan['saetze'] as $s) {
                $gTest2 = $g;
                $gTest2['satz'] = $s;
                $b2 = zd_befehl_bauen($gTest2, 'entladen', (int) $plan['watt']);
                if (!empty($b2['ok']) && $bestsig !== ''
                    && md5((string) json_encode($b2['inhalt'])) === $bestsig
                    && $s !== $plan['bester']) {
                    $gleich[] = $s;
                }
            }
            $plan['ununterscheidbar'] = $gleich;
        }
        unset($plan['laufend']);
        $plan['schritt']++;
        $plan['phase'] = 'senden';
        zd_json_schreiben(zd_satztest_datei(), $plan);
        return;
    }
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
        return array(0, sprintf(zd_t('DIENST.M_BREMSE'), $bremse, $rest));
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
    /* Trockenlauf: alles rechnen, nichts senden.
     *
     * Derselbe Programmcode wie im Ernstfall - Grenzen, Rasterung, Befehlssatz,
     * Nutzlast -, nur ohne die letzte Zeile. Bei einem Plugin, dessen vier
     * Befehlssaetze am Geraet nie gemessen wurden, ist das der Unterschied
     * zwischen "ich probiere" und "ich sehe nach". */
    $trocken = !empty($befehl['trocken']);

    if ($aktion === 'abruf') {
        return array(1, zd_t('DIENST.M_ABRUF_GEPLANT'), true);
    }
    if ($aktion === 'satztest') {
        return zd_satztest_annehmen($befehl, $geraete, $cfg);
    }
    /* Die Suche liest nur - sie braucht weder die Steuerungsfreigabe noch ein
     * eingerichtetes Geraet. Sie steht deshalb vor beiden Pruefungen; ohne
     * eingerichtetes Geraet ist sie sogar der Regelfall. */
    if ($aktion === 'suche') {
        return zd_suche_ausfuehren($befehl, $cfg);
    }
    /* Die Steuerungssperre gilt auch fuer den Trockenlauf NICHT - er sendet ja
     * nichts. Wer wissen will, was ein Befehl taete, soll das duerfen, ohne
     * die Steuerung erst freizugeben. */
    if (empty($cfg['steuerung_ein']) && !$trocken) {
        return array(0, zd_t('DIENST.M_STEUERUNG_AUS'), false);
    }

    $nr = isset($befehl['geraet']) ? (int) $befehl['geraet'] : 1;
    if (!isset($geraete[$nr])) {
        return array(0, sprintf(zd_t('DIENST.M_GERAET_UNBEKANNT_N'), $nr, count($geraete)), false);
    }
    $g = $geraete[$nr];

    // Die Schreibbremse schuetzt den Flash-Speicher. Ein Trockenlauf schreibt
    // nicht, also hat sie hier nichts zu bremsen.
    if (!$trocken) {
        list($frei, $grund) = zd_bremse_pruefen($nr, $cfg);
        if (!$frei) {
            return array(0, $grund, false);
        }
    }

    $zusatz = '';
    $sollwert = null;      // was tatsaechlich vorgegeben wurde - fuer die Quittung
    switch ($aktion) {
        case 'laden':
        case 'entladen':
            $watt = isset($befehl['watt']) ? (int) $befehl['watt'] : null;
            if ($watt === null || !is_numeric($befehl['watt'])) {
                return array(0, zd_t('DIENST.M_WATT_FEHLT'), false);
            }
            $grenze = $aktion === 'laden' ? (int) $g['max_laden'] : (int) $g['max_entladen'];
            if ($aktion === 'laden' && $grenze <= 0) {
                // Eigene Meldung vor der Bereichspruefung: '0 bis 0 W' waere
                // zwar richtig, sagt aber nicht, WARUM.
                return array(0, zd_t('DIENST.M_MODELL_KEIN_LADEN'), false);
            }
            if ($watt < 0 || $watt > $grenze) {
                // Abweisen, nicht zurechtbiegen: ein still gekappter Sollwert
                // fuehrt zu einer Anlage, die etwas anderes tut als angezeigt.
                return array(0, sprintf(zd_t('DIENST.M_AUSSERHALB_HINWEIS'), $watt, $grenze), false);
            }
            $gerastert = zd_rastern($watt, $cfg);
            if ($gerastert !== $watt) {
                $zusatz = ' ' . sprintf(zd_t('DIENST.M_GERASTERT'), $gerastert,
                                        (int) $cfg['schrittweite']);
            }
            $sollwert = $gerastert;
            /* Schutzschwellen und Wiederholungssperre stehen NACH der
             * Rasterung: gerastert wird der Wert, der wirklich hinausginge,
             * und genau der gehoert geprueft und verglichen. */
            $wjetzt = zd_werte();
            $wg = isset($wjetzt[(string) $nr]) ? $wjetzt[(string) $nr]
                : (isset($wjetzt[$nr]) ? $wjetzt[$nr] : array());
            list($erlaubt, $sgrund) = zd_schutz_pruefen($aktion, $wg, $cfg);
            if (!$erlaubt && !$trocken) {
                return array(0, $sgrund, false);
            }
            if ($sgrund === 'ohne frische Messung' && !empty($cfg['schutz_ein'])) {
                // Sagen, dass nicht geprueft werden konnte - ein Schutz, der
                // stillschweigend durchlaesst, taeuscht Sicherheit vor.
                $zusatz .= ' ' . zd_t('DIENST.M_SCHUTZ_BLIND');
                zd_log_gebremst('schutz_blind_' . $nr, 'Schutzschwellen fuer Geraet ' . $nr
                    . ' nicht pruefbar (keine frische Messung) - Befehl durchgelassen.', 900);
            }
            if (!$trocken) {
                list($senden, $wgrund) = zd_wiederholung_pruefen($nr, $aktion, $gerastert, $wg, $cfg);
                if (!$senden) {
                    // Ein uebergangener Befehl ist ein ERFOLG, kein Fehler:
                    // der gewuenschte Zustand steht ja bereits am Geraet.
                    return array(1, $wgrund . $zusatz, false);
                }
            }
            $bau = zd_befehl_bauen($g, $aktion, $gerastert);
            break;

        case 'aus':
            $sollwert = 0;
            $bau = zd_befehl_bauen($g, 'aus');
            break;

        case 'socmin':
        case 'socmax':
            $pz = isset($befehl['prozent']) ? (int) $befehl['prozent'] : -1;
            if ($pz < 0 || $pz > 100) {
                return array(0, zd_t('DIENST.M_PROZENT'), false);
            }
            $sollwert = $pz;
            $bau = zd_befehl_bauen($g, $aktion, $pz);
            break;

        case 'grenzeaus':
        case 'grenzeein':
            $watt = isset($befehl['watt']) ? (int) $befehl['watt'] : -1;
            $grenze = $aktion === 'grenzeaus' ? (int) $g['max_entladen'] : (int) $g['max_laden'];
            if ($watt < 0 || $watt > $grenze) {
                return array(0, sprintf(zd_t('DIENST.M_AUSSERHALB'), $watt, $grenze), false);
            }
            $sollwert = zd_rastern($watt, $cfg);
            $bau = zd_befehl_bauen($g, $aktion, $sollwert);
            break;

        default:
            return array(0, sprintf(zd_t('DIENST.M_AKTION_UNBEKANNT'), $aktion), false);
    }

    if (empty($bau['ok'])) {
        return array(0, isset($bau['meldung']) ? $bau['meldung']
                                               : zd_t('DIENST.M_NICHT_BAUBAR'), false);
    }

    if ($trocken) {
        // Nichts senden, aber alles zeigen - samt der fertigen Nutzlast.
        return array(1, sprintf(zd_t('DIENST.M_TROCKEN'), zd_bau_text($bau)) . $zusatz, false);
    }

    list($ok, $m) = zd_befehl_senden($bau, $g);

    if ($ok) {
        $GLOBALS['zd_letzte_schreibzeit'][$nr] = time();
        // Fuer die Quittung festhalten, WAS vorgegeben wurde und woran sich
        // ablesen liesse, ob es angekommen ist.
        zd_soll_merken($nr, $aktion, $sollwert, $bau, $g);
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
            zd_antwort_schreiben($kennung, 0, zd_t('DIENST.M_DATEI_LEER'));
            continue;
        }
        /* Verfallen? Ein Stellbefehl, der lange liegt, ist keine verspaetete
         * Regelung - er ist eine falsche. Gemessen wird der Zeitstempel des
         * Einreihens; fehlt er (Datei aus einer aelteren Fassung), gilt die
         * Aenderungszeit der Datei. Verworfen wird MIT Antwort: der Aufrufer
         * soll den Unterschied zwischen "abgelehnt" und "nie angekommen"
         * sehen koennen. */
        $verfall = max(0, min(86400, (int) (isset($cfg['befehl_verfall_s'])
                                            ? $cfg['befehl_verfall_s'] : 300)));
        $eingereiht = isset($b['ts']) ? (int) $b['ts'] : (int) @filemtime($datei);
        $liegt = $eingereiht > 0 ? time() - $eingereiht : 0;
        if ($verfall > 0 && $liegt > $verfall
            && zd_ist_stellbefehl(isset($b['aktion']) ? $b['aktion'] : '')) {
            $grund = sprintf(zd_t('DIENST.M_VERFALLEN'), $liegt, $verfall);
            zd_antwort_schreiben($kennung, 0, $grund);
            zd_log('Befehl ' . $kennung . ' verworfen: ' . $grund);
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
 * Verlauf, Energie und Abbild
 * ------------------------------------------------------------------ */

/**
 * Leistung zu Energie aufsummieren.
 *
 * Rechteckintegration ueber den tatsaechlich vergangenen Zeitraum, mit dem
 * ZULETZT gemessenen Wert. Das ist der ehrlichste einfache Weg: was zwischen
 * zwei Abrufen geschah, weiss niemand, und eine Trapezregel taeuschte eine
 * Kenntnis vor, die es nicht gibt.
 *
 * Drei Schranken, und jede hat einen Grund:
 *
 *   ok = 0      Ein Geraet, das nicht antwortet, liefert keine Leistung -
 *               durch eine Stoerung hindurchzuintegrieren erfaende Energie.
 *   Wert null   Dasselbe fuer ein einzelnes Feld, das die Firmware nicht
 *               meldet: kein Wert ist keine Null.
 *   Sprung      Nach einem Dienstneustart oder einer langen Stoerung liegt
 *               der letzte Zeitstempel weit zurueck. Ohne Deckel ergaebe das
 *               aus 300 W und zwei Tagen 14 kWh, die es nie gab.
 */
function zd_energie_schritt($nr, array $w, array $cfg)
{
    if (empty($cfg['energie_ein'])) {
        return;
    }
    $alle = zd_energie_alle();
    $k = (string) (int) $nr;
    $st = isset($alle[$k]) && is_array($alle[$k]) ? $alle[$k]
        : array('stand' => array(), 'tagesstart' => array(), 'tag' => '', 'ts' => 0);
    $felder = array_keys(zd_energiefelder());
    foreach ($felder as $f) {
        if (!isset($st['stand'][$f])) {
            $st['stand'][$f] = 0.0;
        }
    }

    $jetzt = time();
    $letzte = isset($st['ts']) ? (int) $st['ts'] : 0;
    $spanne = $letzte > 0 ? $jetzt - $letzte : 0;
    // Hoechstens drei Takte, mindestens aber eine Minute Spielraum.
    $deckel = max(60, 3 * max(5, (int) $cfg['intervall']));
    if ($spanne > $deckel) {
        zd_log_gebremst('energie_luecke_' . $nr,
            'Energiezaehler Geraet ' . $nr . ': ' . $spanne . ' s Luecke seit dem letzten '
            . 'Schritt - angerechnet werden nur ' . $deckel . ' s, der Rest gilt als nicht '
            . 'gemessen.', 3600);
        $spanne = $deckel;
    }

    if ($spanne > 0 && !empty($w['ok'])) {
        foreach ($felder as $f) {
            if (!isset($w[$f]) || $w[$f] === null) {
                continue;      // nicht gemeldet ist nicht null
            }
            $watt = (float) $w[$f];
            if ($watt <= 0) {
                continue;
            }
            $st['stand'][$f] += $watt * $spanne / 3600.0;
        }
    }
    $st['ts'] = $jetzt;

    /* Tageswechsel: den abgeschlossenen Tag fortschreiben und den Tagesstart
     * neu setzen. Ein Tag OHNE Messung bekommt KEINE Nullzeile - sonst
     * stuende in der Bilanz ein Tag mit 0 kWh, an dem in Wahrheit niemand
     * gemessen hat. */
    $heute = date('Ymd');
    if (!isset($st['tag']) || $st['tag'] === '') {
        $st['tag'] = $heute;
        $st['tagesstart'] = $st['stand'];
    } elseif ($st['tag'] !== $heute) {
        $tagswerte = array();
        $etwas = false;
        foreach ($felder as $f) {
            $d = max(0.0, (float) $st['stand'][$f]
                        - (float) (isset($st['tagesstart'][$f]) ? $st['tagesstart'][$f] : 0));
            $tagswerte[] = round($d, 2);
            if ($d > 0) {
                $etwas = true;
            }
        }
        if ($etwas) {
            $datei = zd_energie_monatsdatei($nr, substr($st['tag'], 0, 6));
            if (!is_dir(dirname($datei))) {
                @mkdir(dirname($datei), 0775, true);
            }
            @file_put_contents($datei, $st['tag'] . ';' . implode(';', $tagswerte) . "\n",
                               FILE_APPEND);
            zd_log('Tagesabschluss Geraet ' . $nr . ' (' . $st['tag'] . '): '
                 . implode(' / ', array_map(function ($x) { return round($x / 1000, 2) . ' kWh'; },
                                            $tagswerte)));
        }
        $st['tag'] = $heute;
        $st['tagesstart'] = $st['stand'];
        zd_energie_aufraeumen($nr, (int) $cfg['energie_monate']);
    }

    $alle[$k] = $st;
    zd_json_schreiben(zd_energie_datei(), $alle);
}

/**
 * Der Rueckfall: kommt lange kein Sollwert mehr, Regie zurueckgeben.
 *
 * Greift nur bei einem STELLBEFEHL (laden, entladen) - eine
 * Ladezustandsgrenze ist eine Einstellung und soll stehen bleiben.
 *
 * Einmal, nicht immer wieder: nach dem Rueckfall wird der Sollmerker
 * geloescht. Ohne das schickte der Dienst im Takt weiter "aus" hinterher und
 * fuellte den Flash-Speicher mit genau der Sorte Wiederholung, die die
 * Wiederholungssperre daneben verhindert.
 */
function zd_rueckfall_pruefen($nr, array $g, array $cfg)
{
    $soll = zd_soll_lesen($nr);
    $rest = zd_rueckfall_rest($soll, $cfg);
    if ($rest === null || $rest > 0) {
        return;
    }
    $bau = zd_befehl_bauen($g, 'aus');
    if (empty($bau['ok'])) {
        zd_log_gebremst('rueckfall_bau_' . $nr, 'Rueckfall fuer Geraet ' . $nr
            . ' nicht moeglich: ' . (isset($bau['meldung']) ? $bau['meldung'] : '?'), 3600);
        return;
    }
    list($ok, $m) = zd_befehl_senden($bau, $g);
    $GLOBALS['zd_letzte_schreibzeit'][$nr] = time();
    zd_log('Rueckfall: seit ' . (int) $cfg['rueckfall_min'] . ' Minuten kam kein Sollwert '
         . 'mehr fuer Geraet ' . $nr . ' (' . $g['name'] . ') - die Regie geht an das '
         . 'Geraet zurueck (ok=' . (int) $ok . ' ' . $m . ').');
    // Den Sollmerker raeumen, damit der Rueckfall genau einmal greift.
    $alle = zd_soll_alle();
    unset($alle[(string) (int) $nr]);
    zd_json_schreiben(zd_paths()['datadir'] . '/soll.json', $alle);
}

/** Monatsdateien wegraeumen, die aelter sind als die Aufbewahrung. */
function zd_energie_aufraeumen($nr, $monate)
{
    $monate = max(1, min(120, (int) $monate));
    $grenze = date('Ym', strtotime('-' . $monate . ' months'));
    foreach (glob(zd_paths()['bestand'] . '/energie/geraet' . (int) $nr . '_*.csv') ?: array() as $f) {
        if (preg_match('/_(\d{6})\.csv$/', $f, $m) && $m[1] < $grenze) {
            @unlink($f);
        }
    }
}

/**
 * Den Herzschlag weiterzaehlen. Rueckgabe: der neue Stand (0 bis 999).
 *
 * Er liegt im Datenordner und nicht im Bestand: ein Zaehler, der ein Update
 * ueberlebt, sagt nichts Zusaetzliches - nach einem Update laeuft der Dienst
 * ohnehin neu an.
 */
function zd_herzschlag()
{
    $f = zd_paths()['datadir'] . '/.takt';
    $n = is_file($f) ? (int) @file_get_contents($f) : -1;
    $n = ($n + 1) % 1000;
    @file_put_contents($f, (string) $n);
    return $n;
}

function zd_verlauf_anhaengen($nr, $soc, $batp, $tage)
{
    if ($soc === null) {
        return;
    }
    $p = zd_paths();
    // NEBEN dem Datenordner - siehe zd_paths(). Darin wuerde der Verlauf bei
    // jedem Plugin-Update geloescht, und die eingestellten acht Tage waeren
    // eine Zusage, die nie eingeloest wird.
    $ordner = $p['verlaufdir'];
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        zd_log_gebremst('verlauf_ordner', 'Der Verlaufsordner liess sich nicht anlegen: '
            . $ordner . ' - es wird kein Verlauf aufgezeichnet.', 3600);
        return;
    }
    // Die Zeitmarke gehoert zum Verlauf und liegt deshalb bei ihm. Bliebe sie
    // im Datenordner, waere sie nach einem Update fort - der erste Messpunkt
    // danach kaeme dann sofort statt nach vier Minuten. Das ist harmlos, aber
    // zwei Ablageorte fuer eine Sache sind einer zu viel.
    $marke = $p['bestand'] . '/.verlauf_ts_' . (int) $nr;
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
        zd_energie_schritt($nr, $w, $cfg);
        zd_rueckfall_pruefen($nr, $g, $cfg);
    }

    zd_json_schreiben($p['datadir'] . '/loxone.json', array(
        /* Der Herzschlag: ein Zaehler, der mit jedem Durchgang weiterlaeuft
         * und bei 999 wieder von vorn beginnt.
         *
         * ALTER beantwortet dieselbe Frage - solange die Uhr stimmt. Genau da
         * liegt der Unterschied: ein Raspberry Pi hat keine Echtzeituhr. Nach
         * dem Booten steht er in der Vergangenheit, und sobald NTP greift,
         * springt die Zeit. ALTER ist in diesem Augenblick Unsinn, der
         * Zaehler nicht. Er ist ausserdem das einfachere Alarmsignal in
         * Loxone: ein Wert, der sich nicht mehr aendert, statt eines Werts,
         * der ueber eine Schwelle steigt. */
        'zaehler' => zd_herzschlag(),
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
                           'packs', 'dvolt', 'temp', 'soll') as $feld) {
                $paare['geraet' . $nr . '/' . $feld] = $w[$feld];
            }
            $paare['geraet' . $nr . '/online'] = $w['ok'];
            // null heisst "keine Aussage" und wird von zd_mqtt_senden()
            // ausgelassen - ein gesendetes 0 waere hier eine Falschaussage.
            $paare['geraet' . $nr . '/sollok'] = $w['sollok'];
            $paare['geraet' . $nr . '/ms'] = $w['ms'];
            // Energie in kWh: was Loxone und der Energiefluss-Monitor wollen.
            if (!empty($cfg['energie_ein'])) {
                foreach (array('tag' => 'heute', 'monat' => 'monat', 'jahr' => 'jahr') as $zr => $name) {
                    foreach (zd_energie_summe($nr, $zr) as $ef => $wh) {
                        $paare['geraet' . $nr . '/energie/' . $name . '/' . $ef] = round($wh / 1000, 3);
                    }
                }
                $kz = zd_energie_kennzahlen($nr, $geraete[$nr]);
                $paare['geraet' . $nr . '/energie/gesamt/laden'] = round($kz['geladen_wh'] / 1000, 3);
                $paare['geraet' . $nr . '/energie/gesamt/entladen'] = round($kz['entladen_wh'] / 1000, 3);
                $paare['geraet' . $nr . '/energie/wirkungsgrad'] = $kz['wirkungsgrad'];
                $paare['geraet' . $nr . '/energie/zyklen'] = $kz['zyklen'];
            }
            foreach ($w['packliste'] as $sn => $pk) {
                /* Die Seriennummer kommt aus packData[].sn, also vom Geraet -
                 * das Plugin hat sie nicht in der Hand. Ungesaeubert zerlegt
                 * ein Leerzeichen darin die Uebertragung zum Gateway, das
                 * zeilenweise liest und Thema und Wert am Leerzeichen trennt.
                 * Gemessen an 0.9.8, Pruefstand p9_name.php. */
                $skenn = zd_mqtt_thema_teil($sn);
                foreach (array('soc', 'volt', 'dvolt', 'temp', 'watt') as $feld) {
                    $paare['geraet' . $nr . '/pack/' . $skenn . '/' . $feld] = $pk[$feld];
                }
            }
        }
        // Summe ueber alle Geraete - nur, wenn es mehr als eines gibt.
        if (count($werte) > 1) {
            foreach (zd_summe($werte, $geraete) as $sf => $sv) {
                if ($sf !== 'ok' && $sf !== 'n' && $sf !== 'nok') {
                    $paare['summe/' . $sf] = $sv;
                }
            }
        }
        zd_mqtt_senden_bei_aenderung($paare, $praefix, $cfg);
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
                /* Ins Protokoll die Systemmeldung, nicht die Erklaerung -
                 * siehe zd_http_abruf(). Fehlt sie (MQTT, HTTP-Status),
                 * bleibt es beim erklaerten Text. */
                zd_log_gebremst('abruf' . $nr, 'Abruf von ' . $g['name'] . ' (' . $g['ip'] . '): '
                    . (!empty($antwort['_roh']) ? $antwort['_roh'] : $antwort['_fehler']), 900);
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
    // Der Satz-Assistent laeuft ueber mehrere Durchgaenge: senden, warten,
    // messen. Er steht NACH dem Abruf, damit er mit frisch gelesenen Werten
    // vergleicht und nicht mit denen des vorigen Durchgangs.
    zd_satztest_schritt($geraete, $cfg);
    zd_abbild_schreiben($geraete, $cfg);
    /* Der Befund - EINE Funktion fuer Oberflaeche, Healthcheck und Meldung.
     * Gemeldet wird nur bei WECHSEL; eine Meldung je Durchgang waere Rauschen,
     * und wer sie abstellt, stellt auch die echte ab. */
    zd_melden(zd_befund());

    zd_zustand_datei_schreiben(array(
        'fehler'   => implode(' | ', $fehler),
        'intervall' => (int) $cfg['intervall'],
        'geraete'  => count($geraete),
    ));
    return $fehler;
}

function zd_dienst_schleife($einmal = false)
{
    /* Einmal beim Start vervollstaendigen (Hausstandard). Nicht bei jedem
     * Durchgang: zd_cfg_vervollstaendigen() schreibt nur, wenn wirklich
     * etwas fehlt, aber ein Aufruf in der Schleife waere trotzdem eine
     * Datei-Abfrage je Takt fuer eine Frage, die sich im Betrieb nicht
     * aendert. */
    list($zd_cok, $zd_cn, $zd_cmeld) = zd_cfg_vervollstaendigen();
    if ($zd_cn > 0) {
        zd_log('Konfiguration vervollstaendigt: ' . $zd_cmeld);
    }
    $cfg = zd_config();
    $geraete = zd_geraete();
    if (!$geraete) {
        /* Bis 0.9.11 beendete sich der Dienst hier. Das ist genau falsch
         * herum: OHNE eingetragenes Geraet braucht man ihn am dringendsten,
         * denn die Geraetesuche laeuft in ihm - sie ist der Weg, an
         * IP-Adresse beziehungsweise Produktschluessel und Geraetekennung
         * ueberhaupt heranzukommen.
         *
         * Er laeuft deshalb weiter und bedient die Warteschlange; abgerufen
         * wird nichts, weil es nichts abzurufen gibt. Sobald ein Geraet
         * eingetragen ist, nimmt die Schleife es von selbst auf. */
        zd_log('Noch kein Geraet eingerichtet - der Dienst laeuft trotzdem, '
             . 'damit die Geraetesuche im Reiter Test benutzbar ist.');
        zd_zustand_datei_schreiben(array('fehler' => zd_t('DIENST.M_KEIN_GERAET')));
    }
    zd_log('Dienst startet: ' . count($geraete) . ' Geraet(e), Takt ' . (int) $cfg['intervall']
         . ' s, Steuerung ' . (!empty($cfg['steuerung_ein']) ? 'ein' : 'aus') . '.');

    // Frueheren Zustand uebernehmen, damit nach einem Neustart nicht alles leer ist.
    $alt = zd_cache();
    if (isset($alt['zustaende']) && is_array($alt['zustaende'])) {
        $GLOBALS['zd_zustaende'] = $alt['zustaende'];
    }

    // Verlauf und Sollmerker liegen seit 0.9.9 neben dem Datenordner, damit
    // sie ein Update ueberstehen. Wer von 0.9.8 kommt, hat sie noch am alten
    // Platz - dieser Zug holt sie einmalig hinueber.
    zd_bestand_sichern();

    zd_horcher_sicherstellen($geraete);
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
        if (!is_file(zd_paths()['soll'])) {
            zd_log('Der Merker soll_laufen ist weg - Dienst haelt an.');
            break;
        }

        zd_horcher_lesen($geraete);
        // Nach dem Lesen, nicht davor: zd_horcher_lesen() ist die Stelle, die
        // einen gestorbenen Horcher erkennt und wegraeumt. Hier wird er in
        // derselben Runde wieder aufgenommen, ohne dass sich dafuer die
        // Geraeteliste aendern muss.
        zd_horcher_sicherstellen($geraete);
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
                $GLOBALS['zd_horcher_wartezeit'] = 0;
                $GLOBALS['zd_horcher_naechster'] = 0;
                zd_horcher_sicherstellen($geraete);
            }
            // Auch hier NICHT abbrechen: wer sein letztes Geraet
            // herausnimmt, will es meist gleich durch ein anderes ersetzen -
            // und braucht dafuer die Suche, die in diesem Dienst laeuft.
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
            $zeilen[] = '[OK]   ' . sprintf(zd_t('DIENST.S_ERW_DA'), $erw);
        } else {
            $fehler++;
            $zeilen[] = '[FEHL] ' . sprintf(zd_t('DIENST.S_ERW_FEHLT'), $erw);
        }
    }

    foreach (array('S_O_CONFIG' => $p['configdir'], 'S_O_DATEN' => $p['datadir'],
                   'S_O_LOG' => $p['logdir']) as $name => $pfad) {
        $ok = is_dir($pfad) && is_writable($pfad);
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ')
                  . sprintf(zd_t('DIENST.S_ORDNER'), zd_t('DIENST.' . $name), $pfad);
        if (!$ok) {
            $fehler++;
        }
    }

    if (!$geraete) {
        $fehler++;
        $zeilen[] = '[FEHL] ' . zd_t('DIENST.S_KEIN_GERAET');
    } else {
        $zeilen[] = '[OK]   ' . sprintf(zd_t('DIENST.S_GERAETE'), count($geraete));
        foreach ($geraete as $nr => $g) {
            $zeilen[] = '[INFO]   ' . $nr . ') ' . $g['name']
                      . ' - ' . sprintf(zd_t('DIENST.S_GERAET_ZEILE'),
                                        $g['art'], $g['satz'],
                                        $g['max_laden'], $g['max_entladen'])
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
        $zeilen[] = '[OK]   ' . zd_t('DIENST.S_MOSQ_DA');
    } elseif ($brauchtMosq) {
        $fehler++;
        $zeilen[] = '[FEHL] ' . zd_t('DIENST.S_MOSQ_FEHLT');
    } else {
        $zeilen[] = '[INFO] ' . zd_t('DIENST.S_MOSQ_EGAL');
    }

    if ($brauchtMosq) {
        $b = zd_broker();
        $heim = zd_mosq_heim();
        $rechte = is_file($heim . '/.config/mosquitto_sub') ? (fileperms($heim . '/.config/mosquitto_sub') & 0777) : -1;
        $zeilen[] = '[INFO] ' . sprintf(zd_t('DIENST.S_BROKER'), $b['host'], $b['port'])
                  . ($b['user'] !== '' ? ' ' . sprintf(zd_t('DIENST.S_BROKER_USER'), $b['user'])
                                       : ' ' . zd_t('DIENST.S_BROKER_ANONYM'));
        // Die Form eines Geheimnisses darf beurteilt werden, sein Wert nie.
        $zeilen[] = ($b['pw'] !== '' ? '[OK]   ' : '[INFO] ')
                  . ($b['pw'] !== '' ? sprintf(zd_t('DIENST.S_PW_DA'), strlen($b['pw']))
                                     : zd_t('DIENST.S_PW_KEINS'));
        $ok = $rechte >= 0 && ($rechte & 0077) === 0;
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ')
                  . sprintf(zd_t('DIENST.S_RECHTE'),
                            $rechte >= 0 ? '0' . decoct($rechte) : zd_t('DIENST.S_DATEI_FEHLT'));
        if (!$ok) {
            $fehler++;
        }
    }

    $m = zd_mqtt_zustand();
    if (!$m['gefunden']) {
        $fehler++;
        $zeilen[] = '[FEHL] ' . zd_t('DIENST.S_KEIN_MQTT_ABSCHNITT');
    } elseif ($m['autostart']) {
        $zeilen[] = '[OK]   ' . sprintf(zd_t('DIENST.S_GATEWAY_AN'),
                                        $m['broker'], $m['brokerport'], $m['udpport']);
    } else {
        $fehler++;
        $zeilen[] = '[FEHL] ' . zd_t('DIENST.S_GATEWAY_AUS');
    }

    $zeilen[] = '[INFO] ' . sprintf(zd_t('DIENST.S_TAKT'), (int) $cfg['intervall'],
                                    (int) $cfg['schreibbremse'], (int) $cfg['schrittweite']);
    $zeilen[] = '[INFO] ' . sprintf(zd_t('DIENST.S_STEUERUNG'),
                    zd_t(!empty($cfg['steuerung_ein']) ? 'DIENST.S_FREI' : 'DIENST.S_GESPERRT'));
    $zeilen[] = '[INFO] ' . sprintf(zd_t('DIENST.S_TEMP'), (string) $cfg['temp_umrechnung']);

    $alter = zd_alter();
    $zeilen[] = $alter < 0 ? '[INFO] ' . zd_t('DIENST.S_NIE_ABGERUFEN')
                           : '[INFO] ' . sprintf(zd_t('DIENST.S_LETZTES_ABBILD'), $alter);

    $zeilen[] = '';
    $zeilen[] = zd_t('DIENST.S_UNGEPRUEFT');
    foreach (array('S_U_ANTWORT', 'S_U_NAMEN', 'S_U_BEFEHLE', 'S_U_EINHEIT') as $zd_u) {
        $zeilen[] = '  - ' . zd_t('DIENST.' . $zd_u);
    }
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
/* Ein unbekannter Schalter darf NICHT den Dienst starten.
 *
 * Bis 0.9.13 fiel jedes unbekannte Argument stillschweigend durch und landete
 * in der Dienstschleife. Aufgefallen ist es beim Bau dieser Fassung: eine
 * Pruefung rief "--selftest" statt "--selbsttest" und startete damit einen
 * Dienst, statt eine Auskunft zu bekommen. Wer ein Werkzeug von Hand
 * aufruft, soll bei einem Tippfehler eine Antwort sehen, keinen Prozess. */
foreach ($zd_argv as $zd_i => $zd_a) {
    if ($zd_i === 0 || strncmp((string) $zd_a, '--', 2) !== 0) {
        continue;
    }
    if (!in_array($zd_a, array('--selbsttest', '--einmal'), true)) {
        fwrite(STDERR, sprintf(zd_t('DIENST.M_SCHALTER_UNBEKANNT'), $zd_a) . "\n");
        exit(2);
    }
}
if (in_array('--selbsttest', $zd_argv, true)) {
    exit(zd_selbsttest());
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'zd_signal_behandeln');
    pcntl_signal(SIGINT, 'zd_signal_behandeln');
}
exit(zd_dienst_schleife(in_array('--einmal', $zd_argv, true)));
