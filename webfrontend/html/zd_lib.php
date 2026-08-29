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


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
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
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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
            /* NEBEN dem Datenordner, nicht darin.
             *
             * plugininstall.pl ruft beim Upgrade purge_installation, und das
             * entfernt data/plugins/<ordner>/ vollstaendig, ohne Bedingung.
             * Bis 0.9.8 lagen dort der SOC-Verlauf - eingestellt sind acht
             * Tage - und der Sollmerker. Nach JEDEM Update war beides fort:
             * die Kurve leer, und der Waechter startete den Dienst nicht
             * wieder, weil er ohne soll_laufen nichts tut. Gemessen an einem
             * nachgestellten Update, Pruefstand p8_update.sh.
             *
             * Der Punkt im Namen ist kein Zufall: der Ordner liegt im selben
             * Verzeichnis, wird von "rm -rf <ordner>/" aber nicht getroffen. */
            'bestand'    => $home . '/data/plugins/' . $dir . '.bestand',
            'verlaufdir' => $home . '/data/plugins/' . $dir . '.bestand/verlauf',
            'soll'       => $home . '/data/plugins/' . $dir . '.bestand/soll_laufen',
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
            'bestand'    => $basis . '/data.bestand',
            'verlaufdir' => $basis . '/data.bestand/verlauf',
            'soll'       => $basis . '/data.bestand/soll_laufen',
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
        // Freie Feldzuordnung: leer = die Vorgaben aus zd_feldkarte()
        'zuordnung'      => array(),
        'packzuordnung'  => array(),
        // Wie lange ein Geraet Zeit bekommt, einen gesetzten Wert
        // zurueckzumelden, bevor die Quittung ihn als nicht uebernommen wertet
        'quittung_nachlauf' => 60,

        /* --- Wiederholungssperre ---------------------------------------
         * Bis 0.9.10 wurde jeder Sollwert geschrieben, auch der
         * unveraenderte. Beim empfohlenen Sendetakt von 60 s sind das 1440
         * Flash-Schreibvorgaenge am Tag fuer einen Wert, der sich nicht
         * geaendert hat - bei einem Plugin, das seine Schreibbremse
         * ausdruecklich mit der Schreibfestigkeit des Flash begruendet.
         *
         * Ab Werk 0: uebergangen wird nur der GLEICHE Wert. Das ueberrascht
         * niemanden und wirkt trotzdem am staerksten. Ein groesseres Totband
         * ist eine bewusste Entscheidung. */
        'totband_w'            => 0,
        /* Auch ein unveraenderter Wert wird nach dieser Zeit erneut gesendet -
         * fuer den Fall, dass das Geraet ihn vergessen hat (Stromausfall,
         * Firmware-Neustart). 0 schaltet das ab. */
        'totband_auffrischung' => 600,

        /* --- Rueckfall (Watchdog) --------------------------------------
         * Zendure stoppt nicht von selbst, wenn Loxone schweigt: ein
         * gesetzter Sollwert bleibt stehen. Bis 0.9.10 schob das README die
         * Aufgabe an Loxone ("vor dem Herunterfahren einmal aktion=aus
         * senden") - das deckt einen geplanten Neustart ab, keinen Ausfall
         * des Miniservers. Der Dienst kann es selbst. Ab Werk AUS: er greift
         * in eine laufende Anlage ein, und das darf niemand ungefragt. */
        /* --- Temperatureinheit ------------------------------------------
         * Sie fehlte bis 0.9.12 in dieser Liste, wurde aber vom Formular
         * geschrieben und an FUENF Stellen mit je eigenem Rueckfall auf
         * 'roh' gelesen. Fuenf Rueckfaelle sind fuenf Gelegenheiten
         * auseinanderzulaufen - und eine Einstellung, die in den Vorgaben
         * fehlt, taucht in keiner Sicherung und in keinem Vergleich auf. */
        'temp_umrechnung' => 'roh',

        'rueckfall_min'   => 0,

        /* --- Verfall in der Warteschlange -------------------------------
         * Ein Befehl liegt im Dateisystem, nicht in der Anfrage. Steht der
         * Dienst - abgestuerzt, angehalten, LoxBerry neu gestartet -, bleibt
         * er dort liegen und wird beim naechsten Start ausgefuehrt, gleich
         * wie viele Stunden spaeter. Ein "laden 3000 W" von gestern Abend
         * heute frueh auszufuehren ist keine verspaetete Regelung, sondern
         * eine falsche. Nach dieser Zeit wird ein Stellbefehl verworfen
         * statt ausgefuehrt - und der Verwurf wird gemeldet, nicht
         * verschwiegen. 0 schaltet ab. */
        'befehl_verfall_s' => 300,

        /* --- Schutzschwellen -------------------------------------------
         * Sollwerte abweisen, die dem Speicher nicht guttun. Ab Werk aus. */
        'schutz_ein'      => 0,
        'schutz_soc_min'  => 10,    // darunter nicht mehr entladen
        'schutz_soc_max'  => 95,    // darueber nicht mehr laden
        'schutz_temp_min' => 0,     // in der EINGESTELLTEN Temperatureinheit
        'schutz_temp_max' => 45,

        /* --- Energiezaehler --------------------------------------------
         * Das Geraet meldet nur Augenblicksleistungen. Der Dienst integriert
         * sie selbst - protokollunabhaengig, also unabhaengig davon, ob eine
         * Firmware Zaehler liefert. */
        'energie_ein'     => 1,
        'energie_monate'  => 25,    // Aufbewahrung der Tagesabschluesse

        /* --- MQTT ------------------------------------------------------
         * Nur senden, was sich geaendert hat. Bis 0.9.10 gingen bei
         * Vorgabetakt 15 s rund 5760 Durchgaenge am Tag mit je etwa zwanzig
         * Feldern je Geraet unveraendert hinaus. Nach dieser Zeit wird
         * trotzdem alles gesendet, damit ein neu gestartetes Gateway einen
         * vollstaendigen Stand bekommt. 0 schaltet die Sperre ab. */
        'mqtt_auffrischung' => 300,
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

/**
 * Was fehlt, was ist fremd?
 *
 * zd_config() ergaenzt Fehlendes bei JEDEM Lesen ueber array_merge() - im
 * Arbeitsspeicher. Auf der Platte steht deshalb weiter eine unvollstaendige
 * Datei, und das faellt erst auf, wenn jemand sie sichert, vergleicht oder
 * von Hand liest. Diese Funktion sagt, was der Fall ist:
 *
 *   fehlend    Schluessel aus den Vorgaben, die in der Datei nicht stehen.
 *              Harmlos im Betrieb, aber eine Sicherung davon ist
 *              unvollstaendig.
 *   fremd      Schluessel in der Datei, die es in den Vorgaben nicht gibt.
 *              Entweder Reste einer aelteren Fassung oder ein Tippfehler
 *              von Hand - in beiden Faellen wirkungslos, und genau das ist
 *              die Ueberraschung: man hat etwas eingestellt, es steht in der
 *              Datei, und es tut nichts.
 *
 * Sie AENDERT nichts von sich aus. Eine Konfiguration ungefragt umzuschreiben
 * waere der schlechtere Weg: wer eine Einstellung von Hand eingetragen hat,
 * soll sie wiederfinden - und sei es als Befund.
 */
function zd_cfg_lage()
{
    $p = zd_paths();
    $vorgaben = zd_vorgaben();
    $datei = zd_json_lesen($p['config']);
    if (!is_array($datei)) {
        $datei = array();
    }
    $fehlend = array_values(array_diff(array_keys($vorgaben), array_keys($datei)));
    $fremd   = array_values(array_diff(array_keys($datei), array_keys($vorgaben)));
    sort($fehlend);
    sort($fremd);
    return array('fehlend' => $fehlend, 'fremd' => $fremd,
                 'vollstaendig' => (!$fehlend && !$fremd) ? 1 : 0,
                 'anzahl' => count($vorgaben));
}

/**
 * Fehlende Schluessel in die Datei schreiben - auf ausdrueckliche Ansage.
 *
 * Fremde bleiben stehen. Sie zu loeschen waere anmassend: diese Funktion
 * weiss nicht, ob dort der Rest einer aelteren Fassung steht oder etwas,
 * das der naechsten schon gehoert.
 *
 * Rueckgabe: array(ok, Anzahl der ergaenzten Schluessel, Meldung).
 */
function zd_cfg_vervollstaendigen()
{
    $p = zd_paths();
    $lage = zd_cfg_lage();
    if (!$lage['fehlend']) {
        return array(1, 0, 'Es fehlte nichts.');
    }
    $datei = zd_json_lesen($p['config']);
    if (!is_array($datei)) {
        $datei = array();
    }
    $vorgaben = zd_vorgaben();
    foreach ($lage['fehlend'] as $k) {
        $datei[$k] = $vorgaben[$k];
    }
    if (!zd_json_schreiben($p['config'], $datei)) {
        return array(0, 0, 'Die Konfiguration liess sich nicht schreiben.');
    }
    return array(1, count($lage['fehlend']),
                 'Ergaenzt: ' . implode(', ', $lage['fehlend']));
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
    /* Vervollstaendigen, nicht ergaenzen (Hausstandard).
     *
     * Die Oberflaeche uebergibt heute immer eine vollstaendige
     * Konfiguration - sie baut sie aus zd_config(), und das legt die
     * Vorgaben ohnehin darueber. Diese Schleife ist deshalb KEIN Ersatz
     * fuer die Vervollstaendigung beim Dienststart, sondern die Zusage,
     * dass auch ein kuenftiger Aufrufer mit einem Teilstueck keine
     * lueckenhafte Datei hinterlassen kann.
     *
     * Wer sie fuer die eigentliche Loesung haelt, sucht den Fehler spaeter
     * an der falschen Stelle: eine Anlage, die von einer aelteren Fassung
     * heraufkommt und in der Oberflaeche NICHTS speichert, behaelt ihre
     * lueckenhafte Datei - dagegen hilft nur der Dienststart.
     *
     * array_key_exists, nicht isset: isset haelt einen leeren Wert fuer
     * nicht vorhanden und schriebe eine bewusst geleerte Angabe jedes Mal
     * zurueck. */
    foreach (zd_vorgaben() as $zd_k => $zd_v) {
        if (!array_key_exists($zd_k, $cfg)) {
            $cfg[$zd_k] = $zd_v;
        }
    }
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
            /* An welcher Eigenschaft laesst sich ablesen, ob ein Befehl
             * angekommen ist? Nur noetig fuer die drei invoke-Saetze - bei
             * properties/write ist die gesetzte Eigenschaft zugleich die
             * gemeldete. Der Satz-Assistent im Reiter Test schlaegt sie vor. */
            'quittungsfeld' => trim((string) (isset($g['quittungsfeld']) ? $g['quittungsfeld'] : '')),
            /* Nutzbare Kapazitaet in Wattstunden. Gebraucht fuer den
             * gewichteten Summen-Ladezustand und fuer die Vollzyklen; ohne
             * sie bleiben beide leer statt falsch zu sein. */
            'kapazitaet_wh' => isset($g['kapazitaet_wh']) ? max(0, (int) $g['kapazitaet_wh']) : 0,
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

/**
 * Ein Wert, der in eine semikolongetrennte Zeile darf.
 *
 * Die Antwort an den Miniserver trennt Felder mit Semikolon und Werte mit
 * Gleichheitszeichen. Ein Geraetename "Keller;OK=1" schiebt damit die Felder
 * und setzt ein zweites OK= VOR das echte - eine Befehlserkennung
 * \iOK=\i\v greift dann frei getippten Text statt der Messung. Gemessen an
 * 0.9.8, Pruefstand p9_name.php:
 *
 *     1;Keller;OK=1;SOC=99;http;zensdk;Packs=1;OK=1
 *
 * Die Oberflaeche weist solche Namen inzwischen ab. Das genuegt nicht: in
 * einer bestehenden Konfiguration kann so ein Name schon stehen, und aus
 * einer zurueckgespielten Sicherung kommt er wieder. Deshalb zusaetzlich
 * hier, an der Ausgabe.
 */
function zd_zeilenwert($v)
{
    $s = str_replace(array("\r\n", "\r", "\n", "\t", ';', '='), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $s));
}

/**
 * Ein Bestandteil eines MQTT-Themas.
 *
 * zd_mqtt_wert_saeubern() saeubert den WERT. Der Themenanteil war davon
 * ausgenommen, obwohl er dieselbe Schwaeche hat und zusaetzlich aus einer
 * Quelle stammt, die das Plugin nicht in der Hand hat: die Pack-Seriennummer
 * kommt aus packData[].sn, also vom Geraet. Gemessen an 0.9.8 mit einer
 * Kennung, die ein Leerzeichen und einen Umbruch enthaelt:
 *
 *     publish zendure/geraet1/pack/SN 1
 *     X/soc 55
 *
 * Das Gateway liest zeilenweise und trennt Thema und Wert am Leerzeichen -
 * aus einer Meldung werden zwei erfundene Themen.
 *
 * Ersetzt wird, nicht abgewiesen: eine Seriennummer ist keine Eingabe, die
 * man dem Anwender zurueckgeben koennte, und ein Akkupack ohne Thema waere
 * schlechter als einer mit einem bereinigten.
 */
function zd_mqtt_thema_teil($v)
{
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $v);
    $s = trim((string) $s, '_');
    return $s === '' ? 'unbenannt' : substr($s, 0, 64);
}

/**
 * Das Merkmal gegen fremde Formulare.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines angemeldeten Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht. Die Anmeldung schickt er dabei automatisch mit,
 * SameSite greift nicht. Gemessen an 0.9.8: ein POST mit nichts als
 * token_neu=1 wuerfelte das Aktionstoken neu, und danach beantwortet der
 * Endpunkt jeden virtuellen Eingang des Miniservers mit HTTP 403.
 *
 * Abgeleitet, nicht gespeichert: es gibt damit keinen zweiten Wert, der
 * verlorengehen oder auseinanderlaufen kann, und das Merkmal wechselt von
 * selbst mit, wenn das Aktionstoken neu gewuerfelt wird.
 *
 * Fail closed: ohne Aktionstoken gibt es kein Merkmal. Ein aus dem
 * Leerstring abgeleiteter Wert waere fuer jeden ausrechenbar und damit kein
 * Schutz, sondern die Behauptung eines Schutzes.
 */
function zd_formtoken()
{
    $cfg = zd_config();
    $t = trim((string) $cfg['aktionstoken']);
    if ($t === '') {
        return '';
    }
    return hash_hmac('sha256', 'formular-v1', $t);
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

/**
 * Der Herzschlag des Dienstes: 0 bis 999, oder -1 wenn es keinen gibt.
 *
 * Ein Zaehler, der stehen bleibt, sagt: der Dienst laeuft nicht mehr. Das
 * beantwortet ALTER auch - aber nur, solange die Uhr stimmt, und ein
 * Raspberry Pi hat keine Echtzeituhr.
 */
function zd_herzstand()
{
    $l = zd_loxone();
    return isset($l['zaehler']) ? (int) $l['zaehler'] : -1;
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function zd_alter()
{
    $l = zd_loxone();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* Aus bin/zendure_dienst.php hierher gezogen: seit 0.9.10 braucht auch die
 * Oberflaeche die Umrechnung, um im Reiter Test alle drei Moeglichkeiten
 * nebeneinander zeigen zu koennen. Zwei Kopien waeren zwei Wahrheiten. */
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
    /* Kein Rueckfall mehr: die Vorgabe steht seit 0.9.13 in zd_vorgaben(),
     * und zd_config() legt sie ueber jede gelesene Datei. */
    $art = (string) $cfg['temp_umrechnung'];
    if ($art === 'kelvin10') {
        return round(((float) $roh - 2731) / 10, 1);
    }
    if ($art === 'zehntel') {
        return round((float) $roh / 10, 1);
    }
    return $roh;
}

/**
 * Welche Temperatur-Umrechnung passt zu diesem Rohwert?
 *
 * In welcher Einheit ein Zendure-Geraet die Temperatur meldet, ist NICHT
 * belegt - die offizielle Integration reicht maxTemp unveraendert durch.
 * Deshalb steht die Umrechnung ab Werk auf 'roh'. Bis 0.9.9 musste der
 * Anwender daraus selbst einen Schluss ziehen; der Hilfetext erklaerte ihm,
 * was er zu tun habe, und die Oberflaeche half nicht dabei.
 *
 * Diese Funktion rechnet alle drei Moeglichkeiten durch und beurteilt jede
 * an einer einzigen Frage: kann das die Temperatur eines Akkupacks sein?
 * Der Bereich ist bewusst weit gefasst (-25 bis 80 Grad Celsius) - er soll
 * Unsinn ausschliessen, nicht eine Entscheidung vortaeuschen.
 *
 * Rueckgabe: Liste aus art, wert, plausibel; dazu 'vorschlag' = die einzige
 * plausible Art, oder '' wenn es keine oder mehrere gibt. Bei mehreren wird
 * NICHT gewaehlt: zwei plausible Werte sind keine Messung, sondern eine
 * Vorauswahl fuer den Menschen.
 *
 * EINE FOLGE, DIE MAN KENNEN MUSS: 'roh' kann nie allein uebrigbleiben. Ist
 * ein Rohwert als Grad Celsius plausibel (-25 bis 80), dann liegt sein
 * Zehntel (-2,5 bis 8) immer ebenfalls im Bereich. Ein Geraet, das die
 * Temperatur schon in Grad meldet, erzeugt deshalb immer zwei plausible
 * Werte - und dieser Vorschlag schweigt dazu, zu Recht: 25 kann 25 Grad
 * sein oder 2,5. Das entscheidet ein Thermometer, kein Plausibilitaetstest.
 * Aufgefallen beim Eichen (Pruefstand a5_temperatur.php).
 */
function zd_temperatur_vorschlag($roh)
{
    $arten = array('roh', 'kelvin10', 'zehntel');
    $liste = array();
    $plausible = array();
    foreach ($arten as $art) {
        if ($roh === null) {
            $liste[$art] = array('wert' => null, 'plausibel' => false);
            continue;
        }
        $w = zd_temperatur($roh, array('temp_umrechnung' => $art));
        $ok = ($w !== null && $w >= -25 && $w <= 80);
        $liste[$art] = array('wert' => $w, 'plausibel' => $ok);
        if ($ok) {
            $plausible[] = $art;
        }
    }
    return array(
        'roh'       => $roh,
        'arten'     => $liste,
        'vorschlag' => count($plausible) === 1 ? $plausible[0] : '',
        'anzahl'    => count($plausible),
        'plausible' => $plausible,
    );
}

/* ---------------- Feldzuordnung ----------------
 *
 * Welche Eigenschaft des Geraets steckt hinter welchem Feld des Plugins?
 *
 * Bis 0.9.9 stand diese Antwort verstreut in zd_abbilden() als Reihe von
 * zd_erstes()-Aufrufen. Sie stammt aus der offiziellen Home-Assistant-
 * Integration (device.py) und ist an KEINEM Geraet nachgemessen - das Plugin
 * wurde ohne eines gebaut und sagt das auch. Wenn eine Firmware ein Feld
 * anders nennt, bleibt der Wert im Plugin leer, und bis 0.9.9 half dagegen
 * nur, den Quelltext zu aendern.
 *
 * Jetzt steht die Zuordnung an einer Stelle und laesst sich im Reiter
 * Einstellungen ueberschreiben. Der Feld-Erkunder im Reiter Test zeigt
 * daneben, wie die Eigenschaften bei DIESEM Geraet wirklich heissen.
 */

/** Feld des Plugins => Vorgabename der Eigenschaft. */
function zd_feldkarte()
{
    return array(
        'soc'        => 'electricLevel',
        'soc_min'    => 'minSoc',
        'soc_max'    => 'socSet',
        'pv'         => 'solarInputPower',
        'haus'       => 'outputHomePower',
        'netz'       => 'gridInputPower',
        'laden'      => 'outputPackPower',
        'entladen'   => 'packInputPower',
        'grenze_aus' => 'outputLimit',
        'grenze_ein' => 'inputLimit',
        'acmodus'    => 'acMode',
        'smart'      => 'smartMode',
        /* OHNE Vorgabe, und das ist Absicht: unter welchem Namen ein
         * Zendure-Geraet seine Firmware-Fassung meldet, ist nirgends belegt.
         * Einen Namen zu raten hiesse, ein leeres Feld gegen ein falsches zu
         * tauschen. Der Feld-Erkunder im Reiter Test zeigt, wie es bei Ihnen
         * heisst; erst dann traegt FW einen Wert. */
        'firmware'   => '',
    );
}

/** Dasselbe fuer die Werte eines einzelnen Akkupacks (packData[]). */
function zd_packkarte()
{
    return array(
        'soc'  => 'socLevel',
        'volt' => 'totalVol',
        'maxv' => 'maxVol',
        'minv' => 'minVol',
        'temp' => 'maxTemp',
        'watt' => 'power',
    );
}

/**
 * Die geltende Zuordnung: Vorgabe, ueberschrieben durch die Konfiguration.
 *
 * Ein leerer Eintrag bedeutet "Vorgabe" und nicht "kein Feld" - sonst
 * loeschte ein leergelassenes Eingabefeld die Zuordnung.
 */
function zd_zuordnung(?array $cfg = null, $pack = false)
{
    if ($cfg === null) {
        $cfg = zd_config();
    }
    $karte = $pack ? zd_packkarte() : zd_feldkarte();
    $schluessel = $pack ? 'packzuordnung' : 'zuordnung';
    $eigen = isset($cfg[$schluessel]) && is_array($cfg[$schluessel]) ? $cfg[$schluessel] : array();
    foreach ($karte as $feld => $vorgabe) {
        if (isset($eigen[$feld]) && trim((string) $eigen[$feld]) !== '') {
            $karte[$feld] = trim((string) $eigen[$feld]);
        }
    }
    return $karte;
}

/**
 * Summe ueber alle Geraete.
 *
 * Der Ladezustand wird NACH KAPAZITAET GEWICHTET. Ein ungewichteter
 * Mittelwert waere bei einem SolarFlow 800 neben einem AIO 2400 schlicht
 * falsch: 80 % von 2 kWh und 20 % von 8 kWh ergeben nicht 50 %, sondern 32 %.
 *
 * FAIL CLOSED: fehlt bei einem Geraet die Kapazitaet oder antwortet eines
 * nicht, kommt fuer SOC und RESTKWH ein Strich statt einer Teilsumme. Eine
 * Teilsumme, die wie eine Gesamtsumme aussieht, ist die gefaehrlichere
 * Auskunft - nach ihr wuerde geregelt.
 *
 * Die Leistungen dagegen werden aus dem addiert, was da ist, und NOK sagt,
 * wie viele fehlen. Fuer eine Momentanleistung ist das brauchbar; fuer einen
 * Ladezustand nicht.
 */
function zd_summe(array $werte, array $geraete)
{
    $n = count($werte);
    $nok = 0;
    $kap_ges = 0.0;
    $kwh_ges = 0.0;
    $vollstaendig = ($n > 0);
    $leistung = array('pv' => null, 'haus' => null, 'netz' => null, 'batp' => null);
    $alter = -1;

    foreach ($werte as $nr => $w) {
        if (empty($w['ok'])) {
            $nok++;
            $vollstaendig = false;
        }
        $g = isset($geraete[(int) $nr]) ? $geraete[(int) $nr] : array();
        $kap = isset($g['kapazitaet_wh']) ? (float) $g['kapazitaet_wh'] : 0.0;
        if ($kap <= 0 || !isset($w['soc']) || $w['soc'] === null) {
            $vollstaendig = false;
        } else {
            $kap_ges += $kap;
            $kwh_ges += $kap * (float) $w['soc'] / 100.0;
        }
        foreach (array_keys($leistung) as $f) {
            if (isset($w[$f]) && $w[$f] !== null) {
                $leistung[$f] = ($leistung[$f] === null ? 0 : $leistung[$f]) + (int) $w[$f];
            }
        }
        if (isset($w['alter']) && $w['alter'] >= 0) {
            $alter = max($alter, (int) $w['alter']);
        }
    }

    return array(
        'ok'      => ($nok === 0 && $n > 0) ? 1 : 0,
        'n'       => $n,
        'nok'     => $nok,
        'soc'     => ($vollstaendig && $kap_ges > 0) ? round(100.0 * $kwh_ges / $kap_ges, 1) : null,
        'kapaz'   => $vollstaendig ? round($kap_ges / 1000, 2) : null,
        'restkwh' => $vollstaendig ? round($kwh_ges / 1000, 2) : null,
        'pv'      => $leistung['pv'],
        'haus'    => $leistung['haus'],
        'netz'    => $leistung['netz'],
        'batp'    => $leistung['batp'],
        'alter'   => $alter,
    );
}

/* ---------------- Rueckfall und Schutzschwellen ----------------
 *
 * Zendure kennt keinen Watchdog: ein gesetzter Sollwert bleibt stehen, auch
 * wenn Loxone nie wieder etwas sagt. Bis 0.9.10 schob das README die Aufgabe
 * an Loxone - das deckt einen geplanten Neustart ab, aber keinen Ausfall des
 * Miniservers, kein durchtrenntes Netzwerkkabel und keinen vergessenen
 * Baustein.
 */

/**
 * Wie lange noch, bis der Rueckfall greift?
 *
 * Rueckgabe: Sekunden, oder null wenn der Rueckfall aus ist oder es keinen
 * aktiven Sollwert gibt. NULL heisst hier "keine Aussage", nicht "sofort".
 */
function zd_rueckfall_rest(array $soll, array $cfg)
{
    $min = max(0, min(1440, (int) (isset($cfg['rueckfall_min']) ? $cfg['rueckfall_min'] : 0)));
    if ($min === 0 || !$soll || !isset($soll['ts'])) {
        return null;
    }
    // Nur ein STELLBEFEHL laeuft ab. Eine Ladezustandsgrenze ist eine
    // Einstellung und soll stehen bleiben.
    if (!zd_ist_stellbefehl(isset($soll['aktion']) ? $soll['aktion'] : '')) {
        return null;
    }
    return max(0, $min * 60 - (time() - (int) $soll['ts']));
}

/**
 * Ist das ein STELLBEFEHL - also eine Leistungsvorgabe?
 *
 * Drei Stellen brauchen dieselbe Unterscheidung, und sie muessen dieselbe
 * Antwort geben: der Rueckfall laesst nur Stellbefehle ablaufen, die
 * Schutzschwellen pruefen nur sie, und nur sie verfallen in der
 * Warteschlange. Eine Ladezustands- oder Leistungsgrenze ist eine
 * EINSTELLUNG: sie soll stehen bleiben, sie altert nicht, und sie ist auch
 * nach zwei Stunden noch richtig. "aus" ebenso - es zurueckzuhalten waere
 * das Gegenteil dessen, was der Verfall bezweckt.
 */
function zd_ist_stellbefehl($aktion)
{
    return in_array((string) $aktion, array('laden', 'entladen'), true);
}

/**
 * Schutzschwellen: darf dieser Befehl abgesetzt werden?
 *
 * Rueckgabe: array(erlaubt, Grund).
 *
 * WAS DIESE PRUEFUNG BEWUSST NICHT TUT: sie sperrt nicht, wenn ihr die
 * Messung fehlt. Ein Schutz, der bei jedem Aussetzer den Speicher stilllegt,
 * richtet mehr Schaden an als der Fall, gegen den er schuetzen soll - und
 * das Geraet hat sein eigenes Batteriemanagement, diese Schwellen sind
 * Komfort, nicht Sicherheit. Ein fehlender oder veralteter Wert wird
 * gemeldet, nicht zur Sperre gemacht. Der Reiter Test sagt das ausdruecklich.
 */
function zd_schutz_pruefen($aktion, array $w, array $cfg)
{
    if (empty($cfg['schutz_ein'])) {
        return array(1, '');
    }
    if (!zd_ist_stellbefehl($aktion)) {
        return array(1, '');
    }
    // Nur frische Werte urteilen mit. 15 Minuten sind grosszuegig; laenger
    // ist keine Aussage mehr ueber den JETZIGEN Zustand.
    $frisch = isset($w['alter']) && $w['alter'] >= 0 && $w['alter'] <= 900 && !empty($w['ok']);
    if (!$frisch) {
        return array(1, 'ohne frische Messung');
    }
    $soc = isset($w['soc']) ? $w['soc'] : null;
    $temp = isset($w['temp']) ? $w['temp'] : null;
    if ($soc !== null) {
        $min = (int) $cfg['schutz_soc_min'];
        $max = (int) $cfg['schutz_soc_max'];
        if ($aktion === 'entladen' && $soc < $min) {
            return array(0, 'Schutzschwelle: der Ladezustand liegt bei ' . $soc
                          . ' % und damit unter der eingestellten Untergrenze von '
                          . $min . ' %. Es wird nicht weiter entladen.');
        }
        if ($aktion === 'laden' && $soc > $max) {
            return array(0, 'Schutzschwelle: der Ladezustand liegt bei ' . $soc
                          . ' % und damit ueber der eingestellten Obergrenze von '
                          . $max . ' %. Es wird nicht weiter geladen.');
        }
    }
    if ($temp !== null) {
        $tmin = (float) $cfg['schutz_temp_min'];
        $tmax = (float) $cfg['schutz_temp_max'];
        if ($temp < $tmin || $temp > $tmax) {
            return array(0, 'Schutzschwelle: die Temperatur liegt bei ' . $temp
                          . ' und damit ausserhalb des eingestellten Bereichs von '
                          . $tmin . ' bis ' . $tmax . '. Steht die Temperatur-Umrechnung '
                          . 'richtig? Der Reiter Test zeigt sie.');
        }
    }
    return array(1, '');
}

/**
 * Wiederholungssperre: muss dieser Wert ueberhaupt gesendet werden?
 *
 * Rueckgabe: array(senden, Grund).
 *
 * Bis 0.9.10 ging jeder Sollwert hinaus, auch der unveraenderte. Beim
 * empfohlenen Sendetakt von 60 s sind das 1440 Flash-Schreibvorgaenge am Tag
 * fuer einen Wert, der sich nicht geaendert hat - bei einem Plugin, dessen
 * Schreibbremse ausdruecklich mit der Schreibfestigkeit des Flash begruendet
 * ist. Die Bremse begrenzt den ABSTAND, nicht die Wiederholung.
 *
 * Uebergangen wird nur, was sicher unnoetig ist:
 *   - dieselbe Aktion, derselbe Wert (im Totband)
 *   - die Vorgabe ist nicht zu alt (sonst hat das Geraet sie vielleicht
 *     vergessen: Stromausfall, Firmware-Neustart)
 *   - und die Quittung sagt NICHT, dass das Geraet sie abgelehnt hat
 *
 * Und es wird gemeldet, nicht verschwiegen.
 */
function zd_wiederholung_pruefen($nr, $aktion, $wert, array $w, array $cfg)
{
    $soll = zd_soll_lesen($nr);
    if (!$soll || !isset($soll['aktion']) || $soll['aktion'] !== $aktion) {
        return array(1, '');
    }
    if (!isset($soll['wert']) || $soll['wert'] === null || $wert === null) {
        return array(1, '');
    }
    $band = max(0, min(5000, (int) (isset($cfg['totband_w']) ? $cfg['totband_w'] : 0)));
    if (abs((int) $soll['wert'] - (int) $wert) > $band) {
        return array(1, '');
    }
    $auffr = max(0, min(86400, (int) (isset($cfg['totband_auffrischung'])
                                      ? $cfg['totband_auffrischung'] : 600)));
    $alter = time() - (int) $soll['ts'];
    if ($auffr > 0 && $alter >= $auffr) {
        return array(1, '');
    }
    // Hat das Geraet den Wert nachweislich NICHT uebernommen, wird erneut
    // gesendet - eine Wiederholungssperre darf keinen Fehlschlag festhalten.
    if (isset($w['sollok']) && $w['sollok'] === 0) {
        return array(1, '');
    }
    return array(0, 'Unveraendert: ' . $wert . ' steht seit ' . $alter . ' s so am Geraet'
                  . ($band > 0 ? ' (Totband ' . $band . ' W)' : '')
                  . '. Es wird nicht erneut geschrieben - das schont den Flash-Speicher. '
                  . 'Spaetestens nach ' . $auffr . ' s wird der Wert aufgefrischt.');
}

/* ---------------- Energiezaehler ----------------
 *
 * Ein Zendure-Geraet meldet Augenblicksleistungen, keine Zaehlerstaende.
 * Loxone will fuer den Energiefluss-Monitor aber Kilowattstunden, und Ihr
 * Miniserver fuehrt bei einem Speicher ueblicherweise "geladen heute",
 * "entladen heute", Monatswerte, Zyklen und Wirkungsgrad.
 *
 * Der Dienst integriert die Leistung deshalb selbst. Das ist
 * protokollunabhaengig - es funktioniert auch dann, wenn eine Firmware gar
 * keine Zaehler liefert, und es haengt nicht an Eigenschaftsnamen, die
 * ohnehin nicht belegt sind.
 *
 * WAS DAS NICHT IST: eine geeichte Messung. Integriert wird ueber den
 * Abfragetakt; was zwischen zwei Abrufen geschieht, sieht niemand. Bei
 * 15 Sekunden Takt ist der Fehler klein, bei 900 Sekunden ist er es nicht.
 * Der Reiter Test nennt den Takt deshalb neben den Zaehlerstaenden.
 *
 * Die Zaehler liegen im BESTANDSORDNER, also neben data/plugins/<ordner> -
 * ein Zaehlerstand, den ein Plugin-Update zurueckdreht, ist schlimmer als
 * keiner.
 */

/** Die Felder, ueber die integriert wird: Feld => Vorzeichen der Leistung. */
function zd_energiefelder()
{
    return array(
        'pv'       => 'ZD_ENERGIE.PV',
        'haus'     => 'ZD_ENERGIE.HAUS',
        'netz'     => 'ZD_ENERGIE.NETZ',
        'laden'    => 'ZD_ENERGIE.LADEN',
        'entladen' => 'ZD_ENERGIE.ENTLADEN',
    );
}

function zd_energie_datei()
{
    return zd_paths()['bestand'] . '/energie.json';
}

function zd_energie_alle()
{
    return zd_json_lesen(zd_energie_datei());
}

/** Der Zaehlerstand eines Geraets, oder ein leerer Satz. */
function zd_energie_stand($nr)
{
    $a = zd_energie_alle();
    $k = (string) (int) $nr;
    if (isset($a[$k]) && is_array($a[$k])) {
        return $a[$k];
    }
    return array('stand' => array(), 'tagesstart' => array(), 'tag' => '', 'ts' => 0);
}

/** Datei der Tagesabschluesse eines Monats. */
function zd_energie_monatsdatei($nr, $monat)
{
    return zd_paths()['bestand'] . '/energie/geraet' . (int) $nr . '_' . $monat . '.csv';
}

/**
 * Die Tagesabschluesse eines Monats lesen.
 * Rueckgabe: Tag (Ymd) => array(feld => Wh)
 */
function zd_energie_tage($nr, $monat)
{
    $f = zd_energie_monatsdatei($nr, $monat);
    $out = array();
    if (!is_file($f)) {
        return $out;
    }
    $felder = array_keys(zd_energiefelder());
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
        $c = explode(';', $zeile);
        if (count($c) < 1 + count($felder)) {
            continue;
        }
        $tag = $c[0];
        $satz = array();
        foreach ($felder as $i => $feld) {
            $satz[$feld] = (float) $c[$i + 1];
        }
        $out[$tag] = $satz;
    }
    return $out;
}

/**
 * Summe eines Zeitraums in Wattstunden.
 *
 * $zeitraum: 'tag', 'monat' oder 'jahr'. Der laufende Tag wird aus dem
 * Zaehlerstand gebildet (Stand minus Tagesstart), abgeschlossene Tage aus
 * den Monatsdateien - so ist der laufende Tag immer aktuell und ein
 * abgeschlossener nie mehr veraenderlich.
 */
function zd_energie_summe($nr, $zeitraum = 'tag')
{
    $felder = array_keys(zd_energiefelder());
    $summe = array_fill_keys($felder, 0.0);
    $st = zd_energie_stand($nr);
    $heute = date('Ymd');

    // Der laufende Tag
    $lauf = array_fill_keys($felder, 0.0);
    if (isset($st['tag']) && $st['tag'] === $heute) {
        foreach ($felder as $f) {
            $a = isset($st['stand'][$f]) ? (float) $st['stand'][$f] : 0.0;
            $b = isset($st['tagesstart'][$f]) ? (float) $st['tagesstart'][$f] : 0.0;
            $lauf[$f] = max(0.0, $a - $b);
        }
    }
    if ($zeitraum === 'tag') {
        return $lauf;
    }

    $monate = $zeitraum === 'jahr'
        ? array_map(function ($m) { return date('Y') . sprintf('%02d', $m); }, range(1, 12))
        : array(date('Ym'));
    foreach ($monate as $m) {
        foreach (zd_energie_tage($nr, $m) as $tag => $satz) {
            if ($tag === $heute) {
                continue;   // der laufende Tag kommt aus dem Zaehlerstand
            }
            foreach ($felder as $f) {
                $summe[$f] += isset($satz[$f]) ? (float) $satz[$f] : 0.0;
            }
        }
    }
    foreach ($felder as $f) {
        $summe[$f] += $lauf[$f];
    }
    return $summe;
}

/**
 * Kennzahlen, die sich aus den Zaehlern ergeben.
 *
 * wirkungsgrad  entladen / geladen ueber die gesamte Laufzeit
 * zyklen        kumuliert geladene Energie geteilt durch die Kapazitaet
 *
 * Beide brauchen einen Mindestbestand, sonst sind sie Zufall: ein Speicher,
 * der eben erst angeschlossen wurde, hat keinen Wirkungsgrad. Unterhalb
 * davon kommt null - lieber kein Wert als eine Zahl, die richtig aussieht.
 */
function zd_energie_kennzahlen($nr, array $g)
{
    $st = zd_energie_stand($nr);
    $geladen = isset($st['stand']['laden']) ? (float) $st['stand']['laden'] : 0.0;
    $entladen = isset($st['stand']['entladen']) ? (float) $st['stand']['entladen'] : 0.0;
    $kap = isset($g['kapazitaet_wh']) ? (float) $g['kapazitaet_wh'] : 0.0;
    return array(
        'geladen_wh'   => $geladen,
        'entladen_wh'  => $entladen,
        // Erst ab einer vollen Kapazitaet Ladung ist der Wirkungsgrad mehr
        // als eine Momentaufnahme; ohne Kapazitaetsangabe ab 1 kWh.
        'wirkungsgrad' => ($geladen >= max(1000.0, $kap) && $entladen > 0)
                          ? round(100.0 * $entladen / $geladen, 1) : null,
        'zyklen'       => $kap > 0 ? round($geladen / $kap, 2) : null,
    );
}

/* ---------------- Soll/Ist-Quittung ----------------
 *
 * Die wichtigste Betriebsfrage dieses Plugins lautet nicht "ist der Befehl
 * abgeschickt worden", sondern "hat das Geraet ihn uebernommen". Das README
 * sagt zu den vier Befehlssaetzen selbst: "Ein falscher Satz fuehrt nicht zu
 * einer Fehlermeldung - es passiert schlicht nichts."
 *
 * Deshalb merkt sich der Dienst nach jedem geglueckten Schreibvorgang, WAS er
 * vorgegeben hat und woran sich das ablesen liesse. Beim naechsten Abruf wird
 * verglichen.
 *
 * Drei Antworten, nicht zwei:
 *     1   das Geraet meldet den vorgegebenen Wert zurueck
 *     0   es meldet etwas anderes, und die Nachlaufzeit ist um
 *     -   noch nicht beurteilbar, oder fuer diesen Befehlssatz nicht belegt
 *
 * Der Strich ist kein Schoenheitsfehler, sondern der ehrliche Regelfall bei
 * den drei invoke-Saetzen: dort ist NIRGENDS belegt, in welcher Eigenschaft
 * sich ein Befehl niederschlaegt. Wer es an seinem Geraet gesehen hat, traegt
 * das Feld als Quittungsfeld ein - dann wird auch dort verglichen.
 */

function zd_soll_datei()
{
    return zd_paths()['datadir'] . '/soll.json';
}

function zd_soll_alle()
{
    return zd_json_lesen(zd_soll_datei());
}

function zd_soll_lesen($nr)
{
    $a = zd_soll_alle();
    $k = (string) (int) $nr;
    return isset($a[$k]) && is_array($a[$k]) ? $a[$k] : array();
}

/**
 * Was wurde vorgegeben, und woran liesse es sich ablesen?
 *
 * $bau ist ein gebauter Befehl; sein Feld 'erwartet' ist bei properties/write
 * gefuellt und bei function/invoke leer. Ist es leer und hat das Geraet ein
 * Quittungsfeld eingetragen, tritt dieses an seine Stelle.
 */
function zd_soll_merken($nr, $aktion, $wert, array $bau, array $g)
{
    $felder = isset($bau['erwartet']) && is_array($bau['erwartet']) ? $bau['erwartet'] : array();
    $qfeld = trim((string) (isset($g['quittungsfeld']) ? $g['quittungsfeld'] : ''));
    if (!$felder && $qfeld !== '' && $wert !== null) {
        $felder = array($qfeld => $wert);
    }
    $alle = zd_soll_alle();
    $alle[(string) (int) $nr] = array(
        'aktion'   => (string) $aktion,
        'wert'     => $wert === null ? null : (int) $wert,
        'ts'       => time(),
        'erwartet' => $felder,
        'satz'     => (string) (isset($g['satz']) ? $g['satz'] : ''),
    );
    zd_json_schreiben(zd_soll_datei(), $alle);
}

/**
 * Die Quittung bilden. Rueckgabe: 1, 0 oder null (= noch keine Aussage).
 *
 * $eigenschaften sind die zuletzt gelesenen Rohwerte des Geraets, $mess_ts
 * ihr Zeitstempel.
 */
function zd_soll_quittung(array $soll, array $eigenschaften, $mess_ts, array $cfg)
{
    if (!$soll || empty($soll['erwartet'])) {
        return null;                     // nichts vorgegeben, oder nicht belegt
    }
    if ((int) $mess_ts <= (int) $soll['ts']) {
        return null;                     // seit dem Befehl noch nichts gemessen
    }
    $passt = true;
    foreach ($soll['erwartet'] as $feld => $sollwert) {
        if (!array_key_exists($feld, $eigenschaften)) {
            /* Das Geraet meldet dieses Feld gar nicht. Das ist KEIN Beleg
             * dafuer, dass der Befehl nicht gewirkt hat - es ist ein Beleg
             * dafuer, dass wir es nicht wissen. */
            return null;
        }
        if (!is_numeric($eigenschaften[$feld])
            || (float) $eigenschaften[$feld] !== (float) $sollwert) {
            $passt = false;
        }
    }
    if ($passt) {
        return 1;
    }
    /* Ein Geraet braucht einen Augenblick, bis es den neuen Wert zurueckmeldet.
     * Vor Ablauf der Nachlaufzeit ist eine Abweichung noch keine Aussage. */
    $nachlauf = max(0, min(900, (int) (isset($cfg['quittung_nachlauf']) ? $cfg['quittung_nachlauf'] : 60)));
    return (time() - (int) $soll['ts']) >= $nachlauf ? 0 : null;
}

/**
 * Der Stand des Satz-Assistenten, wie die Oberflaeche ihn zeigt.
 *
 * Der Assistent selbst laeuft im Dienst (zd_satztest_schritt); hier wird nur
 * gelesen. Ein Lauf, der laenger als eine Stunde her ist, wird nicht mehr
 * angezeigt - sonst stuende das Ergebnis von vorgestern da wie ein frisches.
 */
function zd_satztest_stand()
{
    $p = zd_json_lesen(zd_paths()['datadir'] . '/satztest.json');
    if (!$p || !isset($p['phase'])) {
        return array();
    }
    $ende = isset($p['fertig_ts']) ? (int) $p['fertig_ts']
          : (isset($p['gestartet']) ? (int) $p['gestartet'] : 0);
    if ($p['phase'] === 'fertig' && time() - $ende > 3600) {
        return array();
    }
    return $p;
}

/* ---------------- Protokollierung ---------------- */

function zd_log($text)
{
    $p = zd_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    clearstatcache(true, $p['log']);
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
    return is_file(zd_paths()['soll']) ? 1 : 0;
}

/**
 * Was ein Update ueberstehen soll, einmalig in den Bestandsordner ziehen.
 *
 * Bis 0.9.8 lagen Verlauf und Sollmerker unter data/plugins/<ordner>. Wer von
 * dort aktualisiert, hat sie beim ersten Start von 0.9.9 noch am alten Platz
 * - dieser Zug holt sie hinueber, damit die Kurve nicht bei null anfaengt.
 *
 * Einmalig: umgezogen wird nur, was drueben noch nicht steht. Ein Lauf, der
 * bei jedem Start kopiert, wuerde eine spaeter geloeschte Datei wieder
 * auferstehen lassen.
 */
function zd_bestand_sichern()
{
    $p = zd_paths();
    if (!is_dir($p['bestand']) && !@mkdir($p['bestand'], 0775, true) && !is_dir($p['bestand'])) {
        return false;
    }
    if (!is_dir($p['verlaufdir'])) {
        @mkdir($p['verlaufdir'], 0775, true);
    }
    $alt = $p['datadir'] . '/verlauf';
    if (is_dir($alt)) {
        foreach (glob($alt . '/geraet*_*.csv') ?: array() as $datei) {
            $ziel = $p['verlaufdir'] . '/' . basename($datei);
            if (!is_file($ziel) && @copy($datei, $ziel)) {
                @unlink($datei);
            }
        }
        // Nur entfernen, wenn wirklich nichts mehr darin liegt.
        @rmdir($alt);
    }
    $altsoll = $p['datadir'] . '/soll_laufen';
    if (is_file($altsoll) && !is_file($p['soll'])) {
        @touch($p['soll']);
        @unlink($altsoll);
    }
    return true;
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
    /* Wann er eingereiht wurde - der Dienst verwirft daran alte
     * Stellbefehle. Ohne diese Zeile bliebe nur filemtime(), und das
     * ueberlebt ein Kopieren oder Zuruecksichern des Datenordners nicht. */
    if (!isset($befehl['ts'])) {
        $befehl['ts'] = time();
    }
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

/* ---------------- Konfiguration sichern und zurueckspielen ----------------
 *
 * Die Datei traegt das AKTIONSTOKEN. Das ist Absicht und muss gesagt werden:
 * ohne das Token waere sie nach dem Zurueckspielen wertlos - jede Adresse im
 * Miniserver zeigte ins Leere und muesste von Hand nachgezogen werden. Mit
 * ihm ist sie ein Geheimnis und gehoert entsprechend behandelt.
 */

/** Die Konfiguration als lesbares JSON, mit Kopfzeilen zur Herkunft. */
function zd_konfig_ausfuhr()
{
    $cfg = zd_config();
    $daten = array(
        '_plugin'  => 'Zendure SolarFlow',
        '_fassung' => zd_fassung(),
        '_erzeugt' => date('c'),
        '_hinweis' => 'Diese Datei enthaelt das Aktionstoken und, falls eingetragen, '
                    . 'das Broker-Passwort. Bitte wie ein Passwort behandeln.',
        'konfiguration' => $cfg,
    );
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js === false ? '' : $js;
}

/**
 * Eine Sicherung zurueckspielen.
 *
 * Rueckgabe: array(ok, Meldung). Geprueft wird, BEVOR etwas geschrieben wird -
 * eine halb zurueckgespielte Konfiguration waere schlimmer als gar keine.
 */
function zd_konfig_einfuhr($inhalt)
{
    $d = json_decode((string) $inhalt, true);
    if (!is_array($d)) {
        return array(0, zd_t('EINST.KONFIG_KEIN_JSON'));
    }
    $neu = isset($d['konfiguration']) && is_array($d['konfiguration']) ? $d['konfiguration'] : $d;
    // Ein Merkmal, an dem sich eine Zendure-Sicherung erkennen laesst. Ohne
    // das liesse sich jede beliebige JSON-Datei einspielen.
    if (!array_key_exists('geraete', $neu) || !array_key_exists('aktionstoken', $neu)) {
        return array(0, zd_t('EINST.KONFIG_FREMD'));
    }
    if (!is_array($neu['geraete'])) {
        return array(0, zd_t('EINST.KONFIG_FREMD'));
    }
    // Vervollstaendigen, nicht ergaenzen: was die Sicherung nicht kennt,
    // wird mit der Vorgabe HINEINGESCHRIEBEN. Sonst bliebe die Datei
    // lueckenhaft, und "fehlt" waere von "steht auf der Vorgabe" nicht mehr
    // zu unterscheiden.
    $voll = array_merge(zd_vorgaben(), $neu);
    if (!zd_config_speichern($voll)) {
        return array(0, sprintf(zd_t('EINST.FEHLER_SPEICHERN'), zd_paths()['config']));
    }
    return array(1, sprintf(zd_t('EINST.KONFIG_ZURUECK'), count($voll['geraete'])));
}

/**
 * Die eigene Fassungsnummer.
 *
 * Massgeblich ist die plugindatabase.json des LoxBerry - das ist der Stand,
 * der WIRKLICH installiert ist. Gesucht wird ueber den ORDNERNAMEN, nie ueber
 * den MD5-Schluessel: der wird aus Autorenname, E-Mail und Plugin-Name
 * gebildet und aendert sich bei jedem Fork.
 *
 * Die plugin.cfg ist der Rueckfall - im ausgepackten Archiv, also vor der
 * Installation, gibt es nur sie. Gelesen wird sie zeilenweise:
 * parse_ini_file() scheitert an ihr, weil sie Werte enthaelt, die der
 * INI-Leser als Zahl oder Schluesselwort deutet.
 *
 * Leerstring heisst "nicht feststellbar" - eine geratene Fassungsnummer in
 * einer Sicherungsdatei waere schlimmer als gar keine.
 */
/**
 * Welche Fassung hat das MQTT-Gateway?
 *
 * Der Anlass ist ein Satz, den die Oberflaeche zweimal unbedingt behauptet
 * hat: "Ohne diesen Eintrag kommt am Miniserver nichts an." Er stimmt fuer
 * das Gateway V1, wo jedes Thema von Hand auf der Abo-Seite eingetragen
 * werden muss. Unter V2 erkennt das Gateway die Themengruppe selbst; dort
 * werden nur noch die gewuenschten Datenpunkte angehakt. Der Satz schickte
 * jeden V2-Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * GELESEN WIRD Mqtt.Gatewayversion aus config/system/general.json - ab Werk
 * 1. Das ist der Wert, den der LoxBerry selbst fuehrt.
 *
 * Die erste Fassung dieser Funktion leitete die Nummer aus der VERSION des
 * Plugins "mqttgateway" in der Plugin-Datenbank ab. Das ist eine Ableitung,
 * wo eine Messung danebenliegt: sie waere schon dann falsch, wenn jemand
 * eine V2-faehige Fassung installiert und in general.json weiter V1 fuehrt.
 * MGiSmart 1.1.0 liest an derselben Stelle richtig - mg_mqtt_gateway_info().
 *
 * Rueckgabe:
 *   gefunden  0 = general.json nicht lesbar oder ohne den Schluessel.
 *             Dann wird NICHTS behauptet.
 *   fassung   die Nummer als Zahl (1, 2, ...), 0 = unbekannt
 */
function zd_gateway_fassung()
{
    $m = zd_mqtt_zustand();
    $f = (int) $m['gatewayfassung'];
    return array('gefunden' => $f > 0 ? 1 : 0, 'fassung' => $f);
}

function zd_fassung()
{
    $p = zd_paths();
    if ($p['home'] !== '') {
        $db = zd_json_lesen($p['home'] . '/data/system/plugindatabase.json');
        $liste = isset($db['plugins']) && is_array($db['plugins']) ? $db['plugins'] : $db;
        if (is_array($liste)) {
            foreach ($liste as $eintrag) {
                if (is_array($eintrag) && isset($eintrag['folder'])
                    && $eintrag['folder'] === $p['plugin'] && isset($eintrag['version'])) {
                    return (string) $eintrag['version'];
                }
            }
        }
    }
    foreach (array(
        dirname(dirname(__DIR__)) . '/plugin.cfg',
        dirname(dirname(dirname(__DIR__))) . '/plugin.cfg',
    ) as $f) {
        if (!is_file($f)) {
            continue;
        }
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: array() as $z) {
            if (preg_match('/^\s*VERSION\s*=\s*([0-9][0-9.]*)/', $z, $m)) {
                return $m[1];
            }
        }
    }
    return '';
}

/* ---------------- Befund, Healthcheck und Meldung ----------------
 *
 * EINE Funktion, drei Verbraucher: die Oberflaeche, bin/healthcheck und der
 * Meldebereich. Drei Stellen, die dasselbe verschieden sagen, sind zwei zu
 * viel - und genau so entsteht der Fall, in dem die Plugin-Seite Klartext
 * zeigt und das Symbol auf der Startseite unauffaellig bleibt.
 *
 * Die Statuswerte sind die von LoxBerry: 3 Fehler, 4 Warnung, 5 in Ordnung,
 * 6 Hinweis.
 */
function zd_befund()
{
    $cfg = zd_config();
    $geraete = zd_geraete();
    $werte = zd_werte();

    if (!$geraete) {
        return array('status' => 6, 'kurz' => 'keine_geraete',
                     'text' => zd_t('BEFUND.KEINE_GERAETE'));
    }
    if (zd_dienst_pid() === 0) {
        // Ein bewusst angehaltener Dienst ist kein Fehler, ein abgestuerzter
        // schon. Den Unterschied kennt nur der Sollmerker.
        return zd_dienst_soll()
            ? array('status' => 3, 'kurz' => 'dienst_tot', 'text' => zd_t('BEFUND.DIENST_TOT'))
            : array('status' => 6, 'kurz' => 'dienst_aus', 'text' => zd_t('BEFUND.DIENST_AUS'));
    }
    $stumm = array();
    foreach ($werte as $nr => $w) {
        if (empty($w['ok'])) {
            $stumm[] = $w['name'] . ' (' . $nr . ')';
        }
    }
    if ($stumm && count($stumm) === count($werte)) {
        return array('status' => 3, 'kurz' => 'alle_stumm',
                     'text' => sprintf(zd_t('BEFUND.ALLE_STUMM'), implode(', ', $stumm)));
    }
    if ($stumm) {
        return array('status' => 4, 'kurz' => 'teils_stumm',
                     'text' => sprintf(zd_t('BEFUND.TEILS_STUMM'),
                                       implode(', ', $stumm), count($werte)));
    }
    $m = zd_mqtt_zustand();
    if (!empty($cfg['mqtt_ein']) && !$m['autostart']) {
        return array('status' => 4, 'kurz' => 'gateway_aus',
                     'text' => zd_t('BEFUND.GATEWAY_AUS'));
    }
    return array('status' => 5, 'kurz' => 'ok',
                 'text' => sprintf(zd_t('BEFUND.OK'), count($werte), zd_alter()));
}

/**
 * Melden - aber nur, wenn sich der Befund GEAENDERT hat.
 *
 * Eine Meldung je Minute ist keine Meldung, sondern Rauschen; und wer sie
 * abstellt, stellt auch die echte ab. Gemerkt wird deshalb das Kuerzel des
 * letzten Befundes, nicht sein Text - der kann Zahlen enthalten, die sich in
 * jedem Durchgang aendern, und dann meldete es doch wieder jedes Mal.
 *
 * notify_ext() steckt in einer Bibliothek, die nicht jede LoxBerry-Fassung
 * gleich bestueckt. Die Wache auf function_exists gehoert dazu; ein @ hilft
 * gegen "undefined function" nicht.
 */
function zd_melden(array $befund)
{
    $f = zd_paths()['datadir'] . '/.befund';
    $vorher = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if ($vorher === $befund['kurz']) {
        return false;
    }
    @file_put_contents($f, $befund['kurz']);
    if ($vorher === '') {
        return false;      // der erste Befund ueberhaupt ist keine Aenderung
    }
    zd_log('Befund gewechselt: ' . $vorher . ' -> ' . $befund['kurz'] . ' (' . $befund['text'] . ')');
    if (!function_exists('notify_ext')) {
        return false;
    }
    notify_ext(array(
        'PACKAGE' => zd_paths()['plugin'],
        'NAME'    => 'zendure',
        'MESSAGE' => $befund['text'],
        'SEVERITY' => ((int) $befund['status'] === 3) ? 3 : (((int) $befund['status'] === 4) ? 4 : 6),
    ));
    return true;
}

/* ---------------- Verlauf ---------------- */

/**
 * Welche Tage liegen fuer ein Geraet vor? Neueste zuerst.
 *
 * Der Dienst hielt schon immer so viele Tage vor, wie eingestellt sind - die
 * Oberflaeche zeigte bis 0.9.11 aber nur den heutigen. Alles vor Mitternacht
 * war damit unerreichbar, obwohl es dalag.
 */
function zd_verlauf_tage($nummer)
{
    $out = array();
    $muster = zd_paths()['verlaufdir'] . '/geraet' . (int) $nummer . '_*.csv';
    foreach (glob($muster) ?: array() as $f) {
        if (preg_match('/_(\d{8})\.csv$/', $f, $m)) {
            $out[] = $m[1];
        }
    }
    rsort($out);
    return $out;
}

/**
 * Der Verlauf eines Tages als CSV, wie ihn ein Tabellenprogramm liest.
 *
 * Semikolon als Trennzeichen und eine Kopfzeile - die Rohdatei des Dienstes
 * hat beides nicht, sie ist fuer das Plugin geschrieben, nicht fuer Menschen.
 * Die Zeit kommt als Zeitstempel UND als Uhrzeit: die eine rechnet ein
 * Tabellenprogramm, die andere liest ein Mensch. Und das Komma als
 * Dezimalzeichen, weil deutsche Tabellenprogramme sonst Text daraus machen.
 */
function zd_verlauf_csv($nummer, $tag)
{
    $zeilen = array('Zeit;Uhrzeit;SOC in Prozent;Batterieleistung in Watt');
    foreach (zd_verlauf_lesen($nummer, $tag) as $p) {
        $zeilen[] = $p[0] . ';' . date('H:i:s', $p[0]) . ';'
                  . str_replace('.', ',', (string) $p[1]) . ';'
                  . str_replace('.', ',', (string) $p[2]);
    }
    return implode("\r\n", $zeilen) . "\r\n";
}

function zd_verlauf_lesen($nummer, $tag = '')
{
    if ($tag === '') {
        $tag = date('Ymd');
    }
    $f = zd_paths()['verlaufdir'] . '/geraet' . (int) $nummer . '_' . $tag . '.csv';
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
/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function zd_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function zd_mqtt_zustand()
{
    $p = zd_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0, 'broker' => '',
                  'brokerport' => '', 'user' => '', 'pw' => '', 'lokal' => 0,
                  'gatewayfassung' => 0);
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
        /* Ab Werk 1. Fehlt der Schluessel, bleibt es bei 0 - "unbekannt",
         * und dann wird in der Oberflaeche nichts behauptet. */
        'gatewayfassung' => (int) $hol('Gatewayversion', 'gatewayversion'),
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
        $msg = 'publish ' . $praefix . '/' . $k . ' ' . zd_mqtt_wert_saeubern($v);
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']);
    }
    socket_close($s);
    return true;
}

/**
 * Nur senden, was sich geaendert hat.
 *
 * Bis 0.9.10 baute jeder Durchgang alle Paare neu und gab sie geschlossen an
 * das Gateway. Bei Vorgabetakt 15 s sind das rund 5760 Durchgaenge am Tag mit
 * je etwa zwanzig Feldern JE GERAET - unveraendert wiederholt. Das ist kein
 * Schaden, aber es ist auch kein Nutzen: MQTT ist zustandsbehaftet, das
 * Gateway haelt den letzten Wert.
 *
 * Zwei Dinge muessen dabei stimmen, sonst ist die Sparsamkeit teuer erkauft:
 *
 *   1. Ein neu gestartetes Gateway hat nichts. Deshalb wird nach
 *      mqtt_auffrischung Sekunden ALLES gesendet, nicht nur das Geaenderte.
 *   2. Ein Wert, der von einer Zahl auf "nicht geliefert" faellt, ist eine
 *      Aenderung. zd_mqtt_senden() laesst null aus - das Gateway behaelt
 *      dann den alten Wert, und das ist richtig so; gemeldet wird die
 *      Aenderung trotzdem, damit der Vergleich beim naechsten Mal stimmt.
 */
function zd_mqtt_senden_bei_aenderung(array $paare, $praefix, array $cfg)
{
    $auffr = max(0, min(86400, (int) (isset($cfg['mqtt_auffrischung'])
                                      ? $cfg['mqtt_auffrischung'] : 300)));
    $datei = zd_paths()['datadir'] . '/mqtt_letzte.json';
    $alt = zd_json_lesen($datei);
    $letzte_voll = isset($alt['_voll']) ? (int) $alt['_voll'] : 0;
    $voll = ($auffr === 0 || time() - $letzte_voll >= $auffr);

    $zu_senden = array();
    foreach ($paare as $k => $v) {
        $neu = $v === null ? null : (string) $v;
        $vorher = array_key_exists($k, $alt) ? $alt[$k] : '__nie__';
        if ($voll || $vorher !== $neu) {
            $zu_senden[$k] = $v;
        }
    }
    if (!$zu_senden) {
        return true;
    }
    $ok = zd_mqtt_senden($zu_senden, $praefix);
    if ($ok) {
        $merken = array();
        foreach ($paare as $k => $v) {
            $merken[$k] = $v === null ? null : (string) $v;
        }
        $merken['_voll'] = $voll ? time() : $letzte_voll;
        zd_json_schreiben($datei, $merken);
    }
    return $ok;
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
        'geraetN/soll'       => 'ZD_MQTT.SOLL',
        'geraetN/sollok'     => 'ZD_MQTT.SOLLOK',
        'geraetN/ms'         => 'ZD_MQTT.MS',
        'geraetN/energie/heute/<feld>'  => 'ZD_MQTT.E_HEUTE',
        'geraetN/energie/monat/<feld>'  => 'ZD_MQTT.E_MONAT',
        'geraetN/energie/jahr/<feld>'   => 'ZD_MQTT.E_JAHR',
        'geraetN/energie/gesamt/laden'  => 'ZD_MQTT.E_GES_LADEN',
        'geraetN/energie/gesamt/entladen' => 'ZD_MQTT.E_GES_ENTLADEN',
        'geraetN/energie/wirkungsgrad'  => 'ZD_MQTT.E_WIRKUNG',
        'geraetN/energie/zyklen'        => 'ZD_MQTT.E_ZYKLEN',
        'summe/soc'          => 'ZD_MQTT.S_SOC',
        'summe/kapaz'        => 'ZD_MQTT.S_KAPAZ',
        'summe/restkwh'      => 'ZD_MQTT.S_RESTKWH',
        'summe/pv'           => 'ZD_MQTT.S_PV',
        'summe/haus'         => 'ZD_MQTT.S_HAUS',
        'summe/netz'         => 'ZD_MQTT.S_NETZ',
        'summe/batp'         => 'ZD_MQTT.S_BATP',
        'summe/alter'        => 'ZD_MQTT.S_ALTER',
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
    /* HintText steht VORN und war bis 0.9.10 gar nicht da. Gezaehlt ueber den
     * Arbeitsordner: 32 von 51 Plugin-Ordnern setzen das Info-Element - dieser
     * war nicht darunter. Beides ist an einem echten Export aus Loxone Config
     * gemessen (XML_Vorlagen, VI_weissware_geraet1_status.xml). */
    $o .= 'HintText="" ';
    $o .= 'Title="' . zd_x($kopf['title']) . '" ';
    $o .= 'Comment="' . zd_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . zd_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . zd_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    // Erstes Kindelement. templateType 2 = VirtualInHttp (1 = UDP, 3 = Ausgang).
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $einheit = isset($c['einheit']) ? (string) $c['einheit'] : '';
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
        $o .= 'MaxVal="2147483647" ';
        // Unit traegt das Anzeigeformat, nicht nur das Einheitenzeichen.
        $o .= 'Unit="' . zd_x('<v.1>' . ($einheit !== '' ? ' ' . $einheit : '')) . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Befehlserkennung eines Feldes - EINE Stelle fuer Vorlage und Tabelle.
 *
 * Das Semikolon gehoert ins Muster, und zwar zwingend.
 *
 * Loxone sucht die Zeichenkette WOERTLICH und nimmt den ersten Treffer. Ohne
 * fuehrendes Semikolon findet "LADEN=" auch die Stelle in "ENTLADEN=" - dass
 * es bis 0.9.10 stimmte, lag allein daran, dass LADEN in der Zeile zufaellig
 * vor ENTLADEN steht. Faellt LADEN einmal weg oder wechselt die Reihenfolge,
 * stuende die Entladeleistung im Ladeeingang. Ein falscher Wert ist schlimmer
 * als ein fehlender.
 *
 * In jeder Statuszeile geht jedem Feld ein Semikolon voran
 * (ZENDURE;OK=1;SOC=...), das Muster passt also unveraendert. Bestehende
 * Eingaenge muessen NICHT geaendert werden - ohne das Trennzeichen haengt es
 * aber allein an der Reihenfolge, und das ist eine Zusicherung, die beim
 * naechsten neuen Feld still faellt.
 */
/**
 * Der Wirtsname, unter dem der Miniserver diesen LoxBerry erreicht.
 *
 * Stand bis 0.9.12 fuenfmal wortgleich im Quelltext. Der Filter ist kein
 * Schmuck: HTTP_HOST kommt aus der Anfrage, also vom Aufrufer, und landet
 * hier ungeprueft in einer XML-Datei, die jemand nach Loxone importiert.
 */
function zd_host()
{
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        return preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST']);
    }
    return gethostname() ?: 'loxberry';
}

/**
 * Der Pfad zum Endpunkt, mit Token und Abfrage.
 *
 * $werte ist eine geordnete Liste von Paaren, zum Beispiel
 * array('aktion' => 'status', 'geraet' => 1). Das Token setzt diese
 * Funktion selbst - es zu vergessen ist der Fehler, der stumm bleibt.
 *
 * WARUM AN EINER STELLE: bis 0.9.12 wurde diese Adresse an sieben Stellen
 * von Hand zusammengesetzt - zweimal in den Vorlagen, einmal im Reiter
 * Einbindung und viermal in dessen Befehlstabelle. Solange alle sieben
 * gleich lauten, faellt das nicht auf. Sie muessen es aber auch nach der
 * naechsten Aenderung noch, und dafuer gibt es keinen Grund ausser Sorgfalt.
 *
 * $roh = true laesst die Ersetzungszeichen <v> unangetastet; sonst werden
 * die Werte fuer eine Adresse kodiert.
 */
function zd_endpunkt_pfad(array $werte = array(), $roh = false)
{
    $p = zd_paths();
    $teile = array('token=' . rawurlencode(zd_token()));
    foreach ($werte as $k => $v) {
        $v = (string) $v;
        $teile[] = rawurlencode((string) $k) . '='
                 . (($roh && strpos($v, '<') !== false) ? $v : rawurlencode($v));
    }
    return '/plugins/' . $p['plugin'] . '/index.php?' . implode('&', $teile);
}

/** Dieselbe Adresse mit Schema und Wirt davor. */
function zd_endpunkt_url(array $werte = array(), $roh = false)
{
    return 'http://' . zd_host() . zd_endpunkt_pfad($werte, $roh);
}

function zd_check($feld)
{
    return '\i;' . $feld . '=\i\v';
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
        'ZAEHLER'    => array('',  'ZD_FELD.ZAEHLER'),    // Herzschlag des Dienstes
        'MS'         => array('ms', 'ZD_FELD.MS'),        // Antwortzeit des Abrufs
        'FW'         => array('',  'ZD_FELD.FW'),         // nur mit Zuordnung
        'SOLL'       => array('',  'ZD_FELD.SOLL'),       // zuletzt vorgegeben
        'SOLLALTER'  => array('s', 'ZD_FELD.SOLLALTER'),  // wie lange her
        'SOLLOK'     => array('',  'ZD_FELD.SOLLOK'),     // uebernommen?
        'RESTZEIT'  => array('s', 'ZD_FELD.RESTZEIT'),  // bis zum Rueckfall
        'ALTER'      => array('s', 'ZD_FELD.ALTER'),
        'OK'         => array('',  'ZD_FELD.OK'),
    );
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function zd_vorlage($nummer = 1)
{
    $p = zd_paths();
    $cmds = array();
    foreach (zd_status_felder() as $feld => $info) {
        $bedeutung = zd_vorlagentext($info[1]);   // siehe dort: doppelte Maskierung
        $cmds[] = array(
            'title'   => 'ZENDURE_' . $nummer . '_' . $feld,
            'comment' => $bedeutung . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => zd_check($feld),
            'einheit' => $info[0],
        );
    }
    $adresse = zd_endpunkt_url(array('aktion' => 'status',
                                     'geraet' => (int) $nummer));
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

/** Die Felder des Energie-Endpunkts: Einheit und Sprachschluessel. */
function zd_energie_felder()
{
    return array(
        'PV'        => array('kWh', 'ZD_EFELD.PV'),
        'HAUS'      => array('kWh', 'ZD_EFELD.HAUS'),
        'NETZ'      => array('kWh', 'ZD_EFELD.NETZ'),
        'LADEN'     => array('kWh', 'ZD_EFELD.LADEN'),
        'ENTLADEN'  => array('kWh', 'ZD_EFELD.ENTLADEN'),
        'GLADEN'    => array('kWh', 'ZD_EFELD.GLADEN'),
        'GENTLADEN' => array('kWh', 'ZD_EFELD.GENTLADEN'),
        'WIRKUNG'   => array('%',   'ZD_EFELD.WIRKUNG'),
        'ZYKLEN'    => array('',    'ZD_EFELD.ZYKLEN'),
        'TAKT'      => array('s',   'ZD_EFELD.TAKT'),
        'ALTER'     => array('s',   'ZD_EFELD.ALTER'),
        'OK'        => array('',    'ZD_EFELD.OK'),
    );
}

/**
 * Vorlage fuer die Energiewerte.
 *
 * Eigene Vorlage und eigener Zyklus: Zaehlerstaende aendern sich langsam, ein
 * Abruf alle fuenf Minuten genuegt. Wer sie in den 60-Sekunden-Takt der
 * Messwerte legte, erzeugte zwoelfmal so viele Anfragen fuer eine Zahl, die
 * sich in der Zwischenzeit um ein paar Wattstunden bewegt hat.
 */
function zd_vorlage_energie($nummer = 1, $zeitraum = 'tag')
{
    $p = zd_paths();
    $zeitraum = in_array($zeitraum, array('tag', 'monat', 'jahr'), true) ? $zeitraum : 'tag';
    $kurz = array('tag' => 'T', 'monat' => 'M', 'jahr' => 'J');
    $cmds = array();
    foreach (zd_energie_felder() as $feld => $info) {
        $bedeutung = zd_vorlagentext($info[1]);
        $cmds[] = array(
            'title'   => 'ZENDURE_' . (int) $nummer . '_E' . $kurz[$zeitraum] . '_' . $feld,
            'comment' => $bedeutung . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => zd_check($feld),
            'einheit' => $info[0],
        );
    }
    $adresse = zd_endpunkt_url(array('aktion' => 'energie',
                                     'geraet' => (int) $nummer,
                                     'zeitraum' => $zeitraum));
    return array(
        'zendure_geraet' . (int) $nummer . '_energie_' . $zeitraum . '.xml',
        zd_xml_virtual_in_http(array(
            'title'   => 'Zendure Energie ' . (int) $nummer . ' ' . $zeitraum,
            'address' => $adresse,
            'polling' => '300',
            'comment' => 'Erzeugt vom LoxBerry-Plugin Zendure SolarFlow (' . date('d.m.Y') . ')',
        ), $cmds),
    );
}

/**
 * Ein Sprachtext, wie er in eine XML-Ausfuhr darf.
 *
 * Der Text laeuft gleich durch zd_x() und wuerde dort ein zweites Mal
 * maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten aufloesen -
 * sonst stuende in Loxone Config wortwoertlich 'l&auml;dt'.
 */
function zd_vorlagentext($schluessel)
{
    return trim(strip_tags(html_entity_decode(zd_t($schluessel), ENT_QUOTES, 'UTF-8')));
}

/**
 * VirtualOut - die Vorlage der Steuerbefehle.
 *
 * Gegen einen echten Export gemessen (XML_Vorlagen_0.9.10,
 * VQ_weissware_geraet1_befehle.xml), nicht nachempfunden. Daraus die
 * Attributreihenfolge:
 *
 *   Wurzel   HintText, Title, Comment, Address, CmdInit, CloseAfterSend, CmdSep
 *   Kind     Title, Comment, CmdOnMethod, CmdOn, CmdOffMethod, CmdOff,
 *            Analog, Repeat, RepeatRate, HintText
 *   erstes Kindelement: <Info templateType="3" minVersion="17010727"/>
 *
 * CmdOff darf leer sein - ein Befehl ohne Gegenstueck (etwa "sofort abrufen")
 * traegt CmdOffMethod trotzdem, so wie im Original.
 *
 * Der Wertplatzhalter eines Analogbefehls heisst <v>. Gemessen an Loxones
 * eigenem Beispiel Use-Case-98-InOut-Board.loxone: dort steht
 * CmdOn="/loguser.php?user=&lt;v&gt;" bei Analog="true".
 */
function zd_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . zd_x($kopf['title']) . '" ';
    $o .= 'Comment="' . zd_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . zd_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" CloseAfterSend="false" CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . zd_x($c['title']) . '" ';
        $o .= 'Comment="' . zd_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOn="' . zd_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOff="' . zd_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" RepeatRate="0" HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/**
 * Vorlage der Steuerbefehle fuer ein Geraet.
 *
 * Pflichtbestandteil des Reiters "Einbindung in Loxone" fuer jedes schaltende
 * Plugin. Bis 0.9.11 gab es sie nicht - die Adressen standen als Text in einer
 * Tabelle zum Abschreiben, mit allen Tippfehlern, die dabei entstehen. Und
 * drei der acht Befehle standen dort ueberhaupt nicht.
 */
function zd_vorlage_befehle($nummer = 1)
{
    $p = zd_paths();
    $host = zd_host();
    $nr = (int) $nummer;

    $cmds = array();
    // Analogbefehle: der Wert steht als <v> in der Adresse.
    foreach (array(
        array('ENTLADEN',  'entladen',  '', 'LOX.VQ_ENTLADEN',  array('watt' => '<v>')),
        array('LADEN',     'laden',     '', 'LOX.VQ_LADEN',     array('watt' => '<v>')),
        array('SOCMIN',    'socmin',    '', 'LOX.VQ_SOCMIN',    array('prozent' => '<v>')),
        array('SOCMAX',    'socmax',    '', 'LOX.VQ_SOCMAX',    array('prozent' => '<v>')),
        array('GRENZEAUS', 'grenzeaus', '', 'LOX.VQ_GRENZEAUS', array('watt' => '<v>')),
        array('GRENZEEIN', 'grenzeein', '', 'LOX.VQ_GRENZEEIN', array('watt' => '<v>')),
    ) as $c) {
        $cmds[] = array(
            'title'   => 'ZENDURE_' . $nr . '_CMD_' . $c[0],
            'comment' => zd_vorlagentext($c[3]),
            'on'      => zd_endpunkt_pfad(array('aktion' => $c[1], 'geraet' => $nr) + $c[4], true),
            'off'     => '',
            'analog'  => 1,
        );
    }
    // Digitalbefehle: Ein loest aus, ein Gegenstueck gibt es nicht.
    $cmds[] = array('title' => 'ZENDURE_' . $nr . '_CMD_AUS', 'analog' => 0,
                    'comment' => zd_vorlagentext('LOX.VQ_AUS'),
                    'on' => zd_endpunkt_pfad(array('aktion' => 'aus', 'geraet' => $nr)), 'off' => '');
    $cmds[] = array('title' => 'ZENDURE_CMD_ABRUF', 'analog' => 0,
                    'comment' => zd_vorlagentext('LOX.VQ_ABRUF'),
                    'on' => zd_endpunkt_pfad(array('aktion' => 'abruf')), 'off' => '');
    return array(
        'zendure_geraet' . $nr . '_befehle.xml',
        zd_xml_virtual_out(array(
            'title'   => 'Zendure SolarFlow ' . $nr . ' Befehle',
            'address' => 'http://' . $host,
            'comment' => zd_vorlagentext('LOX.VQ_KOPF'),
        ), $cmds),
    );
}

/**
 * Alle Vorlagen in einem Archiv.
 *
 * Bei sechs Geraeten kaeme man sonst auf zwanzig Klicks. Rueckgabe:
 * array(name, inhalt) oder array('', Fehlertext), wenn ZipArchive fehlt -
 * die Erweiterung ist auf einem LoxBerry ueblich, aber nicht zugesichert,
 * und ein Knopf, der eine kaputte Datei liefert, ist schlimmer als einer,
 * der sagt warum er nicht kann.
 */
function zd_vorlagen_paket()
{
    if (!class_exists('ZipArchive')) {
        return array('', zd_t('LOX.ZIP_FEHLT'));
    }
    $geraete = zd_geraete();
    if (!$geraete) {
        return array('', zd_t('LOX.ZIP_KEIN_GERAET'));
    }
    $tmp = zd_paths()['datadir'] . '/vorlagen_' . getmypid() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return array('', sprintf(zd_t('LOX.ZIP_FEHLER'), zd_e($tmp)));
    }
    foreach (array_keys($geraete) as $nr) {
        foreach (array(zd_vorlage($nr), zd_vorlage_befehle($nr)) as $v) {
            $zip->addFromString($v[0], $v[1]);
        }
        foreach (array('tag', 'monat', 'jahr') as $zr) {
            $v = zd_vorlage_energie($nr, $zr);
            $zip->addFromString($v[0], $v[1]);
        }
    }
    if (count($geraete) > 1) {
        $v = zd_vorlage_summe();
        $zip->addFromString($v[0], $v[1]);
    }
    $zip->close();
    $inhalt = (string) @file_get_contents($tmp);
    @unlink($tmp);
    if ($inhalt === '') {
        return array('', sprintf(zd_t('LOX.ZIP_FEHLER'), zd_e($tmp)));
    }
    return array('zendure_vorlagen.zip', $inhalt);
}

/** Die Felder des Summen-Endpunkts. */
function zd_summe_felder()
{
    return array(
        'N'       => array('',    'ZD_SFELD.N'),
        'NOK'     => array('',    'ZD_SFELD.NOK'),
        'SOC'     => array('%',   'ZD_SFELD.SOC'),
        'KAPAZ'   => array('kWh', 'ZD_SFELD.KAPAZ'),
        'RESTKWH' => array('kWh', 'ZD_SFELD.RESTKWH'),
        'PV'      => array('W',   'ZD_SFELD.PV'),
        'HAUS'    => array('W',   'ZD_SFELD.HAUS'),
        'NETZ'    => array('W',   'ZD_SFELD.NETZ'),
        'BATP'    => array('W',   'ZD_SFELD.BATP'),
        'ALTER'   => array('s',   'ZD_SFELD.ALTER'),
        'OK'      => array('',    'ZD_SFELD.OK'),
    );
}

/** Vorlage fuer die Summe ueber alle Geraete. */
function zd_vorlage_summe()
{
    $p = zd_paths();
    $host = zd_host();
    $cmds = array();
    foreach (zd_summe_felder() as $feld => $info) {
        $bedeutung = zd_vorlagentext($info[1]);
        $cmds[] = array(
            'title'   => 'ZENDURE_SUMME_' . $feld,
            'comment' => $bedeutung . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => zd_check($feld),
            'einheit' => $info[0],
        );
    }
    $adresse = 'http://' . $host . '/plugins/' . $p['plugin']
             . '/index.php?token=' . zd_token() . '&aktion=summe';
    return array(
        'zendure_summe.xml',
        zd_xml_virtual_in_http(array(
            'title'   => 'Zendure SolarFlow Summe',
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
    $sprache = '';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    /* Dritte Quelle, und fuer den DIENST die einzige: er laeuft als eigener
     * Prozess ohne Weboberflaeche - LBSystem ist dort nicht geladen, und
     * LBLANG setzt niemand. Bis 0.9.13 fiel er deshalb immer auf Deutsch
     * zurueck, auch auf einem englischen LoxBerry. Base.Lang in der
     * general.json ist der Wert, den der LoxBerry selbst fuehrt. */
    if ($sprache === '') {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            $home = lb_wurzel_ermitteln();
        }
        if ($home) {
            $d = zd_json_lesen($home . '/config/system/general.json');
            if (isset($d['Base']['Lang'])) {
                $sprache = (string) $d['Base']['Lang'];
            }
        }
    }
    if ($sprache === '') {
        $sprache = 'de';
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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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
