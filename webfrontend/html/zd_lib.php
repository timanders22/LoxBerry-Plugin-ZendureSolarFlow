<?php
require_once __DIR__ . '/sprachausgabe.php';     // Stamm, fuer die Vorgaben der Sprachansagen schon beim ersten Lesen der Konfiguration
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

/* Gruppe 43 (0.9.38): die Anbieterregel fuer haus/speicher - gemeinsames Stueck, byte-gleich in allen Anbieter-Linien
 * (haus_anbieter.php, hanb_*(); nur reine Funktionen; Stamm Werkzeuge/gemeinsam/haus_anbieter.php). Liegt neben
 * dieser Datei. */
require_once __DIR__ . '/haus_anbieter.php';


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, webfrontend UND config/system/general.json enthaelt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer ohnehin abfangen muss).
 *
 * general.json unterscheidet einen LoxBerry von einem fremden Baum oder einem
 * Rest aus Pruefstaenden (Regeln/06, Wurzelsuche). Bis 0.9.23 fehlte die
 * Bedingung: in WSL gemessen (Pruefung-ZendureSolarFlow-0.9.24, Fall P1)
 * hielt zd_paths() einen Baum mit config/plugins und webfrontend, aber ohne
 * general.json fuer die Wurzel; alle Pfade der Oberflaeche zeigten damit in
 * dessen config/, data/ und log/ (gelesen, nicht einzeln gemessen).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die LoxBerry-Wurzel fuer zd_paths(), zd_t() und zd_sprache(), in dieser
 * Reihenfolge:
 *   1. $LBHOMEDIR, wenn darunter config/plugins liegt. Ein Verzeichnis ohne
 *      config/plugins ist keine Wurzel, auch wenn die Umgebung es nennt.
 *      general.json wird hier NICHT verlangt: die Pruefkette setzt
 *      LBHOMEDIR auf eine Attrappe (Werkzeuge/lb), die nur so aussieht.
 *   2. lb_wurzel_ermitteln() - aufwaerts, mit general.json.
 *   3. sonst leer. Dahinter steht KEIN fester Standardort mehr; die Aufrufer
 *      arbeiten dann im eigenen Ordner (Archivmodus).
 * Bis 0.9.24 galt jedes vorhandene Verzeichnis in $LBHOMEDIR als Wurzel, ein
 * nicht vorhandenes blieb stehen, wenn die Suche nichts fand, und in
 * zd_paths() und zd_t() folgte der feste Standardort eines LoxBerry. In WSL
 * gemessen (Pruefung-ZendureSolarFlow-0.9.25/messe_h2.sh): aus einem
 * ausgepackten Archiv heraus las die Bibliothek die Konfiguration eines
 * fremden Baums an diesem Ort, schrieb dort aus dessen Zweitschrift eine
 * zendure.json, lud dessen Sprachdatei und hielt dessen Upgrade-Marke fuer
 * die eigene (Faelle B1-B8); mit LBHOMEDIR auf einem beliebigen Verzeichnis
 * nahm sie dieses als Wurzel, auch aus der Anlage heraus (B9-B11, E1).
 */
function zd_lbhome()
{
    $home = getenv('LBHOMEDIR');
    if ($home && is_dir($home . '/config/plugins')) {
        return $home;
    }
    return lb_wurzel_ermitteln();
}

function zd_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    // Ohne Wurzel ist $home leer: Archivmodus weiter unten (zd_lbhome()).
    $home = zd_lbhome();
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
            /* Die Upgrade-Marke, aus demselben Grund neben dem Datenordner:
             * preupgrade.sh legt sie an, purge_installation nimmt sie deshalb
             * nicht mit, postinstall.sh raeumt sie weg. bin/dienst.sh startet
             * nicht, solange sie liegt und juenger als 3600 s ist. */
            'marke'      => $home . '/data/plugins/' . $dir . '.upgrade_laeuft',
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
            'marke'      => $basis . '/data.upgrade_laeuft',
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
    return zd_ansage_vorgaben() + array(
        'geraete'        => array(),
        'intervall'      => 15,     // Sekunden; lokal, also guenstig
        'mqtt_ein'       => 0,
        'mqtt_topic'     => 'zendure',
        'broker_host'    => '',     // leer = Broker aus der general.json
        'broker_port'    => 1883,
        'broker_user'    => '',
        'broker_pw'      => '',
        'steuerung_ein'  => 0,
        /* Gruppe 43 (0.9.38), alle ab Werk aus.
         * haus_speicher_ein: Anbieter haus/speicher (E7, wirkt nur mit mqtt_ein, mit Anbieterregel E4).
         * befehle_mqtt_ein:  Befehle ueber MQTT annehmen (Befehlsbruecke 1.0.2, E8; ab Werk UND beim Update 0;
         *                    eingreifende Befehle zusaetzlich nur mit steuerung_ein - kein neuer zweiter Haken).
         * endpunkt_ein:      der LESENDE HTTP-Endpunkt (Hausform G39 E-E1 A: Neuinstallation aus, Update 1 durch
         *                    postinstall.sh, wenn der Schluessel fehlt); die schaltenden Aktionen bleiben mit Token. */
        'haus_speicher_ein' => 0,
        'befehle_mqtt_ein'  => 0,
        'endpunkt_ein'      => 0,
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

        /* --- Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25) --------
         * Meldet ab Werk, wenn mehr als ein Schreiber Sollwerte an dasselbe
         * Geraet schickt (aendert am Haus nichts); sperrt ab Werk nicht. */
        'wache_ein'         => 1,   // mehrere Schreiber im Fenster melden (Protokoll, Reiter Test)
        'wache_fenster_min' => 15,  // Fenster in Minuten (1..120)
        'wache_lb_melden'   => 0,   // neue Runde zusaetzlich als LoxBerry-Meldung
        'wache_sperren_ein' => 0,   // fremde Schreiber mit 409 abweisen
        'wache_erlaubt'     => '',  // erlaubte Schreiber: Kennung, Adresse oder Kennung@Adresse
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
    /* Geschrieben ist erst, was GANZ geschrieben ist und sich so
     * zuruecklesen laesst (Befund Code 1, 29.09.2026). Bis 0.9.27 galt
     * "fwrite !== false" als Erfolg: bei voller Karte liefert fwrite() die
     * Zahl der geschriebenen Bytes, nicht false. In WSL gemessen (ulimit -f
     * 1): 1024 von 1881 Byte, Rueckgabe true, Konfiguration UND Zweitschrift
     * abgeschnitten, danach ein neues Aktionstoken. */
    $n = ftruncate($fh, 0) ? @fwrite($fh, $json) : false;
    $ok = ($n === strlen($json));
    $ok = @fflush($fh) && $ok;
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        clearstatcache(true, $tmp);
        $ok = (@file_get_contents($tmp) === $json);
    }
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
 * Traegt diese Datei ueberhaupt etwas?
 *
 * Nicht "ist sie leer?", sondern "laesst sie sich als JSON-Objekt mit
 * mindestens einem Schluessel lesen?". Der Unterschied ist gemessen
 * (18.09.2026, WSL, Bestand-2026-09-18/klasse-A/Ergebnis.md): eine
 * ABGESCHNITTENE zendure.json - weder leer noch "{}", aber fuer json_decode
 * unbrauchbar - ging bis 0.9.22 an der Selbstheilung in zd_config() vorbei.
 * zd_json_lesen() gab daraus array(), zd_config() die blanken Vorgaben,
 * zd_token() wuerfelte ein NEUES Aktionstoken und zd_config_speichern()
 * schrieb es samt Zweitschrift. Damit war das alte Token in BEIDEN Dateien
 * fort, und jeder virtuelle Eingang im Miniserver bekam HTTP 403.
 * Messzeile des Bestandslaufs, Fall "kaputt":
 *   [ROT] Konfiguration traegt altes Token: NEIN (erwartet JA)
 *   [ROT] Zweitschrift traegt altes Token: NEIN (erwartet JA)
 *
 * Bauart: sp_inhalt_oder_null() aus Sprachsteuerung 0.11.7, dort aus
 * Intercom 2.2.11 uebernommen.
 *
 * Rueckgabe: die gelesenen Daten oder null, wenn die Datei nichts traegt.
 */
function zd_inhalt_oder_null($pfad)
{
    if (!is_file($pfad)) {
        return null;
    }
    $roh = trim((string) @file_get_contents($pfad));
    if ($roh === '') {
        return null;
    }
    $d = json_decode($roh, true);
    if (!is_array($d) || $d === array()) {
        return null;
    }
    return $d;
}

/**
 * Traegt diese Konfiguration das, was nur sie tragen kann?
 *
 * Das Aktionstoken. Es steht in JEDER Loxone-Adresse dieses Plugins (Reiter
 * "Einbindung in Loxone"); geht es verloren, scheitern saemtliche virtuellen
 * Eingaenge, und es laesst sich nicht zurueckrechnen. Alles andere - auch das
 * Broker-Passwort - laesst sich in der Oberflaeche noch einmal eintragen.
 *
 * Eine Konfiguration OHNE Token gibt es auf keinem Weg der Oberflaeche:
 * zd_token() fuellt es beim ersten Seitenaufbau. Steht dort keines, ist die
 * Datei nicht aus einem gespeicherten Stand hervorgegangen - dann wird aus
 * der Zweitschrift geheilt, statt ein neues Token zu wuerfeln.
 */
function zd_config_hat_inhalt($c)
{
    return is_array($c) && $c !== array()
        && trim((string) (isset($c['aktionstoken']) ? $c['aktionstoken'] : '')) !== '';
}

/**
 * Was die Zweitschrift traegt und der neue Stand nicht.
 *
 * Leere Rueckgabe heisst: die Zweitschrift darf erneuert werden. Verglichen
 * wird je Feld, ob der neue Stand es ueberhaupt fuehrt - eine bewusst
 * geleerte Angabe ist ein gewolltes Loeschen und wird nachgezogen; ein
 * fehlendes Aktionstoken heisst dagegen, der neue Stand ist gar nicht aus dem
 * gespeicherten hervorgegangen. Geprueft wird deshalb NUR das Aktionstoken:
 * ein Feld, das der Bediener leeren darf (broker_pw), wuerde hier bei jedem
 * Speichern eine Warnung erzeugen, und ein Fehlalarm bei jedem Lauf ist eine
 * abgeschaltete Pruefung (CLAUDE.md, Abschnitt 6).
 *
 * Bauart: sp_zweitschrift_fehlt() aus Sprachsteuerung 0.11.7.
 */
function zd_zweitschrift_fehlt($sicherung, array $neu, array $felder)
{
    $z = zd_inhalt_oder_null($sicherung);
    if ($z === null) {
        return array();
    }
    $fehlt = array();
    foreach ($felder as $feld) {
        if (!array_key_exists($feld, $z)) {
            continue;
        }
        $hat_z = is_string($z[$feld]) ? (trim($z[$feld]) !== '') : !empty($z[$feld]);
        if (!$hat_z) {
            continue;
        }
        $hat_n = array_key_exists($feld, $neu)
               && (is_string($neu[$feld]) ? (trim($neu[$feld]) !== '') : true);
        if (!$hat_n) {
            $fehlt[] = $feld;
        }
    }
    return $fehlt;
}

/**
 * Die Zweitschrift erneuern - oder begruendet nicht.
 *
 * Die Zweitschrift ist der EINZIGE Rueckweg zum Aktionstoken. Sie wird nie
 * mit einem Stand ueberschrieben, der das Geheimnis nicht traegt. Das
 * Speichern selbst wird dadurch nicht verhindert - nur der Rueckweg bleibt
 * stehen, und das Protokoll sagt es.
 */
function zd_zweitschrift_ziehen($quelle, $ziel, array $neu, array $felder, $rechte = null)
{
    $fehlt = zd_zweitschrift_fehlt($ziel, $neu, $felder);
    if ($fehlt) {
        zd_log('WARNUNG: Die Zweitschrift bleibt unveraendert - der gespeicherte Stand '
            . 'traegt nicht, was dort steht (' . implode(', ', $fehlt) . '): ' . $ziel);
        return false;
    }
    /* Erst die Quelle zuruecklesen, dann die Zweitschrift auf demselben
     * Weg schreiben (Befund Code 1, 29.09.2026). Bis 0.9.27 stand hier ein
     * @copy ohne Rueckgabepruefung, und die Zweitschrift wurde aus einer
     * Hauptdatei gezogen, die niemand gelesen hatte - eine abgeschnittene
     * Konfiguration stand danach zweimal da. */
    clearstatcache(true, $quelle);
    $zurueck = json_decode((string) @file_get_contents($quelle), true);
    $fl = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (!is_array($zurueck) || json_encode($zurueck, $fl) !== json_encode($neu, $fl)) {
        zd_log('WARNUNG: ' . $quelle . ' traegt nach dem Schreiben nicht den gespeicherten Stand - '
            . 'die Zweitschrift bleibt unveraendert: ' . $ziel);
        return false;
    }
    return zd_json_schreiben($ziel, $zurueck, $rechte);
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
    /* ZUERST lesen wie die Oberflaeche - sonst geht dieser Weg an der
     * Selbstheilung vorbei.
     *
     * Gemessen 18.09.2026 (Pruefung-ZendureSolarFlow-0.9.22, Fall
     * "dienst_erg"): bin/zendure_dienst.php:2122 ruft diese Funktion beim
     * Dienststart auf, und zwar VOR jedem zd_config(). Bei einer
     * abgeschnittenen Konfiguration gab zd_json_lesen() array(), es galten
     * alle 30 Schluessel als fehlend, und die Funktion schrieb die blanken
     * Vorgaben ueber die Datei - das Aktionstoken war fort, bevor die Heilung
     * ueberhaupt zum Zuge kam:
     *   [ROT] Konfiguration traegt das Token (Dienstweg): NEIN (erwartet JA)
     * Der Aufruf von zd_config() heilt jetzt vorher aus der Zweitschrift. */
    zd_config();
    $lage = zd_cfg_lage();
    if (!$lage['fehlend']) {
        return array(1, 0, zd_t('EINST.M_ERG_NICHTS'));
    }
    $datei = zd_json_lesen($p['config']);
    if (!is_array($datei)) {
        $datei = array();
    }
    /* Liess sich nicht heilen, traegt die Datei also weiterhin kein
     * Aktionstoken, wird hier NICHTS geschrieben. Sonst legte dieser Weg die
     * Vorgaben ueber den abgeschnittenen Stand und machte ihn unlesbar, ohne
     * dass jemand das Token noch von Hand herausholen koennte. */
    if (!zd_config_hat_inhalt($datei)) {
        return array(0, 0, sprintf(zd_t('EINST.M_ERG_KEIN_TOKEN'), $p['config'] . '.kaputt'));
    }
    $vorgaben = zd_vorgaben();
    foreach ($lage['fehlend'] as $k) {
        $datei[$k] = $vorgaben[$k];
    }
    /* 0600, nicht die Vorgaben der umask: in der Konfiguration steht das
     * Broker-Passwort im Klartext. zd_json_schreiben() legt eine NEUE
     * Nebendatei an und benennt sie um - ohne diesen Wert stand die Datei
     * danach auf 0644 (gemessen 18.09.2026, Fall "dienst_erg": 644 statt
     * 600), obwohl zd_config_speichern() sie seit je auf 0600 haelt. */
    if (!zd_json_schreiben($p['config'], $datei, 0600)) {
        return array(0, 0, zd_t('EINST.M_ERG_SCHREIBEN'));
    }
    zd_zweitschrift_ziehen($p['config'], $p['sicherung'], $datei,
                           array('aktionstoken'), 0600);
    return array(1, count($lage['fehlend']),
                 sprintf(zd_t('EINST.M_ERG_ERGAENZT'), implode(', ', $lage['fehlend'])));
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false schaltet JEDEN Schreibvorgang ab. So ruft der
 * unangemeldete Endpunkt auf (webfrontend/html/index.php): wer sich nicht
 * ausweisen kann, legt nichts an und stellt nichts wieder her - auch nichts
 * Harmloses. Gemessen 18.09.2026: mit "{}" in der Datei schrieb ein einziger
 * Aufruf des Endpunkts die Konfiguration aus der Zweitschrift neu
 * ("unangemeldeter Endpunkt heilt die leere Konfiguration NICHT: ANDERS").
 *
 * Geheilt wird nach INHALT (zd_inhalt_oder_null(), zd_config_hat_inhalt()),
 * nicht nach Form, und nur aus einer Zweitschrift, die selbst Inhalt traegt:
 * ein Stand ohne Aktionstoken darf keinen anderen ersetzen - in keine der
 * beiden Richtungen. Was vorher in der Datei stand, wird nicht weggeworfen,
 * sondern liegt als <datei>.kaputt daneben (0600 - darin steht das
 * Broker-Passwort).
 */
function zd_config($erzeugen = true)
{
    $p = zd_paths();
    if ($erzeugen && !zd_config_hat_inhalt(zd_inhalt_oder_null($p['config']))) {
        /* Der vorherige Inhalt bleibt erhalten, auch wenn es nichts zu heilen
         * gibt: ohne Zweitschrift ist die abgeschnittene Datei das Einzige,
         * woraus sich das alte Token noch von Hand herauslesen laesst. */
        $alt = is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
        $rest = preg_replace('/\s+/', '', $alt);
        $hat_alt = ($rest !== '' && $rest !== '{}' && $rest !== '[]');
        if ($hat_alt) {
            @copy($p['config'], $p['config'] . '.kaputt');
            @chmod($p['config'] . '.kaputt', 0600);
        }
        if (zd_config_hat_inhalt(zd_inhalt_oder_null($p['sicherung']))) {
            @mkdir($p['configdir'], 0775, true);
            if (@copy($p['sicherung'], $p['config'])) {
                @chmod($p['config'], 0600);
                // Fuer die Pruefzeile "Ist die Konfiguration heil?" (U9).
                $GLOBALS['zd_cfg_geheilt'] = 1;
                zd_log_gebremst('heilung',
                    'Die Konfiguration trug kein Aktionstoken und wurde aus der Zweitschrift '
                    . 'wiederhergestellt: ' . $p['sicherung']
                    . ($hat_alt ? ' (der vorherige Inhalt liegt unter '
                                . $p['config'] . '.kaputt)' : '') . '.');
            }
        } elseif ($hat_alt) {
            zd_log_gebremst('kaputt_ohne_zweitschrift',
                'Die Konfiguration trug kein Aktionstoken, und es gibt keine Zweitschrift '
                . 'mit Inhalt. Der vorherige Inhalt liegt unter ' . $p['config'] . '.kaputt.');
        }
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
    /* Die Zweitschrift wird NICHT erneuert, wenn der neue Stand das
     * Aktionstoken nicht traegt, das dort steht. Sie ist der einzige
     * Rueckweg; ein Stand ohne Geheimnis darf ihn nicht ueberschreiben.
     * Gespeichert wird trotzdem - nur der Rueckweg bleibt stehen. */
    zd_zweitschrift_ziehen($p['config'], $p['sicherung'], (array) $cfg,
                           array('aktionstoken'), 0600);
    /* Schreiber-Wache (Energie-1 C1): die Merker ausgetragener oder umgestellter
     * Geraete gehen mit. */
    zd_wache_aufraeumen();
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
    /* MIT Heilung, anders als im unangemeldeten Endpunkt: diese Funktion
     * wird ausschliesslich aus webfrontend/htmlauth/index.php:95 gerufen,
     * und zwar als erstes, vor jedem Handler. Wuerde sie hier nicht heilen,
     * bekaeme der Bediener nach einer beschaedigten Datei beim ersten Klick
     * eine Abweisung ("kein Merkmal"), obwohl die Zweitschrift danebenliegt -
     * geheilt wuerde erst beim uebernaechsten Seitenaufbau. */
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

/**
 * Ab diesem Alter des Abbilds gilt kein Geraet mehr als frisch: dem
 * Dreifachen des Abfragetakts (Entscheidung des Hausherrn 29.09.2026, Nr. 4).
 */
function zd_ok_grenze(array $cfg)
{
    return 3 * max(5, (int) (isset($cfg['intervall']) ? $cfg['intervall'] : 15));
}

/**
 * Die Geraetewerte mit OK und ALTER zur LESEZEIT (Befund Code 2, 29.09.2026).
 *
 * Bis 0.9.27 standen ok und alter so im Abbild, wie der Dienst sie beim
 * Schreiben rechnete. Starb der Dienst, meldete der Endpunkt unbegrenzt
 * OK=1 und ALTER=0 mit den Werten von gestern (in WSL gemessen: Abbild
 * 86400 s alt, status "OK=1 ... ALTER=0"). Jetzt:
 *   ALTER = Alter beim Schreiben + Alter des Abbilds
 *   OK    = 1 nur, wenn der Dienst es beim Schreiben so sah, das Abbild
 *           nicht aelter als zd_ok_grenze() ist UND das ALTER innerhalb
 *           der Frist des Dienstes liegt (max(120 s, 3 x Takt), dieselbe
 *           Rechnung wie in zd_abbilden()).
 */
function zd_werte_jetzt(array $cfg)
{
    $l = zd_loxone();
    $werte = isset($l['geraete']) && is_array($l['geraete']) ? $l['geraete'] : array();
    $abbild = (isset($l['ts']) && (int) $l['ts'] > 0) ? max(0, time() - (int) $l['ts']) : -1;
    $frisch = ($abbild >= 0 && $abbild <= zd_ok_grenze($cfg));
    $frist = max(120, 3 * (int) (isset($cfg['intervall']) ? $cfg['intervall'] : 15));
    foreach ($werte as $nr => $w) {
        if (!is_array($w)) {
            unset($werte[$nr]);
            continue;
        }
        $a = isset($w['alter']) ? (int) $w['alter'] : -1;
        $w['alter'] = ($a >= 0 && $abbild >= 0) ? $a + $abbild : -1;
        $w['ok'] = (!empty($w['ok']) && $frisch && $w['alter'] >= 0 && $w['alter'] <= $frist) ? 1 : 0;
        $werte[$nr] = $w;
    }
    return $werte;
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

/**
 * Eine HTTP-Anfrage ueber fopen() statt file_get_contents() (Befund Code 14,
 * 29.09.2026).
 *
 * Bis 0.9.27 lasen zendure_dienst.php und zd_test.php den Statuscode aus der
 * alten Kopfzeilen-Variable von PHP; 8.5 meldet sie beim Uebersetzen als
 * ueberholt, PHP 9 soll sie abschaffen - dann hiesse jeder Code 0 und jede
 * Absage des Geraets Erfolg. Die Kopfzeilen stehen in beiden Fassungen im
 * wrapper_data des Datenstroms; Zeitschranke und ignore_errors wirken ueber
 * denselben Kontext. Bauform eb_http_abruf() aus Einspeisebremse 0.9.28.
 *
 * Rueckgabe: array(Rumpf oder false, Kopfzeilen). false heisst: keine
 * Verbindung; error_get_last() sagt dann warum.
 */
function zd_http_holen($url, $ctx)
{
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) {
        return array(false, array());
    }
    $meta = @stream_get_meta_data($fp);
    $rumpf = @stream_get_contents($fp);
    @fclose($fp);
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
          ? $meta['wrapper_data'] : array();
    return array($rumpf === false ? '' : (string) $rumpf, $kopf);
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

/**
 * Ist dieser Prozess UNSER Abrufdienst?
 *
 * Bis 0.9.22 stand in zd_dienst_pid() ein
 * strpos($cmd, 'zendure_dienst.php') ueber die GANZE Befehlszeile. Das ist
 * dieselbe Bauart, die am 18.09.2026 in preupgrade.sh und bin/dienst.sh
 * ersetzt worden ist (Klasse F): sie trifft jeden Prozess, der die
 * Zeichenkette irgendwo fuehrt - "nano .../zendure_dienst.php", ein
 * "tail -f" auf die Datei, und vor allem den Dienst einer ZWEITEN
 * Installation (zendure_01), dessen Pfad die Zeichenkette ebenfalls
 * enthaelt. Prozessnummern werden wiederverwendet; die PID-Datei kann aus
 * einem abgestuerzten Lauf stammen.
 *
 * Das ist hier keine Schoenheitsfrage, denn an dieser Antwort haengen drei
 * Entscheidungen, nicht nur eine Anzeige:
 *   webfrontend/html/index.php  der unangemeldete Loxone-Endpunkt reiht einen
 *                               Schaltbefehl nur ein, wenn ein Dienst laeuft -
 *                               sonst 503. Ein falsches "laeuft" nimmt dem
 *                               Miniserver die klare Absage und legt einen
 *                               Befehl in eine Warteschlange, die niemand
 *                               abarbeitet.
 *   zd_befund()                 speist bin/healthcheck (LoxBerry-Statusseite,
 *                               retained nach MQTT) und zd_melden().
 *   webfrontend/htmlauth        Anzeige im Reiter Einstellungen und im Test.
 *
 * Geprueft wird deshalb argumentweise, genau wie laeuft() in bin/dienst.sh
 * und zd_eigener_dienst() in preupgrade.sh:
 *   argv[0] ist ein PHP,
 *   argv[1] ist - bei relativem Start ueber /proc/<pid>/cwd aufgeloest -
 *   zeichengenau das Dienstskript DIESER Installation,
 *   und es gibt kein drittes Argument (ein "--selbsttest" ist kein Dienst).
 *
 * Auf eine Benutzerpruefung wird verzichtet, wie in laeuft(): die Nummer
 * kommt aus der eigenen PID-Datei im eigenen Datenordner, den nur der
 * Dienstbenutzer beschreibt. In preupgrade.sh, das ueber ALLE Prozesse geht,
 * steht sie sehr wohl.
 */
function zd_ist_dienst($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return false;
    }
    $roh = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($roh === '') {
        return false;
    }
    $args = explode("\0", rtrim($roh, "\0"));
    if (count($args) !== 2) {
        return false;
    }
    if (!preg_match('/^php[0-9.]*$/', basename($args[0]))) {
        return false;
    }
    $ziel = $args[1];
    if (substr($ziel, 0, 1) !== '/') {
        $wd = @readlink('/proc/' . $pid . '/cwd');
        if (!is_string($wd) || $wd === '') {
            return false;
        }
        // Den Zusatz " (deleted)" haengt der Kern an, wenn das
        // Arbeitsverzeichnis inzwischen fort ist (Regeln/06).
        $wd = preg_replace('/ \(deleted\)$/', '', $wd);
        $ziel = $wd . '/' . $ziel;
    }
    $soll = zd_paths()['bindir'] . '/zendure_dienst.php';
    $zr = @realpath($ziel);
    $sr = @realpath($soll);
    if ($zr !== false && $sr !== false) {
        return $zr === $sr;
    }
    return $ziel === $soll;
}

function zd_dienst_pid()
{
    $f = zd_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    return zd_ist_dienst($pid) ? $pid : 0;
}

function zd_dienst_soll()
{
    return is_file(zd_paths()['soll']) ? 1 : 0;
}

/**
 * Liegt die Upgrade-Marke, und gilt sie?
 *
 * preupgrade.sh legt sie als Erstes an, bin/dienst.sh startet nicht, solange
 * sie juenger als 3600 s ist, postinstall.sh raeumt sie weg. Diese Funktion
 * ist die Auskunft dafuer - der Reiter Test zeigt sie an, damit die Regel ein
 * Werkzeug hat, das sie findet (CLAUDE.md, Abschnitt 6).
 *
 * Sie urteilt genauso wie marke_gilt() in bin/dienst.sh: ohne Zeitpunkt,
 * aelter als 3600 s oder mehr als 300 s aus der Zukunft gilt die Marke
 * nicht. Die 300 s Vorlauf: die Uhr kann nach dem Setzen ein Stueck
 * zurueckspringen (in WSL gemessen bis 0,64 s; Fall M5 in
 * Pruefung-ZendureSolarFlow-0.9.24). Den Fall "keine lesbare Uhr" gibt es
 * hier nicht - time() liefert immer etwas.
 *
 * Die Oberflaeche wird bei liegender Marke NICHT gesperrt. Das ist eine
 * Messung, keine Regel (Regeln/06, Nachtrag 17.09.2026): am 18.09.2026 in
 * WSL gemessen (Pruefung-ZendureSolarFlow-0.9.23, Fall 5) hat ein
 * Seitenaufbau mitten in der Luecke weder das Aktionstoken noch die
 * Geraeteliste verloren - die Selbstheilung holt beides aus derselben
 * Zweitschrift, die auch postinstall.sh benutzt. Eine Sperre ohne gemessenen
 * Schaden nimmt dem Anwender nur die Seite.
 */
function zd_marke()
{
    $f = zd_paths()['marke'];
    if (!is_file($f)) {
        return array('da' => 0, 'alter' => -1, 'gilt' => 0, 'pfad' => $f);
    }
    $roh = trim((string) @file_get_contents($f));
    if ($roh === '' || !preg_match('/^[0-9]+$/', $roh)) {
        return array('da' => 1, 'alter' => -1, 'gilt' => 0, 'pfad' => $f);
    }
    $alter = time() - (int) $roh;
    return array('da' => 1, 'alter' => $alter,
                 'gilt' => ($alter >= -300 && $alter < 3600) ? 1 : 0, 'pfad' => $f);
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
        return array(0, zd_t('EINST.M_DIENST_UNBEKANNT'));
    }
    $skript = zd_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, sprintf(zd_t('EINST.M_DIENSTSH_FEHLT'), $skript));
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

/* ---------------- Gleichwert-Unterdrueckung (X-7) ----------------
 *
 * B-Nachzug 01.10.2026, Entscheidung Nr. 19; Vorbild EVCC 0.9.37
 * (webfrontend/html/index.php, Befehlsbremse) und der AnkerSolix-Nachzug. Ein
 * Sollwert-Befehl des Endpunkts mit DEMSELBEN Wert geht innerhalb von 60 s
 * nicht erneut in die Warteschlange (HTTP 200, UNVERAENDERT=1). Bis 0.9.30
 * reihte ein Loxone-Ausgang, der denselben Wert wiederholt, jeden Aufruf
 * ein; innerhalb der Schreibbremse (ab Werk 30 s) kam er als OK=0 mit
 * HTTP 500 "Schreibbremse" zurueck, "aus" und die Grenzen gingen ohne
 * Schreibbremse jedes Mal an das Geraet. Ein anderer Wert geht sofort
 * hinaus - ein zusaetzliches 429 gibt es nicht (Nr. 19); Schreibbremse,
 * Wiederholungssperre und Rasterung des Dienstes bleiben. */

/** Fenster der Gleichwert-Unterdrueckung in Sekunden. */
function zd_gleichwert_fenster()
{
    return 60;
}

/**
 * Merkerschluessel eines Befehls, '' fuer alle nicht betroffenen.
 *
 * Betroffen sind die Sollwert-Befehle (Nr. 19): laden, entladen und aus
 * teilen sich EINEN Schluessel je Geraet - sie setzen alle drei die Leistung,
 * und "laden 500" nach "entladen 300" ist ein neuer Sollwert. socmin, socmax,
 * grenzeaus und grenzeein sind je eine eigene Grenze. Nicht abruf (Ereignis
 * mit eigener Bremse), nicht der Trockenlauf (sendet nichts). Das Geraet als
 * Zahl: "01" ist dasselbe Geraet wie "1".
 */
function zd_gleichwert_schluessel($befehl)
{
    if (!is_array($befehl) || !empty($befehl['trocken']) || !isset($befehl['aktion'])) {
        return '';
    }
    $aktion = (string) $befehl['aktion'];
    $nr = isset($befehl['geraet']) ? (int) $befehl['geraet'] : 1;
    if (in_array($aktion, array('laden', 'entladen', 'aus'), true)) {
        return 'leistung|' . $nr;
    }
    if (in_array($aktion, array('socmin', 'socmax', 'grenzeaus', 'grenzeein'), true)) {
        return $aktion . '|' . $nr;
    }
    return '';
}

/** Der verglichene Wert eines Befehls: Aktion samt watt bzw. prozent. */
function zd_gleichwert_wert($befehl)
{
    $w = (is_array($befehl) && isset($befehl['aktion'])) ? (string) $befehl['aktion'] : '';
    foreach (array('watt', 'prozent') as $k) {
        if (is_array($befehl) && isset($befehl[$k])) {
            $w .= ';' . $k . '=' . (int) $befehl[$k];
        }
    }
    return $w;
}

/**
 * Den Merker oeffnen und sperren. Rueckgabe: Dateizeiger oder false.
 *
 * Die Sperre bleibt waehrend des Einreihens und Wartens gehalten (wie EVCC):
 * zwei gleichzeitige gleiche Aufrufe reihen so nur einmal ein. "e"
 * (close-on-exec), damit ein Kindprozess die Sperre nie erbt. Angelegt wird
 * nur der Merker selbst, kein Ordner: ohne Datenordner false, und der
 * Endpunkt faellt geschlossen aus (503).
 */
function zd_gleichwert_oeffnen()
{
    $p = zd_paths();
    $f = $p['datadir'] . '/befehl_gleichwert.json';
    $fh = is_dir($p['datadir']) ? @fopen($f, 'c+e') : false;
    if ($fh !== false && !@flock($fh, LOCK_EX)) {
        @fclose($fh);
        $fh = false;
    }
    if ($fh === false) {
        zd_log_gebremst('gleichwert', 'Der Merker der Gleichwert-Unterdrueckung (' . $f . ') laesst sich '
            . 'nicht oeffnen - schaltende Befehle werden mit 503 abgewiesen, bis das behoben ist. '
            . 'Pruefen: Datenordner, Platz und Eigentuemer (loxberry).', 600);
    }
    return $fh;
}

/** Den gesperrten Merker lesen; Unlesbares gilt als leer (dann geht der
 * Befehl hinaus - im Zweifel senden, nie still verschlucken). */
function zd_gleichwert_lesen($fh)
{
    @rewind($fh);
    $d = json_decode((string) stream_get_contents($fh), true);
    return is_array($d) ? $d : array();
}

/** Sekunden seit DEMSELBEN Wert, -1 wenn ein anderer Wert gemerkt ist oder
 * der gemerkte nicht im Fenster liegt (eine zurueckgestellte Uhr haelt
 * nichts zurueck). */
function zd_gleichwert_seit($merker, $schluessel, $wert)
{
    if ($schluessel === '' || !isset($merker[$schluessel]) || !is_array($merker[$schluessel])) {
        return -1;
    }
    $e = $merker[$schluessel];
    if (!isset($e['w'], $e['t']) || (string) $e['w'] !== (string) $wert) {
        return -1;
    }
    $seit = time() - (int) $e['t'];
    return ($seit >= 0 && $seit < zd_gleichwert_fenster()) ? $seit : -1;
}

/**
 * Der Merker nach einem Befehl: bestaetigt (ok=1) - der eigene Wert mit
 * Zeit; abgelehnt oder ohne Antwort (0/2) - der eigene Eintrag faellt weg,
 * ein Wiederholen geht dann hinaus. Eintraege ausserhalb des Fensters
 * werden nicht mitgeschleppt.
 */
function zd_gleichwert_nachher($merker, $schluessel, $wert, $gelungen)
{
    $jetzt = time();
    foreach ($merker as $k => $e) {
        $alter = (is_array($e) && isset($e['t'])) ? $jetzt - (int) $e['t'] : -1;
        if ($alter < 0 || $alter >= zd_gleichwert_fenster()) {
            unset($merker[$k]);
        }
    }
    if ($schluessel !== '') {
        if ($gelungen) {
            $merker[$schluessel] = array('w' => (string) $wert, 't' => $jetzt);
        } else {
            unset($merker[$schluessel]);
        }
    }
    return $merker;
}

/** Den Merker schreiben (ausser bei null), entsperren und schliessen.
 * Erfolg nur bei vollstaendig geschriebenem Inhalt (Fehlerklasse 1). */
function zd_gleichwert_schliessen($fh, $merker)
{
    $ok = true;
    if ($merker !== null) {
        $roh = (string) json_encode($merker);
        $ok = @ftruncate($fh, 0) && @rewind($fh) && @fwrite($fh, $roh) === strlen($roh) && @fflush($fh);
        if (!$ok) {
            zd_log_gebremst('gleichwert_schreiben', 'Der Merker der Gleichwert-Unterdrueckung liess sich '
                . 'nicht schreiben - ein gleicher Befehl geht dann erneut hinaus. '
                . 'Pruefen: Platz und Eigentuemer (loxberry).', 600);
        }
    }
    @flock($fh, LOCK_UN);
    @fclose($fh);
    return $ok;
}

/**
 * Den Merker nach einem Befehl aus dem Reiter Test nachfuehren.
 *
 * Der Reiter Test unterdrueckt nichts (ein Mensch drueckt den Knopf
 * bewusst). Er fuehrt den Merker aber nach: sonst wuerde ein Loxone-Befehl,
 * der kurz zuvor denselben Wert setzte, nach einem anderen Wert aus dem
 * Reiter Test noch 60 s lang unterdrueckt. Laesst sich der Merker nicht
 * oeffnen, bleibt es still - der Endpunkt faellt dann ohnehin geschlossen aus.
 */
function zd_gleichwert_nachfuehren($befehl, $erg)
{
    $schl = zd_gleichwert_schluessel($befehl);
    if ($schl === '') {
        return false;
    }
    $fh = zd_gleichwert_oeffnen();
    if ($fh === false) {
        return false;
    }
    return zd_gleichwert_schliessen($fh, zd_gleichwert_nachher(zd_gleichwert_lesen($fh), $schl,
        zd_gleichwert_wert($befehl), (int) $erg === 1));
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

    /* Ohne laufenden Dienst wird nichts eingereiht - dieselbe Absage wie am
     * Endpunkt (webfrontend/html/index.php, 503). Bis 0.9.25 reihten die
     * Geraetesuche und die Knoepfe des Reiters Test auch ohne Dienst ein:
     * die Seite wartete bis zu zehn Sekunden, meldete danach "Die Suche
     * laeuft", und der Auftrag blieb liegen, bis irgendwann ein Dienst
     * startete und ihn ungefragt ausfuehrte. Gefunden von
     * Werkzeuge/wirkungstest.py (je Suchknopf eine neue Datei in befehle/),
     * gemessen in WSL, Pruefung-ZendureSolarFlow-0.9.26, Faelle B1/B2.
     * Vorbild: BatterieBMS 0.9.25 (Entscheidung des Hausherrn 18.09.2026). */
    if (zd_dienst_pid() === 0) {
        return array(0, zd_t('TEST.M_DIENST_LAEUFT_NICHT'));
    }

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, sprintf(zd_t('TEST.M_WS_ORDNER'), $ordner));
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
        return array(0, zd_t('TEST.M_WS_JSON'));
    }
    if (@file_put_contents($tmp, $zd_js) !== strlen($zd_js) || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, sprintf(zd_t('TEST.M_WS_ABLEGEN'), $datei));
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
    return array(2, sprintf(zd_t('TEST.M_WS_KEINE_ANTWORT'), $wartezeit));
}

/* ================= Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25) ==================
 *
 * WOZU. Ein Speicher soll nicht von zwei Reglern zugleich gefuehrt werden. Im Haus
 * koordiniert Loxone (ENERGIE1_ENTWURF.md, Weg C); ein zweiter Schreiber am Endpunkt -
 * ein anderes Plugin, ein Skript, ein zweiter Miniserver - stellte dieselben Groessen
 * (Leistung, Ladezustandsgrenzen, Leistungsgrenzen) gegen Loxone, und bis 0.9.32
 * unterschied der Endpunkt seine Schreiber nicht.
 *
 * WAS. Jeder Sollwert-Befehl (zd_wache_gilt(): laden, entladen, aus, socmin, socmax,
 * grenzeaus, grenzeein) wird je Geraet mit seiner Herkunft gemerkt: optional
 * &von=<kennung> (die Vorlage setzt von=loxone) und der Absender (REMOTE_ADDR). Ein
 * Schreiber ist das Paar Kennung@Absender; ohne &von= heisst er "ohne Kennung" - das
 * ist kein Fehler, so erscheint jede Loxone-Vorlage, die nicht neu eingelesen wurde.
 * abruf ist kein Sollwert (es fragt das Geraet nur sofort ab, statt auf den Takt zu
 * warten, und hat seine eigene Bremse); zwei Abrufer fuehren keinen Speicher.
 * Kommen innerhalb des Fensters (wache_fenster_min, ab Werk 15) an einem Geraet Befehle
 * von mehr als einem Schreiber, steht das
 *   - im Protokoll, gebremst: eine Zeile, wenn die Runde der Schreiber neu ist, sonst
 *     hoechstens eine je Fenster,
 *   - in der Antwort (;SCHREIBER=n, vor MELDUNG),
 *   - im Reiter Test (die Schreiber der letzten 24 h je Geraet),
 *   - bei einer neuen Runde und nur mit wache_lb_melden (ab Werk aus) als
 *     LoxBerry-Meldung.
 * Abgewiesen wird dadurch NICHTS. Der Trockenlauf (&dry=1) merkt sich nichts, prueft
 * die Sperre aber wie echt.
 *
 * SPERREN (wache_sperren_ein, ab Werk aus): ein Befehl eines Schreibers, der nicht in
 * wache_erlaubt steht, bekommt HTTP 409 GRUND=FREMDSCHREIBER, und nichts wird
 * eingereiht. Eine Ruecknahme gibt es bei diesem Plugin NICHT: "aus" heisst im README
 * "Regie an das Geraet zurueckgeben", baut beim Befehlssatz zensdk aber genau dieselbe
 * Nutzlast wie "entladen 0" (smartMode 0, acMode 2, outputLimit 0, inputLimit 0) - das
 * ist ein Sollwert "Leerlauf halten" wie p=0 beim Marstek, und ein Fremder, der ihn
 * schickt, ueberschreibt Loxone. Die Gleichwert-Unterdrueckung zaehlt "aus" ebenso zur
 * Leistung. Der Rueckfall des Dienstes und die Knoepfe des Reiters Test gehen nicht
 * durch den Endpunkt und damit nicht durch die Wache. Das Urteil braucht den Merker
 * nicht, es haengt nur an der Liste und an der Anfrage. Ist Sperren an, die Liste aber
 * leer oder unbrauchbar (nur von Hand moeglich - Formular und Sicherung weisen das ab),
 * wirkt die Sperre nicht, und das Protokoll sagt es: eine verschriebene Liste darf den
 * Hausregler nicht aussperren.
 *
 * DER MERKER FAELLT OFFEN AUS. <datadir>/schreiber_geraet<N>.json - der Datenordner ist
 * der Laufzeitordner dieser Linie (eine eigene Ramdisk hat sie nicht; dort liegen schon
 * die Warteschlange und der Gleichwert-Merker, und der Installer raeumt ihn bei jedem
 * Update ab, also beginnt die Wache danach leer). Geoeffnet mit close-on-exec ('e',
 * unter Windows-PHP 7.4 und 8.5 gemessen: geht), gesperrt mit flock hoechstens 2 s,
 * gehalten nur fuer Lesen und Schreiben, nie waehrend des Einreihens. Laesst er sich
 * nicht oeffnen, sperren oder schreiben, geht der Befehl trotzdem hinaus - die Antwort
 * traegt ;WACHE=MERKER, das Protokoll eine Zeile je Zustandswechsel. Anders als die
 * Gleichwert-Unterdrueckung (503, faellt geschlossen aus): die entscheidet ueber das
 * Senden, die Wache beobachtet nur. Der Merker traegt die Kennung des Geraets (Weg und
 * Adresse); passt sie nicht mehr zur Nummer, beginnt er neu, und beim Speichern der
 * Einstellungen raeumt zd_wache_aufraeumen() die Merker ausgetragener Geraete ab.
 *
 * WARUM 15 MINUTEN. Das Fenster muss den langsamsten regelmaessigen Schreiber fassen;
 * Loxone sendet bei jeder Aenderung, ein Fahrplan oft nur alle paar Minuten. Einstellbar
 * 1 bis 120 min, wie bei Marstek 1.1.19 und EVCC 0.9.37.
 */
if (!defined('ZD_WACHE_AUFBEWAHREN_S')) {
    define('ZD_WACHE_AUFBEWAHREN_S', 86400);   // Reiter Test: Schreiber der letzten 24 h
}
if (!defined('ZD_WACHE_HOECHSTENS')) {
    define('ZD_WACHE_HOECHSTENS', 20);          // Schreiber im Merker je Geraet
}

/** Die Einstellungen der Wache - EINE Liste fuer Vorgaben, Sicherung und Formular. */
function zd_wache_schluessel()
{
    return array('wache_ein', 'wache_fenster_min', 'wache_lb_melden', 'wache_sperren_ein', 'wache_erlaubt');
}

/** Steht die Wache an dieser Aktion? Die Sollwert-Befehle - nicht abruf (Kopf). */
function zd_wache_gilt($aktion)
{
    return in_array($aktion, array('laden', 'entladen', 'aus', 'socmin', 'socmax', 'grenzeaus', 'grenzeein'), true);
}

/** Pfad des Merkers eines Geraets. */
function zd_wache_datei($nr)
{
    return zd_paths()['datadir'] . '/schreiber_geraet' . (int) $nr . '.json';
}

/** Woran der Merker sein Geraet erkennt: Weg und Adresse (bzw. Produkt- und Geraetekennung).
 *  Die Nummer allein genuegt nicht - sie ist die Stelle in der Geraeteliste und rueckt
 *  nach, wenn davor ein Geraet ausgetragen wird. */
function zd_wache_geraetekennung(array $g)
{
    return (isset($g['art']) && $g['art'] === 'mqtt')
        ? 'mqtt|' . (string) $g['prodkey'] . '/' . (string) $g['deviceid']
        : 'http|' . (string) (isset($g['ip']) ? $g['ip'] : '');
}

/** Eine Kennung fuer &von= und fuer die Liste: 1 bis 32 Zeichen aus A-Z a-z 0-9 _ -.
 *  Ohne Punkt und Doppelpunkt - so verwechselt sie sich nie mit einer Adresse.
 *  \z statt $: ein angehaengter Zeilenumbruch (von=loxone%0A) passt nicht. */
function zd_wache_kennung_gueltig($k)
{
    return is_string($k) && preg_match('/^[A-Za-z0-9_\-]{1,32}\z/', $k) === 1;
}

/** Eine Absenderadresse (IPv4 oder IPv6) fuer die Liste. */
function zd_wache_adresse_gueltig($a)
{
    return is_string($a) && $a !== '' && filter_var($a, FILTER_VALIDATE_IP) !== false;
}

/** Zwei Adressen gleich? IPv6 in jeder Schreibweise (::1 = 0:0:0:0:0:0:0:1). */
function zd_wache_adresse_gleich($a, $b)
{
    if ((string) $a === (string) $b) {
        return true;
    }
    $x = @inet_pton((string) $a);
    $y = @inet_pton((string) $b);
    return $x !== false && $y !== false && $x === $y;
}

/** Der Absender dieser Anfrage, auf die zulaessigen Zeichen beschraenkt (wie zd_ep_abweisung). */
function zd_wache_absender()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9A-Fa-f:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '';
    return substr((string) $ip, 0, 45);
}

/**
 * Die Liste der erlaubten Schreiber zerlegen (rein).
 * Eintraege durch Komma, Semikolon oder Leerraum getrennt, je Eintrag eine Kennung
 * ("loxone"), eine Adresse (die des Miniservers) oder beides als Kennung@Adresse.
 * Rueckgabe: array(Eintraege array('von','ip'), unzulaessige Teile); mehr als 16
 * Eintraege sind ein unzulaessiger Teil "> 16".
 */
function zd_wache_liste($text)
{
    if (!is_string($text)) {
        return array(array(), array('?'));
    }
    $ein = array();
    $fehl = array();
    foreach (preg_split('/[\s,;]+/', trim($text)) as $teil) {
        if ($teil === '') {
            continue;
        }
        if (strpos($teil, '@') !== false) {
            list($von, $ip) = explode('@', $teil, 2);
            if (zd_wache_kennung_gueltig($von) && zd_wache_adresse_gueltig($ip)) {
                $ein[] = array('von' => $von, 'ip' => $ip);
                continue;
            }
        } elseif (zd_wache_adresse_gueltig($teil)) {
            $ein[] = array('von' => '', 'ip' => $teil);
            continue;
        } elseif (zd_wache_kennung_gueltig($teil)) {
            $ein[] = array('von' => $teil, 'ip' => '');
            continue;
        }
        $fehl[] = substr((string) preg_replace('/[^\x20-\x7E]/', '?', $teil), 0, 40);
    }
    if (count($ein) > 16) {
        $fehl[] = '> 16';
    }
    return array($ein, $fehl);
}

/** Die Liste als Wert einer Einstellung pruefen (rein). Rueckgabe array(Code, Teile):
 *  Code '' = brauchbar (auch leer), sonst TEXT (keine Zeichenkette), LANG (> 512 Zeichen),
 *  STEUER (Steuerzeichen) oder TEILE (unverstandene Eintraege bzw. mehr als 16). */
function zd_wache_liste_pruefen($v)
{
    if (!is_string($v)) {
        return array('TEXT', array());
    }
    if (strlen($v) > 512) {
        return array('LANG', array());
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
        return array('STEUER', array());
    }
    list(, $fehl) = zd_wache_liste($v);
    return $fehl ? array('TEILE', $fehl) : array('', array());
}

/** Dieselbe Pruefung als Text fuer Formular und Sicherung: '' oder der Grund (HTML-sicher). */
function zd_wache_liste_mangel($v)
{
    list($code, $teile) = zd_wache_liste_pruefen($v);
    if ($code === '') {
        return '';
    }
    if ($code === 'TEILE') {
        return sprintf(zd_t('EINST.WACHE_M_TEILE'), zd_e(implode(', ', array_slice($teile, 0, 4))));
    }
    return zd_t('EINST.WACHE_M_' . $code);
}

/** Steht der Schreiber Kennung@Absender in der Liste? (rein) */
function zd_wache_erlaubt(array $eintraege, $von, $ip)
{
    foreach ($eintraege as $e) {
        if ($e['von'] !== '' && $e['von'] !== (string) $von) {
            continue;
        }
        if ($e['ip'] !== '' && !zd_wache_adresse_gleich($e['ip'], $ip)) {
            continue;
        }
        return true;
    }
    return false;
}

/** Die Einstellungen der Wache aus einer Konfiguration. Was die eigene Pruefung
 *  (zd_sicherung_wert_pruefen, dieselbe wie beim Zurueckspielen) nicht besteht - von
 *  Hand bearbeitet -, gilt mit der Vorgabe; "Einstellungen sichern" warnt dann (X-3). */
function zd_wache_einstellungen(array $cfg)
{
    $v = zd_vorgaben();
    $aus = array();
    foreach (zd_wache_schluessel() as $k) {
        $aus[$k] = (array_key_exists($k, $cfg) && zd_sicherung_wert_pruefen($k, $cfg[$k]) === '') ? $cfg[$k] : $v[$k];
    }
    $aus['wache_ein'] = (int) $aus['wache_ein'];
    $aus['wache_fenster_min'] = (int) $aus['wache_fenster_min'];
    $aus['wache_lb_melden'] = (int) $aus['wache_lb_melden'];
    $aus['wache_sperren_ein'] = (int) $aus['wache_sperren_ein'];
    $aus['wache_erlaubt'] = (string) $aus['wache_erlaubt'];
    return $aus;
}

/** Kreuzpruefung (rein): Sperren an ohne einen einzigen erlaubten Schreiber wiese jeden
 *  Befehl ab - auch den des Hausreglers. Rueckgabe true = Mangel. */
function zd_wache_kreuz($c)
{
    return is_array($c) && isset($c['wache_sperren_ein'], $c['wache_erlaubt'])
        && is_scalar($c['wache_sperren_ein']) && (string) $c['wache_sperren_ein'] === '1'
        && is_string($c['wache_erlaubt']) && trim($c['wache_erlaubt']) === '';
}

/**
 * Das Urteil der Sperre (rein). Rueckgabe array(aktiv, erlaubt, fehler):
 * aktiv = Sperren an UND eine brauchbare Liste. fehler 'LISTE': Sperren an, die Liste
 * aber leer oder unbrauchbar - dann wirkt die Sperre NICHT (Kopf).
 */
function zd_wache_sperre_urteil(array $w, $von, $ip)
{
    if ((int) $w['wache_sperren_ein'] !== 1) {
        return array(false, true, '');
    }
    list($ein, $fehl) = zd_wache_liste((string) $w['wache_erlaubt']);
    if ($fehl || !$ein) {
        return array(false, true, 'LISTE');
    }
    return array(true, zd_wache_erlaubt($ein, $von, $ip), '');
}

/**
 * Den Merker eines Geraets fortschreiben (rein, ohne Datei - von den Proben direkt gerufen).
 * $m: array('geraet' => Kennung des Geraets, 'schreiber' => array('<von>@<ip>' => Eintrag),
 *           'runde' => '', 'gemeldet' => ts)
 * Rueckgabe: array(Merker, Schreiber im Fenster (neueste zuerst), melden, neue Runde).
 * "Runde" ist die Menge der Schreiber im Fenster; gemeldet wird eine neue Runde sofort,
 * dieselbe hoechstens einmal je Fenster. Faellt die Runde auf einen Schreiber zurueck,
 * gilt die naechste zweite wieder als neu. Gehoert der Merker zu einem anderen Geraet
 * (Kennung anders), beginnt er neu.
 */
function zd_wache_fortschreiben(array $m, $geraet, $von, $ip, $art, $abgewiesen, $jetzt, $fenster_s)
{
    if (!isset($m['geraet']) || (string) $m['geraet'] !== (string) $geraet) {
        $m = array();
    }
    $jetzt = (int) $jetzt;
    $liste = (isset($m['schreiber']) && is_array($m['schreiber'])) ? $m['schreiber'] : array();
    $schl = (string) $von . '@' . (string) $ip;
    $e = (isset($liste[$schl]) && is_array($liste[$schl])) ? $liste[$schl]
        : array('von' => (string) $von, 'ip' => (string) $ip, 'erst' => $jetzt, 'n' => 0, 'abgewiesen' => 0);
    $e['zuletzt'] = $jetzt;
    $e['n'] = (int) (isset($e['n']) ? $e['n'] : 0) + 1;
    $e['abgewiesen'] = (int) (isset($e['abgewiesen']) ? $e['abgewiesen'] : 0) + ($abgewiesen ? 1 : 0);
    $e['art'] = (string) $art;
    $liste[$schl] = $e;
    // Aufbewahren: 24 h (in beide Richtungen - eine zurueckgesprungene Uhr laesst keinen
    // Eintrag ewig stehen), hoechstens ZD_WACHE_HOECHSTENS je Geraet.
    foreach ($liste as $k => $x) {
        if (!is_array($x) || !isset($x['zuletzt'], $x['von'], $x['ip'])
                || abs($jetzt - (int) $x['zuletzt']) > ZD_WACHE_AUFBEWAHREN_S) {
            unset($liste[$k]);
        }
    }
    uasort($liste, function ($a, $b) {
        return (int) $b['zuletzt'] - (int) $a['zuletzt'];
    });
    $liste = array_slice($liste, 0, ZD_WACHE_HOECHSTENS, true);
    $fenster = array();
    foreach ($liste as $k => $x) {
        if (abs($jetzt - (int) $x['zuletzt']) < (int) $fenster_s) {
            $fenster[$k] = $x;
        }
    }
    $gemeldet = isset($m['gemeldet']) ? (int) $m['gemeldet'] : 0;
    $runde = '';
    $melden = false;
    $neu = false;
    if (count($fenster) > 1) {
        $k2 = array_keys($fenster);
        sort($k2, SORT_STRING);
        $runde = implode('|', $k2);
        $neu = ($runde !== (isset($m['runde']) ? (string) $m['runde'] : ''));
        $melden = $neu || abs($jetzt - $gemeldet) >= (int) $fenster_s;
        if ($melden) {
            $gemeldet = $jetzt;
        }
    } else {
        $gemeldet = 0;
    }
    return array(array('geraet' => (string) $geraet, 'schreiber' => $liste, 'runde' => $runde, 'gemeldet' => $gemeldet),
                 array_values($fenster), $melden, $neu);
}

/** Ein Schreiber als Text (Kennung@Absender, ohne Kennung so benannt). $ohne: das Wort fuer
 *  "ohne Kennung" - das Protokoll bleibt deutsch, der Reiter Test reicht die Sprachdatei herein. */
function zd_wache_name(array $x, $ohne = 'ohne Kennung')
{
    return ((string) $x['von'] !== '' ? $x['von'] : (string) $ohne) . '@' . ((string) $x['ip'] !== '' ? $x['ip'] : '?');
}

/** Die Schreiber einer Runde als Text fuer Protokoll und Meldung. */
function zd_wache_text(array $fenster)
{
    $t = array();
    foreach ($fenster as $x) {
        $t[] = zd_wache_name($x)
             . ' (' . (int) $x['n'] . 'x' . (!empty($x['abgewiesen']) ? ', ' . (int) $x['abgewiesen'] . ' abgewiesen' : '')
             . ', zuletzt ' . date('H:i:s', (int) $x['zuletzt']) . ' ' . (string) $x['art'] . ')';
    }
    return implode(', ', $t);
}

/** Den Merker oeffnen - mit close-on-exec ('e'): ein Kindprozess erbt die flock-Sperre sonst
 *  und haelt sie ueber das Ende des Endpunkts hinaus ("Sperre vererbt sich an Kinder").
 *  Rueckgabe Handle oder false; ein Verzeichnis an der Stelle ist false. Angelegt wird nur
 *  die Datei, kein Ordner. */
function zd_wache_oeffnen($f, $modus = 'c+')
{
    if (is_dir($f)) {
        return false;
    }
    return @fopen($f, $modus . 'e');
}

/** Eine Protokollzeile je Zustandswechsel (nicht je Aufruf): $fehl wahr und vorher in
 *  Ordnung -> $text_fehl; wieder in Ordnung nach einem Fehler -> $text_wieder. Gemerkt in
 *  <datadir>/.wache_<schluessel>; geschrieben wird nur beim Wechsel. */
function zd_wache_wechsel($schluessel, $fehl, $text_fehl, $text_wieder)
{
    $f = zd_paths()['datadir'] . '/.wache_' . preg_replace('/[^a-z0-9_]/i', '', (string) $schluessel);
    $war = is_file($f);
    if ($fehl && !$war) {
        @file_put_contents($f, (string) time());
        zd_log($text_fehl);
    } elseif (!$fehl && $war) {
        @unlink($f);
        zd_log($text_wieder);
    }
}

/** LoxBerry-Meldung der Wache (nur mit wache_lb_melden). Derselbe Weg wie zd_melden() seit
 *  0.9.18: loxberry_log.php selbst nachladen - keine phplib laedt es von allein, und ohne es
 *  ist notify_ext() nie erreichbar. Gelingt das nicht, sagt es das Protokoll (gebremst). */
function zd_wache_lb_melden($text)
{
    $zd_home = zd_paths()['home'];
    $zd_liblog = $zd_home !== '' ? $zd_home . '/libs/phplib/loxberry_log.php' : '';
    if (!function_exists('notify_ext') && $zd_liblog !== '' && is_file($zd_liblog)) {
        @require_once $zd_liblog;
    }
    if (!function_exists('notify_ext')) {
        zd_log_gebremst('wache_lb', 'Schreiber-Wache: die LoxBerry-Meldung ist eingeschaltet, aber notify_ext() ist '
            . 'nicht erreichbar (' . ($zd_liblog !== '' ? $zd_liblog : 'keine LoxBerry-Wurzel') . ') - gemeldet wird '
            . 'nur im Protokoll.', 3600);
        return false;
    }
    notify_ext(array(
        'PACKAGE'  => zd_paths()['plugin'],
        'NAME'     => 'zendure',
        'MESSAGE'  => (string) $text,
        'SEVERITY' => 4,
    ));
    return true;
}

/**
 * Einen Befehl bei der Wache anmelden. Faellt offen aus (Kopf).
 * $g: das Geraet aus zd_geraete(); $w: zd_wache_einstellungen().
 * Rueckgabe: array('merker' => ging, 'anzahl' => Schreiber im Fenster an diesem Geraet).
 */
function zd_wache_merken(array $g, $von, $ip, $art, $abgewiesen, array $w)
{
    $aus = array('merker' => true, 'anzahl' => 0);
    $nr = (int) $g['nr'];
    $fenster_s = 60 * (int) $w['wache_fenster_min'];
    $f = zd_wache_datei($nr);
    $erg = null;
    $unlesbar = -1;
    $fh = zd_wache_oeffnen($f);
    if ($fh !== false) {
        $ende = microtime(true) + 2;
        $gesperrt = true;
        while (!@flock($fh, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $ende) {
                $gesperrt = false;
                break;
            }
            usleep(20000);
        }
        if ($gesperrt) {
            $roh = (string) stream_get_contents($fh);
            $m = $roh === '' ? array() : json_decode($roh, true);
            if (!is_array($m)) {
                // Unlesbar: neu beginnen - der Merker beobachtet nur (eine Zeile, unten).
                $unlesbar = strlen($roh);
                $m = array();
            }
            list($m2, $fenster, $melden, $neu) = zd_wache_fortschreiben($m, zd_wache_geraetekennung($g), $von, $ip,
                $art, $abgewiesen, time(), $fenster_s);
            $inhalt = (string) json_encode($m2);
            if ($inhalt !== '' && @ftruncate($fh, 0) && @rewind($fh)
                    && @fwrite($fh, $inhalt) === strlen($inhalt) && @fflush($fh)) {
                $erg = array($fenster, $melden, $neu);
            }
            @flock($fh, LOCK_UN);
        }
        @fclose($fh);
    }
    // Protokoll und Meldung erst nach dem Schliessen: gesperrt wird nur fuer Lesen und Schreiben.
    if ($unlesbar >= 0) {
        zd_log('Schreiber-Wache, Geraet ' . $nr . ': der Merker ' . $f . ' war unlesbar (' . $unlesbar
            . ' Byte) und beginnt neu.');
    }
    zd_wache_wechsel('merker' . $nr, $erg === null,
        'Der Merker der Schreiber-Wache (' . $f . ') laesst sich nicht oeffnen, sperren oder schreiben - die '
        . 'Befehle gehen weiter hinaus, nur das Melden mehrerer Schreiber faellt fuer Geraet ' . $nr . ' aus, bis das '
        . 'behoben ist. Pruefen: Platz und Eigentuemer (loxberry).',
        'Der Merker der Schreiber-Wache fuer Geraet ' . $nr . ' ist wieder lesbar.');
    if ($erg === null) {
        $aus['merker'] = false;
        return $aus;
    }
    list($fenster, $melden, $neu) = $erg;
    $aus['anzahl'] = count($fenster);
    if ($melden) {
        $text = 'Schreiber-Wache, Geraet ' . $nr . ' (' . $g['name'] . '): ' . count($fenster)
              . ' Schreiber in den letzten ' . (int) $w['wache_fenster_min'] . ' min - ' . zd_wache_text($fenster)
              . ((int) $w['wache_sperren_ein'] === 1 ? '.' : '. Nichts abgewiesen (Sperren aus).');
        zd_log($text);
        if ($neu && (int) $w['wache_lb_melden'] === 1) {
            zd_wache_lb_melden($text);
        }
    }
    return $aus;
}

/**
 * Die Wache fuer einen Sollwert-Befehl am Endpunkt (nach Geraet, Freigabe und Dienst, vor der
 * Gleichwert-Unterdrueckung). Rueckgabe array(abweisen, Zusatz fuer die Antwortzeile):
 * abweisen = 409 GRUND=FREMDSCHREIBER (der Endpunkt antwortet und reiht nichts ein);
 * Zusatz ;SCHREIBER=n ab zwei Schreibern im Fenster, ;WACHE=MERKER, wenn das Merken nicht
 * ging. Der Trockenlauf merkt sich nichts, prueft die Sperre aber wie echt.
 */
function zd_wache_anwenden(array $g, $aktion, $von, $trocken)
{
    $w = zd_wache_einstellungen(zd_config(false));
    $ip = zd_wache_absender();
    list($aktiv, $erlaubt, $fehler) = zd_wache_sperre_urteil($w, $von, $ip);
    if ($fehler !== '' || $w['wache_sperren_ein'] === 1) {
        zd_wache_wechsel('liste', $fehler !== '',
            'Fremde Schreiber abweisen ist eingeschaltet, aber die Liste der erlaubten Schreiber ist leer oder '
            . 'unbrauchbar - die Sperre wirkt NICHT, bis die Liste im Reiter Einstellungen berichtigt ist.',
            'Die Liste der erlaubten Schreiber ist wieder brauchbar, die Sperre wirkt.');
    }
    // Keine Ruecknahme bei diesem Plugin (Kopf): jeder Sollwert eines Fremden ist sperrbar.
    $abweisen = $aktiv && !$erlaubt;
    $zusatz = '';
    if (!$trocken && $w['wache_ein'] === 1) {
        $m = zd_wache_merken($g, $von, $ip, $aktion, $abweisen, $w);
        if ($m['anzahl'] > 1) {
            $zusatz .= ';SCHREIBER=' . (int) $m['anzahl'];
        }
        if (!$m['merker']) {
            $zusatz .= ';WACHE=MERKER';
        }
    }
    return array($abweisen, $zusatz);
}

/** Den Zusatz der Wache in eine Antwortzeile setzen: vor ;MELDUNG= (die bleibt das letzte,
 *  freie Feld), sonst ans Ende. Mit leerem Zusatz unveraendert - mit einem Schreiber ist die
 *  Antwort also wortgleich wie vorher. */
function zd_wache_zeile($zeile, $zusatz)
{
    if ($zusatz === '') {
        return $zeile;
    }
    $nl = substr($zeile, -1) === "\n" ? "\n" : '';
    $z = rtrim($zeile, "\n");
    $i = strpos($z, ';MELDUNG=');
    return ($i === false ? $z . $zusatz : substr($z, 0, $i) . $zusatz . substr($z, $i)) . $nl;
}

/** Die Schreiber eines Geraets fuer den Reiter Test, neueste zuerst.
 *  Rueckgabe array(zustand, eintraege): 'ok' | 'leer' (kein Befehl in 24 h, seit dem letzten
 *  Update, oder der Merker gehoert zu einem anderen Geraet) | 'merker' (nicht lesbar). */
function zd_wache_lesen(array $g)
{
    $f = zd_wache_datei((int) $g['nr']);
    clearstatcache(true, $f);
    if (!file_exists($f)) {
        return array('leer', array());
    }
    $fh = zd_wache_oeffnen($f, 'r');
    if ($fh === false) {
        return array('merker', array());
    }
    $ende = microtime(true) + 2;
    $ok = true;
    while (!@flock($fh, LOCK_SH | LOCK_NB)) {
        if (microtime(true) >= $ende) {
            $ok = false;
            break;
        }
        usleep(20000);
    }
    $roh = $ok ? (string) stream_get_contents($fh) : '';
    if ($ok) {
        @flock($fh, LOCK_UN);
    }
    @fclose($fh);
    $m = ($ok && $roh !== '') ? json_decode($roh, true) : ($ok ? array() : null);
    if (!is_array($m)) {
        return array('merker', array());
    }
    if (!isset($m['geraet']) || (string) $m['geraet'] !== zd_wache_geraetekennung($g)) {
        return array('leer', array());
    }
    $aus = array();
    $liste = (isset($m['schreiber']) && is_array($m['schreiber'])) ? $m['schreiber'] : array();
    foreach ($liste as $x) {
        if (!is_array($x) || !isset($x['zuletzt'], $x['n']) || abs(time() - (int) $x['zuletzt']) > ZD_WACHE_AUFBEWAHREN_S) {
            continue;
        }
        $aus[] = array('von' => isset($x['von']) && is_string($x['von']) ? $x['von'] : '',
                       'ip' => isset($x['ip']) && is_string($x['ip']) ? $x['ip'] : '',
                       'erst' => (int) (isset($x['erst']) ? $x['erst'] : 0), 'zuletzt' => (int) $x['zuletzt'],
                       'n' => (int) $x['n'], 'abgewiesen' => (int) (isset($x['abgewiesen']) ? $x['abgewiesen'] : 0),
                       'art' => isset($x['art']) && is_string($x['art']) ? $x['art'] : '');
    }
    usort($aus, function ($a, $b) {
        return $b['zuletzt'] - $a['zuletzt'];
    });
    return array($aus ? 'ok' : 'leer', $aus);
}

/** Die Merker ausgetragener oder umgestellter Geraete abraeumen (aus zd_config_speichern()).
 *  Die Geraetenummer ist die Stelle in der Liste: wird Geraet 1 ausgetragen, ist das bisherige
 *  Geraet 2 danach Geraet 1. Entfernt wird jeder Merker, dessen Nummer es nicht mehr gibt
 *  oder dessen Geraetekennung nicht zur Nummer passt; ein unlesbarer bleibt (er beginnt beim
 *  naechsten Befehl neu, mit einer Zeile). Rueckgabe: Zahl der entfernten Merker. */
function zd_wache_aufraeumen()
{
    $ger = zd_geraete();
    $weg = 0;
    foreach (glob(zd_paths()['datadir'] . '/schreiber_geraet*.json') ?: array() as $f) {
        if (is_dir($f) || !preg_match('/schreiber_geraet([0-9]{1,3})\.json\z/', $f, $t)) {
            continue;
        }
        $nr = (int) $t[1];
        $bleibt = false;
        if (isset($ger[$nr])) {
            $m = json_decode((string) @file_get_contents($f), true);
            $bleibt = !is_array($m) || !isset($m['geraet']) || (string) $m['geraet'] === zd_wache_geraetekennung($ger[$nr]);
        }
        if (!$bleibt && @unlink($f)) {
            $weg++;
        }
    }
    if ($weg > 0) {
        zd_log('Schreiber-Wache: ' . $weg . ' Merker ausgetragener oder umgestellter Geraete entfernt.');
    }
    return $weg;
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
    );
    /* X-3: Wuerde das Zurueckspielen genau diese Datei abweisen, sagt es der
     * Kopf - nur Namen, nie Werte. Geliefert wird sie trotzdem vollstaendig. */
    $warn = zd_rueckspiel_befund($cfg);
    if ($warn) {
        $daten['_warnung'] = sprintf(zd_t('EINST.SICH_X3_KOPF'), implode(', ', $warn));
    }
    $daten['konfiguration'] = zd_ansage_fuer_sicherung($cfg);     // 0.9.40: nie ein Sprechtoken
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $js === false ? '' : $js;
}

/**
 * Eine Sicherung zurueckspielen.
 *
 * Rueckgabe: array(ok, Meldung). Geprueft wird, BEVOR etwas geschrieben wird -
 * eine halb zurueckgespielte Konfiguration waere schlimmer als gar keine.
 */
function zd_konfig_einfuhr($inhalt, $nur_pruefen = false, &$namen = null)
{
    /* X-3: $namen sammelt die Namen der abgewiesenen Schluessel (nie Werte);
     * $nur_pruefen endet nach der Pruefung, ohne etwas zu schreiben. So prueft
     * zd_rueckspiel_befund() die eigene Sicherung mit DIESER Funktion. */
    $namen = array();
    /* JEDER Wert wird geprueft, mit den Regeln des Formulars (Befund U4/C3,
     * 29.09.2026, Bauart E). Bis 0.9.27 wurde nur das Vorhandensein von
     * geraete und aktionstoken geprueft und dann array_merge() gespeichert:
     * Token als Liste (der Endpunkt nahm danach "token=Array"), Token "a",
     * steuerung_ein=1, fremde Schluessel, Listen statt Zeichenketten - alles
     * mit "zurueckgespielt" quittiert. Jetzt:
     *   - ein fremder Schluessel oder ein unzulaessiger Wert: nichts geaendert
     *   - ein fehlender Schluessel: der geltende Wert bleibt (auch das
     *     Broker-Passwort)
     *   - leeres Token: das geltende bleibt, und die Meldung sagt es
     *     (Bauform Renault 2.1.13 U4)
     *   - steuerung_ein wird nie still eingeschaltet
     *   - Erfolg erst nach dem Zuruecklesen der Datei
     * Die Rueckgabe ist HTML-sicher (Werte maskiert), Auszeichnung aus den
     * Sprachdateien bleibt stehen (U6). */
    $d = json_decode((string) $inhalt, true);
    if (!is_array($d)) {
        return array(0, zd_t('EINST.KONFIG_KEIN_JSON'));
    }
    $neu = isset($d['konfiguration']) && is_array($d['konfiguration']) ? $d['konfiguration'] : $d;
    $neu = zd_ansage_rueck($neu);     // 0.9.40: Token und Vermerk bleiben die geltenden
    // Ein Merkmal, an dem sich eine Zendure-Sicherung erkennen laesst. Die
    // Meldung nennt nur, was wirklich fehlt (U6).
    $fehlt = array();
    foreach (array('geraete', 'aktionstoken') as $k) {
        if (!array_key_exists($k, $neu)) {
            $fehlt[] = "<span class='sm-mono'>" . $k . '</span>';
            $namen[] = $k;
        }
    }
    if ($fehlt) {
        return array(0, sprintf(zd_t('EINST.KONFIG_FREMD'), implode(', ', $fehlt)));
    }
    $vorgaben = zd_vorgaben();
    $bean = array();
    $gut = array();
    foreach ($neu as $k => $v) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;       // lesbarer Kopf der eigenen Sicherung
        }
        if (!array_key_exists($k, $vorgaben)) {
            $bean[] = sprintf(zd_t('EINST.SICH_FREMD'), zd_e($k));
            $namen[] = $k;
            continue;
        }
        $grund = zd_sicherung_wert_pruefen($k, $v);
        if ($grund !== '') {
            $bean[] = sprintf(zd_t('EINST.SICH_WERT'), zd_e($k), $grund);
            $namen[] = $k;
            continue;
        }
        $gut[$k] = $v;
    }
    $alt = zd_config();
    $cfg = $alt;
    foreach ($gut as $k => $v) {
        $cfg[$k] = $v;
    }
    if ((int) $cfg['schutz_soc_min'] >= (int) $cfg['schutz_soc_max']) {
        $bean[] = zd_t('EINST.FEHLER_SOC_REIHE');
        $namen[] = 'schutz_soc_min';
        $namen[] = 'schutz_soc_max';
    }
    /* Energie-1 C1: Sperren an ohne erlaubten Schreiber - dieselbe Kreuzpruefung wie das
     * Formular. Ueber $namen warnt damit auch "Einstellungen sichern" (X-3). */
    if (zd_wache_kreuz($cfg)) {
        $bean[] = zd_t('EINST.SICH_WACHE_KREUZ');
        $namen[] = 'wache_sperren_ein';
        $namen[] = 'wache_erlaubt';
    }
    if ($bean) {
        return array(0, zd_t('EINST.SICH_ABGEWIESEN') . '<br>' . implode('<br>', $bean));
    }
    if ($nur_pruefen) {
        return array(1, '');
    }
    $hinweise = array();
    /* Energie-1 C1: eine Sicherung von vor der Schreiber-Wache kennt deren Einstellungen
     * nicht. Sie ist trotzdem gueltig - ein fehlender Schluessel behaelt hier ohnehin den
     * geltenden Wert -, und die Meldung sagt es. */
    $zd_wfehlt = array_values(array_diff(zd_wache_schluessel(), array_keys($neu)));
    if ($zd_wfehlt) {
        $hinweise[] = sprintf(zd_t('EINST.SICH_WACHE_BEHALTEN'), zd_e(implode(', ', $zd_wfehlt)));
    }
    /* Gruppe 43 (0.9.38): eine Sicherung aus 0.9.37 oder frueher kennt die neuen Haken nicht - es gilt weiter, was
     * eingestellt ist (Hinweis EINST.SICH_OHNE_G43), nichts wird abgewiesen. */
    $zd_g43fehlt = array_values(array_diff(zd_neue_schluessel_0938(), array_keys($neu)));
    if ($zd_g43fehlt) {
        $hinweise[] = sprintf(zd_t('EINST.SICH_OHNE_G43'), zd_e(implode(', ', $zd_g43fehlt)));
    }
    if (!isset($gut['aktionstoken']) || $gut['aktionstoken'] === '') {
        $cfg['aktionstoken'] = $alt['aktionstoken'];
        $hinweise[] = zd_t('EINST.SICH_TOKEN_BEHALTEN');
    }
    if (!empty($gut['steuerung_ein']) && empty($alt['steuerung_ein'])) {
        $cfg['steuerung_ein'] = 0;
        $hinweise[] = zd_t('EINST.SICH_STEUERUNG_AUS');
    }
    /* Gruppe 43 (0.9.38): wie die Freigabe oben - eine Sicherung schaltet die Befehle ueber MQTT nicht ein. */
    if (!empty($gut['befehle_mqtt_ein']) && empty($alt['befehle_mqtt_ein'])) {
        $cfg['befehle_mqtt_ein'] = 0;
        $hinweise[] = zd_t('EINST.SICH_BEFEHLE_AUS');
    }
    if (!zd_config_speichern($cfg)) {
        return array(0, sprintf(zd_t('EINST.FEHLER_SPEICHERN'), zd_e(zd_paths()['config'])));
    }
    clearstatcache(true, zd_paths()['config']);
    $zurueck = zd_json_lesen(zd_paths()['config']);
    foreach ($cfg as $k => $v) {
        if (!array_key_exists($k, $zurueck) || json_encode($zurueck[$k]) !== json_encode($v)) {
            return array(0, sprintf(zd_t('EINST.SICH_NICHT_WIRKSAM'), zd_e($k)));
        }
    }
    return array(1, sprintf(zd_t('EINST.KONFIG_ZURUECK'), count((array) $cfg['geraete']))
                  . ($hinweise ? ' ' . implode(' ', $hinweise) : ''));
}

/**
 * X-3: Wuerde die EIGENE Sicherung beim Zurueckspielen bestehen?
 *
 * Geprueft mit derselben zd_konfig_einfuhr() wie das Zurueckspielen, nur
 * ohne Schreiben - eine zweite Pruefliste waere eine zweite Wahrheit.
 * Rueckgabe: die Namen der Schluessel, die abgewiesen wuerden (nie Werte);
 * leer, wenn die Sicherung durchginge.
 */
function zd_rueckspiel_befund($cfg = null)
{
    if (!is_array($cfg)) {
        $cfg = zd_config();
    }
    $js = json_encode(array('konfiguration' => zd_ansage_fuer_sicherung($cfg)),     // 0.9.40: wie die Ausfuhr
                      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return array('konfiguration');
    }
    $namen = array();
    list($ok) = zd_konfig_einfuhr($js, true, $namen);
    if ($ok) {
        return array();
    }
    return $namen ? array_values(array_unique(array_map('strval', $namen))) : array('konfiguration');
}

/** Zahlenfelder des Reiters Einstellungen: Feld => array(von, bis). EINE Tabelle fuer Formular und Sicherung. */
function zd_grenzen()
{
    return array(
        'intervall'     => array(5, 900),
        'schreibbremse' => array(0, 600),
        'schrittweite'  => array(1, 500),
        'verlauf_tage'  => array(1, 90),
        'wartezeit'     => array(0, 20),
        'quittung_nachlauf' => array(0, 900),
        'totband_w'            => array(0, 5000),
        'totband_auffrischung' => array(0, 86400),
        'rueckfall_min'     => array(0, 1440),
        'befehl_verfall_s'  => array(0, 86400),
        'schutz_soc_min'    => array(0, 100),
        'schutz_soc_max'    => array(0, 100),
        'energie_monate'    => array(1, 120),
        // Energie-1 C1: Fenster der Schreiber-Wache in ganzen Minuten.
        'wache_fenster_min' => array(1, 120),
    );
}

/** Das Themenpraefix: nicht leer, hoechstens 64 Zeichen, kein / am Rand, kein //, keine Platzhalter (U12). */
function zd_topic_gueltig($s)
{
    return is_string($s) && strlen($s) <= 64
        && (bool) preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\z#', $s);
}

/** Das Aktionstoken in der Form, die zd_token_erzeugen() bildet (U4). */
function zd_token_gueltig($t)
{
    return is_string($t) && (bool) preg_match('/^[a-km-np-z2-9]{24}\z/', $t);
}

/** Ein Wert aus einer Sicherung. Rueckgabe: '' (in Ordnung) oder der Grund (HTML-sicher). */
function zd_sicherung_wert_pruefen($k, $v)
{
    $zd_aw = zd_ansage_wert($k, $v);     // 0.9.40
    if ($zd_aw !== null) { return $zd_aw ? '' : zd_t('ANSAGEPLUGIN.M_FEHL'); }
    $g = zd_grenzen();
    $g['broker_port'] = array(1, 65535);
    /* Die Sicherung nimmt bis 86400 an, obwohl das Formular seit 0.9.28 bei
     * 3600 endet (M8): sonst liesse sich eine eigene Sicherung einer
     * Vorfassung nicht mehr zurueckspielen. Der Dienst deckelt auf 3600. */
    $g['mqtt_auffrischung'] = array(0, 86400);
    if (isset($g[$k])) {
        if (!is_int($v) || $v < $g[$k][0] || $v > $g[$k][1]) {
            return sprintf(zd_t('EINST.SICH_ZAHL'), $g[$k][0], $g[$k][1]);
        }
        return '';
    }
    if (in_array($k, array('mqtt_ein', 'steuerung_ein', 'schutz_ein', 'energie_ein',
                           'wache_ein', 'wache_lb_melden', 'wache_sperren_ein',
                           'haus_speicher_ein', 'befehle_mqtt_ein', 'endpunkt_ein'), true)) {
        return ($v === 0 || $v === 1) ? '' : zd_t('EINST.SICH_SCHALTER');
    }
    $text = function ($x, $max) {
        return is_string($x) && strlen($x) <= $max && !preg_match('/[\x00-\x1F\x7F]/', $x);
    };
    switch ($k) {
        case 'mqtt_topic':
            return zd_topic_gueltig($v) ? '' : zd_t('EINST.FEHLER_TOPIC');
        case 'wache_erlaubt':
            // Energie-1 C1: dieselbe Zerlegung wie der Endpunkt (zd_wache_liste()).
            return zd_wache_liste_mangel($v);
        case 'broker_host':
            return (is_string($v) && ($v === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,80}\z/', $v)))
                ? '' : zd_t('EINST.FEHLER_BROKER');
        case 'broker_user':
            return ($text($v, 128) && strpbrk($v, '"\'') === false) ? '' : zd_t('EINST.SICH_TEXT');
        case 'broker_pw':
            return $text($v, 256) ? '' : zd_t('EINST.SICH_TEXT');
        case 'aktionstoken':
            return (is_string($v) && ($v === '' || zd_token_gueltig($v))) ? '' : zd_t('EINST.SICH_TOKEN');
        case 'temp_umrechnung':
            return in_array($v, array('roh', 'kelvin10', 'zehntel'), true) ? '' : zd_t('EINST.SICH_AUSWAHL');
        case 'schutz_temp_min':
        case 'schutz_temp_max':
            return ((is_int($v) || is_float($v)) && abs($v) <= 999999) ? '' : zd_t('EINST.SICH_ZAHL_FREI');
        case 'zuordnung':
        case 'packzuordnung':
            if (!is_array($v)) {
                return zd_t('EINST.SICH_ZUORDNUNG');
            }
            $karte = $k === 'zuordnung' ? zd_feldkarte() : zd_packkarte();
            foreach ($v as $fk => $fw) {
                if (!isset($karte[$fk]) || !is_string($fw) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}\z/', $fw)) {
                    return zd_t('EINST.SICH_ZUORDNUNG');
                }
            }
            return '';
        case 'geraete':
            if (!is_array($v) || count($v) > 6 || ($v && array_keys($v) !== range(0, count($v) - 1))) {
                return zd_t('EINST.SICH_GERAETE');
            }
            foreach ($v as $i => $z) {
                $f = zd_geraet_zeile_pruefen($z);
                if ($f !== '') {
                    return sprintf(zd_t('EINST.SICH_GERAET_ZEILE'), $i + 1, $f);
                }
            }
            return '';
    }
    return zd_t('EINST.SICH_UNBEKANNT');
}

/** Eine Geraetezeile aus einer Sicherung, mit den Regeln des Formulars. Rueckgabe '' oder Grund. */
function zd_geraet_zeile_pruefen($z)
{
    if (!is_array($z)) {
        return zd_t('EINST.SICH_GERAETE');
    }
    $erlaubt = array('name', 'art', 'ip', 'prodkey', 'deviceid', 'sn', 'modell', 'satz',
                     'quittungsfeld', 'kapazitaet_wh', 'max_laden', 'max_entladen');
    foreach ($z as $k => $w) {
        if (!in_array((string) $k, $erlaubt, true)) {
            return sprintf(zd_t('EINST.SICH_FREMD'), zd_e($k));
        }
        if (in_array($k, array('kapazitaet_wh', 'max_laden', 'max_entladen'), true)) {
            $max = $k === 'kapazitaet_wh' ? 999000 : 5000;
            if (!is_int($w) || $w < 0 || $w > $max) {
                return zd_e($k) . ': ' . sprintf(zd_t('EINST.SICH_ZAHL'), 0, $max);
            }
        } elseif (!is_string($w) || preg_match('/[\x00-\x1F\x7F]/', $w) || strlen($w) > 128) {
            return zd_e($k) . ': ' . zd_t('EINST.SICH_TEXT');
        }
    }
    $s = function ($k) use ($z) { return isset($z[$k]) ? $z[$k] : ''; };
    $art = $s('art') === '' ? 'http' : $s('art');
    if (!in_array($art, array('http', 'mqtt'), true)) {
        return 'art: ' . zd_t('EINST.SICH_AUSWAHL');
    }
    if (preg_match('/[;="\']/', $s('name'))) {
        return 'name: ' . zd_t('EINST.SICH_TEXT');
    }
    if ($art === 'http' && !preg_match('/^\d{1,3}(\.\d{1,3}){3}\z/', $s('ip'))
        && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{1,80}\z/', $s('ip'))) {
        return 'ip: ' . zd_t('EINST.SICH_TEXT');
    }
    foreach (array('prodkey', 'deviceid') as $k) {
        if (($art === 'mqtt' || $s($k) !== '') && !preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $s($k))) {
            return $k . ': ' . zd_t('EINST.SICH_TEXT');
        }
    }
    if ($s('satz') !== '' && !in_array($s('satz'), zd_befehlssaetze(), true)) {
        return 'satz: ' . zd_t('EINST.SICH_AUSWAHL');
    }
    if (!preg_match('/^[a-z0-9]{0,40}\z/', $s('modell'))) {
        return 'modell: ' . zd_t('EINST.SICH_TEXT');
    }
    if ($s('quittungsfeld') !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}\z/', $s('quittungsfeld'))) {
        return 'quittungsfeld: ' . zd_t('EINST.SICH_TEXT');
    }
    return '';
}

/* ---------------- Einmalmeldung nach dem POST (U1) ----------------
 *
 * Jeder POST endet mit 303 (Regeln/04). Was die Seite danach zeigen soll,
 * reist in dieser Datei: im Datenordner, 0600, beim naechsten GET gelesen
 * und geloescht, aelter als 120 s verworfen. Aktionstoken und
 * Broker-Passwort werden vorher unkenntlich gemacht (Regeln/04, Nachtrag
 * Raumklima 17.09.). Bauform ak_einmal_*() aus AnkerSolix 0.9.22. */
function zd_einmal_schreiben(array $meldungen, array $fehler, $test, $eingaben = null)
{
    $cfg = zd_config(false);
    $geheim = array();
    foreach (array($cfg['aktionstoken'], $cfg['broker_pw']) as $g) {
        if (is_string($g) && strlen($g) >= 4) {
            $geheim[] = $g;
            $geheim[] = zd_e($g);
        }
    }
    $weg = function ($t) use ($geheim) {
        return $geheim ? str_replace($geheim, '***', (string) $t) : (string) $t;
    };
    return zd_json_schreiben(zd_paths()['datadir'] . '/einmalmeldung.json', array(
        'zeit'      => time(),
        'meldungen' => array_map($weg, array_values($meldungen)),
        'fehler'    => array_map($weg, array_values($fehler)),
        'test'      => $weg($test),
        // X-2: nur nach einer Beanstandung gesetzt, Geheimnisse nie darin
        // (zd_eingaben_sammeln()).
        'eingaben'  => is_array($eingaben) ? $eingaben : null,
    ), 0600);
}

function zd_einmal_lesen()
{
    $f = zd_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $liste = function ($x) {
        return is_array($x) ? array_values(array_map('strval', array_filter($x, 'is_scalar'))) : array();
    };
    return array(
        'meldungen' => $liste(isset($d['meldungen']) ? $d['meldungen'] : null),
        'fehler'    => $liste(isset($d['fehler']) ? $d['fehler'] : null),
        'test'      => isset($d['test']) && is_scalar($d['test']) ? (string) $d['test'] : '',
        'eingaben'  => isset($d['eingaben']) && is_array($d['eingaben']) ? $d['eingaben'] : null,
    );
}

/* ---------------- Eingaben nach einer Beanstandung (X-2) ----------------
 *
 * Regeln/04 "Nach einer Beanstandung stehen die eingetippten Werte wieder im
 * Formular" (Hausregel seit 30.09.2026). Seit der Umleitung nach jedem POST
 * (U1) zeigte der GET danach die gespeicherten Werte: wer sechs Felder
 * richtig und eines falsch eintrug, tippte alle neu - und seit Entscheidung
 * 16 wird bei einer Beanstandung gar nichts gespeichert.
 *
 * Nur nach einer Beanstandung, nur das eine Formular, nur seine Felder. Nie
 * Geheimnisse: das Broker-Passwort steht in keiner Liste, und ein Wert, der
 * das Aktionstoken oder das gespeicherte Broker-Passwort enthaelt, reist
 * nicht (das Feld zeigt dann den gespeicherten Stand). Bauform wie
 * ACTiKamera 1.9.25 (cam_eingabe*) und Heimkino 1.3.15 (hk_eingabe*). */

/** Die Felder je Formular: text (ein Wert), liste (je Geraetezeile), haken. */
function zd_eingabe_felder($form)
{
    if ($form === 'settings') {
        $text = array_keys(zd_grenzen());
        foreach (array('schutz_temp_min', 'schutz_temp_max', 'temp_umrechnung', 'wache_erlaubt') as $k) {
            $text[] = $k;
        }
        foreach (array_keys(zd_feldkarte()) as $k) {
            $text[] = 'z_' . $k;
        }
        foreach (array_keys(zd_packkarte()) as $k) {
            $text[] = 'zp_' . $k;
        }
        return array(
            'text'  => $text,
            'liste' => array('g_name', 'g_art', 'g_ip', 'g_prodkey', 'g_deviceid', 'g_sn', 'g_modell',
                             'g_satz', 'g_max_laden', 'g_max_entladen', 'g_quittungsfeld', 'g_kapazitaet'),
            'haken' => array('steuerung_ein', 'schutz_ein', 'energie_ein',
                             'wache_ein', 'wache_lb_melden', 'wache_sperren_ein'),
        );
    }
    if ($form === 'mqtt') {
        return array(
            'text'  => array('broker_host', 'broker_port', 'broker_user', 'mqtt_topic', 'mqtt_auffrischung'),
            'liste' => array(),
            'haken' => array('mqtt_ein'),
        );
    }
    return null;
}

/**
 * Die eingetippten Werte eines Formulars aus $_POST, fuer die Einmalmeldung.
 * Ein Wert, der kein gueltiges UTF-8 ist, laenger als 256 Byte oder ein
 * Geheimnis enthaelt, reist nicht mit (sonst scheiterte json_encode und mit
 * ihm die Umleitung) - das Feld zeigt dann den gespeicherten Stand.
 */
function zd_eingaben_sammeln($form, array $beanstandet)
{
    $f = zd_eingabe_felder($form);
    if ($f === null || !$beanstandet) {
        return null;
    }
    $cfg = zd_config(false);
    $geheim = array();
    foreach (array($cfg['aktionstoken'], $cfg['broker_pw']) as $g) {
        if (is_string($g) && strlen($g) >= 4) {
            $geheim[] = $g;
        }
    }
    $ok = function ($v) use ($geheim) {
        if (!is_string($v) || strlen($v) > 256 || preg_match('//u', $v) !== 1) {
            return false;
        }
        foreach ($geheim as $g) {
            if (strpos($v, $g) !== false) {
                return false;
            }
        }
        return true;
    };
    $werte = array();
    foreach ($f['text'] as $k) {
        if (isset($_POST[$k]) && $ok($_POST[$k])) {
            $werte[$k] = $_POST[$k];
        }
    }
    foreach ($f['liste'] as $k) {
        if (isset($_POST[$k]) && is_array($_POST[$k])) {
            $l = array();
            for ($i = 0; $i < 6; $i++) {
                $l[] = (isset($_POST[$k][$i]) && $ok($_POST[$k][$i])) ? $_POST[$k][$i] : null;
            }
            $werte[$k] = $l;
        }
    }
    foreach ($f['haken'] as $k) {
        $werte[$k] = isset($_POST[$k]) ? '1' : '';
    }
    return array('form' => $form, 'werte' => $werte,
                 'beanstandet' => array_values(array_unique(array_map('strval', $beanstandet))));
}

/** Die Eingaben aus der Einmalmeldung annehmen (nur bekannte Felder, nur Text); ohne Argument: der Stand. */
function zd_eingaben_setzen($roh = null)
{
    static $ein = array('form' => '', 'werte' => array(), 'beanstandet' => array());
    if ($roh === null) {
        return $ein;
    }
    if (!is_array($roh) || !isset($roh['form']) || !is_string($roh['form'])) {
        return $ein;
    }
    $f = zd_eingabe_felder($roh['form']);
    if ($f === null) {
        return $ein;
    }
    $w = isset($roh['werte']) && is_array($roh['werte']) ? $roh['werte'] : array();
    $werte = array();
    foreach (array_merge($f['text'], $f['haken']) as $k) {
        if (isset($w[$k]) && is_string($w[$k])) {
            $werte[$k] = $w[$k];
        }
    }
    foreach ($f['liste'] as $k) {
        if (isset($w[$k]) && is_array($w[$k])) {
            $l = array();
            for ($i = 0; $i < 6; $i++) {
                $l[$i] = (isset($w[$k][$i]) && is_string($w[$k][$i])) ? $w[$k][$i] : null;
            }
            $werte[$k] = $l;
        }
    }
    $bean = array();
    if (isset($roh['beanstandet']) && is_array($roh['beanstandet'])) {
        foreach ($roh['beanstandet'] as $b) {
            if (is_string($b) && preg_match('/^[A-Za-z_]{1,40}(#[0-5])?\z/', $b)) {
                $bean[] = $b;
            }
        }
    }
    if ($bean) {
        $ein = array('form' => $roh['form'], 'werte' => $werte, 'beanstandet' => $bean);
    }
    return $ein;
}

/** Wert eines Textfelds oder Hakens ('1'/''): die Eingabe nach einer Beanstandung, sonst der gespeicherte. */
function zd_eingabe($form, $feld, $gespeichert)
{
    $ein = zd_eingaben_setzen();
    if ($ein['form'] === $form && array_key_exists($feld, $ein['werte']) && is_string($ein['werte'][$feld])) {
        return $ein['werte'][$feld];
    }
    return $gespeichert;
}

/** Wert eines Felds der Geraetetabelle (Zeile $i), nur im Formular Einstellungen. */
function zd_eingabe_zeile($feld, $i, $gespeichert)
{
    $ein = zd_eingaben_setzen();
    if ($ein['form'] === 'settings' && isset($ein['werte'][$feld]) && is_array($ein['werte'][$feld])
        && isset($ein['werte'][$feld][$i]) && is_string($ein['werte'][$feld][$i])) {
        return $ein['werte'][$feld][$i];
    }
    return $gespeichert;
}

/** Die gespeicherten Werte eines Formulars mit den Eingaben ueberlagern (nur Schluessel der Konfiguration). */
function zd_eingaben_ueberlagern(array $cfg, $form)
{
    $ein = zd_eingaben_setzen();
    if ($ein['form'] !== $form) {
        return $cfg;
    }
    $f = zd_eingabe_felder($form);
    foreach (array_merge($f['text'], $f['haken']) as $k) {
        if (array_key_exists($k, $cfg) && isset($ein['werte'][$k]) && is_string($ein['werte'][$k])) {
            $cfg[$k] = $ein['werte'][$k];
        }
    }
    return $cfg;
}

/** Das Merkmal am beanstandeten Feld: rot umrandet und fuer Vorleseprogramme markiert. */
function zd_markierung($feld)
{
    $ein = zd_eingaben_setzen();
    return in_array((string) $feld, $ein['beanstandet'], true)
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
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
        /* Ob der Dienst laeuft, wird GEFRAGT, nicht angenommen. Bis 0.9.19
         * stand hier immer "Der Dienst laeuft" - am LoxBerry am 17.09.2026
         * gemessen: frisch installiert, dienst.sh status "gestoppt", kein
         * Prozess, und der Healthcheck meldete trotzdem einen laufenden
         * Dienst. Das Kuerzel bleibt dasselbe: zd_melden() merkt sich das
         * Kuerzel, und ein Wechsel nur im Text ist kein neuer Befund. */
        return array('status' => 6, 'kurz' => 'keine_geraete',
                     'text' => zd_t(zd_dienst_pid() > 0
                                    ? 'BEFUND.KEINE_GERAETE'
                                    : 'BEFUND.KEINE_GERAETE_DIENST_AUS'));
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
/** Die Schluessel der Sprachansagen in der Hauptkonfiguration. */
function zd_ansage_schluessel()
{
    return array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft', 'ansage_aus_datei');
}

/** Die Vorgaben: nie gesetzt = ab Werk EIN. Ein gespeicherter Block bleibt, auch 'aus'. */
function zd_ansage_vorgaben()
{
    return array('tts' => ansage_werk_block(), 'ansage_regeln' => ansage_regeln_vorgaben(), 'ansage_haken' => array('befund' => 1), 'ansage_token_herkunft' => array(), 'ansage_aus_datei' => 0);
}

/** Wertpruefung fuer die Sprachansagen: null = nicht meine Schluessel, sonst tauglich ja/nein. */
function zd_ansage_wert($k, $w)
{
    if (!in_array((string) $k, zd_ansage_schluessel(), true)) { return null; }
    $g = '';
    switch ((string) $k) {
        case 'tts':
            return is_array($w) && ansage_wert_pruefen($w, $g, zd_ansage_modi()) !== null;
        case 'ansage_regeln':
            return is_array($w) && ansage_regeln_wert($w, $g) !== null;
        case 'ansage_haken':
            if (!is_array($w)) { return false; }
            foreach ($w as $a => $v) {
                if (!array_key_exists($a, array('befund' => 1)) || !in_array($v, array(0, 1, '0', '1'), true)) { return false; }
            }
            return true;
        case 'ansage_token_herkunft':
            return is_array($w);
        case 'ansage_aus_datei':
            return in_array($w, array(0, 1, '0', '1'), true);
    }
    return false;
}

/** Fuer die Sicherung: nie ein Sprechtoken, nie der Herkunftsvermerk. Ausgabeart, Ziele, Regeln und Haken reisen mit. */
function zd_ansage_fuer_sicherung(array $c)
{
    unset($c['ansage_token_herkunft'], $c['ansage_aus_datei']);
    if (isset($c['tts']) && is_array($c['tts'])) { $c['tts'] = ansage_sicherung_bereinigen($c['tts']); }
    return $c;
}

/**
 * Vor dem Zurueckspielen. Eine Datei mit Sprechtoken wird abgewiesen (der Block wird unbrauchbar gemacht, die Wertpruefung nennt ihn). Die geltenden Token und der Vermerk bleiben.
 * Fehlt ein Schluessel (Sicherung einer aelteren Fassung), gilt der geltende Wert: Ein bewusstes AUS geht beim Zurueckspielen nicht verloren.
 */
function zd_ansage_sicherung_vor($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d)) { return $roh; }
    $inhalt = false;
    foreach (array_keys($d) as $k) { if ((string) $k !== '' && ((string) $k)[0] !== '_') { $inhalt = true; } }
    if (!$inhalt) { return $roh; }
    $j = zd_ansage_cfg();
    unset($d['ansage_token_herkunft'], $d['ansage_aus_datei']);
    $jt = isset($j['tts']) && is_array($j['tts']) ? $j['tts'] : array();
    if (array_key_exists('tts', $d)) {
        $g = '';
        $p = is_array($d['tts']) ? ansage_wert_pruefen($d['tts'], $g, zd_ansage_modi()) : null;
        if (ansage_sicherung_mangel($d['tts']) || $p === null) {
            $d['tts'] = 'ABGEWIESEN';
        } else {
            list($tv) = ansage_vervollstaendigen($p, 'aus');
            $d['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
        }
    } else {
        $d['tts'] = zd_tts($j);
    }
    foreach (array_diff(zd_ansage_schluessel(), array('tts', 'ansage_token_herkunft', 'ansage_aus_datei')) as $k) {
        if (!array_key_exists($k, $d) && array_key_exists($k, $j)) { $d[$k] = $j[$k]; }
    }
    $d['ansage_token_herkunft'] = isset($j['ansage_token_herkunft']) && is_array($j['ansage_token_herkunft']) ? $j['ansage_token_herkunft'] : array();
    $d['ansage_aus_datei'] = 1;
    return (string) json_encode($d);
}

/**
 * Die Hauptkonfiguration. Liegt eine ansage.json aus einer Probe vom 01.11., wird sie EINMAL uebernommen (Vermerk ansage_aus_datei) und bleibt liegen.
 */
function zd_ansage_cfg()
{
    $c = zd_config();
    if (empty($c['ansage_aus_datei'])) {
        $f = rtrim(dirname(zd_paths()['config']), '/') . '/ansage.json';
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                foreach (array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft') as $k) {
                    if (array_key_exists($k, $d) && zd_ansage_wert($k, $d[$k]) === true) { $c[$k] = $d[$k]; }
                }
            }
            $c['ansage_aus_datei'] = 1;
            zd_ansage_cfg_schreiben($c);
        }
    }
    return $c;
}

function zd_ansage_cfg_schreiben(array $cfg)
{
    return zd_config_speichern($cfg);
}

/** Die Ausgabearten dieser Linie. */
function zd_ansage_modi()
{
    return array_values(array_diff(ansage_modi(), array('audioserver')));
}

/** Der Block tts: gespeichert bleibt, auch 'aus'; ungueltig gilt als 'aus'. */
function zd_tts($cfg = null)
{
    if ($cfg === null) { $cfg = zd_ansage_cfg(); }
    return ansage_block_oder_aus(isset($cfg['tts']) ? $cfg['tts'] : ansage_werk_block(), zd_ansage_modi());
}

/** Der Kontext des Moduls. */
function zd_ansage_k()
{
    $d = zd_paths()['datadir'];
    return array(
        'port' => ansage_webport(ansage_lbhome() . '/config/system/general.json'),
        'home' => ansage_lbhome(),
        'kopf' => array('User-Agent: LoxBerry Zendure SolarFlow'),
        'ordner' => @is_dir($d) ? $d : '',
        't' => function ($s) { return zd_t($s); },
        'werk' => ansage_werk_modus(),
    );
}

/** Die Anlaesse: Name => Beschriftung. */
function zd_ansage_anlaesse()
{
    return array('befund' => zd_t('ANSAGEPLUGIN.L_BEFUND'));
}

/** Ist der Haken dieses Anlasses gesetzt? Fehlt er, gilt die Vorgabe der Linie. */
function zd_ansage_anlass_an($a, $cfg = null)
{
    if ($cfg === null) { $cfg = zd_ansage_cfg(); }
    $h = (isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken'])) ? $cfg['ansage_haken'] : array();
    $v = array('befund' => 1);
    return !empty(array_key_exists($a, $h) ? $h[$a] : (isset($v[$a]) ? $v[$a] : 0));
}

/** Die Eingaben nach einer Beanstandung bis zur naechsten Seite aufheben, nie ein Sprechtoken. */
function zd_ansage_eingaben($setzen = null)
{
    $f = zd_paths()['datadir'] . '/ansage_eingaben.json';
    if (is_array($setzen)) {
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, (string) json_encode($setzen));
        @chmod($f, 0600);
        return $setzen;
    }
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($d) && $d) { @file_put_contents($f, '{}'); return $d; }
    return array();
}

/** Das Formular lesen und speichern: array(gelungen, Fehlertexte, Hinweise). */
function zd_ansage_post(array $post)
{
    $cfg = zd_ansage_cfg();
    $k = zd_ansage_k();
    $m = array();
    $b = array();
    $h = array();
    $neu = ansage_formular_lesen($post, zd_tts($cfg), $m, $b, array('modi' => zd_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), $k, $h);
    list($r, $hk) = ansage_regeln_formular($post, zd_ansage_regeln($cfg), zd_ansage_anlaesse(), $m, $b, $k);
    if ($m) {
        $t = array();
        foreach ($m as $x) { $t[] = $x['text']; }
        $e = array('_bean' => array_values($b));
        foreach ($post as $n => $w) {
            if (strncmp((string) $n, 'tts_', 4) === 0 || strncmp((string) $n, 'ansage_', 7) === 0) {
                if ((string) $n !== 'tts_alexa_token' && (string) $n !== 'tts_google_token') { $e[$n] = $w; }
            }
        }
        zd_ansage_eingaben($e);
        return array(false, $t, array());
    }
    $cfg['tts'] = $neu;
    $cfg['ansage_regeln'] = $r;
    $cfg['ansage_haken'] = $hk;
    $t = array();
    foreach ($h as $x) { $t[] = $x['text']; }
    return array(zd_ansage_cfg_schreiben($cfg), array(), $t);
}

/** Der Abschnitt im Reiter Einstellungen, eigenes Formular. $felder: die versteckten Felder (Formularmerkmal, Reiter). Nach einer Beanstandung traegt er das Eingetippte. */
function zd_ansage_form_html($felder)
{
    $cfg = zd_ansage_cfg();
    $k = zd_ansage_k();
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $ein = zd_ansage_eingaben();
    $bean = isset($ein['_bean']) && is_array($ein['_bean']) ? $ein['_bean'] : array();
    $h = array(
        'w' => function ($n, $g) use ($ein) { return (isset($ein[$n]) && is_string($ein[$n])) ? $ein[$n] : $g; },
        'm' => function ($n) use ($bean) { return in_array($n, $bean, true) ? ' class="sm-beanstandet"' : ''; },
        'c' => function ($n, $g) use ($ein) { return $ein ? !empty($ein[$n]) : (bool) $g; },
    );
    $o = array();
    $o[] = '<h2>' . $e(zd_t('ANSAGEPLUGIN.H')) . '</h2>';
    $o[] = '<form action="index.php" method="post" autocomplete="off">' . $felder;
    $o[] = ansage_formular_html(zd_tts($cfg), $h + array('modi' => zd_ansage_modi(), 'ziele' => true, 'ziele_post' => isset($ein['tts_ziel_art']) ? $ein : null), $k);
    $o[] = ansage_regeln_html(zd_ansage_regeln($cfg), isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken']) ? $cfg['ansage_haken'] : array('befund' => 1), zd_ansage_anlaesse(), $h + array('token_vor_ort' => true), $k);
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_speichern" value="1">' . $e(zd_t('ANSAGEPLUGIN.K_SPEICHERN')) . '</button>';
    $o[] = '<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1">' . $e(zd_t('ANSAGEPLUGIN.K_TEST')) . '</button></div>';
    $o[] = '</form>';
    $o[] = zd_ansage_lage_html($cfg);
    return implode("\n", $o) . "\n";
}

/** Die Testansage (der Knopf spricht immer). Rueckgabe: array(gesendet, Grund). */
function zd_ansage_test()
{
    $k = zd_ansage_k();
    zd_ansage_token_pflegen();
    $r = ansage_testansage(zd_ansage_vor_ort(), $k);
    zd_log('Testansage: ' . ansage_kurz($r));
    return array($r['stand'] === 1, $r['stand'] === 1 ? '' : ansage_kennung_text($r['kennung'], $k));
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (0.9.40, Welle S2, Stamm sprachausgabe.php 1.3.2) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('ZD_ANSAGE_HOECHSTALTER')) { define('ZD_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function zd_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = zd_ansage_cfg(); }
    $g = '';
    if (!empty($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function zd_ansage_warte($fn)
{
    $d = zd_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function zd_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    zd_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; zd_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function zd_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = zd_ansage_cfg(); }
    list($neu, $her2, $g) = ansage_token_abgleich(zd_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), zd_ansage_k());
    if (!$g) { return false; }
    $cfg = zd_ansage_cfg();
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!zd_ansage_cfg_schreiben($cfg)) { return false; }
    zd_log('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function zd_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = zd_ansage_cfg(); }
    return ansage_tafel_einreihen(ansage_token_fuer_lauf(zd_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), zd_ansage_k()), zd_ansage_regeln($cfg), zd_ansage_k(), true);     // Stamm 1.4.0: die Tafel, wenn sie bereitsteht
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function zd_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = zd_ansage_cfg(); }
    $t = zd_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(zd_ansage_vor_ort($cfg), zd_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function zd_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = zd_ansage_cfg(); }
    $k = zd_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(zd_tts($cfg), zd_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function zd_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function zd_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = zd_ansage_cfg();
    $k = zd_ansage_k();
    $t = $tts === null ? zd_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, zd_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function zd_ansage_senden($jetzt = null)
{
    $d = zd_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = zd_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= ZD_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = zd_ansage_cfg();
        zd_ansage_token_pflegen($conf);
        $conf = zd_ansage_cfg();
        $a = ansage_regel_sprechen($frisch, zd_tts($conf), zd_ansage_regeln($conf), zd_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { zd_log('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            zd_log('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/** Die Sicherung vor dem Zurueckspielen (Feld statt Rohtext). */
function zd_ansage_rueck(array $neu)
{
    $j = json_decode((string) zd_ansage_sicherung_vor((string) json_encode($neu)), true);
    return is_array($j) ? $j : $neu;
}

/** Aus zd_melden(): der Befund hat gewechselt. Stoerung = alles ausser ok und "keine Geraete"/"Dienst aus" (gewollt). Rueckgabe: das Ergebnis von zd_ansage_legen(). */
function zd_ansage_befund($vorher, array $befund)
{
    if (!zd_ansage_anlass_an('befund')) { return 'aus'; }
    $saetze = array('alle_stumm' => 'SATZ_STUMM', 'teils_stumm' => 'SATZ_TEILS', 'dienst_tot' => 'SATZ_DIENST', 'gateway_aus' => 'SATZ_GATEWAY');
    $k = (string) $befund['kurz'];
    if (isset($saetze[$k])) {
        return zd_ansage_legen(array(array('schluessel' => 'stoerung|' . $k, 'text' => zd_t('ANSAGEPLUGIN.' . $saetze[$k]))));
    }
    if ($k === 'ok' && isset($saetze[(string) $vorher])) {
        return zd_ansage_legen(array(array('schluessel' => 'wieder', 'text' => zd_t('ANSAGEPLUGIN.SATZ_WIEDER'), 'nur_nach' => 'stoerung|' . (string) $vorher)));
    }
    return 'nichts';
}

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
    zd_ansage_befund($vorher, $befund);     // 0.9.40
    /* loxberry_log.php nachladen - weder der Cron noch die Oberflaeche
     * laden sie von selbst.
     *
     * Bis 0.9.17 stand hier nur die Wache, und sie schlug IMMER an: am
     * Geraet gemessen (LoxBerry 4.0.0.15, 13.09.2026) ist
     * function_exists('notify_ext') ohne loxberry_log.php false. Es ging
     * also nie eine Meldung hinaus, und weil hier ohne Protokollzeile
     * zurueckgekehrt wurde, hat es niemand bemerkt. Deshalb sagt der
     * Fehlschlag jetzt auch etwas. Bauart aus oc_lib.php des
     * Octopus-Plugins. */
    /* Nur unter einer LoxBerry-Wurzel. Bis 0.9.25 wurde der Pfad auch mit
     * leerer Wurzel gebildet - aus dem ausgepackten Archiv also
     * /libs/phplib/loxberry_log.php ab der Laufwerkswurzel, und was dort lag,
     * lief als PHP (in WSL gemessen, Pruefung-ZendureSolarFlow-0.9.26,
     * Fall T4). */
    $zd_home = zd_paths()['home'];
    $zd_liblog = $zd_home !== '' ? $zd_home . '/libs/phplib/loxberry_log.php' : '';
    if (!function_exists('notify_ext') && $zd_liblog !== '' && is_file($zd_liblog)) {
        @require_once $zd_liblog;
    }
    if (!function_exists('notify_ext')) {
        zd_log('Der Hinweis "' . $befund['text'] . '" konnte nicht an das '
             . 'Benachrichtigungszentrum gehen: notify_ext() ist nicht '
             . 'erreichbar (' . ($zd_liblog !== '' ? $zd_liblog : 'keine LoxBerry-Wurzel') . ').');
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
 * Geht dieses Thema zurueckbehalten (retained) hinaus?
 *
 * Hausstandard seit 03.09.2026 (Regeln/07): **Zustaende** retained, damit
 * Loxone nach einem Neustart des Miniservers oder des Brokers sofort den
 * Stand hat; **Messwerte mit Zeitbezug** nicht, damit kein alter Wert als
 * aktueller erscheint; das **Lebenszeichen** nie.
 *
 * Die Entscheidung faellt je THEMA und nicht je Aufruf: der Dienst schickt
 * Ladezustaende, Sollwerte und Momentanleistungen in EINEM Durchgang hinaus
 * (ACTiKamera 1.9.19, 08.09.2026).
 *
 * Die Trennlinie ist dieselbe wie bei MarstekVenus 1.1.10, dem naechsten
 * Verwandten - dort ist sie an einem echten Speicher gemessen:
 *
 *   Zustand   soc, soc_min, soc_max, grenze_ein, grenze_aus, acmodus,
 *             soll, sollok, packs, geraete, kapaz, restkwh,
 *             Energiezaehler gesamt, Wirkungsgrad, Zyklen - ein
 *             Zaehlerstand ist der Stand, nicht die Messung
 *   Zeitwert  energie/heute|monat|jahr - seit 0.9.28 NICHT mehr retained,
 *             siehe zd_mqtt_zeitwert()
 *   Dienst    ok, geraetN/online - seit 0.9.26 NICHT mehr retained, siehe
 *             zd_mqtt_dienstaussage()
 *   Messwert  pv, haus, netz, batp, laden, entladen, temp, dvolt, volt,
 *             watt, ms - alles, was sich im Sekundentakt aendert und nach
 *             einem Ausfall nicht stehenbleiben darf
 *   Leben     summe/alter
 *
 * Der Ladezustand steht bewusst bei den Zustaenden: er faellt nicht ins
 * Bodenlose, wenn der Dienst haengt, und Loxone soll ihn nach einem Neustart
 * sofort haben. Die LEISTUNG daneben darf genau das nicht.
 *
 * Ein Thema OHNE Eintrag geht fluechtig. Das ist die sichere Richtung: ein
 * nicht zurueckbehaltener Zustand ist unbequem, ein zurueckbehaltener Wert,
 * an den niemand gedacht hat, bleibt fuer immer im Broker stehen.
 */
function zd_mqtt_retain($thema)
{
    /* Geraetenummer und Seriennummer heraus - die Tabelle fuehrt die Themen
       in derselben Schreibweise wie zd_mqtt_themen() und der Reiter. */
    $t = preg_replace('#^geraet[0-9]+/#', 'geraetN/', (string) $thema);
    $t = preg_replace('#^geraetN/pack/[^/]+/#', 'geraetN/pack/<SN>/', $t);

    /* Tages-, Monats- und Jahreswerte werden allein durch die Uhr falsch
       und gehen deshalb fluechtig hinaus (Entscheidung 3, Befund M2,
       29.09.2026). Bis 0.9.27 retained: starb der Dienst vor Mitternacht,
       lieferte der Broker nach einem Neustart den gestrigen "heute"-Wert. */
    if (zd_mqtt_zeitwert($t)) {
        return false;
    }

    static $tab = null;
    if ($tab === null) {
        $tab = array();
        foreach (array(
            'geraete',
            'geraetN/soc', 'geraetN/soc_min', 'geraetN/soc_max',
            'geraetN/grenze_aus', 'geraetN/grenze_ein', 'geraetN/acmodus',
            'geraetN/soll', 'geraetN/sollok',
            'geraetN/packs',
            'geraetN/energie/gesamt/laden', 'geraetN/energie/gesamt/entladen',
            'geraetN/energie/wirkungsgrad', 'geraetN/energie/zyklen',
            'geraetN/pack/<SN>/soc',
            'summe/soc', 'summe/kapaz', 'summe/restkwh',
        ) as $x) { $tab[$x] = true; }
    }
    return isset($tab[$t]);
}

/**
 * Ist das eine Aussage des DIENSTES ueber sich selbst?
 *
 * ok        "mindestens ein Geraet hat frische Daten" - aus der eigenen
 *           Empfangsmarke gerechnet (zd_abbilden(), bin/zendure_dienst.php)
 * online    je Geraet: "die EIGENEN letzten Daten sind juenger als die
 *           Frist" - der Dienst schliesst es aus seinem eigenen Abruf, das
 *           Geraet meldet es nicht
 *
 * Beide gingen von 0.9.19 bis 0.9.25 retained hinaus (in den Archiven
 * 0.9.19-0.9.25 gleich). Stirbt der Dienst, bliebe die 1 stehen, und nach
 * einem Neustart von Broker oder Gateway laese Loxone "in Ordnung" von einem
 * Dienst, der nicht mehr laeuft. Entscheidung des Hausherrn vom 18./19.09.2026
 * (Regeln/07, Abschnitt 3): jede Aussage des Dienstes ueber sich selbst ist
 * nie retained; Liste Bestand-2026-09-18/klasse-E/Dienstzustand-retained_
 * 2026-09-19.md. Der Altwert wird einmal abgeraeumt
 * (zd_mqtt_altlast_pruefen()), die Deinstallation leert alles
 * (zd_mqtt_leeren()).
 */
function zd_mqtt_dienstaussage($thema)
{
    $t = preg_replace('#^geraet[0-9]+/#', 'geraetN/', (string) $thema);
    return $t === 'ok' || $t === 'geraetN/online';
}

/**
 * Ein Wert mit Zeitbezug: energie/heute|monat|jahr/<feld>. Bis 0.9.27
 * retained, seit 0.9.28 fluechtig (Befund M2); der Altwert wird wie bei den
 * Dienstaussagen einmal abgeraeumt (zd_mqtt_altlast_pruefen()).
 */
function zd_mqtt_zeitwert($thema)
{
    $t = preg_replace('#^geraet[0-9]+/#', 'geraetN/', (string) $thema);
    return (bool) preg_match('#^geraetN/energie/(heute|monat|jahr)/#', $t);
}

/** Das Befehlswort fuer das Gateway V1 - EINE Stelle fuer Senden und Pruefzeile (M10). */
function zd_mqtt_befehlswort($thema)
{
    return zd_mqtt_retain($thema) ? 'retain' : 'publish';
}

/** Ging dieses Thema in irgendeiner veroeffentlichten Fassung retained hinaus? */
function zd_mqtt_je_retained($thema)
{
    return zd_mqtt_retain($thema) || zd_mqtt_dienstaussage($thema) || zd_mqtt_zeitwert($thema);
}

/* ---------------- Liste der retained gesendeten Themen (Befund M4) ----------------
 *
 * Bis 0.9.27 blieben die Themen eines entfernten Geraets (geraet2/* nach dem
 * Herausnehmen von Geraet 1: 26 Themen) und eines getauschten Akkupacks
 * (pack/<alteSN>/soc) fuer immer im Broker, auch nach der Deinstallation.
 * Jetzt fuehrt zd_mqtt_senden() jedes retained gesendete Thema (mit Praefix)
 * in dieser Liste. Sie liegt im Bestandsordner und uebersteht ein Update;
 * der Dienst raeumt ab, was nicht mehr entsteht, die Deinstallation liest
 * dieselbe Liste. */
function zd_mqtt_liste_datei()
{
    return zd_paths()['bestand'] . '/mqtt_retained.json';
}

function zd_mqtt_liste_doc()
{
    $d = zd_json_lesen(zd_mqtt_liste_datei());
    $t = isset($d['themen']) && is_array($d['themen']) ? $d['themen'] : array();
    return array('themen' => array_values(array_filter($t, 'is_string')),
                 'versuch' => isset($d['versuch']) ? (int) $d['versuch'] : 0);
}

function zd_mqtt_liste_lesen()
{
    return zd_mqtt_liste_doc()['themen'];
}

function zd_mqtt_liste_merken(array $themen)
{
    $d = zd_mqtt_liste_doc();
    $neu = array_values(array_unique(array_merge($d['themen'], $themen)));
    sort($neu);
    $alt = $d['themen'];
    sort($alt);
    if ($neu !== $alt) {
        $d['themen'] = $neu;
        zd_json_schreiben(zd_mqtt_liste_datei(), $d);
    }
}

/** Welche retained Themen kann die jetzige Einrichtung ueberhaupt erzeugen? */
function zd_mqtt_soll_retained(array $werte, $praefix)
{
    $soll = array();
    foreach (array_keys(zd_mqtt_themen()) as $st) {
        if (strpos($st, '<') !== false || !zd_mqtt_retain($st)) {
            continue;
        }
        if (strncmp($st, 'geraetN/', 8) === 0) {
            foreach (array_keys($werte) as $nr) {
                $soll[$praefix . '/geraet' . (int) $nr . '/' . substr($st, 8)] = true;
            }
        } else {
            $soll[$praefix . '/' . $st] = true;
        }
    }
    foreach ($werte as $nr => $w) {
        $pl = (is_array($w) && isset($w['packliste']) && is_array($w['packliste'])) ? $w['packliste'] : array();
        foreach (array_keys($pl) as $sn) {
            $soll[$praefix . '/geraet' . (int) $nr . '/pack/' . zd_mqtt_thema_teil($sn) . '/soc'] = true;
        }
    }
    return $soll;
}

/**
 * Themen der Liste, die nicht mehr entstehen, abraeumen und nachlesen.
 *
 * Leere retain-Nutzlast ueber den UDP-Eingang (dieselbe Form wie
 * zd_mqtt_leeren()), 5 ms zwischen den Datagrammen, danach den Broker
 * fragen. Aus der Liste faellt nur, was der Broker als fort bestaetigt; ist
 * er nicht zu fragen, bleibt die Liste stehen. Hoechstens ein Versuch je
 * 300 s. Rueckgabe: Zahl der bestaetigt abgeraeumten Themen.
 */
function zd_mqtt_verwaiste_raeumen(array $soll)
{
    $d = zd_mqtt_liste_doc();
    $weg = array();
    foreach ($d['themen'] as $t) {
        if (!isset($soll[$t])) {
            $weg[] = $t;
        }
    }
    if (!$weg || time() - $d['versuch'] < 300) {
        return 0;
    }
    $z = zd_mqtt_zustand();
    if (!$z['udpport']) {
        return 0;
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        return 0;
    }
    foreach ($weg as $i => $t) {
        if ($i > 0) {
            usleep(5000);
        }
        $m = 'retain ' . $t . ' ';
        @socket_sendto($s, $m, strlen($m), 0, '127.0.0.1', $z['udpport']);
    }
    socket_close($s);
    usleep(300000);
    $f = zd_mqtt_behalten_fragen($weg);
    $d['versuch'] = time();
    $fort = 0;
    if ($f['lage'] === 'ok') {
        $geraeumt = array_values(array_diff($weg, array_keys($f['belegt'])));
        $fort = count($geraeumt);
        $d['themen'] = array_values(array_diff($d['themen'], $geraeumt));
        zd_log('MQTT: ' . $fort . ' zurueckbehaltene Themen, die nicht mehr entstehen (entferntes '
            . 'Geraet, getauschter Akkupack, anderes Praefix), abgeraeumt - vom Broker bestaetigt'
            . ($f['belegt'] ? '; noch belegt: ' . implode(', ', array_slice(array_keys($f['belegt']), 0, 5)) : '')
            . '.');
    } else {
        zd_log_gebremst('mqtt_verwaist', 'MQTT: ' . count($weg) . ' Themen, die nicht mehr entstehen, '
            . 'mit leerer Nutzlast gesendet - der Broker liess sich nicht befragen, die Liste bleibt '
            . 'stehen, neuer Versuch in 300 s.');
    }
    zd_json_schreiben(zd_mqtt_liste_datei(), $d);
    return $fort;
}

/**
 * Das Lebenszeichen (Befund M1, 29.09.2026; Regeln/07 Abschnitt 3): ts und
 * zaehler gehen in jedem Durchgang am Aenderungsfilter vorbei hinaus,
 * publish, hoechstens alle 30 s. Bis 0.9.27 gab es ueber MQTT keines - nachts
 * war ein toter Dienst von einem lebenden nicht zu unterscheiden.
 */
function zd_mqtt_lebenszeichen($ts, $zaehler)
{
    return array('status/ts' => (int) $ts, 'status/zaehler' => (int) $zaehler);
}

/**
 * Die -1-Regel (Nachtrag 0.9.38, 29.10.2026, Auftrag des Hausherrn; Hausform wie BYD 0.9.29 und Weissware
 * 0.9.44): welcher Fehlwert geht fuer ein Thema OHNE Aussage hinaus? Rueckgabe -1, -999 oder null ("kein
 * Fehlwert" - das Thema verhaelt sich wie bisher).
 *
 *   Zustand (zd_mqtt_retain())    null: zd_mqtt_senden() macht daraus "-" retained (Entscheidung 5, Regeln/07
 *                                 "ein leerer Zustand geht als - hinaus") - soc, soll, sollok, Grenzen,
 *                                 acmodus, packs, Zaehlerstaende, pack/<SN>/soc, summe/soc|kapaz|restkwh
 *   temp, pack/<SN>/temp          -999: -1 Grad ist eine echte Temperatur (Hausform -999 wie haus/klima)
 *   batp, summe/batp, pack/watt   null: vorzeichenbehaftet (batp positiv = laedt, negativ = entlaedt; das
 *                                 Vorzeichen der Packleistung ist nicht belegt) - -1 W ist eine echte
 *                                 Leistung. Sie gehen ohne Aussage wie bisher gar nicht hinaus (so wie
 *                                 BREITE/LAENGE bei BYD 0.9.29); ob sie gelten, sagen online und ok.
 *   jeder andere Messwert         -1: pv, haus, netz, laden, entladen, dvolt, ms, pack/<SN>/volt|dvolt,
 *                                 summe/pv|haus|netz (alle ohne Vorzeichen)
 *
 * Bis 0.9.37 ging ein Messwert ohne Aussage gar nicht hinaus - in Loxone stand der letzte Wert und sah gemessen
 * aus. Ein Geraet, das nicht ok ist, sendet weiter keine Messwerte (M7, auch keine -1): das sagt online.
 * Gilt nur fuer MQTT; das Abbild loxone.json (HTTP-Statuszeile) traegt weiter null bzw. den Strich.
 */
function zd_mqtt_fehlwert($thema)
{
    if (zd_mqtt_retain($thema)) {
        return null;
    }
    $t = preg_replace('#^geraet[0-9]+/#', 'geraetN/', (string) $thema);
    $t = preg_replace('#^geraetN/pack/[^/]+/#', 'geraetN/pack/<SN>/', $t);
    if ($t === 'geraetN/temp' || $t === 'geraetN/pack/<SN>/temp') {
        return -999;
    }
    if (in_array($t, array('geraetN/batp', 'summe/batp', 'geraetN/pack/<SN>/watt'), true)) {
        return null;
    }
    return -1;
}

/** Ein Wert fuer MQTT nach der -1-Regel: mit Aussage unveraendert, ohne Aussage der Fehlwert (zd_mqtt_fehlwert()). */
function zd_mqtt_messwert($thema, $v)
{
    if ($v !== null && zd_mqtt_wert_saeubern($v) !== '') {
        return $v;
    }
    $f = zd_mqtt_fehlwert($thema);
    return $f === null ? $v : $f;
}

/**
 * Die Paare eines Durchgangs (seit 0.9.28 hier statt im Dienst, damit der
 * Reiter Test dieselbe Funktion gegen die Themenliste halten kann - M10).
 *
 * M7: Ein Geraet, das nicht ok ist, meldet nur online (Regeln/07 "bei einer
 * Stoerung nur das Signal"). Bis 0.9.27 gingen die Messwerte des letzten
 * Kontakts bei jeder Auffrischung neu hinaus (gemessen: pv 800, haus 300
 * neben online 0). Soll, Quittung und die Energiezaehler rechnet das Plugin
 * selbst; sie gehen weiter.
 *
 * M3: Summenthemen, die frueher gesendet wurden, bekommen bei nur noch einem
 * Geraet "keine Aussage" (null, in zd_mqtt_senden() zu "-").
 *
 * -1-Regel (Nachtrag 0.9.38, 29.10.2026): zum Schluss geht jeder Wert durch zd_mqtt_messwert() - ein
 * Messwert ohne Aussage wird -1 (Temperatur -999), ein Zustand bleibt null ("-"). Das Abbild $werte und damit
 * die HTTP-Statuszeile bleiben unberuehrt.
 */
function zd_mqtt_paare(array $werte, array $geraete, array $cfg, $ok, $praefix)
{
    $paare = array('ok' => (int) $ok, 'geraete' => count($werte));
    foreach ($werte as $nr => $w) {
        if (!empty($w['ok'])) {
            foreach (array('soc', 'pv', 'haus', 'netz', 'batp', 'laden', 'entladen',
                           'grenze_aus', 'grenze_ein', 'soc_min', 'soc_max', 'acmodus',
                           'packs', 'dvolt', 'temp') as $feld) {
                $paare['geraet' . $nr . '/' . $feld] = isset($w[$feld]) ? $w[$feld] : null;
            }
        }
        $paare['geraet' . $nr . '/soll'] = isset($w['soll']) ? $w['soll'] : null;
        $paare['geraet' . $nr . '/online'] = !empty($w['ok']) ? 1 : 0;
        // null heisst "keine Aussage"; fuer einen Zustand wird daraus "-".
        $paare['geraet' . $nr . '/sollok'] = isset($w['sollok']) ? $w['sollok'] : null;
        if (!empty($w['ok'])) {
            $paare['geraet' . $nr . '/ms'] = isset($w['ms']) ? $w['ms'] : null;
        }
        // Energie in kWh: was Loxone und der Energiefluss-Monitor wollen.
        if (!empty($cfg['energie_ein'])) {
            foreach (array('tag' => 'heute', 'monat' => 'monat', 'jahr' => 'jahr') as $zr => $name) {
                foreach (zd_energie_summe($nr, $zr) as $ef => $wh) {
                    $paare['geraet' . $nr . '/energie/' . $name . '/' . $ef] = round($wh / 1000, 3);
                }
            }
            $kz = zd_energie_kennzahlen($nr, isset($geraete[(int) $nr]) ? $geraete[(int) $nr] : array());
            $paare['geraet' . $nr . '/energie/gesamt/laden'] = round($kz['geladen_wh'] / 1000, 3);
            $paare['geraet' . $nr . '/energie/gesamt/entladen'] = round($kz['entladen_wh'] / 1000, 3);
            $paare['geraet' . $nr . '/energie/wirkungsgrad'] = $kz['wirkungsgrad'];
            $paare['geraet' . $nr . '/energie/zyklen'] = $kz['zyklen'];
        }
        if (!empty($w['ok']) && isset($w['packliste']) && is_array($w['packliste'])) {
            foreach ($w['packliste'] as $sn => $pk) {
                /* Die Seriennummer kommt aus packData[].sn, also vom Geraet -
                 * zd_mqtt_thema_teil() saeubert sie (gemessen an 0.9.8). */
                $skenn = zd_mqtt_thema_teil($sn);
                foreach (array('soc', 'volt', 'dvolt', 'temp', 'watt') as $feld) {
                    $paare['geraet' . $nr . '/pack/' . $skenn . '/' . $feld] = isset($pk[$feld]) ? $pk[$feld] : null;
                }
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
    } else {
        $liste = zd_mqtt_liste_lesen();
        foreach (array('soc', 'kapaz', 'restkwh') as $sf) {
            if (in_array($praefix . '/summe/' . $sf, $liste, true)) {
                $paare['summe/' . $sf] = null;
            }
        }
    }
    // -1-Regel (Nachtrag 0.9.38): Messwert ohne Aussage -> -1 bzw. -999, Zustand bleibt null ("-").
    foreach ($paare as $k => $v) {
        $paare[$k] = zd_mqtt_messwert($k, $v);
    }
    return $paare;
}

/**
 * Die Abo-Datei des MQTT-Gateways (Befund M9, 29.09.2026; Regeln/07 seit
 * 17.09.2026): config/plugins/<ordner>/mqtt_subscriptions.cfg mit
 * "<praefix>/#". Das Gateway V1 liest sie selbst (am Geraet belegt an
 * Midea2Lox). Geschrieben nur, wenn sie abweicht, mit Protokollzeile.
 * Bauform eb_abo_datei() aus Einspeisebremse 0.9.28. Rueckgabe:
 * array(Pfad, traegt das Abo).
 */
function zd_abo_datei($praefix, $schreiben = false)
{
    $p = zd_paths();
    $pfad = $p['configdir'] . '/mqtt_subscriptions.cfg';
    $soll = trim((string) $praefix, '/') . '/#';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $da = in_array($soll, array_map('trim', preg_split('/\r?\n/', $roh)), true);
    if ($schreiben && $roh !== $soll . "\n" && is_dir($p['configdir'])) {
        if (@file_put_contents($pfad, $soll . "\n") === strlen($soll) + 1) {
            @chmod($pfad, 0644);
            zd_log('Gateway-Abo gesetzt: ' . $soll . ' (' . $pfad . ').');
            $da = true;
        }
    }
    return array($pfad, $da);
}

/**
 * Den Broker der Anlage fragen, welche dieser Themen zurueckbehalten stehen.
 *
 * Rueckgabe: array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => wert))
 *   ok         der Broker hat das Abonnement bestaetigt; was nicht unter
 *              'belegt' steht, steht nicht zurueckbehalten da
 *   unbekannt  keine Wurzel, kein Mqtt-Abschnitt, keine Verbindung,
 *              Anmeldung abgewiesen oder keine Bestaetigung
 *
 * Warum fragen: gesendet wird ueber den UDP-Eingang des Gateways, und dort
 * meldet sendto() auch fuer ein verworfenes Datagramm Erfolg. Am Geraet
 * gemessen (Regeln/07, "Ein Absender merkt nichts davon", 19.09.2026): wer
 * nach einem einzigen Senden einen Merker setzt, haelt die Sache fuer
 * erledigt, waehrend der Altwert weiter im Broker steht.
 *
 * MQTT 3.1.1 von Hand - CONNECT, SUBSCRIBE (QoS 0), DISCONNECT -, ohne fremde
 * Bibliothek; uebernommen aus Spotpreis-Tibber 0.9.18
 * (tb_mqtt_behalten_fragen), erweitert auf mehrere Themen in EINEM
 * Abonnement. Die Anmeldung nimmt Brokeruser/Brokerpass aus der general.json
 * (Regeln/07, Abschnitt 2); das Kennwort steht nur im CONNECT-Paket, nie in
 * einem Protokoll und nie auf einer Kommandozeile.
 */
function zd_mqtt_behalten_fragen(array $themen)
{
    $aus = array('lage' => 'unbekannt', 'belegt' => array());
    $soll = array();
    foreach ($themen as $t) {
        if ((string) $t !== '') {
            $soll[(string) $t] = true;
        }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $m = zd_mqtt_zustand();
    if (!$m['gefunden']) {
        return $aus;
    }
    $host = trim((string) $m['broker']);
    if ($host === '' || $host === 'localhost') {
        $host = '127.0.0.1';
    }
    $port = (int) $m['brokerport'];
    if ($port <= 0 || $port > 65535) {
        $port = 1883;
    }
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) {
        return $aus;
    }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        /* bis 0.9.39 ohne Frist: ein Broker, der ein Paket ankuendigt und nur tropfenweise liefert, hielt den Leser fest. Schweigt er ganz, greift schon stream_set_timeout(). */
        $lies_bis = microtime(true) + 5.0;
        while (strlen($d) < $n) {
            if (microtime(true) >= $lies_bis) { return null; }
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $benutzer = (string) $m['user'];
    $kennwort = (string) $m['pw'];
    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('zdrueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
        if ($kennwort !== '') { $flags |= 0x40; }
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
        $ack = $paket();
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $sub = pack('n', 1);
            foreach (array_keys($soll) as $t) {
                $sub .= $zk($t) . chr(0);
            }
            @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            $bestaetigt = false;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    /* Je Filter ein Rueckgabebyte hinter der Paketkennung, in der
                       Reihenfolge des SUBSCRIBE; ab 0x80 heisst abgelehnt (etwa
                       durch eine ACL). Danach schickt der Broker nichts - ungeprueft
                       hiesse das "nichts belegt", und der Merker laege auf einer
                       Antwort, die keine war (in WSL gemessen, Pruefung-ZendureSolarFlow-0.9.27,
                       Faelle S3, S4, S7, S9, S11). Bauart bw_mqtt_behalten_liste(),
                       Beschattungswaechter 0.9.21. */
                    $rc = (string) substr($pk[1], 2);
                    if (strlen($rc) !== count($soll)) { break; }
                    $abgelehnt = false;
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt = true;
                    // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    $ende = min($ende, microtime(true) + 1.0);
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                        $aus['belegt'][$t] = $wert;
                    }
                }
            }
            if ($bestaetigt) {
                $aus['lage'] = 'ok';
            }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche Dienstaussagen muessen in DIESEM Senden noch abgeraeumt werden?
 *
 * $themen: Themen ohne Praefix (ok, geraetN/online), die gleich gesendet
 * werden. Rueckgabe: array(thema => true) - fuer diese geht unmittelbar vor
 * dem gueltigen Wert die leere retain-Nutzlast hinaus (zd_mqtt_senden()).
 *
 * Je Thema, bis der Merker es fuehrt:
 *   Broker sagt "steht nicht da"  -> Merker, nichts abraeumen
 *   Broker sagt "steht da"        -> abraeumen, KEIN Merker - beim naechsten
 *                                    Senden wird wieder gefragt
 *   Broker nicht zu fragen        -> abraeumen, kein Merker
 * Der Merker liegt im Datenordner, eine Zeile "leer-bestaetigt <praefix>/
 * <thema>" je Thema. Eine Vorfassung hat nie so eine Zeile geschrieben, ein
 * anderes Praefix traegt andere Zeilen - beides gilt also nicht als
 * erledigt. purge_installation raeumt den Merker bei jedem Upgrade mit ab;
 * dann wird genau einmal nachgefragt.
 */
function zd_mqtt_altlast_pruefen($praefix, array $themen)
{
    $datei = zd_paths()['datadir'] . '/.mqtt_dienstaussage_geraeumt';
    /* Neue Kennung seit 0.9.28 (Befund M2): die Tages-, Monats- und
     * Jahreswerte kamen dazu, und kein Merker einer Vorfassung darf als
     * erledigt gelten. */
    $kennung = 'leer-bestaetigt-v2 ';
    $zeilen = is_file($datei) ? preg_split('/\r?\n/', (string) @file_get_contents($datei)) : array();
    $bestaetigt = array_flip(array_map('trim', $zeilen));
    $offen = array();
    foreach ($themen as $t) {
        $voll = $praefix . '/' . $t;
        if (isset($bestaetigt[$kennung . $voll])) {
            continue;
        }
        $offen[(string) $t] = $voll;
    }
    if (!$offen) {
        return array();
    }
    $f = zd_mqtt_behalten_fragen(array_values($offen));
    if ($f['lage'] !== 'ok') {
        zd_log_gebremst('mqtt_rueckfrage', 'MQTT: der Broker liess sich nicht befragen, ob unter '
            . implode(', ', $offen) . ' noch ein zurueckbehaltener Wert einer Vorfassung steht. '
            . 'Er wird deshalb bei jedem Senden geloescht, bis der Broker antwortet.');
        return array_fill_keys(array_keys($offen), true);
    }
    $raeumen = array();
    $neu = array();
    foreach ($offen as $t => $voll) {
        if (isset($f['belegt'][$voll])) {
            $raeumen[$t] = true;
            continue;
        }
        $neu[] = $kennung . $voll;
    }
    if ($neu) {
        $alt = array();
        foreach ($zeilen as $z) {
            $z = trim((string) $z);
            if (strpos($z, $kennung) === 0) {
                $alt[] = $z;
            }
        }
        $alle = array_values(array_unique(array_merge($alt, $neu)));
        if (@file_put_contents($datei, implode("\n", $alle) . "\n") === false) {
            zd_log_gebremst('mqtt_merker', 'MQTT: der Merker ' . $datei . ' liess sich nicht '
                . 'schreiben - der Broker wird beim naechsten Senden wieder gefragt.');
        } else {
            zd_log('MQTT: vom Broker bestaetigt, kein zurueckbehaltener Altwert mehr unter '
                . implode(', ', array_map(function ($z) use ($kennung) {
                    return substr($z, strlen($kennung));
                }, $neu)) . '. Diese Themen gehen fluechtig hinaus (ok/online seit 0.9.26, '
                . 'Tages-, Monats- und Jahreswerte seit 0.9.28).');
        }
    }
    return $raeumen;
}

/**
 * Die Themen (ohne Praefix), die die Deinstallation leert: jedes, das eine
 * veroeffentlichte Fassung je retained gesendet hat (zd_mqtt_je_retained()).
 *
 * Geraetenummern aus der Konfiguration (jeder Eintrag, auch ein
 * unvollstaendiger), aus dem letzten Abbild (loxone.json) und aus dem Merker
 * des Doppelt-senden-Filters (mqtt_letzte.json - er traegt jedes Thema, das
 * seit dem letzten Update hinausging). Seriennummern der Akkupacks aus dem
 * Abbild, Energiefelder aus zd_energiefelder().
 */
function zd_mqtt_leer_themen($praefix = null)
{
    $p = zd_paths();
    $cfg = zd_config(false);
    $nummern = array();
    $n = is_array($cfg['geraete']) ? count($cfg['geraete']) : 0;
    for ($i = 1; $i <= $n; $i++) {
        $nummern[$i] = true;
    }
    $packs = array();
    $lox = zd_json_lesen($p['datadir'] . '/loxone.json');
    if (isset($lox['geraete']) && is_array($lox['geraete'])) {
        foreach ($lox['geraete'] as $nr => $w) {
            $nr = (int) $nr;
            if ($nr <= 0) {
                continue;
            }
            $nummern[$nr] = true;
            if (is_array($w) && isset($w['packliste']) && is_array($w['packliste'])) {
                foreach (array_keys($w['packliste']) as $sn) {
                    $packs[$nr][] = zd_mqtt_thema_teil($sn);
                }
            }
        }
    }
    $themen = array();
    foreach (array_keys(zd_json_lesen($p['datadir'] . '/mqtt_letzte.json')) as $k) {
        $k = (string) $k;
        if ($k === '' || $k[0] === '_') {
            continue;
        }
        if (preg_match('#^geraet([0-9]+)/#', $k, $mm)) {
            $nummern[(int) $mm[1]] = true;
        }
        if (zd_mqtt_je_retained($k)) {
            $themen[$k] = true;
        }
    }
    foreach (array_keys(zd_mqtt_themen()) as $st) {
        if (strpos($st, '<') !== false) {
            continue;       // Platzhalter: unten eigens
        }
        if (strncmp($st, 'geraetN/', 8) === 0) {
            foreach (array_keys($nummern) as $nr) {
                $t = 'geraet' . $nr . '/' . substr($st, 8);
                if (zd_mqtt_je_retained($t)) {
                    $themen[$t] = true;
                }
            }
        } elseif (zd_mqtt_je_retained($st)) {
            $themen[$st] = true;
        }
    }
    foreach (array_keys($nummern) as $nr) {
        foreach (array('heute', 'monat', 'jahr') as $zr) {
            foreach (array_keys(zd_energiefelder()) as $ef) {
                $themen['geraet' . $nr . '/energie/' . $zr . '/' . $ef] = true;
            }
        }
        if (isset($packs[$nr])) {
            foreach ($packs[$nr] as $sk) {
                $themen['geraet' . $nr . '/pack/' . $sk . '/soc'] = true;
            }
        }
    }
    /* Dazu jedes Thema der Liste retained gesendeter Themen unter diesem
     * Praefix (Befund M4): bis 0.9.27 fehlten hier die Themen entfernter
     * Geraete und getauschter Akkupacks, und die Ausgabe "Broker bestaetigt"
     * stimmte nur fuer die Teilmenge. */
    if ($praefix !== null && $praefix !== '') {
        $vor = $praefix . '/';
        foreach (zd_mqtt_liste_lesen() as $voll) {
            if (strncmp($voll, $vor, strlen($vor)) === 0 && strlen($voll) > strlen($vor)) {
                $themen[substr($voll, strlen($vor))] = true;
            }
        }
    }
    ksort($themen);
    return array_keys($themen);
}

/**
 * Aus der Deinstallation: alle zurueckbehaltenen Themen der Linie leeren.
 *
 * Der Weg ist derselbe wie beim Senden - der UDP-Eingang des Gateways,
 * "retain <thema> " mit leerer Nutzlast (mqttgateway.pl, am Geraet belegt:
 * die leere Nachricht geht als Loeschung an den Broker, Regeln/07). Nach
 * jeder Runde wird der Broker gefragt (zd_mqtt_behalten_fragen()); nur was
 * dort noch steht, geht in der naechsten Runde wieder hinaus. Hoechstens
 * $runden Runden. Ist der Broker nicht zu fragen, gehen alle Runden hinaus,
 * und die Ausgabe sagt, dass nicht nachgelesen wurde.
 *
 * Schreibt kein Protokoll und legt nichts an (zd_config(false)).
 * Rueckgabe 0 geleert oder nicht nachpruefbar, 1 es steht noch etwas bzw.
 * Senden gescheitert, 2 nicht moeglich.
 */
function zd_mqtt_leeren($runden = 3, $pause = 1.0, $praefix = null)
{
    $cfg = zd_config(false);
    /* $praefix: seit 0.9.28 fuer das Speichern im Reiter MQTT (alter Praefix
     * beim Wechsel, Abschalten; Befunde M5/M6). Ohne Angabe der eingestellte. */
    if ($praefix === null) {
        $praefix = (string) $cfg['mqtt_topic'];
    }
    $praefix = trim((string) $praefix, '/');
    if ($praefix === '') {
        $praefix = 'zendure';
    }
    if (preg_match('/[#+\s]/', $praefix)) {
        echo "<WARNING> MQTT: das Themenpraefix enthaelt einen Platzhalter oder ein "
           . "Leerzeichen - zurueckbehaltene Themen wurden nicht geleert.\n";
        return 2;
    }
    $z = zd_mqtt_zustand();
    if (!$z['udpport']) {
        echo "<INFO> MQTT: in general.json steht kein UDP-Eingangsport des Gateways - "
           . "zurueckbehaltene Themen unter " . $praefix . "/ wurden nicht geleert.\n";
        return 2;
    }
    $offen = array();
    foreach (zd_mqtt_leer_themen($praefix) as $t) {
        $offen[] = $praefix . '/' . $t;
    }
    $n = count($offen);
    $strom = @stream_socket_client('udp://127.0.0.1:' . (int) $z['udpport'], $errno, $errstr, 2);
    if (!$strom) {
        echo "<WARNING> MQTT: der UDP-Eingang des Gateways war nicht erreichbar - "
           . "zurueckbehaltene Themen unter " . $praefix . "/ wurden nicht geleert.\n";
        return 1;
    }
    $nachgelesen = false;
    $datagramme = 0;
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) {
            usleep((int) ($pause * 1000000));
        }
        foreach ($offen as $zd_i => $t) {
            // 5 ms zwischen den Datagrammen (Befund M8).
            if ($zd_i > 0) {
                usleep(5000);
            }
            // Ein Leerzeichen hinter dem Thema, sonst keine Nutzlast: die
            // Form, die das Gateway als Loeschung liest.
            @fwrite($strom, 'retain ' . $t . ' ');
            $datagramme++;
        }
        usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
        $f = zd_mqtt_behalten_fragen($offen);
        if ($f['lage'] === 'ok') {
            $nachgelesen = true;
            $offen = array_keys($f['belegt']);
        } else {
            $nachgelesen = false;
        }
    }
    fclose($strom);
    echo "<INFO> MQTT: " . $n . " zurueckbehaltene Themen unter " . $praefix . "/ mit leerer "
       . "Nutzlast an den UDP-Eingang " . (int) $z['udpport'] . " des Gateways gesendet ("
       . $datagramme . " Datagramme).\n";
    if ($nachgelesen && !$offen) {
        echo "<OK> MQTT: der Broker bestaetigt: keines der " . $n . " Themen steht mehr zurueckbehalten.\n";
        return 0;
    }
    if ($nachgelesen) {
        echo "<WARNING> MQTT: " . count($offen) . " Themen stehen noch zurueckbehalten im Broker ("
           . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
           . "). Von Hand: mosquitto_pub -r -n -t <thema>\n";
        return 1;
    }
    echo "<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der UDP-Eingang "
       . "verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit "
       . "mosquitto_pub -r -n -t <thema> von Hand loeschen.\n";
    return 0;
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
    /* Dienstaussagen (ok, geraetN/online) gingen bis 0.9.25 retained hinaus.
     * Ein publish ersetzt einen zurueckbehaltenen Wert im Broker NICHT; der
     * Altwert wird deshalb abgeraeumt, bis der Broker bestaetigt, dass er
     * fort ist (zd_mqtt_altlast_pruefen()). */
    $zd_kand = array();
    foreach ($paare as $k => $v) {
        if ((zd_mqtt_dienstaussage($k) || zd_mqtt_zeitwert($k)) && $v !== null && $v !== ''
            && zd_mqtt_wert_saeubern($v) !== '') {
            $zd_kand[] = (string) $k;
        }
    }
    $raeumen = $zd_kand ? zd_mqtt_altlast_pruefen($praefix, $zd_kand) : array();
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        zd_log_gebremst('mqtt_socket', 'MQTT: Socket nicht moeglich.');
        return false;
    }
    $zd_n = 0;
    $zd_ret = array();
    foreach ($paare as $k => $v) {
        /* Ein retained Zustand ohne Aussage geht als "-" retained hinaus -
         * nie als leere Nutzlast und nie als stehenbleibender Altwert
         * (Entscheidung 5 vom 29.09.2026, Befund M3). Bis 0.9.27 wurde null
         * ausgelassen: nach dem Rueckfall standen soll=600 und sollok=1 fuer
         * immer im Broker, summe/soc blieb bei schweigendem Geraet auf 55. */
        if (zd_mqtt_retain($k) && ($v === null || zd_mqtt_wert_saeubern($v) === '')) {
            $v = '-';
        }
        if ($v === null || $v === '') {
            continue;   // ohne Aussage und ohne Fehlwert (batp, Packleistung - zd_mqtt_fehlwert()): nichts senden
        }
        /* GESAEUBERT wird vor der Frage "ist er leer?", nicht danach. Ein
           Wert aus lauter Leerzeichen faellt oben nicht durch ($v !== ''),
           kaeme hier aber als leere Nutzlast an - und eine leere Nutzlast
           LOESCHT ein zurueckbehaltenes Thema im Broker (mqttgateway.pl,
           sub udpin: "Delete $udptopic from memory because of empty
           message"). Ohne Retain war das folgenlos, mit Retain nimmt es dem
           Miniserver den Wert weg. */
        $wert = zd_mqtt_wert_saeubern($v);
        if ($wert === '') {
            continue;
        }
        /* Zustand oder Messwert - die Frage stellt zd_mqtt_retain() je
           THEMA. Ueber das Gateway V1 heisst der Befehl dann "retain" statt
           "publish" (mqttgateway.pl:293 und :354-357, am Geraet gemessen). */
        /* Die leere retain-Nutzlast loescht den zurueckbehaltenen Altwert
           (mqttgateway.pl:281, :311-315, :357, am Geraet 19.09.2026 belegt,
           Regeln/07). Sie geht UNMITTELBAR vor dem gueltigen Wert hinaus:
           wer das Thema abonniert hat, bekommt die Loeschung als leere
           Nachricht, und der naechste Wert steht gleich dahinter. Das ist die
           eine gewollte leere Nutzlast; sonst laesst diese Funktion keine
           durch. */
        /* 5 ms zwischen zwei Datagrammen (Befund M8, 29.09.2026): am Geraet
           kamen 90 Datagramme ohne Pause zu 0, 0 und 6 an (Regeln/07,
           Octopus-Eichung 13.09.); bis 0.9.27 gingen 98 in 19 ms hinaus. */
        if (isset($raeumen[$k])) {
            if ($zd_n++ > 0) {
                usleep(5000);
            }
            $leer = 'retain ' . $praefix . '/' . $k . ' ';
            @socket_sendto($s, $leer, strlen($leer), 0, '127.0.0.1', $z['udpport']);
        }
        if ($zd_n++ > 0) {
            usleep(5000);
        }
        $befehl = zd_mqtt_befehlswort($k);
        $msg = $befehl . ' ' . $praefix . '/' . $k . ' ' . $wert;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']);
        if ($befehl === 'retain') {
            $zd_ret[] = $praefix . '/' . $k;
        }
    }
    socket_close($s);
    if ($zd_ret) {
        zd_mqtt_liste_merken($zd_ret);
    }
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
    /* Hoechstens 3600 s (Befund M8): ein verlorener retained Zustand wird erst
     * bei der naechsten Auffrischung nachgeholt - bis 0.9.27 bis zu einem Tag. */
    $auffr = max(0, min(3600, (int) (isset($cfg['mqtt_auffrischung'])
                                     ? $cfg['mqtt_auffrischung'] : 300)));
    $datei = zd_paths()['datadir'] . '/mqtt_letzte.json';
    $alt = zd_json_lesen($datei);
    $letzte_voll = isset($alt['_voll']) ? (int) $alt['_voll'] : 0;
    /* Anderes Praefix als beim letzten Senden: Vollversand (M5, auch fuer
     * einen Wechsel, der nicht ueber den Reiter MQTT kam). */
    $anderes = isset($alt['_praefix']) && $alt['_praefix'] !== $praefix;
    $voll = ($auffr === 0 || $anderes || time() - $letzte_voll >= $auffr);

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
        $merken['_praefix'] = (string) $praefix;
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
        'status/ts'          => 'ZD_MQTT.STATUS_TS',
        'status/zaehler'     => 'ZD_MQTT.STATUS_ZAEHLER',
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
 *
 * Die lange Bedeutung steht in der Feldtabelle des Reiters "Einbindung in
 * Loxone". In die Vorlage geht der Kurztext ZD_KURZ.<FELD> (zd_status_kurz):
 * dort wird der Comment zum Kachelnamen, hoechstens 40 Zeichen samt Einheit
 * (Regeln/07; B-Nachzug 01.10.2026 - bis 0.9.30 standen dort Saetze bis 163
 * Zeichen).
 */
function zd_status_kurz($feld)
{
    return zd_vorlagentext('ZD_KURZ.' . $feld);
}

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

/**
 * Titel und Namen der Vorlagen - EINE Quelle (Welle Bild 9 VOLL, 0.9.35).
 *
 * Die Vorlagen (zd_vorlage(), zd_vorlage_energie(), zd_vorlage_befehle()) und die beiden
 * Baustein-Listen im Reiter "Einbindung in Loxone" nennen sie von hier. Bis 0.9.34 standen die
 * Zeichenketten fest in den Vorlagen; eine Liste, die sie abschreibt, laeuft nach der naechsten
 * Aenderung still auseinander.
 */
function zd_vorlage_titel($nummer = 1)
{
    return 'Zendure SolarFlow ' . (int) $nummer;
}

function zd_vorlage_energie_titel($nummer = 1, $zeitraum = 'tag')
{
    return 'Zendure Energie ' . (int) $nummer . ' ' . $zeitraum;
}

function zd_vorlage_befehle_titel($nummer = 1)
{
    return 'Zendure SolarFlow ' . (int) $nummer . ' Befehle';
}

/** Name eines Messwerts der Vorlage zd_vorlage(), z. B. ZENDURE_1_SOC. */
function zd_vi_name($nummer, $feld)
{
    return 'ZENDURE_' . (int) $nummer . '_' . $feld;
}

/** Name eines Energiewerts der Vorlage zd_vorlage_energie(), z. B. ZENDURE_1_ET_PV. */
function zd_ve_name($nummer, $zeitraum, $feld)
{
    $kurz = array('tag' => 'T', 'monat' => 'M', 'jahr' => 'J');
    return 'ZENDURE_' . (int) $nummer . '_E' . $kurz[$zeitraum] . '_' . $feld;
}

/** Name eines Steuerbefehls der Vorlage zd_vorlage_befehle(); der Sofortabruf gilt fuer alle Geraete. */
function zd_vq_name($nummer, $befehl)
{
    return $befehl === 'ABRUF' ? 'ZENDURE_CMD_ABRUF' : 'ZENDURE_' . (int) $nummer . '_CMD_' . $befehl;
}

/** Vorlage fuer den Import in Loxone Config. Rueckgabe: array(name, inhalt) */
function zd_vorlage($nummer = 1)
{
    $p = zd_paths();
    $cmds = array();
    foreach (zd_status_felder() as $feld => $info) {
        $bedeutung = zd_status_kurz($feld);   // Kurztext, siehe zd_status_felder()
        $cmds[] = array(
            'title'   => zd_vi_name($nummer, $feld),
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
            'title'   => zd_vorlage_titel($nummer),
            'address' => $adresse,
            'polling' => '60',
            'comment' => sprintf(zd_vorlagentext('LOX.VI_KOPF'), date('d.m.Y')),
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
    $cmds = array();
    foreach (zd_energie_felder() as $feld => $info) {
        $bedeutung = zd_vorlagentext($info[1]);
        $cmds[] = array(
            'title'   => zd_ve_name($nummer, $zeitraum, $feld),
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
            'title'   => zd_vorlage_energie_titel($nummer, $zeitraum),
            'address' => $adresse,
            'polling' => '300',
            'comment' => sprintf(zd_vorlagentext('LOX.VI_KOPF'), date('d.m.Y')),
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
            'title'   => zd_vq_name($nr, $c[0]),
            'comment' => zd_vorlagentext($c[3]),
            /* Energie-1 C1: von=loxone, damit die Schreiber-Wache den Miniserver von
             * anderen Schreibern unterscheidet - am Ende der Adresse, so wie man es an
             * einen bestehenden Befehl anhaengt. Eine alte Vorlage ohne den Zusatz geht
             * weiter und erscheint dort als "ohne Kennung". */
            'on'      => zd_endpunkt_pfad(array('aktion' => $c[1], 'geraet' => $nr) + $c[4] + array('von' => 'loxone'), true),
            'off'     => '',
            'analog'  => 1,
        );
    }
    // Digitalbefehle: Ein loest aus, ein Gegenstueck gibt es nicht.
    $cmds[] = array('title' => zd_vq_name($nr, 'AUS'), 'analog' => 0,
                    'comment' => zd_vorlagentext('LOX.VQ_AUS'),
                    'on' => zd_endpunkt_pfad(array('aktion' => 'aus', 'geraet' => $nr, 'von' => 'loxone')), 'off' => '');
    $cmds[] = array('title' => zd_vq_name($nr, 'ABRUF'), 'analog' => 0,
                    'comment' => zd_vorlagentext('LOX.VQ_ABRUF'),
                    'on' => zd_endpunkt_pfad(array('aktion' => 'abruf', 'von' => 'loxone')), 'off' => '');
    return array(
        'zendure_geraet' . $nr . '_befehle.xml',
        zd_xml_virtual_out(array(
            'title'   => zd_vorlage_befehle_titel($nr),
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
    /* Gruppe 43 (0.9.38): die Befehle ueber MQTT (Ausgang an den UDP-Eingang des Gateways), wenn eingeschaltet. */
    $zd_c43 = zd_config(false);
    if (!empty($zd_c43['befehle_mqtt_ein'])) {
        $v = zd_vorlage_bruecke();
        if ($v !== null) {
            $zip->addFromString($v[0], $v[1]);
        }
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
            'comment' => sprintf(zd_vorlagentext('LOX.VI_KOPF'), date('d.m.Y')),
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
        $home = zd_lbhome();
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
        // Wie zd_paths(): ohne festen Standardort dahinter (Fall B4).
        $home = zd_lbhome();
        $ordner = basename(dirname(__FILE__));
        /* Ohne Wurzel NUR die eigenen Sprachdateien. Bis 0.9.25 wurde der
         * Installationspfad auch mit leerer Wurzel gebildet und abgefragt -
         * aus dem ausgepackten Archiv also /templates/plugins/html/lang ab
         * der Laufwerkswurzel; lag dort etwas, zeigte die Oberflaeche fremde
         * Texte (in WSL gemessen, Pruefung-ZendureSolarFlow-0.9.26, Fall T1;
         * Bauart aus Weissware 0.9.30, ww_t()). */
        $pfad = '';
        if ($home !== '' && is_dir($home . '/templates/plugins/' . $ordner . '/lang')) {
            $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        }
        if ($pfad === '') {
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

/* ==================================================================
 * Gruppe 43 (0.9.38, 29.10.2026): Hausform - Annahmefunktion fuer HTTP und MQTT, Befehlsbruecke 1.0.2, Gateway V2,
 * Anbieter haus/speicher mit Anbieterregel, endpunkt_ein, Gesundheit
 *
 * Bauvorschrift wz_bild/GRUPPE43_BAUVORSCHRIFT.md Abschnitt 3 (Z1-Z6); Entscheidungen ENTSCHEIDUNGEN_GRUPPEN42_44.md
 * mit ENTWURF_GRUPPE43_TIBBER_ZENDURE.md (E2, E7-E11, B1-B3). Abschriften: Haus-Sitzung, Anbieter und Leser von
 * tb_haus_*() (Spotpreis Tibber 0.9.33, selbst Abschrift aWATTar/Marstek), V2-Pruefzeile von tb_v2_*(), Bruecke nach
 * marstek_bruecke_*() (Marstek Venus E 1.1.28), Gesundheit nach rn_gesund_*() (Renault NG 2.1.20). Die Anbieterregel
 * selbst steht im gemeinsamen Stueck haus_anbieter.php (hanb_*(), byte-gleich in allen Anbieter-Linien).
 * ================================================================== */

/** Der Name dieser Linie in haus/speicher/anbieter (E7: zendure, retained, immer). */
define('ZD_HAUS_NAME', 'zendure');

/** Das wirksame MQTT-Praefix - wie der Dienst es bildet (zd_abbild_schreiben(): trim '/', leer -> zendure). */
function zd_praefix($cfg = null)
{
    if (!is_array($cfg)) { $cfg = zd_config(false); }
    $p = trim((string) (isset($cfg['mqtt_topic']) ? $cfg['mqtt_topic'] : ''), '/');
    return $p === '' ? 'zendure' : $p;
}

/* ---------- Z3: eine Annahmefunktion fuer HTTP (index.php) und MQTT (Befehlsbruecke) ---------- */

/**
 * Jede Abweisung ins Protokoll, mit der Adresse des Anrufers, hoechstens eine Zeile je Minute und Grund; nie das
 * Token (Befund Code 8, 29.09.2026). Bis 0.9.37 in index.php - seit 0.9.38 hier, weil zd_befehl_annehmen() sie auch
 * fuer den MQTT-Weg braucht. Wortgleich; ohne REMOTE_ADDR (MQTT) steht "?" als Adresse.
 */
function zd_ep_abweisung($grund, $aktion = '')
{
    $wer = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9A-Fa-f:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '';
    $akt = is_string($aktion) ? substr(preg_replace('/[^a-z0-9_]/i', '', $aktion), 0, 20) : '';
    zd_log_gebremst('ep_' . strtolower($grund), 'Endpunkt: Anfrage von ' . ($wer === '' ? '?' : $wer)
        . ' abgewiesen, Grund ' . $grund . ($akt !== '' ? ', Aktion ' . $akt : '') . '.', 60);
}

/**
 * Einen schaltenden Befehl annehmen - der Schaltteil des Endpunkts index.php (bis 0.9.37 dort ab "Erst die Anfrage,
 * dann Freigabe und Dienst"), herausgezogen OHNE die Wirkung des HTTP-Wegs zu aendern: dieselbe Reihenfolge der
 * Pruefungen (Wert fehlt 400, Geraet 404, Freigabe 403, Dienst 503, Schreiber-Wache 409, Sofortabruf-Bremse,
 * Gleichwert-Unterdrueckung, Einreihen), dieselben Antwortzeilen, HTTP-Codes und Protokollzeilen (Probe: Antworten
 * byte-gleich mit 0.9.37). Der Endpunkt prueft vorher Token, Aktion und Muster; die Befehlsbruecke ruft dieselbe
 * Funktion mit Quelle 'mqtt' und von=mqtt (bin/befehle_zuordnung.php, E8).
 *   $aktion  laden|entladen|aus|socmin|socmax|grenzeaus|grenzeein|abruf
 *   $nr      Geraetenummer als Zeichenkette ohne fuehrende Null, $watt/$prozent '' oder Ziffern, $dry true|false,
 *   $von     Kennung der Schreiber-Wache ('' = ohne), $cfg Konfiguration (null = zd_config(false))
 * Rueckgabe array('http' => Code, 'aus' => vollstaendige Antwort, 'ok' => OK-Wert der ersten Zeile).
 */
function zd_befehl_annehmen($aktion, $nr, $watt, $prozent, $dry, $von, $cfg = null, $quelle = 'http')
{
    if (!is_array($cfg)) { $cfg = zd_config(false); }
    $aus = function ($http, $text) {
        return array('http' => (int) $http, 'aus' => (string) $text,
                     'ok' => preg_match('/;OK=([0-9])/', (string) $text, $m) ? (int) $m[1] : 0);
    };
    $ab = function ($grund) use ($aktion, $quelle) {
        if ($quelle === 'mqtt') {
            zd_log_gebremst('mq_' . strtolower($grund), 'Befehlsbruecke: Befehl ' . preg_replace('/[^a-z0-9_]/i', '', (string) $aktion)
                . ' abgewiesen, Grund ' . $grund . '.', 60);
        } else {
            zd_ep_abweisung($grund, $aktion);
        }
    };
    $befehl = array('aktion' => $aktion, 'geraet' => (int) $nr);
    if ($dry) {
        $befehl['trocken'] = 1;
    }
    if (in_array($aktion, array('laden', 'entladen', 'grenzeaus', 'grenzeein'), true)) {
        if ((string) $watt === '') {
            $ab('WATT_FEHLT');
            return $aus(400, "SET;OK=0;GRUND=WATT_FEHLT\n");
        }
        $befehl['watt'] = (int) $watt;
    } elseif (in_array($aktion, array('socmin', 'socmax'), true)) {
        if ((string) $prozent === '') {
            $ab('PROZENT_FEHLT');
            return $aus(400, "SET;OK=0;GRUND=PROZENT_FEHLT\n");
        }
        $befehl['prozent'] = (int) $prozent;
    }
    $ger = zd_geraete();
    if (!isset($ger[$nr])) {
        $ab('GERAET_UNBEKANNT');
        return $aus(404, sprintf("SET;OK=0;AKTION=%s;GRUND=GERAET_UNBEKANNT;N=%d\n", $aktion, count($ger))
            . sprintf("Geraet %d ist nicht eingerichtet (eingerichtet: %d) - es wurde nichts gesendet. Reiter Einstellungen.\n",
                      (int) $nr, count($ger)));
    }
    if ($aktion !== 'abruf' && !$dry && empty($cfg['steuerung_ein'])) {
        $ab('STEUERUNG_AUS');
        return $aus(403, "SET;OK=0;GRUND=STEUERUNG_AUS\n"
            . "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n");
    }
    if (zd_dienst_pid() === 0) {
        $ab('DIENST_LAEUFT_NICHT');
        return $aus(503, "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n"
            . "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n");
    }
    $wz = '';
    if (zd_wache_gilt($aktion)) {
        list($wab, $wz) = zd_wache_anwenden($ger[$nr], $aktion, $von, $dry);
        if ($wab) {
            $ab('FREMDSCHREIBER');
            return $aus(409, 'SET;OK=0;AKTION=' . $aktion . ';GRUND=FREMDSCHREIBER' . ($dry ? ';DRY=1' : '') . "\n"
                . "Dieser Schreiber steht nicht in der Liste der erlaubten Schreiber (Reiter Einstellungen, Schreiber-Wache) - es wurde nichts eingereiht.\n");
        }
    }
    if ($aktion === 'abruf') {
        $lox = zd_loxone();
        $abstand = max(10, (int) floor(max(5, (int) $cfg['intervall']) / 2));
        $warten = 0;
        $fh = @fopen(zd_paths()['datadir'] . '/.abruf_endpunkt', 'c+');
        if ($fh !== false) {
            if (@flock($fh, LOCK_EX)) {
                $letzt = (int) trim((string) stream_get_contents($fh));
                $basis = max($letzt, isset($lox['ts']) ? (int) $lox['ts'] : 0);
                $warten = $basis + $abstand - time();
                if ($warten <= 0) {
                    ftruncate($fh, 0);
                    rewind($fh);
                    fwrite($fh, (string) time());
                    fflush($fh);
                }
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        }
        if ($warten > 0) {
            return $aus(200, sprintf("SET;OK=1;AKTION=abruf;GRUND=ZU_FRUEH;WARTEN=%d;ALTER=%d\n", $warten, zd_alter())
                . 'Hoechstens ein Sofortabruf je ' . $abstand . " s - es gilt das vorhandene Abbild.\n");
        }
    }
    $gw_schl = zd_gleichwert_schluessel($befehl);
    $gw = null;
    $gw_merker = array();
    $gw_wert = zd_gleichwert_wert($befehl);
    if ($gw_schl !== '') {
        $gw = zd_gleichwert_oeffnen();
        if ($gw === false) {
            $ab('GLEICHWERT_MERKER');
            return $aus(503, zd_wache_zeile('SET;OK=0;AKTION=' . $aktion . ";GRUND=GLEICHWERT_MERKER\n", $wz)
                . "Der Merker der Gleichwert-Unterdrueckung laesst sich nicht oeffnen - es wurde nichts gesendet. Protokoll im Reiter Logdateien.\n");
        }
        $gw_merker = zd_gleichwert_lesen($gw);
        $seit = zd_gleichwert_seit($gw_merker, $gw_schl, $gw_wert);
        if ($seit >= 0) {
            zd_gleichwert_schliessen($gw, null);
            return $aus(200, zd_wache_zeile(sprintf("SET;OK=1;AKTION=%s;UNVERAENDERT=1;SEIT_S=%d;MELDUNG=Derselbe Wert ging vor %d s hinaus - nichts gesendet.\n",
                $aktion, $seit, $seit), $wz));
        }
    }
    list($erg, $meldung) = zd_befehl_absetzen($befehl);
    if ($gw !== null) {
        zd_gleichwert_schliessen($gw, zd_gleichwert_nachher($gw_merker, $gw_schl, $gw_wert, $erg === 1));
    }
    return $aus($erg === 0 ? 500 : 200, zd_wache_zeile(sprintf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $erg, $aktion,
        str_replace(array("\r", "\n", ';'), ' ', $meldung)), $wz));
}

/**
 * Das Ergebnis von zd_befehl_annehmen() fuer die Quittung der Befehlsbruecke: array(bool ok, string grund).
 * "angenommen" bei OK=1 (auch UNVERAENDERT=1 und abruf ZU_FRUEH); sonst GRUND der ersten Zeile (z. B.
 * GERAET_UNBEKANNT, STEUERUNG_AUS, FREMDSCHREIBER, DIENST_LAEUFT_NICHT) oder die Meldung des Dienstes (gekuerzt).
 */
function zd_befehl_quittung(array $e)
{
    $z = (string) strtok((string) (isset($e['aus']) ? $e['aus'] : ''), "\n");
    if (!empty($e['ok']) && (int) $e['ok'] === 1) {
        return array(true, '');
    }
    if (preg_match('/;GRUND=([A-Za-z0-9_]+)/', $z, $m)) {
        return array(false, $m[1]);
    }
    if (preg_match('/;OK=2;/', $z)) {
        return array(false, 'Dienst antwortet nicht rechtzeitig - der Befehl liegt in der Warteschlange');
    }
    if (preg_match('/;MELDUNG=(.*)$/', $z, $m)) {
        return array(false, substr(trim($m[1]), 0, 120));
    }
    return array(false, 'abgelehnt');
}

/**
 * Ist diese Datei ein Auftrag der Warteschlange? Nur <16 Hexziffern>.json - so benennt zd_befehl_absetzen() jeden
 * Auftrag (bin2hex(random_bytes(8))). Seit 0.9.38 liegen im selben Ordner data/plugins/<ordner>/befehle auch die
 * Dateien der Befehlsbruecke (zustand.json, waechter.json, mosquitto/ - Stamm 1.0.2, Pfad fest): bis 0.9.37 nahm der
 * Dienst JEDE *.json als Auftrag, las sie, loeschte sie und schrieb eine Fehlantwort (Befund G43-Z-B5).
 */
function zd_auftrag_datei($datei)
{
    return preg_match('/^[0-9a-f]{16}\.json\z/', basename((string) $datei)) === 1;
}

/* ---------- Z3: Befehlsbruecke (gemeinsames Modul, bin/befehlsbruecke.php) ---------- */

/** Der bin-Ordner mit der Befehlsbruecke: installiert <wurzel>/bin/plugins/<ordner>, im ausgepackten Archiv bin/ neben
 *  webfrontend/. '' = nicht vorhanden. Abschrift marstek_bin_dir(). */
function zd_bin_dir()
{
    $p = zd_paths();
    if ($p['home'] !== '') {
        $d = $p['home'] . '/bin/plugins/' . $p['plugin'];
        if (is_file($d . '/befehlsbruecke.php')) { return $d; }
    }
    $d = dirname(dirname(__DIR__)) . '/bin';
    return is_file($d . '/befehlsbruecke.php') ? $d : '';
}

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN) und die Zuordnung holen und pruefen. array(Tabelle|null, Fehler). */
function zd_befehle_tabelle()
{
    $bin = zd_bin_dir();
    if ($bin === '') { return array(null, 'bin/befehlsbruecke.php fehlt'); }
    if (!function_exists('bb_zuordnung_laden')) {
        if (!defined('BB_NUR_LADEN')) { define('BB_NUR_LADEN', true); }
        require_once $bin . '/befehlsbruecke.php';
    }
    list($z, $fehler) = bb_zuordnung_laden($bin . '/befehle_zuordnung.php');
    if ($z === null) { return array(null, $fehler); }
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) { return array(null, $fehl[0]); }
    return array($z, '');
}

/** Die Bruecke beim Speichern sofort starten bzw. anhalten (befehlsbruecke.sh start|stop); der Waechter im Minutentakt
 *  (cron/cron.01min) startet hoechstens alle 600 s neu. array(rc, Ausgabe). Abschrift marstek_bruecke_schalten(). */
function zd_bruecke_schalten($an)
{
    list($z, $fehler) = zd_befehle_tabelle();
    if ($z === null) { return array(1, $fehler); }
    $p = bb_pfade();
    if (!$p) { return array(1, 'keine LoxBerry-Installation erkannt'); }
    return bb_sh($p, $an ? 'start' : 'stop');
}

/** Die Befehlsliste aus bb_liste() - EINE Quelle fuer Reiter MQTT, Vorlage und Musterprojekt. array(Liste|null, Fehler). */
function zd_befehle_liste()
{
    list($z, $fehler) = zd_befehle_tabelle();
    if ($z === null) { return array(null, $fehler); }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/** Die Vorlage der MQTT-Befehle (eigener Ausgang an den UDP-Eingang des Gateways, bb_vorlage()). array(name, inhalt)|null. */
function zd_vorlage_bruecke()
{
    list($l) = zd_befehle_liste();
    if ($l === null) { return null; }
    $m = zd_mqtt_zustand();
    $port = (int) $m['udpport'];
    return array('VQ_zendure_mqtt.xml', bb_vorlage($l, zd_host(), $port > 0 ? $port : 11884));
}

/** Zustand der Bruecke fuer Reiter MQTT, Reiter Test und Healthcheck: array(true laeuft | false steht/defekt | null aus,
 *  Text). Abschrift marstek_bruecke_zustand(). */
function zd_bruecke_zustand()
{
    list($z, $fehler) = zd_befehle_tabelle();
    if ($z === null) {
        return array(false, sprintf(zd_t(zd_bin_dir() === '' ? 'BEFEHL.Z_FEHLT' : 'BEFEHL.Z_DEFEKT'), $fehler));
    }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) { return array(null, zd_t('BEFEHL.Z_AUS')); }
    if ($soll !== 0) { return array(false, sprintf(zd_t('BEFEHL.Z_DEFEKT'), $grund)); }
    $zu = bb_zustand_lesen();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : -1;
    if (!bb_dienste() || $alter < 0 || $alter > BB_WACHE_GRENZE || empty($zu['abo'])) {
        $warum = (is_array($zu) && !empty($zu['fehler'])) ? ' (' . $zu['fehler'] . ')' : '';
        return array(false, sprintf(zd_t('BEFEHL.Z_STEHT'), $warum));
    }
    $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
    $letzter = '';
    if (isset($zu['letzter']) && is_array($zu['letzter']) && isset($zu['letzter']['name'])) {
        $letzter = sprintf(zd_t('BEFEHL.Z_LETZTER'), (string) $zu['letzter']['name'],
            (string) (isset($zu['letzter']['ergebnis']) ? $zu['letzter']['ergebnis'] : ''),
            max(0, time() - (int) (isset($zu['letzter']['zeit']) ? $zu['letzter']['zeit'] : time())));
    }
    return array(true, sprintf(zd_t('BEFEHL.Z_LAEUFT'), date('d.m.Y H:i', (int) (isset($zu['start']) ? $zu['start'] : 0)),
        (int) (isset($zl['angenommen']) ? $zl['angenommen'] : 0), (int) (isset($zl['abgelehnt']) ? $zl['abgelehnt'] : 0),
        $letzter));
}

/* ---------- Z1: Gateway V2 - Namenstabelle, Hakenliste, Pruefzeile ---------- */

/** Der Name, den das MQTT-Gateway einem Thema gibt: "/", "%" und Leerzeichen werden "_" (Regeln/07 :69). */
function zd_gateway_name($thema)
{
    return str_replace(array('/', '%', ' '), '_', (string) $thema);
}

/**
 * Die Datenpunkte, die unter Gateway V2 anzuhaken sind - EINE Quelle fuer Reiter MQTT, Reiter Test, Healthcheck und
 * Messskript. Rueckgabe Liste von array('thema', 'name', 'noncached', 'bez'). Quelle ist zd_mqtt_themen() - dieselbe
 * Liste, die der Reiter Test gegen zd_mqtt_paare() haelt (M10) -, ausgerollt auf die eingetragenen Geraete:
 *   - geraetN/pack/<SN>/... NIE (Seriennummer im Namen - weder Hakenliste noch Vorgaben noch Musterprojekt, E/Z1)
 *   - summe/* nur ab zwei Geraeten (zd_mqtt_paare() sendet sie nur dann), energie/* nur mit energie_ein
 *   - status/ts nicht (Zeitstempel, aendert sich je Lauf - Regeln/07 :267)
 *   - status/befehle (Lebenszeichen der Befehlsbruecke) nur mit "Befehle ueber MQTT annehmen"
 * Noncached genau ok, status/zaehler und status/befehle.
 */
function zd_v2_haken($cfg = null, $geraete = null)
{
    if (!is_array($cfg)) { $cfg = zd_config(false); }
    if (!is_array($geraete)) { $geraete = zd_geraete(); }
    $pf = zd_praefix($cfg);
    $nrn = array_keys($geraete);
    sort($nrn);
    $nc = array('ok' => true, 'status/zaehler' => true, 'status/befehle' => true);
    $aus = array();
    $dazu = function ($t, $bez) use (&$aus, $pf, $nc) {
        $th = $pf . '/' . $t;
        $aus[] = array('thema' => $th, 'name' => zd_gateway_name($th), 'noncached' => isset($nc[$t]), 'bez' => $bez);
    };
    foreach (zd_mqtt_themen() as $t => $bez) {
        if ($t === 'status/ts' || strpos($t, '/pack/') !== false) { continue; }
        if (strpos($t, 'summe/') === 0 && count($nrn) < 2) { continue; }
        if (strpos($t, '/energie/') !== false && empty($cfg['energie_ein'])) { continue; }
        if (strpos($t, 'geraetN/') === 0) {
            foreach ($nrn as $n) {
                $r = 'geraet' . (int) $n . substr($t, 7);
                if (strpos($r, '<feld>') !== false) {
                    foreach (array_keys(zd_energiefelder()) as $f) { $dazu(str_replace('<feld>', $f, $r), $bez); }
                } else {
                    $dazu($r, $bez);
                }
            }
            continue;
        }
        $dazu($t, $bez);
    }
    if (!empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_ein'])) {
        $dazu('status/befehle', 'BEFEHL.T_LEBENSZEICHEN');
    }
    return $aus;
}

/** Die angehakten Datenpunkte aus subscriptions.json: Id => noncached; null = unlesbar. Abschrift tb_v2_abos(). */
function zd_v2_abos($daten)
{
    if (!is_array($daten)) { return null; }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) {
            $liste = $daten[$s];
            break;
        }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) { continue; }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) {
                $id = $e[$k];
                break;
            }
        }
        if ($id === '') { continue; }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    return $abo;
}

/** Soll gegen Ist: array(1|0, fehlt[], noncached_falsch[], da, soll). Abschrift tb_v2_haken_abgleich(). */
function zd_v2_haken_abgleich(array $soll, array $abo)
{
    $fehlt = array();
    $nc = array();
    $da = 0;
    foreach ($soll as $e) {
        if (!array_key_exists($e['thema'], $abo)) {
            $fehlt[] = $e['name'];
            continue;
        }
        $da++;
        if ((bool) $abo[$e['thema']] !== (bool) $e['noncached']) {
            $nc[] = $e['name'] . ($e['noncached'] ? ' (Noncached fehlt)' : ' (Noncached zu viel)');
        }
    }
    return array(($fehlt || $nc) ? 0 : 1, $fehlt, $nc, $da, count($soll));
}

/**
 * Einheitliche Pruefzeile "Gateway V2: sind alle Datenpunkte angehakt?": array(1|0|2, Text); 2 = grau (nicht
 * feststellbar, Gateway V1, leere Liste, noch nicht eingerichtet). Liest nur. Die Argumente nach $cfg nur fuer Proben.
 * "eingerichtet" heisst hier: ein Geraet eingetragen UND "Werte ueber MQTT" an (vorher kommt kein Wert).
 */
function zd_v2_haken_lage($cfg = null, $soll = null, $abo = false, $fassung = null, $eingerichtet = null)
{
    if (!is_array($cfg)) { $cfg = zd_config(false); }
    if ($fassung === null) {
        $g = zd_gateway_fassung();
        $fassung = !empty($g['gefunden']) ? (int) $g['fassung'] : 0;
    }
    if ((int) $fassung <= 0) { return array(2, zd_t('TEST.V2_UNKLAR')); }
    if ((int) $fassung < 2) { return array(2, zd_t('TEST.V2_V1')); }
    if ($soll === null) { $soll = zd_v2_haken($cfg); }
    if ($eingerichtet === null) { $eingerichtet = count(zd_geraete()) > 0 && !empty($cfg['mqtt_ein']); }
    if (!$eingerichtet) { return array(2, zd_t('TEST.V2_NICHT_EINGERICHTET')); }
    if (!$soll) { return array(2, zd_t('TEST.V2_LEER')); }
    if ($abo === false) {
        $p = zd_paths();
        $f = $p['home'] !== '' ? $p['home'] . '/config/system/subscriptions.json' : '';
        $abo = ($f !== '' && is_file($f)) ? zd_v2_abos(json_decode((string) @file_get_contents($f), true)) : null;
    }
    if ($abo === null) { return array(2, zd_t('TEST.V2_UNKLAR')); }
    list($ok, $fehlt, $nc, $da, $zahl) = zd_v2_haken_abgleich($soll, $abo);
    $txt = sprintf(zd_t('TEST.V2_ZAHL'), $da, $zahl);
    if ($fehlt) {
        $txt .= ' ' . sprintf(zd_t('TEST.V2_FEHLT'), implode(', ', array_slice($fehlt, 0, 12))
            . (count($fehlt) > 12 ? ' ...' : ''));
    }
    if ($nc) { $txt .= ' ' . sprintf(zd_t('TEST.V2_NC'), implode(', ', $nc)); }
    return array($ok, $txt);
}

/* ---------- Haus-Themen: eine kurze Sitzung am Broker ---------- */

/**
 * Eine kurze Sitzung am Broker des LoxBerry - Abschrift von tb_haus_sitzung() (Tibber 0.9.33, selbst Abschrift von
 * spot_haus_sitzung()/marstek_haus_sitzung()). EINE Funktion fuer den Anbieter (senden), den Knopf, das Abraeumen und
 * den Leser. Zugang aus general.json (Mqtt.Brokerhost/-port/-user/-pass) - NICHT der Broker der Geraete
 * (broker_host/-user dieser Konfiguration). Das Kennwort steht nur im CONNECT-Paket, nie in einem Protokoll und nie auf
 * einer Befehlszeile (Regeln/05). Client-ID 'zdhaus<pid>'.
 *   $senden  Thema => array(Wert, retain) - PUBLISH QoS 0; eine leere Nutzlast nur mit retain (Abraeumen)
 *   $lesen   genaue Themen; SUBSCRIBE QoS 0, SUBACK mit Rueckgabebyte-Pruefung
 *   $warten  Themen, die LIVE (Retain-Bit 0) gesehen sein muessen, bevor das Fenster endet
 *   $fenster Sekunden, hoechstens 66
 * Rueckgabe array('lage' => ok|kein_broker|abgelehnt|zeit, 'werte', 'live', 'gesendet').
 */
function zd_haus_sitzung(array $senden, array $lesen, array $warten, $fenster)
{
    $aus = array('lage' => 'kein_broker', 'werte' => array(), 'live' => array(), 'gesendet' => 0);
    $p = zd_paths();
    $gj = $p['home'] !== '' ? $p['home'] . '/config/system/general.json' : '';
    if ($gj === '' || !is_file($gj)) { return $aus; }
    $gen = json_decode((string) @file_get_contents($gj), true);
    if (!is_array($gen)) { return $aus; }
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $aus; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross]) && is_scalar($m[$gross])) { return (string) $m[$gross]; }
        return (isset($m[$klein]) && is_scalar($m[$klein])) ? (string) $m[$klein] : '';
    };
    $host = trim($hol('Brokerhost', 'brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport', 'brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser', 'brokeruser');
    $kennwort = $hol('Brokerpass', 'brokerpass');

    $soll = array();
    foreach ($lesen as $t) {
        $t = (string) $t;
        if ($t !== '' && strpbrk($t, '#+') === false) { $soll[$t] = true; }
    }
    $soll = array_slice(array_keys($soll), 0, 50);
    $warte = array();
    foreach ($warten as $t) {
        if (in_array((string) $t, $soll, true)) { $warte[(string) $t] = true; }
    }
    $fenster = max(0.0, min(66.0, (float) $fenster));

    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 1);
    $schreib = function ($daten) use ($s) {
        $daten = (string) $daten;
        return @fwrite($s, $daten) === strlen($daten);
    };
    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* bis 0.9.39 ohne Frist: ein Broker, der ein Paket ankuendigt und nur tropfenweise liefert, hielt den Leser ueber sein Fenster hinaus fest.
     * Schweigt der Broker ganz, greift schon stream_set_timeout(). */
    $lies_bis = microtime(true) + (float) $fenster + 5.0;
    $lies = function ($n) use ($s, $lies_bis) {
        $d = '';
        while (strlen($d) < $n) {
            if (microtime(true) >= $lies_bis) { return null; }
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };
    $merke = function ($pk) use (&$aus, $soll) {
        if (strlen($pk[1]) < 2) { return; }
        $tl = unpack('n', substr($pk[1], 0, 2));
        $t = substr($pk[1], 2, $tl[1]);
        $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
        if (!in_array($t, $soll, true)) { return; }
        $aus['werte'][$t] = (string) substr($pk[1], $versatz);
        if (!($pk[0] & 1)) { $aus['live'][$t] = true; }
    };

    $flags = 0x02;
    $nutz = $zk('zdhaus' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 60);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (!$schreib(chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz)) {
        fclose($s);
        return $aus;
    }
    $ack = $paket();
    if ($ack === null || ($ack[0] >> 4) !== 2 || strlen($ack[1]) < 2) {
        fclose($s);
        $aus['lage'] = 'zeit';
        return $aus;
    }
    if (ord($ack[1][1]) !== 0) {
        fclose($s);
        $aus['lage'] = 'abgelehnt';
        return $aus;
    }
    $lage = 'ok';
    $suback_zeit = microtime(true);
    if ($soll) {
        $sub = pack('n', 1);
        foreach ($soll as $t) { $sub .= $zk($t) . chr(0); }
        $schreib(chr(0x82) . $laenge(strlen($sub)) . $sub);
        $lage = 'zeit';
        $ende = microtime(true) + 3.0;
        while (microtime(true) < $ende) {
            $pk = $paket();
            if ($pk === null) { if (feof($s)) { break; } continue; }
            $art = $pk[0] >> 4;
            if ($art === 9) {
                $rc = (string) substr($pk[1], 2);
                if (strlen($rc) !== count($soll)) { break; }
                $lage = 'ok';
                for ($i = 0; $i < strlen($rc); $i++) {
                    if (ord($rc[$i]) >= 0x80) { $lage = 'abgelehnt'; }
                }
                break;
            } elseif ($art === 3) {
                $merke($pk);
            }
        }
        $suback_zeit = microtime(true);
    }
    if ($lage === 'ok') {
        foreach ($senden as $t => $wr) {
            $t = (string) $t;
            $w = (string) (is_array($wr) ? $wr[0] : $wr);
            $ret = is_array($wr) && !empty($wr[1]);
            if ($t === '' || strpbrk($t, "#+\0") !== false || ($w === '' && !$ret)) { continue; }
            $rumpf = $zk($t) . $w;
            if ($schreib(chr(0x30 | ($ret ? 1 : 0)) . $laenge(strlen($rumpf)) . $rumpf)) {
                $aus['gesendet']++;
            }
        }
    }
    if ($lage === 'ok' && $soll) {
        $offen = function () use (&$aus, $warte) {
            foreach (array_keys($warte) as $t) { if (empty($aus['live'][$t])) { return true; } }
            return false;
        };
        $ende = $warte ? $suback_zeit + max(1.0, $fenster) : $suback_zeit + 1.0;
        while (microtime(true) < $ende && ($offen() || !$warte)) {
            $pk = $paket();
            if ($pk === null) { if (feof($s)) { break; } continue; }
            if (($pk[0] >> 4) === 3) { $merke($pk); }
        }
    }
    $schreib(chr(0xE0) . chr(0));
    fclose($s);
    $aus['lage'] = $lage;
    return $aus;
}

/* ---------- Z2: Anbieter haus/speicher (Regeln/07 Nr. 15) mit Anbieterregel (E4/E7) ---------- */

/** Die eigenen Haus-Themen, Thema => retained. EINE Liste fuer Senden, Abraeumen und Reiter Test (fuenf retained:
 *  vier Werte + anbieter, E7; zaehler nie retained). */
function zd_haus_themen()
{
    return array('haus/speicher/soc' => true, 'haus/speicher/kwh' => true, 'haus/speicher/leistung' => true,
                 'haus/speicher/richtung' => true, 'haus/speicher/anbieter' => true, 'haus/speicher/zaehler' => false);
}

function zd_haus_retained()
{
    return array_keys(array_filter(zd_haus_themen()));
}

/** Eine Zahl fuer haus/speicher: Punkt, hoechstens drei Nachkommastellen, nie Exponent (Abschrift tb_haus_zahl()). */
function zd_haus_zahl($v)
{
    $s = sprintf('%.3F', round((float) $v, 3));
    if (strpos($s, '.') !== false) { $s = rtrim(rtrim($s, '0'), '.'); }
    return ($s === '-0' || $s === '') ? '0' : $s;
}

/**
 * Die Werte der Haus-Themen (E7, Regeln/07 Nr. 15). Quelle: das Abbild des Dienstes ($werte wie zd_werte(), Nr =>
 * Werte je Geraet) und die Geraeteliste (Kapazitaet). Ohne Aussage alle vier -1: kein Geraet eingetragen, ein Geraet
 * antwortet nicht (ok 0) oder seine Messung ist aelter als 180 s, oder der Ladezustand fehlt.
 *   soc      ein Geraet: dessen soc (auch ohne eingetragene Kapazitaet); mehrere: nach Kapazitaet gewichtet wie
 *            summe/soc (zd_summe(), fehlt eine Kapazitaet -> -1)
 *   kwh      Restinhalt in kWh (zd_summe() restkwh; ohne Kapazitaet -1)
 *   leistung Betrag der Batterieleistung in W (Summe von batp, positiv = laden, R07 :291)
 *   richtung 1 laedt (> 20 W), 0 ruht, 2 entlaedt (< -20 W) - wie Marstek Venus E
 *   anbieter IMMER zendure (Anbieterregel E4: der Anspruch muss lesbar bleiben, auch ohne Werte)
 */
function zd_haus_werte($werte, $geraete, $zaehler)
{
    $leer = array('soc' => '-1', 'kwh' => '-1', 'leistung' => '-1', 'richtung' => '-1');
    $w = $leer;
    $werte = is_array($werte) ? $werte : array();
    $gut = count($werte) > 0;
    foreach ($werte as $x) {
        $alter = isset($x['alter']) ? (int) $x['alter'] : -1;
        if (empty($x['ok']) || $alter < 0 || $alter > 180) { $gut = false; }
    }
    if ($gut) {
        $s = zd_summe($werte, is_array($geraete) ? $geraete : array());
        $soc = null;
        if (count($werte) === 1) {
            $eins = reset($werte);
            $soc = (isset($eins['soc']) && is_numeric($eins['soc'])) ? (float) $eins['soc'] : null;
        } else {
            $soc = $s['soc'] !== null ? (float) $s['soc'] : null;
        }
        if ($soc !== null && $soc >= 0 && $soc <= 100 && $s['batp'] !== null) {
            $b = (int) $s['batp'];
            $w = array('soc' => zd_haus_zahl(round($soc, 1)),
                       'kwh' => $s['restkwh'] !== null ? zd_haus_zahl($s['restkwh']) : '-1',
                       'leistung' => (string) abs($b),
                       'richtung' => $b > 20 ? '1' : ($b < -20 ? '2' : '0'));
        }
    }
    return array(
        'haus/speicher/soc'      => $w['soc'],
        'haus/speicher/kwh'      => $w['kwh'],
        'haus/speicher/leistung' => $w['leistung'],
        'haus/speicher/richtung' => $w['richtung'],
        'haus/speicher/anbieter' => ZD_HAUS_NAME,
        'haus/speicher/zaehler'  => (string) ((((int) $zaehler) % 1000 + 1000) % 1000),
    );
}

/** Vollversand oder nur Geaendertes; zaehler immer und nie retained. Abschrift tb_haus_auswahl(). */
function zd_haus_auswahl($werte, $merker, $voll)
{
    $th = zd_haus_themen();
    $aus = array();
    foreach ($werte as $t => $v) {
        if (empty($th[$t])) { $aus[$t] = array((string) $v, false); continue; }
        if ($voll || !is_array($merker) || !array_key_exists($t, $merker) || (string) $merker[$t] !== (string) $v) {
            $aus[$t] = array((string) $v, true);
        }
    }
    return $aus;
}

function zd_haus_merker_datei()   { return zd_paths()['datadir'] . '/.haus_letzte.json'; }
function zd_haus_sitzung_datei()  { return zd_paths()['datadir'] . '/haus_sitzung.json'; }
function zd_haus_zustand_datei()  { return zd_paths()['datadir'] . '/haus_anbieter.json'; }
function zd_haus_quellen_datei()  { return zd_paths()['datadir'] . '/haus_quellen.json'; }

/** Der Zustand der Anbieterregel (hanb_zustand()) - im Datenordner, uebersteht ein Update (preupgrade.sh). */
function zd_haus_zustand()
{
    $d = zd_json_lesen(zd_haus_zustand_datei());
    return hanb_zustand(is_array($d) ? $d : array());
}

/** Den Zustand ablegen; ein Wechsel von Rolle, Grund oder fremdem Namen geht einmal ins Protokoll. */
function zd_haus_zustand_setzen(array $z)
{
    $alt = zd_haus_zustand();
    $z = hanb_zustand($z);
    if ($alt['rolle'] !== $z['rolle'] || $alt['grund'] !== $z['grund'] || $alt['fremd'] !== $z['fremd']) {
        zd_log('Haus-Themen haus/speicher: Rolle ' . $z['rolle'] . ($z['grund'] !== '' ? ' (' . $z['grund'] . ')' : '')
               . ($z['fremd'] !== '' ? ', Anbieter ' . preg_replace('/[^a-z0-9_.-]/i', '?', $z['fremd']) : '') . '.');
    }
    return zd_json_schreiben(zd_haus_zustand_datei(), $z);
}

/** Den Merker "war Anbieter" (_haus_frueher, in der Konfiguration - uebersteht ein Update) setzen oder entfernen. */
function zd_haus_frueher_setzen($ja)
{
    $c = zd_config();
    if ((int) !empty($c['_haus_frueher']) === ($ja ? 1 : 0)) { return false; }
    if ($ja) { $c['_haus_frueher'] = 1; } else { unset($c['_haus_frueher']); }
    return zd_config_speichern($c);
}

/**
 * Anbieter haus/speicher (aus dem Dienst, je MINUTE ueber zd_haus_takt(), nicht je Durchgang). Nur mit
 * haus_speicher_ein = 1 UND mqtt_ein = 1 UND erkannter Wurzel. Die Anbieterregel (hanb_lage()) entscheidet nach dem
 * Stand des Lesers (haus_quellen.json, Quelle 'speicher'), ob diese Installation sendet: wartet sie, geht NICHTS hinaus
 * (auch kein Zaehler); wurde sie abgeloest, faellt der Merker "war Anbieter". Haken oder MQTT aus: hoechstens alle 10
 * Minuten abraeumen, wenn diese Installation Anbieter war (hanb_darf_abraeumen()). Senden: Geaendertes, voll ohne
 * Merker und alle 30 Minuten, haus/speicher/zaehler in jeder Sitzung (derselbe Zaehler wie status/zaehler).
 */
function zd_haus_lauf($cfg, $werte, $geraete, $zaehler, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    $p = zd_paths();
    $mf = zd_haus_merker_datei();
    $z_alt = zd_haus_zustand();
    if (empty($cfg['haus_speicher_ein']) || empty($cfg['mqtt_ein']) || $p['home'] === '') {
        if (is_file($mf)) { @unlink($mf); }
        $darf = hanb_darf_abraeumen(array('rolle' => 'aus', 'grund' => $z_alt['grund']), !empty($cfg['_haus_frueher']));
        if ($z_alt['rolle'] !== 'aus') {
            zd_haus_zustand_setzen(array('rolle' => 'aus', 'grund' => $z_alt['grund'] === 'abgeloest' ? 'abgeloest' : '',
                                         'fremd' => '', 'seit' => $jetzt));
        }
        if ($darf && $p['home'] !== '') {
            $vf = $p['datadir'] . '/.haus_leeren_versuch';
            clearstatcache(true, $vf);
            $alt = is_file($vf) ? (int) filemtime($vf) : 0;
            if ($alt <= 0 || $alt > time() || time() - $alt >= 600) {
                @touch($vf);
                $e = zd_haus_leeren();
                zd_log('Haus-Themen haus/speicher: Abraeumen im Dienst - ' . $e['lage']
                    . ($e['offen'] ? ' (' . implode(', ', $e['offen']) . ')' : '') . '.');
                if (in_array($e['lage'], array('geleert', 'nichts'), true)) { zd_haus_frueher_setzen(false); }
            }
        }
        return null;
    }
    $c = zd_haus_quellen();
    $z = hanb_lage(ZD_HAUS_NAME, isset($c['speicher']) ? $c['speicher'] : array(), $z_alt, $jetzt);
    zd_haus_zustand_setzen($z);
    if ($z['rolle'] !== 'anbieter') {
        if (is_file($mf)) { @unlink($mf); }
        /* Abgeloest: der Merker faellt immer (zd_haus_frueher_setzen() liest die Datei selbst und schreibt nur bei
         * Bedarf) - nicht nur, wenn $cfg ihn schon traegt: $cfg kann aelter sein als der Merker (gemessen K4e). */
        if ($z['grund'] === 'abgeloest') { zd_haus_frueher_setzen(false); }
        zd_json_schreiben(zd_haus_sitzung_datei(), array('zeit' => $jetzt, 'lage' => 'wartet', 'gesendet' => 0,
            'voll' => 0, 'soc' => '-1'));
        return null;
    }
    $hw = zd_haus_werte($werte, $geraete, $zaehler);
    $vorher = null;
    $voll_zeit = 0;
    $d = zd_json_lesen($mf);
    if (is_array($d) && isset($d['werte'], $d['voll']) && is_array($d['werte'])) {
        $vorher = $d['werte'];
        $voll_zeit = (int) $d['voll'];
    }
    $voll = ($vorher === null || $voll_zeit <= 0 || $voll_zeit > $jetzt || $jetzt - $voll_zeit > 1800);
    $r = zd_haus_sitzung(zd_haus_auswahl($hw, $vorher, $voll), array(), array(), 0);
    if ($r['lage'] === 'ok') {
        $merk = $hw;
        unset($merk['haus/speicher/zaehler']);
        zd_json_schreiben($mf, array('werte' => $merk, 'voll' => $voll ? $jetzt : $voll_zeit, 'zeit' => $jetzt));
        if (empty($cfg['_haus_frueher'])) { zd_haus_frueher_setzen(true); }
    }
    zd_json_schreiben(zd_haus_sitzung_datei(), array('zeit' => $jetzt, 'lage' => $r['lage'],
        'gesendet' => (int) $r['gesendet'], 'voll' => $voll ? 1 : 0, 'soc' => $hw['haus/speicher/soc']));
    zd_log_gebremst('haus_sitzung_' . ($r['lage'] === 'ok' ? 'ok' : 'fehler'), $r['lage'] === 'ok'
        ? 'Haus-Themen haus/speicher: Sitzung am Broker ok'
        : 'Haus-Themen haus/speicher: Broker nicht erreichbar (' . $r['lage'] . ') - naechster Versuch in einer Minute');
    return $r;
}

/** Die fuenf retained Themen abraeumen - mit Ruecklesen in eigener Sitzung, bis zu drei Runden, nur die eigenen Namen.
 *  Rueckgabe array('lage' => geleert|nichts|rest|kein_broker, 'offen', 'grund'). Abschrift tb_haus_leeren(). */
function zd_haus_leeren($runden = 3)
{
    $th = zd_haus_retained();
    $belegt = function ($w) {
        $o = array();
        foreach ($w as $t => $v) { if ((string) $v !== '') { $o[] = $t; } }
        return $o;
    };
    $r = zd_haus_sitzung(array(), $th, array(), 0);
    if ($r['lage'] !== 'ok') { return array('lage' => 'kein_broker', 'offen' => array(), 'grund' => $r['lage']); }
    $offen = $belegt($r['werte']);
    if (!$offen) { return array('lage' => 'nichts', 'offen' => array(), 'grund' => ''); }
    $lage = 'rest';
    $grund = '';
    for ($i = 1; $i <= max(1, (int) $runden); $i++) {
        $senden = array();
        foreach ($offen as $t) { $senden[$t] = array('', true); }
        $r = zd_haus_sitzung($senden, array(), array(), 0);
        if ($r['lage'] !== 'ok') { $lage = 'kein_broker'; $grund = $r['lage']; continue; }
        usleep(300000);
        $r = zd_haus_sitzung(array(), $th, array(), 0);
        if ($r['lage'] !== 'ok') { $lage = 'kein_broker'; $grund = $r['lage']; continue; }
        $offen = $belegt($r['werte']);
        if (!$offen) { return array('lage' => 'geleert', 'offen' => array(), 'grund' => ''); }
        $lage = 'rest';
    }
    return array('lage' => $lage, 'offen' => $offen, 'grund' => $grund);
}

/**
 * Folgen einer Aenderung von haus_speicher_ein/mqtt_ein (Reiter MQTT, Zurueckspielen einer Sicherung). Wird die
 * Installation (wieder) eingeschaltet, verfaellt der Merker. Hoert sie auf und durfte sie abraeumen
 * (hanb_darf_abraeumen()), raeumt sie mit Ruecklesen ab - wartete sie nur oder wurde sie abgeloest, bleibt alles
 * stehen (die Themen gehoeren einem anderen). Rueckgabe array(Meldung oder '', ok|warn). Abschrift tb_haus_folgen().
 */
function zd_haus_folgen($alt, $neu)
{
    $ist = !empty($neu['haus_speicher_ein']) && !empty($neu['mqtt_ein']);
    $war = !empty($alt['haus_speicher_ein']) && !empty($alt['mqtt_ein']);
    @unlink(zd_haus_merker_datei());
    if ($ist) { return array('', 'ok'); }
    if (!$war && empty($alt['_haus_frueher'])) { return array('', 'ok'); }
    $z = zd_haus_zustand();
    $frueher = !empty($alt['_haus_frueher']);
    if (!hanb_darf_abraeumen($z, $frueher)) {
        zd_haus_zustand_setzen(array('rolle' => 'aus', 'grund' => '', 'fremd' => '', 'seit' => time()));
        if ($frueher && $z['grund'] === 'abgeloest') { zd_haus_frueher_setzen(false); }
        return array($z['rolle'] === 'wartet' ? zd_t('HAUS.M_WARTETE') : '', 'ok');
    }
    zd_haus_zustand_setzen(array('rolle' => 'aus', 'grund' => '', 'fremd' => '', 'seit' => time()));
    $e = zd_haus_leeren();
    zd_log('Haus-Themen haus/speicher: Abraeumen ' . $e['lage']
        . ($e['offen'] ? ' (' . implode(', ', $e['offen']) . ')' : '') . '.');
    if ($e['lage'] === 'geleert' || $e['lage'] === 'nichts') {
        zd_haus_frueher_setzen(false);
        return array($e['lage'] === 'geleert' ? sprintf(zd_t('HAUS.M_GELEERT'), count(zd_haus_retained()))
                                              : zd_t('HAUS.M_NICHTS'), 'ok');
    }
    zd_haus_frueher_setzen(true);
    if ($e['lage'] === 'rest') { return array(sprintf(zd_t('HAUS.M_REST'), implode(', ', $e['offen'])), 'warn'); }
    return array(zd_t('HAUS.M_KEIN_BROKER'), 'warn');
}

/**
 * Der Knopf "Diese Installation soll Anbieter sein" (E4 Schritt 3): schreibt EINMAL haus/speicher/anbieter = zendure
 * (retained) und setzt die Rolle anbieter/gewaehlt; die naechste Minute des Dienstes sendet den vollen Satz. Marstek
 * Venus E bis 1.1.27 kennt die Regel nicht: dort den Haken "Speicherstand an andere Plugins geben" ausschalten, sonst
 * senden beide. Rueckgabe array(Meldung, ok|warn).
 */
function zd_haus_waehlen($cfg, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    if (empty($cfg['haus_speicher_ein']) || empty($cfg['mqtt_ein'])) {
        return array(zd_t('HAUS.W_ERST_HAKEN'), 'warn');
    }
    $c = zd_haus_quellen();
    list($z, $hinweis) = hanb_waehlen(ZD_HAUS_NAME, isset($c['speicher']) ? $c['speicher'] : array(), $jetzt);
    $r = zd_haus_sitzung(array('haus/speicher/anbieter' => array(ZD_HAUS_NAME, true)), array(), array(), 0);
    if ($r['lage'] !== 'ok' || $r['gesendet'] < 1) {
        return array(sprintf(zd_t('HAUS.W_BROKER'), $r['lage']), 'warn');
    }
    zd_haus_zustand_setzen($z);
    @unlink(zd_haus_merker_datei());
    $m = sprintf(zd_t('HAUS.W_OK'), $z['fremd'] !== '' ? $z['fremd'] : '-');
    if ($hinweis === 'fremd_lebt') { return array($m . ' ' . sprintf(zd_t('HAUS.W_FREMD_LEBT'), $z['fremd']), 'warn'); }
    if ($hinweis === 'bestand') { return array($m . ' ' . zd_t('HAUS.W_BESTAND'), 'warn'); }
    return array($m, 'ok');
}

/** Deinstallation (zendure_dienst.php --haus-leeren): nur, wenn diese Installation abraeumen darf. Ausgabe fuer das
 *  Installationsprotokoll; Rueckgabe immer 0. Abschrift tb_haus_leeren_ausgabe(). */
function zd_haus_leeren_ausgabe()
{
    $cfg = zd_config(false);
    $z = zd_haus_zustand();
    if (!hanb_darf_abraeumen($z, !empty($cfg['_haus_frueher']))) {
        echo "<INFO> haus/speicher: diese Installation ist nicht Anbieter (oder wurde abgeloest) - nichts zu leeren.\n";
        return 0;
    }
    $e = zd_haus_leeren();
    if ($e['lage'] === 'geleert') {
        echo "<OK> haus/speicher: geleert (vom Broker bestaetigt, " . count(zd_haus_retained()) . " Themen).\n";
    } elseif ($e['lage'] === 'nichts') {
        echo "<INFO> haus/speicher: nichts zu leeren (der Broker haelt keines der Themen zurueck).\n";
    } elseif ($e['lage'] === 'rest') {
        echo "<WARNING> haus/speicher: noch zurueckbehalten - bitte von Hand leeren: " . implode(', ', $e['offen'])
           . " (mosquitto_pub -r -n -t <thema>)\n";
    } else {
        echo "<WARNING> haus/speicher: der Broker war nicht erreichbar (" . $e['grund'] . ") - bitte von Hand leeren: "
           . implode(', ', zd_haus_retained()) . " (mosquitto_pub -r -n -t <thema>)\n";
    }
    return 0;
}

/** Die Zeile "Anbieter haus/speicher" der Selbstpruefung: array(1|0|-1, Text) in der Form von zd_pruefzeile(). */
function zd_haus_testzeile($cfg, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    if (empty($cfg['haus_speicher_ein'])) { return array(-1, zd_t('HAUS.T_AUS')); }
    if (empty($cfg['mqtt_ein'])) { return array(-1, zd_t('HAUS.T_MQTT_AUS')); }
    $z = zd_haus_zustand();
    $fremd = $z['fremd'] !== '' ? $z['fremd'] : '-';
    if ($z['rolle'] !== 'anbieter') {
        $g = $z['grund'] !== '' ? $z['grund'] : 'pruefe';
        $st = in_array($g, array('fremd_schweigt', 'veraltet'), true) ? 0 : -1;
        return array($st, sprintf(zd_t('HAUS.A_' . strtoupper($g)), $fremd));
    }
    $sz = zd_json_lesen(zd_haus_sitzung_datei());
    $vor = sprintf(zd_t('HAUS.A_' . strtoupper($z['grund'] !== '' ? $z['grund'] : 'frei')), $fremd) . ' ';
    if (!is_array($sz) || !isset($sz['lage'], $sz['zeit']) || $sz['lage'] === 'wartet') {
        return array(-1, $vor . zd_t('HAUS.T_NIE'));
    }
    $alter = max(0, $jetzt - (int) $sz['zeit']);
    if ((string) $sz['lage'] !== 'ok') { return array(0, $vor . sprintf(zd_t('HAUS.T_BROKER'), (string) $sz['lage'], $alter)); }
    $soc = isset($sz['soc']) ? (string) $sz['soc'] : '-1';
    $ok = ($alter <= 150 && hanb_ampel($z) === 1) ? 1 : 0;
    return array($ok, $vor . ($soc !== '-1' ? sprintf(zd_t('HAUS.T_OK'), $alter, $soc) : sprintf(zd_t('HAUS.T_OHNE'), $alter)));
}

/* ---------- Leser der Anbieterregel (haus/speicher/anbieter|zaehler) ---------- */

/** Der Stand des Lesers - bei jedem Aufruf frisch aus der Datei. */
function zd_haus_quellen()
{
    $d = zd_json_lesen(zd_haus_quellen_datei());
    return is_array($d) ? $d : array();
}

/** Welche Quellen liest der Leser? kurz => array(Zaehlerthema, kurz => Thema). Nur 'speicher' fuer die Anbieterregel
 *  (haus_speicher_ein und MQTT) - Zendure ist kein Abnehmer (E9). */
function zd_haus_lesequellen($cfg)
{
    $q = array();
    if (!empty($cfg['haus_speicher_ein']) && !empty($cfg['mqtt_ein'])) {
        $q['speicher'] = array('haus/speicher/zaehler', array('anbieter' => 'haus/speicher/anbieter'));
    }
    return $q;
}

/**
 * Eine Lesesitzung (nur aus dem abgekoppelten Leser zendure_dienst.php --haus-lesen): Fenster bis $fenster s auf den
 * live gesehenen Zaehler (Phasenfalle: der Zaehler kommt einmal je Minute, nicht retained). Ohne Quelle verfaellt die
 * Ablage. Rueckgabe das Sitzungsergebnis oder null. Abschrift tb_haus_abnehmer().
 */
function zd_haus_lesen(array $cfg, $fenster)
{
    $datei = zd_haus_quellen_datei();
    $quellen = zd_haus_lesequellen($cfg);
    if (!$quellen) {
        if (is_file($datei)) { @unlink($datei); }
        return null;
    }
    $c = zd_haus_quellen();
    $lesen = array();
    $warten = array();
    foreach ($quellen as $k => $d) {
        $lesen[] = $d[0];
        foreach ($d[1] as $t) { $lesen[] = $t; }
        if (hanb_warten(isset($c[$k]) && is_array($c[$k]) ? $c[$k] : array(), time())) { $warten[] = $d[0]; }
    }
    $erg = zd_haus_sitzung(array(), $lesen, $warten, (float) $fenster);
    $jetzt = time();
    $neu = array();
    foreach ($quellen as $k => $d) {
        $neu[$k] = hanb_merken(isset($c[$k]) && is_array($c[$k]) ? $c[$k] : array(), $erg, $d[0], $d[1], $jetzt,
                               in_array($d[0], $warten, true));
    }
    $neu['sitzung'] = array('zeit' => $jetzt, 'lage' => $erg['lage']);
    zd_log_gebremst('haus_lesen_' . ($erg['lage'] === 'ok' ? 'ok' : 'fehler'), $erg['lage'] === 'ok'
        ? 'Haus-Themen lesen: Sitzung am Broker ok' : 'Haus-Themen lesen: Sitzung am Broker gescheitert (' . $erg['lage'] . ')');
    zd_json_schreiben($datei, $neu);
    return $erg;
}

function zd_haus_leser_sperre()
{
    return sys_get_temp_dir() . '/zd_haus_lesen_' . zd_paths()['plugin'] . '.lock';
}

/**
 * Den abgekoppelten Leser starten (Abschrift tb_haus_abnehmer_starten()): hoechstens EINEN, nie darauf warten - der
 * Dienst darf nicht 65 s stehen (Horcher, Warteschlange). Das Kind erbt keine Deskriptoren: die Schale schliesst vor
 * dem exec jede Nummer ueber 2 (Sperre dienst.lock und Horcher-Rohre des Dienstes; Gedaechtnis
 * sperre-vererbt-sich-an-kinder). Rueckgabe aus|laeuft|gestartet|kein_start.
 */
function zd_haus_leser_starten(array $cfg, $skript)
{
    if (!zd_haus_lesequellen($cfg)) {
        $datei = zd_haus_quellen_datei();
        if (is_file($datei)) { @unlink($datei); }
        return 'aus';
    }
    $fh = @fopen(zd_haus_leser_sperre(), 'ce');
    if ($fh === false) { return 'kein_start'; }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return 'laeuft';
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' ' . escapeshellarg($skript) . ' --haus-lesen';
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Der abgekoppelte Leser selbst: eigene Sperre (close-on-exec), sonst still beenden. */
function zd_haus_leser_lauf($fenster = 65)
{
    $fh = @fopen(zd_haus_leser_sperre(), 'ce');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = zd_haus_lesen(zd_config(false), $fenster);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg === null ? 'aus' : $erg['lage'];
}

/**
 * Der Minutentakt der Haus-Themen im Dienst (zd_abbild_schreiben() ruft ihn je Durchgang; er handelt hoechstens einmal
 * je 60 s): erst den Leser anstossen (abgekoppelt), dann der Anbieter-Lauf mit dem Abbild DIESES Durchgangs und dem
 * Herzschlag als Zaehler. $skript = Pfad von zendure_dienst.php. Rueckgabe true, wenn gehandelt wurde.
 */
function zd_haus_takt(array $werte, array $geraete, array $cfg, $zaehler, $skript, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    $letzt = isset($GLOBALS['zd_haus_ts']) ? (int) $GLOBALS['zd_haus_ts'] : 0;
    if ($letzt > 0 && $jetzt >= $letzt && $jetzt - $letzt < 60) { return false; }
    $GLOBALS['zd_haus_ts'] = $jetzt;
    zd_haus_leser_starten($cfg, $skript);
    zd_haus_lauf($cfg, $werte, $geraete, $zaehler, $jetzt);
    return true;
}

/* ---------- G3/Sicherung: neue Schluessel dieser Fassung ---------- */

/** Schluessel, die 0.9.38 neu einfuehrt. Eine Sicherung ohne sie wird angenommen; es gilt weiter, was eingestellt ist
 *  (Hinweis EINST.SICH_OHNE_G43). endpunkt_ein ab Werk 0, beim Update 1 (postinstall.sh); befehle_mqtt_ein ab Werk
 *  und beim Update 0; haus_speicher_ein ab Werk 0. */
function zd_neue_schluessel_0938()
{
    return array('haus_speicher_ein', 'befehle_mqtt_ein', 'endpunkt_ein');
}

/* ---------- Gesundheit (Healthcheck nach Renault NG 2.1.20) ---------- */

/** Die Fakten des Healthchecks - nur lesend, kein Netz, kein Broker. Grundlage bleibt zd_befund() (Oberflaeche und
 *  Meldung sagen dasselbe). */
function zd_gesund_fakten()
{
    $cfg = zd_config(false);
    $p = zd_paths();
    $f = array();
    $f['befund'] = zd_befund();
    $f['geraete'] = count(zd_geraete());
    $f['rechte'] = is_file($p['config']) ? substr(sprintf('%o', fileperms($p['config'])), -4) : '';
    $f['v2'] = $f['geraete'] > 0 ? zd_v2_haken_lage($cfg) : null;
    $f['haus'] = (!empty($cfg['haus_speicher_ein']) && !empty($cfg['mqtt_ein'])) ? zd_haus_testzeile($cfg) : null;
    $f['bruecke'] = (!empty($cfg['befehle_mqtt_ein']) && !empty($cfg['mqtt_ein'])) ? zd_bruecke_zustand() : null;
    return $f;
}

/**
 * Das Urteil (rein): array(Status, Text) - 3 Fehler, 4 Warnung, 5 in Ordnung, 6 Hinweis (Regeln/03 :73-78). Ohne
 * Geraet 6 "nicht eingerichtet" (Text aus zd_befund()); sonst zd_befund() als Grundlage, dazu Warnungen (4): die
 * Konfiguration traegt nicht 0600, Gateway V2 mit fehlenden Haken, Anbieter haus/speicher gescheitert (Testzeile 0),
 * Befehlsbruecke soll laufen und steht.
 */
function zd_gesund_urteil(array $f)
{
    $b = $f['befund'];
    if ((int) $f['geraete'] === 0) {
        return array(6, (string) $b['text']);
    }
    $status = (int) $b['status'];
    $befunde = array((string) $b['text']);
    $setze = function ($s, $t) use (&$status, &$befunde) {
        if ($s === 3 || ($s === 4 && ($status === 5 || $status === 6))) { $status = $s; }
        $befunde[] = $t;
    };
    if ($f['rechte'] !== '' && $f['rechte'] !== '0600') {
        $setze(4, 'Die Konfiguration traegt die Rechte ' . $f['rechte'] . ' statt 0600');
    }
    if (is_array($f['v2']) && $f['v2'][0] === 0) {
        $setze(4, 'Gateway V2: ' . $f['v2'][1]);
    }
    if (is_array($f['haus']) && $f['haus'][0] === 0) {
        $setze(4, 'Anbieter haus/speicher: ' . $f['haus'][1]);
    }
    if (is_array($f['bruecke']) && $f['bruecke'][0] === false) {
        $setze(4, 'Befehlsbruecke: ' . $f['bruecke'][1]);
    }
    return array($status, implode(' - ', $befunde));
}
