<?php
/**
 * Zendure SolarFlow - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der Abruf laeuft im Dienst
 * (bin/zendure_dienst.php), der Miniserver spricht mit
 * webfrontend/html/index.php. Ein Plugin, das den Abruf hier erledigt, ist
 * falsch gebaut - auch wenn es funktioniert.
 *
 * Praefix 'zd_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Bibliothek einbinden. Sie liegt unter webfrontend/html/, weil Endpunkt und
 * Dienst sie ebenfalls brauchen - installiert unter
 * <home>/webfrontend/html/plugins/<ordner>/, im Archiv unter ../html/. */
$zd_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/zd_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/zd_lib.php',
    dirname(__DIR__) . '/html/zd_lib.php',
) as $zd_kandidat) {
    if (is_file($zd_kandidat)) {
        require_once $zd_kandidat;
        $zd_gefunden = true;
        break;
    }
}
if (!$zd_gefunden) {
    echo '<p><b>Fehler:</b> zd_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/zd_test.php';

$zd_p = zd_paths();
if ($zd_p['home'] !== '' && is_file($zd_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $zd_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $zd_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Die Reiter, an EINER Stelle.
 *
 * Bis 0.9.0 stand die Liste dreimal da: als Positivliste in diesem regulaeren
 * Ausdruck, als Leiste im Rumpf und als id an den Bereichen. Wer einen Reiter
 * ergaenzt und eine der drei Stellen vergisst, bekommt keinen Fehler, sondern
 * einen Reiter, der sich anklicken laesst und nach jedem Absenden auf
 * Einstellungen zurueckspringt. Jetzt entstehen Leiste und Pruefung aus
 * diesem Feld - vergessen kann man nichts mehr. */
$zd_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,                    // Eigenname, wird nicht uebersetzt
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$zd_muster = '/^tab-(' . implode('|', array_map(function ($k) {
    return preg_quote($k, '/');
}, array_keys($zd_reiter))) . ')$/';
$zd_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($zd_muster, (string) $_POST['activetab'])) {
    $zd_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($zd_muster, 'tab-' . (string) $_GET['form'])) {
    $zd_tab = 'tab-' . (string) $_GET['form'];
}

$zd_meldungen = array();
$zd_fehler = array();      // gesammelt, nicht ueberschrieben
$zd_testausgabe = '';
$zd_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Wachposten gegen fremde Formulare ----------------
 *
 * EINE Pruefung, VOR allen Handlern. Einen einzelnen Handler kann man beim
 * Erweitern vergessen, einen Wachposten am Eingang nicht.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines angemeldeten Bedieners ein Formular abschickt, das auf
 * einer fremden Seite steht. Die Anmeldung schickt er automatisch mit,
 * SameSite greift dagegen nicht.
 *
 * Gemessen an 0.9.8 (Pruefstand p4_csrf.php): ein POST mit nichts als
 * token_neu=1 wuerfelte das Aktionstoken neu -
 *     vorher  pruftokenpruftoken1234
 *     nachher 5zxg6v3fb7uzx69patkspcc8
 * Danach beantwortet der Endpunkt jeden virtuellen Eingang des Miniservers
 * mit HTTP 403: die Ueberwachung ist tot, ohne jede Rueckmeldung. Ueber
 * log_leeren=1 liesse sich gleich die Spur wegraeumen, ueber dienst=stop der
 * Abrufdienst anhalten. Der Angreifer sieht die Antwort nicht; er braucht
 * sie auch nicht.
 */
$zd_fmt = zd_formtoken();
if ($zd_post) {
    $zd_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    $zd_csrf_ok = true;
    if ($zd_fmt === '') {
        // Fail closed. Ohne Aktionstoken gibt es kein Merkmal - und ein aus
        // dem Leerstring abgeleitetes waere fuer jeden ausrechenbar.
        $zd_csrf_ok = false;
        $zd_fehler[] = zd_t('FEHLER.CSRF_KEIN_TOKEN');
    } elseif (!hash_equals($zd_fmt, $zd_mit)) {
        $zd_csrf_ok = false;
        $zd_fehler[] = zd_t('FEHLER.CSRF');
        zd_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
    if (!$zd_csrf_ok) {
        /* $_POST leeren, damit danach KEIN Handler mehr anlaeuft, ohne dass
         * jeder einzelne davon wissen muesste. Den aktiven Reiter behalten -
         * der Bediener soll die Meldung dort sehen, wo er war. */
        $zd_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($zd_behalten !== null) {
            $_POST['activetab'] = $zd_behalten;
        }
        $zd_post = false;
    }
}

/* ---------------- Vorlage herunterladen ----------------
 *
 * Bis 0.9.10 gab es genau einen Knopf, und er war fest auf Geraet 1
 * verdrahtet. Bei sechs Geraeten fehlten fuenf Vorlagen. Jetzt waehlt eine
 * Auswahl das Geraet, und es gibt drei Arten: Messwerte, Energiezaehler und
 * die Summe ueber alle Geraete.
 */
if ($zd_post && isset($_POST['vorlage'])) {
    $zd_art = (string) $_POST['vorlage'];
    $zd_vnr = isset($_POST['vorlage_geraet'])
              && preg_match('/^[0-9]{1,2}$/', (string) $_POST['vorlage_geraet'])
              ? max(1, (int) $_POST['vorlage_geraet']) : 1;
    $zd_vzr = isset($_POST['vorlage_zeitraum'])
              && in_array((string) $_POST['vorlage_zeitraum'], array('tag', 'monat', 'jahr'), true)
              ? (string) $_POST['vorlage_zeitraum'] : 'tag';
    $zd_typ = 'application/xml';
    if ($zd_art === 'energie') {
        list($zd_name, $zd_inhalt) = zd_vorlage_energie($zd_vnr, $zd_vzr);
    } elseif ($zd_art === 'summe') {
        list($zd_name, $zd_inhalt) = zd_vorlage_summe();
    } elseif ($zd_art === 'befehle') {
        list($zd_name, $zd_inhalt) = zd_vorlage_befehle($zd_vnr);
    } elseif ($zd_art === 'alle') {
        list($zd_name, $zd_inhalt) = zd_vorlagen_paket();
        $zd_typ = 'application/zip';
        if ($zd_name === '') {
            /* Kein Archiv moeglich - dann sagen warum, statt eine kaputte
             * Datei auszuliefern. Der Grund steht in $zd_inhalt. */
            $zd_fehler[] = zd_e($zd_inhalt);
            $zd_tab = 'tab-loxone';
        }
    } else {
        list($zd_name, $zd_inhalt) = zd_vorlage($zd_vnr);
    }
    if ($zd_name !== '') {
        header('Content-Type: ' . $zd_typ . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $zd_name . '"');
        echo $zd_inhalt;
        exit;
    }
}

/* ---------------- Verlauf als CSV ----------------
 *
 * Eigenes Formular und eigener Handler, damit die Ausfuhr nicht am Speichern
 * haengt. Die Punkte lagen schon immer da - bis 0.9.11 gab es nur keinen Weg,
 * sie herauszubekommen.
 */
if ($zd_post && isset($_POST['verlauf_csv'])) {
    $zd_vnr = preg_match('/^[0-9]{1,2}$/', (string) $_POST['verlauf_csv'])
              ? max(1, (int) $_POST['verlauf_csv']) : 1;
    $zd_vtag = isset($_POST['verlauf_tag'])
               && preg_match('/^[0-9]{8}$/', (string) $_POST['verlauf_tag'])
               ? (string) $_POST['verlauf_tag'] : date('Ymd');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="zendure_geraet'
           . $zd_vnr . '_' . $zd_vtag . '.csv"');
    echo zd_verlauf_csv($zd_vnr, $zd_vtag);
    exit;
}

/* ---------------- Konfiguration sichern ---------------- */
if ($zd_post && isset($_POST['konfig_aus'])) {
    $zd_js = zd_konfig_ausfuhr();
    if ($zd_js === '') {
        $zd_fehler[] = zd_t('EINST.KONFIG_KEIN_JSON');
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="zendure_konfiguration_'
               . date('Ymd_His') . '.json"');
        echo $zd_js;
        exit;
    }
}

/* ---------------- Konfiguration vervollstaendigen ---------------- */
if ($zd_post && isset($_POST['konfig_erg'])) {
    /* Fehlende Schluessel in die DATEI schreiben. Im Betrieb greifen sie
     * ohnehin - eine Sicherung ohne sie waere aber unvollstaendig, und wer
     * die Datei von Hand liest, sucht sonst nach etwas, das nicht da ist.
     * Fremde Schluessel bleiben stehen: niemand hier weiss, ob dort der
     * Rest einer aelteren Fassung steht oder etwas, das der naechsten
     * schon gehoert. */
    list($zd_cok, $zd_cn, $zd_cmeld) = zd_cfg_vervollstaendigen();
    if ($zd_cok) {
        $zd_meldungen[] = zd_e($zd_cmeld);
        if ($zd_cn > 0) {
            zd_log('Konfiguration vervollstaendigt: ' . $zd_cmeld);
        }
    } else {
        $zd_fehler[] = zd_e($zd_cmeld);
    }
    $zd_tab = 'tab-settings';
}

/* ---------------- Konfiguration zurueckspielen ---------------- */
if ($zd_post && isset($_POST['konfig_ein'])) {
    if (!isset($_FILES['konfig_datei']) || !is_array($_FILES['konfig_datei'])
        || (int) $_FILES['konfig_datei']['error'] !== 0) {
        $zd_fehler[] = zd_t('EINST.KONFIG_KEINE_DATEI');
    } elseif ((int) $_FILES['konfig_datei']['size'] > 262144) {
        // Eine Konfiguration dieses Plugins ist ein paar Kilobyte gross.
        $zd_fehler[] = zd_t('EINST.KONFIG_ZU_GROSS');
    } else {
        $zd_inhalt = (string) @file_get_contents($_FILES['konfig_datei']['tmp_name']);
        list($zd_kok, $zd_kmeld) = zd_konfig_einfuhr($zd_inhalt);
        if ($zd_kok) {
            $zd_meldungen[] = zd_e($zd_kmeld);
            zd_log('Konfiguration aus einer Sicherung zurueckgespielt.');
        } else {
            $zd_fehler[] = zd_e($zd_kmeld);
        }
    }
    $zd_tab = 'tab-settings';
}

/* ---------------- Geraetesuche ---------------- */
if ($zd_post && isset($_POST['suche'])) {
    $zd_sart = (string) $_POST['suche'] === 'mqtt' ? 'mqtt' : 'http';
    /* Die Suche braucht Zeit - der Netzbereich wird angeklopft, oder es wird
     * zwoelf Sekunden gehorcht. Die Wartezeit der Warteschlange ist auf zehn
     * Sekunden gedeckelt; laeuft sie ab, steht das Ergebnis trotzdem gleich
     * im Reiter Test, sobald der Dienst fertig ist. */
    list($zd_sst, $zd_stext) = zd_befehl_absetzen(
        array('aktion' => 'suche', 'art' => $zd_sart), 10);
    if ($zd_sst === 1) {
        $zd_meldungen[] = zd_e($zd_stext);
    } elseif ($zd_sst === 2) {
        $zd_meldungen[] = zd_t('TEST.SUCHE_LAEUFT');
    } else {
        $zd_fehler[] = zd_e($zd_stext);
    }
    $zd_tab = 'tab-test';
}

/* ---------------- Gefundenes Geraet uebernehmen ----------------
 *
 * Es wird ANGEHAENGT, nie ersetzt: wer zwei Speicher hat und den zweiten
 * findet, will den ersten behalten. Und ein Geraet, das schon in der Liste
 * steht, wird nicht ein zweites Mal aufgenommen.
 */
if ($zd_post && isset($_POST['uebernehmen'])) {
    $zd_ucfg = zd_config();
    $zd_uliste = isset($zd_ucfg['geraete']) && is_array($zd_ucfg['geraete'])
                 ? array_values($zd_ucfg['geraete']) : array();
    $zd_uip = isset($_POST['u_ip']) ? trim((string) $_POST['u_ip']) : '';
    $zd_upk = isset($_POST['u_prodkey']) ? trim((string) $_POST['u_prodkey']) : '';
    $zd_udi = isset($_POST['u_deviceid']) ? trim((string) $_POST['u_deviceid']) : '';
    $zd_neu = null;
    if ($zd_uip !== '' && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $zd_uip)) {
        $zd_neu = array('name' => 'Speicher ' . (count($zd_uliste) + 1), 'art' => 'http',
                        'ip' => $zd_uip, 'prodkey' => '', 'deviceid' => '',
                        'sn' => '', 'modell' => '', 'satz' => '');
    } elseif (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $zd_upk)
              && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $zd_udi)) {
        $zd_neu = array('name' => 'Speicher ' . (count($zd_uliste) + 1), 'art' => 'mqtt',
                        'ip' => '', 'prodkey' => $zd_upk, 'deviceid' => $zd_udi,
                        'sn' => '', 'modell' => '', 'satz' => '');
    }
    if ($zd_neu === null) {
        $zd_fehler[] = zd_t('TEST.UEBERNEHMEN_UNGUELTIG');
    } else {
        $zd_schon = false;
        foreach ($zd_uliste as $zd_alt) {
            if (($zd_uip !== '' && isset($zd_alt['ip']) && $zd_alt['ip'] === $zd_uip)
                || ($zd_udi !== '' && isset($zd_alt['deviceid']) && $zd_alt['deviceid'] === $zd_udi)) {
                $zd_schon = true;
            }
        }
        if ($zd_schon) {
            $zd_meldungen[] = zd_t('TEST.UEBERNEHMEN_SCHON');
        } elseif (count($zd_uliste) >= 6) {
            $zd_fehler[] = zd_t('TEST.UEBERNEHMEN_VOLL');
        } else {
            $zd_uliste[] = $zd_neu;
            $zd_ucfg['geraete'] = $zd_uliste;
            if (zd_config_speichern($zd_ucfg)) {
                $zd_meldungen[] = sprintf(zd_t('TEST.UEBERNEHMEN_OK'), count($zd_uliste));
            } else {
                $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_SPEICHERN'), $zd_p['config']);
            }
        }
    }
    $zd_tab = 'tab-test';
}

/* ---------------- Einstellungen speichern ---------------- */
if ($zd_post && isset($_POST['speichern'])) {
    $zd_cfg = zd_config();
    $zd_saetze = zd_befehlssaetze();
    $zd_modelle = zd_modelle();

    /* Geraetetabelle: bis zu sechs Zeilen.
     *
     * Eine beanstandete Zeile wird UEBERGANGEN, alles Uebrige gespeichert -
     * Hausregel vom 16.08.2026, "Beanstandungen melden, nicht das ganze
     * Speichern verhindern". Bis 0.9.8 hing das Speichern an
     * "if (!$zd_fehler)", und ein einziges Leerzeichen in einer IP warf
     * Geraetenamen, Takt, Schreibbremse, Schrittweite, Aufbewahrungsdauer,
     * Wartezeit und Temperaturumrechnung gleich mit weg. Gemessen, Pruefstand
     * p3_formular.php.
     *
     * Uebergangen heisst hier: die BISHERIGE Angabe derselben Zeile bleibt
     * stehen. Sie einfach wegzulassen waere schlimmer als das alte Verhalten -
     * dann loeschte ein Tippfehler das Geraet. */
    $zd_alt_zeilen = isset($zd_cfg['geraete']) && is_array($zd_cfg['geraete'])
                   ? array_values($zd_cfg['geraete']) : array();
    $zd_neu = array();
    /* Meldet eine Zeile ab und rettet, was dort bisher stand. */
    $zd_zeile_ab = function ($i, $meldung) use (&$zd_neu, &$zd_fehler, $zd_alt_zeilen) {
        if (isset($zd_alt_zeilen[$i]) && is_array($zd_alt_zeilen[$i])) {
            $zd_neu[$i] = $zd_alt_zeilen[$i];
            $zd_fehler[] = $meldung . ' ' . zd_t('EINST.FEHLER_ZEILE_ALT');
        } else {
            $zd_fehler[] = $meldung . ' ' . zd_t('EINST.FEHLER_ZEILE_WEG');
        }
    };
    for ($i = 0; $i < 6; $i++) {
        $hol = function ($feld) use ($i) {
            $a = isset($_POST[$feld]) ? (array) $_POST[$feld] : array();
            // Nur Steuerzeichen, Anfuehrungszeichen und Leerraum entfernen -
            // ein hartes preg_replace auf eine Positivliste zerstoert
            // eingefuegte Werte (belegt am ACTi-Plugin am 26.07.2026).
            return isset($a[$i]) ? trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $a[$i])) : '';
        };
        $art = $hol('g_art') === 'mqtt' ? 'mqtt' : 'http';
        $ip = $hol('g_ip');
        $prodkey = $hol('g_prodkey');
        $deviceid = $hol('g_deviceid');
        $name = $hol('g_name');
        if ($ip === '' && $prodkey === '' && $deviceid === '' && $name === '') {
            continue;   // leere Zeile
        }
        /* Der Name landet in der semikolongetrennten Antwort an den
         * Miniserver. Ein Semikolon oder Gleichheitszeichen darin schiebt
         * dort die Felder - abweisen, nicht stillschweigend zurechtbiegen. */
        if ($name !== '' && preg_match('/[;=]/', $name)) {
            $zd_zeile_ab($i, sprintf(zd_t('EINST.FEHLER_NAME'), $i + 1));
            continue;
        }
        if ($art === 'http') {
            if ($ip === '') {
                $zd_zeile_ab($i, sprintf(zd_t('EINST.FEHLER_IP_FEHLT'), $i + 1));
                continue;
            }
            // IPv4 oder Hostname zulassen - beides ist gebraeuchlich.
            if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)
                && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{1,80}$/', $ip)) {
                $zd_zeile_ab($i, sprintf(zd_t('EINST.FEHLER_IP'), $i + 1));
                continue;
            }
        } else {
            if ($prodkey === '' || $deviceid === '') {
                $zd_zeile_ab($i, sprintf(zd_t('EINST.FEHLER_MQTT_IDS'), $i + 1));
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $prodkey)
                || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $deviceid)) {
                $zd_zeile_ab($i, sprintf(zd_t('EINST.FEHLER_MQTT_MUSTER'), $i + 1));
                continue;
            }
        }
        $satz = $hol('g_satz');
        if (!in_array($satz, $zd_saetze, true)) {
            $satz = '';
        }
        $modell = strtolower(preg_replace('/[^a-z0-9]/i', '', $hol('g_modell')));
        $zd_zeile = array(
            'name'     => $name,
            'art'      => $art,
            'ip'       => $ip,
            'prodkey'  => $prodkey,
            'deviceid' => $deviceid,
            'sn'       => $hol('g_sn'),
            'modell'   => $modell,
            'satz'     => $satz,
            'quittungsfeld' => $hol('g_quittungsfeld'),
        );
        $zd_kap = $hol('g_kapazitaet');
        if ($zd_kap !== '') {
            // Eingabe in Kilowattstunden, Ablage in Wattstunden: gerechnet
            // wird mit Wattstunden, eingetragen wird, was auf dem Geraet steht.
            if (!preg_match('/^[0-9]{1,3}([.,][0-9]{1,3})?$/', $zd_kap)) {
                $zd_zeile_ab($i, sprintf(zd_t('EINST.FEHLER_KAPAZITAET'), $i + 1));
                continue;
            }
            $zd_zeile['kapazitaet_wh'] = (int) round(1000 * (float) str_replace(',', '.', $zd_kap));
        }
        // Ein Eigenschaftsname, kein Freitext - abweisen, nicht zurechtbiegen.
        if ($zd_zeile['quittungsfeld'] !== ''
            && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $zd_zeile['quittungsfeld'])) {
            $zd_zeile_ab($i, sprintf(zd_t('EINST.FEHLER_QUITTUNGSFELD'), $i + 1));
            continue;
        }
        foreach (array('max_laden', 'max_entladen') as $feld) {
            $w = $hol('g_' . $feld);
            if ($w === '') {
                continue;   // leer = Werksgrenze des Modells nehmen
            }
            if (!preg_match('/^[0-9]{1,4}$/', $w)) {
                // Nur diese eine Grenze verwerfen, nicht die ganze Zeile:
                // ohne Eintrag gilt die Werksgrenze des Modells.
                $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_GRENZE'), $i + 1);
                continue;
            }
            $zd_zeile[$feld] = (int) $w;
        }
        $zd_neu[$i] = $zd_zeile;
    }
    // Die Luecken der uebergangenen Zeilen schliessen, Reihenfolge erhalten.
    ksort($zd_neu);
    $zd_cfg['geraete'] = array_values($zd_neu);

    foreach (array(
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
        /* mqtt_auffrischung steht bewusst NICHT hier: das Feld wohnt im
         * Reiter MQTT und wird vom dortigen Handler gelesen. Stuende es in
         * dieser Liste, beanstandete das Einstellungsformular es bei jedem
         * Speichern - es schickt das Feld ja gar nicht mit. */
    ) as $zd_feld => $zd_grenzen) {
        $zd_wert = isset($_POST[$zd_feld]) ? trim((string) $_POST[$zd_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $zd_wert)) {
            $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_ZAHL'), zd_t('EINST.L_' . strtoupper($zd_feld)));
            continue;
        }
        $zd_zahl = (int) $zd_wert;
        if ($zd_zahl < $zd_grenzen[0] || $zd_zahl > $zd_grenzen[1]) {
            $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_BEREICH'),
                zd_t('EINST.L_' . strtoupper($zd_feld)), $zd_grenzen[0], $zd_grenzen[1]);
            continue;
        }
        $zd_cfg[$zd_feld] = $zd_zahl;
    }

    $zd_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $zd_cfg['schutz_ein']    = isset($_POST['schutz_ein']) ? 1 : 0;
    $zd_cfg['energie_ein']   = isset($_POST['energie_ein']) ? 1 : 0;
    /* Temperaturschwellen duerfen negativ sein und Nachkommastellen haben -
     * sie stehen in der EINGESTELLTEN Einheit, und die kann roh sein. */
    foreach (array('schutz_temp_min', 'schutz_temp_max') as $zd_tf) {
        $zd_tw = isset($_POST[$zd_tf]) ? trim((string) $_POST[$zd_tf]) : '';
        if (!preg_match('/^-?[0-9]{1,6}([.,][0-9]{1,2})?$/', $zd_tw)) {
            $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_ZAHL'), zd_t('EINST.L_' . strtoupper($zd_tf)));
            continue;
        }
        $zd_cfg[$zd_tf] = (float) str_replace(',', '.', $zd_tw);
    }
    if ((int) $zd_cfg['schutz_soc_min'] >= (int) $zd_cfg['schutz_soc_max']) {
        $zd_fehler[] = zd_t('EINST.FEHLER_SOC_REIHE');
    }


    /* Broker und MQTT wohnen im Reiter MQTT (eigenes Formular, eigener
     * Handler). $zd_cfg kommt aus zd_config(), die Werte ueberleben das
     * Speichern der Einstellungen also unveraendert. */

    $zd_tu = isset($_POST['temp_umrechnung']) ? (string) $_POST['temp_umrechnung'] : 'roh';
    $zd_cfg['temp_umrechnung'] = in_array($zd_tu, array('roh', 'kelvin10', 'zehntel'), true) ? $zd_tu : 'roh';

    /* Freie Feldzuordnung.
     *
     * Ein LEERES Feld bedeutet "Vorgabe" und nicht "keine Zuordnung" - sonst
     * loeschte ein leergelassenes Eingabefeld die Zuordnung, und der Wert
     * verschwaende ohne Meldung. Gespeichert wird deshalb nur, was abweicht. */
    foreach (array(array('z_', 'zuordnung', zd_feldkarte()),
                   array('zp_', 'packzuordnung', zd_packkarte())) as $zd_zk) {
        list($zd_praefix, $zd_schluessel, $zd_karte) = $zd_zk;
        $zd_neuz = array();
        foreach ($zd_karte as $zd_feld => $zd_vorgabe) {
            $zd_w = isset($_POST[$zd_praefix . $zd_feld])
                  ? trim(preg_replace('/[^A-Za-z0-9_]/', '', (string) $_POST[$zd_praefix . $zd_feld])) : '';
            if ($zd_w === '' || $zd_w === $zd_vorgabe) {
                continue;
            }
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $zd_w)) {
                $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_ZUORDNUNG'), zd_e($zd_feld));
                continue;
            }
            $zd_neuz[$zd_feld] = $zd_w;
        }
        $zd_cfg[$zd_schluessel] = $zd_neuz;
    }

    /* Gespeichert wird IMMER. Was beanstandet wurde, ist oben uebergangen
     * worden und steht in $zd_fehler - der Bediener sieht die Meldung UND
     * behaelt alles, was in Ordnung war. */
    if (zd_config_speichern($zd_cfg)) {
        $zd_meldungen[] = $zd_fehler ? zd_t('EINST.GESPEICHERT_TEILWEISE') : zd_t('EINST.GESPEICHERT');
    } else {
        $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_SPEICHERN'), $zd_p['config']);
    }
    $zd_tab = 'tab-settings';
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verloere
 * Werte, die er nie gesehen hat. */
if ($zd_post && isset($_POST['save_mqtt'])) {
    $zd_mcfg = zd_config();
    $zd_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $zd_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($zd_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $zd_mtopic)) {
        $zd_fehler[] = zd_t('EINST.FEHLER_TOPIC');
    } else {
        $zd_mcfg['mqtt_topic'] = trim($zd_mtopic, '/');
    }
    $zd_bp = isset($_POST['broker_port']) ? trim((string) $_POST['broker_port']) : '';
    if (preg_match('/^[0-9]+$/', $zd_bp) && (int) $zd_bp >= 1 && (int) $zd_bp <= 65535) {
        $zd_mcfg['broker_port'] = (int) $zd_bp;
    } else {
        $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_BEREICH'),
            zd_t('EINST.L_BROKER_PORT'), 1, 65535);
    }
    $zd_bh = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) (isset($_POST['broker_host']) ? $_POST['broker_host'] : '')));
    if ($zd_bh !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{0,80}$/', $zd_bh)) {
        $zd_fehler[] = zd_t('EINST.FEHLER_BROKER');
    } else {
        $zd_mcfg['broker_host'] = $zd_bh;
    }
    $zd_ma = isset($_POST['mqtt_auffrischung']) ? trim((string) $_POST['mqtt_auffrischung']) : '';
    if (preg_match('/^[0-9]+$/', $zd_ma) && (int) $zd_ma <= 86400) {
        $zd_mcfg['mqtt_auffrischung'] = (int) $zd_ma;
    } else {
        $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_BEREICH'),
            zd_t('EINST.L_MQTT_AUFFR'), 0, 86400);
    }
    $zd_mcfg['broker_user'] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) (isset($_POST['broker_user']) ? $_POST['broker_user'] : '')));
    // Leeres Passwortfeld loescht nichts - sonst stuende irgendwann ein leeres
    // Passwort in der Konfiguration, ohne dass es jemand merkt.
    $zd_bpw = isset($_POST['broker_pw']) ? (string) $_POST['broker_pw'] : '';
    if ($zd_bpw !== '') {
        $zd_mcfg['broker_pw'] = $zd_bpw;
    }
    /* Auch hier gilt: jeder beanstandete Wert wurde oben uebergangen, also
     * behaelt er seinen bisherigen Stand - alles Uebrige wird gespeichert.
     * Bis 0.9.8 nahm ein leeres Port-Feld Themen-Praefix, Haken und
     * Broker-Passwort mit weg.
     *
     * Und ein misslungenes Schreiben wird gemeldet: bis 0.9.8 fehlte hier der
     * else-Zweig, den das Einstellungsformular hat. Der Bediener drueckte
     * Speichern, die Seite lud neu, alles sah aus wie vorher - und das
     * Broker-Passwort war still verloren. */
    if (zd_config_speichern($zd_mcfg)) {
        $zd_meldungen[] = $zd_fehler ? zd_t('EINST.GESPEICHERT_TEILWEISE') : zd_t('EINST.GESPEICHERT');
    } else {
        $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_SPEICHERN'), $zd_p['config']);
    }
    $zd_tab = 'tab-mqtt';
}

/* ---------------- Dienst ---------------- */
if ($zd_post && isset($_POST['dienst'])) {
    $zd_befehl = (string) $_POST['dienst'];
    list($zd_ok, $zd_ausgabe) = zd_dienst($zd_befehl);
    if ($zd_ok) {
        $zd_meldungen[] = zd_t('EINST.DIENST_' . strtoupper($zd_befehl)) . ' ' . zd_e($zd_ausgabe);
    } else {
        $zd_fehler[] = zd_e($zd_ausgabe);
    }
    $zd_tab = 'tab-settings';
}

/* ---------------- Neues Token ---------------- */
if ($zd_post && isset($_POST['token_neu'])) {
    $zd_cfg = zd_config();
    $zd_cfg['aktionstoken'] = zd_token_erzeugen();
    if (zd_config_speichern($zd_cfg)) {
        $zd_meldungen[] = zd_t('LOX.TOKEN_NEU');
    } else {
        $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_SPEICHERN'), $zd_p['config']);
    }
    $zd_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($zd_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($zd_p['log']), 0775, true);
    @file_put_contents($zd_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . zd_t('LOG.GELEERT') . "\n");
    $zd_meldungen[] = zd_t('LOG.GELEERT');
    $zd_tab = 'tab-log';
}

/* ---------------- Reiter Test ---------------- */
if ($zd_post && isset($_POST['test'])) {
    list($zd_stand, $zd_text) = zd_test_aktion((string) $_POST['test']);
    if ($zd_stand === 1) {
        $zd_meldungen[] = zd_e($zd_text);
    } else {
        $zd_fehler[] = zd_e($zd_text);
    }
    $zd_tab = 'tab-test';
}
if ($zd_post && isset($_POST['selbsttest'])) {
    $zd_testausgabe = zd_selbsttest_ausgabe();
    $zd_tab = 'tab-test';
}

/* ---------------- Satz-Assistent ---------------- */
if ($zd_post && isset($_POST['satztest'])) {
    list($zd_stand, $zd_text) = zd_satztest_ausloesen();
    if ($zd_stand === 1) {
        $zd_meldungen[] = zd_e($zd_text);
    } else {
        $zd_fehler[] = zd_e($zd_text);
    }
    $zd_tab = 'tab-test';
}

/* ---------------- Temperatur-Umrechnung uebernehmen ----------------
 *
 * Ein eigener Knopf und ein eigener Handler, damit die Wahl nicht am
 * Speichern der gesamten Einstellungen haengt: der Vorschlag steht im Reiter
 * Test, und wer ihn annimmt, soll nicht in einen anderen Reiter wechseln. */
if ($zd_post && isset($_POST['temp_uebernehmen'])) {
    $zd_wahl = (string) $_POST['temp_uebernehmen'];
    if (!in_array($zd_wahl, array('roh', 'kelvin10', 'zehntel'), true)) {
        $zd_fehler[] = zd_t('TEST.M_TEMP_UNGUELTIG');
    } else {
        $zd_tcfg = zd_config();
        $zd_tcfg['temp_umrechnung'] = $zd_wahl;
        if (zd_config_speichern($zd_tcfg)) {
            $zd_meldungen[] = sprintf(zd_t('TEST.M_TEMP_UEBERNOMMEN'), zd_e($zd_wahl));
        } else {
            $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_SPEICHERN'), $zd_p['config']);
        }
    }
    $zd_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$zd_cfg = zd_config();
$zd_token = zd_token();
$zd_geraete = zd_geraete();
$zd_werte = zd_werte();
$zd_zustand = zd_zustand();
$zd_alter = zd_alter();
$zd_pid = zd_dienst_pid();
$zd_mqtt = zd_mqtt_zustand();
$zd_gw = zd_gateway_fassung();
/* Welcher der drei Warntexte gilt? Die Abo-Seite gibt es nur unter V1;
 * unter V2 leitet das Gateway von sich aus weiter. Ist keine Fassung
 * auffindbar, wird NICHTS behauptet - beide Faelle werden genannt. */
$zd_abo_schl = !$zd_gw['gefunden'] ? 'ABO_WARNUNG_UNBEKANNT'
             : ($zd_gw['fassung'] >= 2 ? 'ABO_WARNUNG_V2' : 'ABO_WARNUNG_V1');
$zd_host = zd_host();
$zd_basis = 'http://' . $zd_host . '/plugins/' . $zd_p['plugin'] . '/index.php';
// Nur das Ende lesen, nicht die ganze Datei - siehe zd_log_ende().
$zd_logzeilen = zd_log_ende($zd_p['log'], 400);

$zd_rahmen = class_exists('LBWeb', false);
if ($zd_rahmen) {
    LBWeb::lbheader('Zendure SolarFlow', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
/* Eine Tabelle, die breiter ist als das Fenster.
   .sm-tbl hat width:100%, .sm-wrap hat max-width ohne Ueberlauf - die
   ueberzaehlige Spalte steht damit AUSSERHALB und ist unerreichbar, nicht
   bloss unbequem. Die Geraetetabelle hat zwoelf Spalten mit Eingabefeldern.
   Hausstandard: jede Tabelle mit mehr als sechs Spalten oder mit
   Eingabefeldern kommt in einen Behaelter der Klasse sm-breit.
   (Ohne spitze Klammern geschrieben - ein Beispiel-Tag in einem Kommentar
   verwirrt jeden Zaehler, der die Tag-Bilanz der fertigen Seite prueft.
   Beim Bau von 0.9.10 genau daran haengengeblieben.) */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen: mit data-role="none"
   faellt der eingebaute Pfeil am rechten Rand nicht auf, und das Feld sieht
   aus wie ein Textfeld. Die Raute im SVG wird als %23 geschrieben - eine rohe
   Raute beendet in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
</style>
<div class="sm-wrap">

<?php foreach ($zd_meldungen as $zd_m) { ?>
<div class="sm-hinweis"><?= $zd_m ?></div>
<?php } ?>
<?php if ($zd_fehler) { ?>
<div class="sm-fehler"><b><?= zd_e(zd_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($zd_fehler as $zd_f) { ?><li><?= $zd_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<!-- ================= Statuskacheln ================= -->
<div class="sm-kacheln">
  <div class="sm-kachel"><?= zd_e(zd_t('ALLG.DIENST')) ?>
    <b class="<?= $zd_pid ? 'sm-an' : 'sm-aus' ?>"><?= $zd_pid ? zd_e(zd_t('ALLG.LAEUFT')) : zd_e(zd_t('ALLG.GESTOPPT')) ?></b>
    <span class="sm-hilfe"><?= $zd_pid ? 'PID ' . (int) $zd_pid : zd_e(zd_t('ALLG.KEINE_PID')) ?></span>
  </div>
  <div class="sm-kachel"><?= zd_e(zd_t('ALLG.LETZTER_ABRUF')) ?>
    <b><?= $zd_alter < 0 ? '&ndash;' : (int) $zd_alter . ' s' ?></b>
    <span class="sm-hilfe"><?= $zd_alter < 0 ? zd_e(zd_t('ALLG.NIE')) : zd_e(date('d.m.Y H:i:s', time() - $zd_alter)) ?></span>
  </div>
  <div class="sm-kachel"><?= zd_e(zd_t('ALLG.GERAETE')) ?>
    <b><?= count($zd_geraete) ?></b>
    <span class="sm-hilfe"><?php
      $zd_ok = 0;
      foreach ($zd_werte as $zd_w) { if (!empty($zd_w['ok'])) { $zd_ok++; } }
      echo (int) $zd_ok . ' ' . zd_e(zd_t('ALLG.ERREICHBAR'));
    ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $zd_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $zd_mqtt['autostart'] ? zd_e(zd_t('ALLG.EIN')) : zd_e(zd_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= zd_e(zd_t('ALLG.GATEWAY')) ?></span>
  </div>
</div>

<?php if (!empty($zd_zustand['fehler'])) { ?>
<div class="sm-warnung"><b><?= zd_e(zd_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= zd_e($zd_zustand['fehler']) ?></div>
<?php } ?>

<?php foreach ($zd_werte as $zd_nr => $zd_w) { ?>
<div class="sm-hinweis">
<b><?= zd_e($zd_w['name']) ?></b> (<?= zd_e(zd_t('ALLG.GERAET')) ?> <?= zd_e($zd_nr) ?>,
<span class="sm-mono"><?= zd_e($zd_w['art']) ?></span>)
&middot; <?= zd_e(zd_t('ALLG.SOC')) ?> <b><?= $zd_w['soc'] === null ? '&ndash;' : zd_e($zd_w['soc']) . ' %' ?></b>
&middot; <?= zd_e(zd_t('ALLG.PV')) ?> <?= $zd_w['pv'] === null ? '&ndash;' : zd_e($zd_w['pv']) . ' W' ?>
&middot; <?= zd_e(zd_t('ALLG.BATTERIE')) ?> <?= $zd_w['batp'] === null ? '&ndash;' : zd_e($zd_w['batp']) . ' W' ?>
&middot; <?= zd_e(zd_t('ALLG.HAUS')) ?> <?= $zd_w['haus'] === null ? '&ndash;' : zd_e($zd_w['haus']) . ' W' ?>
&middot; <?= zd_e(zd_t('ALLG.PACKS')) ?> <?= (int) $zd_w['packs'] ?>
<?php if (!empty($zd_cfg['energie_ein'])) {
    $zd_eh = zd_energie_summe((int) $zd_nr, 'tag');
    $zd_ekz = zd_energie_kennzahlen((int) $zd_nr,
        isset($zd_geraete[(int) $zd_nr]) ? $zd_geraete[(int) $zd_nr] : array());
?>
<div class="sm-hilfe" style="margin-top:6px;">
<?= zd_e(zd_t('ALLG.E_HEUTE')) ?>
<?= zd_e(zd_t('ALLG.E_LADEN')) ?> <b><?= zd_e(round($zd_eh['laden'] / 1000, 2)) ?> kWh</b>,
<?= zd_e(zd_t('ALLG.E_ENTLADEN')) ?> <b><?= zd_e(round($zd_eh['entladen'] / 1000, 2)) ?> kWh</b>,
<?= zd_e(zd_t('ALLG.E_PV')) ?> <b><?= zd_e(round($zd_eh['pv'] / 1000, 2)) ?> kWh</b>
<?php if ($zd_ekz['zyklen'] !== null) { ?>
&middot; <?= zd_e(zd_t('ALLG.E_ZYKLEN')) ?> <b><?= zd_e($zd_ekz['zyklen']) ?></b>
<?php } ?>
<?php if ($zd_ekz['wirkungsgrad'] !== null) { ?>
&middot; <?= zd_e(zd_t('ALLG.E_WIRKUNG')) ?> <b><?= zd_e($zd_ekz['wirkungsgrad']) ?> %</b>
<?php } ?>
</div>
<?php } ?>
<?php
/* Welcher Tag wird gezeigt? Bis 0.9.11 immer der heutige - alles davor lag
 * da und war unerreichbar. Die Auswahl steht in der Adresse, damit ein
 * Reiterwechsel sie nicht verliert. */
$zd_vtage = zd_verlauf_tage((int) $zd_nr);
$zd_vtag = (isset($_GET['tag']) && preg_match('/^[0-9]{8}$/', (string) $_GET['tag'])
            && in_array((string) $_GET['tag'], $zd_vtage, true))
           ? (string) $_GET['tag'] : (isset($zd_vtage[0]) ? $zd_vtage[0] : date('Ymd'));
?>
<div style="margin-top:8px;"><?= zd_soc_svg(zd_verlauf_lesen((int) $zd_nr, $zd_vtag), $zd_vtag) ?></div>
<?php if (count($zd_vtage) > 0) { ?>
<div class="sm-knopfreihe" style="margin-top:6px;">
<?php foreach (array_slice($zd_vtage, 0, 10) as $zd_vt) { ?>
  <a class="sm-btn <?= $zd_vt === $zd_vtag ? 'sm-b-aktion' : 'sm-b-lesen' ?>"
     style="min-width:auto;padding:4px 10px !important;font-size:0.85em;"
     href="index.php?form=settings&amp;tag=<?= zd_e($zd_vt) ?>"><?= zd_e(
       substr($zd_vt, 6, 2) . '.' . substr($zd_vt, 4, 2) . '.') ?></a>
<?php } ?>
  <form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="verlauf_tag" value="<?= zd_e($zd_vtag) ?>">
    <button data-role="none" class="sm-btn sm-b-technik"
            style="min-width:auto;padding:4px 10px !important;font-size:0.85em;"
            type="submit" name="verlauf_csv" value="<?= zd_e($zd_nr) ?>"><?= zd_e(zd_t('ALLG.CSV')) ?></button>
  </form>
</div>
<?php } ?>
<div class="sm-hilfe"><?= zd_e(zd_t('ALLG.VERLAUF_HINWEIS')) ?></div>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar und Eingaben in anderen Reitern gehen nicht verloren.
     Welcher Reiter offen ist, entscheidet der Server - bis 0.9.0 setzte erst
     das Skript die Klasse sm-active, und weil .sm-seite auf display:none
     steht, war die Seite ohne JavaScript vollstaendig leer. Der Kommentar an
     dieser Stelle behauptete das Gegenteil. -->
<div class="sm-tabs">
<?php foreach ($zd_reiter as $zd_k => $zd_schl): $zd_id = 'tab-' . $zd_k; ?>
	<a class="sm-tab<?= $zd_tab === $zd_id ? ' sm-active' : '' ?>" data-ziel="<?= zd_e($zd_id) ?>"
	   href="index.php?form=<?= zd_e($zd_k) ?>"><?= $zd_schl === null ? 'MQTT' : zd_e(zd_t($zd_schl)) ?></a>
<?php endforeach; ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $zd_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= zd_e(zd_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= zd_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= zd_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= zd_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= zd_e(zd_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= zd_e(zd_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= zd_e(zd_t('EINST.K_STOPP')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= zd_e(zd_t('EINST.H_GERAETE')) ?></h2>
<div class="sm-hinweis"><?= zd_t('EINST.GERAETE_ERKLAERUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:28px;">#</th><th><?= zd_e(zd_t('EINST.T_NAME')) ?></th>
    <th style="width:90px;"><?= zd_e(zd_t('EINST.T_ART')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_IP')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_PRODKEY')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_DEVICEID')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_SN')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_MODELL')) ?></th>
    <th style="width:110px;"><?= zd_e(zd_t('EINST.T_SATZ')) ?></th>
    <th style="width:80px;"><?= zd_e(zd_t('EINST.T_MAXLADEN')) ?></th>
    <th style="width:80px;"><?= zd_e(zd_t('EINST.T_MAXENTLADEN')) ?></th>
    <th style="width:110px;"><?= zd_e(zd_t('EINST.T_QUITTUNGSFELD')) ?></th>
    <th style="width:90px;"><?= zd_e(zd_t('EINST.T_KAPAZITAET')) ?></th></tr>
<?php
$zd_roh = isset($zd_cfg['geraete']) && is_array($zd_cfg['geraete']) ? $zd_cfg['geraete'] : array();
for ($zd_i = 0; $zd_i < 6; $zd_i++) {
    $zd_z = isset($zd_roh[$zd_i]) && is_array($zd_roh[$zd_i]) ? $zd_roh[$zd_i] : array();
    $zd_v = function ($k) use ($zd_z) { return isset($zd_z[$k]) ? (string) $zd_z[$k] : ''; };
?>
<tr>
<td><?= $zd_i + 1 ?></td>
<td><input data-role="none" type="text" name="g_name[]" value="<?= zd_e($zd_v('name')) ?>" size="12"></td>
<td><select data-role="none" name="g_art[]">
    <option value="http"<?= $zd_v('art') !== 'mqtt' ? ' selected' : '' ?>>HTTP</option>
    <option value="mqtt"<?= $zd_v('art') === 'mqtt' ? ' selected' : '' ?>>MQTT</option>
</select></td>
<td><input data-role="none" type="text" name="g_ip[]" value="<?= zd_e($zd_v('ip')) ?>" size="14" placeholder="<?= $zd_i === 0 ? '192.168.1.50' : '' ?>"></td>
<td><input data-role="none" type="text" name="g_prodkey[]" value="<?= zd_e($zd_v('prodkey')) ?>" size="12"></td>
<td><input data-role="none" type="text" name="g_deviceid[]" value="<?= zd_e($zd_v('deviceid')) ?>" size="12"></td>
<td><input data-role="none" type="text" name="g_sn[]" value="<?= zd_e($zd_v('sn')) ?>" size="12"></td>
<td><select data-role="none" name="g_modell[]">
    <option value=""><?= zd_e(zd_t('EINST.MODELL_FREI')) ?></option>
<?php foreach (array_keys(zd_modelle()) as $zd_mo) { ?>
    <option value="<?= zd_e($zd_mo) ?>"<?= $zd_v('modell') === $zd_mo ? ' selected' : '' ?>><?= zd_e($zd_mo) ?></option>
<?php } ?>
</select></td>
<td><select data-role="none" name="g_satz[]">
    <option value=""><?= zd_e(zd_t('EINST.SATZ_AUTO')) ?></option>
<?php foreach (zd_befehlssaetze() as $zd_s) { ?>
    <option value="<?= zd_e($zd_s) ?>"<?= $zd_v('satz') === $zd_s ? ' selected' : '' ?>><?= zd_e($zd_s) ?></option>
<?php } ?>
</select></td>
<td><input data-role="none" type="text" name="g_max_laden[]" value="<?= zd_e($zd_v('max_laden')) ?>" size="4"></td>
<td><input data-role="none" type="text" name="g_max_entladen[]" value="<?= zd_e($zd_v('max_entladen')) ?>" size="4"></td>
<td><input data-role="none" type="text" name="g_quittungsfeld[]" value="<?= zd_e($zd_v('quittungsfeld')) ?>" size="12" placeholder="outputLimit"></td>
<td><input data-role="none" type="text" name="g_kapazitaet[]" value="<?= $zd_v('kapazitaet_wh') !== '' ? zd_e(rtrim(rtrim(number_format((float) $zd_v('kapazitaet_wh') / 1000, 3, '.', ''), '0'), '.')) : '' ?>" size="6" placeholder="1.92"></td>
</tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?= zd_t('EINST.GERAETE_HILFE') ?></div>
<div class="sm-hilfe"><?= zd_t('EINST.H_QUITTUNGSFELD') ?></div>

<h2><?= zd_e(zd_t('EINST.H_KONFIG')) ?></h2>
<div class="sm-hinweis"><?= zd_t('EINST.KONFIG_ERKLAERUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= zd_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= zd_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="konfig_aus" value="1"><?= zd_e(zd_t('EINST.K_KONFIG_AUS')) ?></button>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="konfig_erg" value="1"><?= zd_e(zd_t('EINST.K_KONFIG_ERG')) ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="konfig_datei" accept=".json,application/json" style="max-width:220px;">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="konfig_ein" value="1"><?= zd_e(zd_t('EINST.K_KONFIG_EIN')) ?></button>
  </form>
</div>

<h2><?= zd_e(zd_t('EINST.H_ZUORDNUNG')) ?></h2>
<div class="sm-hinweis"><?= zd_t('EINST.ZUORDNUNG_ERKLAERUNG') ?></div>
<?php
/* Die zuletzt gelesenen Rohwerte des ersten Geraets - damit man beim
 * Eintragen sieht, ob die Zuordnung greift. Ohne Abruf bleibt die Spalte
 * leer; ein erfundener Wert waere schlimmer als gar keiner. */
$zd_cache = zd_cache();
$zd_roh1 = array();
$zd_rohpack1 = array();
if (isset($zd_cache['zustaende']) && is_array($zd_cache['zustaende'])) {
    $zd_erste = reset($zd_cache['zustaende']);
    if (is_array($zd_erste)) {
        $zd_roh1 = isset($zd_erste['eigenschaften']) && is_array($zd_erste['eigenschaften'])
                 ? $zd_erste['eigenschaften'] : array();
        if (isset($zd_erste['packs']) && is_array($zd_erste['packs'])) {
            $zd_p1 = reset($zd_erste['packs']);
            $zd_rohpack1 = is_array($zd_p1) ? $zd_p1 : array();
        }
    }
}
$zd_zjetzt  = zd_zuordnung($zd_cfg);
$zd_zpjetzt = zd_zuordnung($zd_cfg, true);
foreach (array(
    array('z_',  zd_feldkarte(), $zd_zjetzt,  $zd_roh1,     'EINST.T_ZUORD_GERAET'),
    array('zp_', zd_packkarte(), $zd_zpjetzt, $zd_rohpack1, 'EINST.T_ZUORD_PACK'),
) as $zd_zb) {
    list($zd_praefix, $zd_karte, $zd_gilt, $zd_rohwerte, $zd_ueber) = $zd_zb;
?>
<h3><?= zd_e(zd_t($zd_ueber)) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('EINST.T_ZUORD_FELD')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_ZUORD_VORGABE')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_ZUORD_EIGEN')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_ZUORD_WERT')) ?></th></tr>
<?php foreach ($zd_karte as $zd_feld => $zd_vorgabe) {
    $zd_gilt_name = isset($zd_gilt[$zd_feld]) ? $zd_gilt[$zd_feld] : $zd_vorgabe;
    $zd_eigen = $zd_gilt_name === $zd_vorgabe ? '' : $zd_gilt_name;
    $zd_da = array_key_exists($zd_gilt_name, $zd_rohwerte);
?>
<tr><td><span class="sm-mono"><?= zd_e($zd_feld) ?></span></td>
    <td><span class="sm-mono"><?= zd_e($zd_vorgabe) ?></span></td>
    <td><input data-role="none" type="text" name="<?= zd_e($zd_praefix . $zd_feld) ?>"
               value="<?= zd_e($zd_eigen) ?>" size="18" placeholder="<?= zd_e($zd_vorgabe) ?>"></td>
    <td><?= $zd_da ? '<span class="sm-mono">' . zd_e((string) $zd_rohwerte[$zd_gilt_name]) . '</span>'
                   : ($zd_rohwerte ? '<span class="sm-aus">' . zd_e(zd_t('EINST.ZUORD_FEHLT')) . '</span>'
                                   : '&ndash;') ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<div class="sm-hilfe"><?= zd_t('EINST.ZUORDNUNG_HILFE') ?></div>

<h2><?= zd_e(zd_t('EINST.H_MODELLE')) ?></h2>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('EINST.T_MODELL')) ?></th><th><?= zd_e(zd_t('EINST.T_SATZ')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_MAXLADEN')) ?></th><th><?= zd_e(zd_t('EINST.T_MAXENTLADEN')) ?></th>
    <th><?= zd_e(zd_t('EINST.T_MAXSOLAR')) ?></th></tr>
<?php foreach (zd_modelle() as $zd_mo => $zd_d) { ?>
<tr><td><span class="sm-mono"><?= zd_e($zd_mo) ?></span></td><td><span class="sm-mono"><?= zd_e($zd_d[0]) ?></span></td>
    <td><?= $zd_d[1] > 0 ? (int) $zd_d[1] . ' W' : zd_e(zd_t('EINST.KEIN_ACLADEN')) ?></td>
    <td><?= (int) $zd_d[2] ?> W</td><td><?= (int) $zd_d[3] ?> W</td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= zd_t('EINST.MODELLE_HILFE') ?></div>

<h2><?= zd_e(zd_t('EINST.H_TAKT')) ?></h2>
<div class="sm-feld">
  <label for="intervall"><?= zd_e(zd_t('EINST.L_INTERVALL')) ?></label>
  <input data-role="none" type="number" id="intervall" name="intervall" value="<?= (int) $zd_cfg['intervall'] ?>" min="5" max="900">
  <div class="sm-hilfe"><?= zd_t('EINST.H_INTERVALL') ?></div>
</div>
<div class="sm-feld">
  <label for="verlauf_tage"><?= zd_e(zd_t('EINST.L_VERLAUF_TAGE')) ?></label>
  <input data-role="none" type="number" id="verlauf_tage" name="verlauf_tage" value="<?= (int) $zd_cfg['verlauf_tage'] ?>" min="1" max="90">
</div>
<div class="sm-feld">
  <label for="temp_umrechnung"><?= zd_e(zd_t('EINST.L_TEMP')) ?></label>
  <select data-role="none" id="temp_umrechnung" name="temp_umrechnung">
<?php foreach (array('roh', 'kelvin10', 'zehntel') as $zd_tu) { ?>
    <option value="<?= $zd_tu ?>"<?= $zd_cfg['temp_umrechnung'] === $zd_tu ? ' selected' : '' ?>><?= zd_e(zd_t('EINST.TEMP_' . strtoupper($zd_tu))) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= zd_t('EINST.H_TEMP') ?></div>
</div>

<h2><?= zd_e(zd_t('EINST.H_STEUERUNG')) ?></h2>
<div class="sm-warnung"><?= zd_t('EINST.STEUERUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="steuerung_ein" value="1" <?= !empty($zd_cfg['steuerung_ein']) ? 'checked' : '' ?>>
    <?= zd_e(zd_t('EINST.L_STEUERUNG_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="schreibbremse"><?= zd_e(zd_t('EINST.L_SCHREIBBREMSE')) ?></label>
  <input data-role="none" type="number" id="schreibbremse" name="schreibbremse" value="<?= (int) $zd_cfg['schreibbremse'] ?>" min="0" max="600">
  <div class="sm-hilfe"><?= zd_t('EINST.H_SCHREIBBREMSE') ?></div>
</div>
<div class="sm-feld">
  <label for="schrittweite"><?= zd_e(zd_t('EINST.L_SCHRITTWEITE')) ?></label>
  <input data-role="none" type="number" id="schrittweite" name="schrittweite" value="<?= (int) $zd_cfg['schrittweite'] ?>" min="1" max="500">
  <div class="sm-hilfe"><?= zd_t('EINST.H_SCHRITTWEITE') ?></div>
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= zd_e(zd_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="number" id="wartezeit" name="wartezeit" value="<?= (int) $zd_cfg['wartezeit'] ?>" min="0" max="20">
  <div class="sm-hilfe"><?= zd_t('EINST.H_WARTEZEIT') ?></div>
</div>
<div class="sm-feld">
  <label for="quittung_nachlauf"><?= zd_e(zd_t('EINST.L_QUITTUNG_NACHLAUF')) ?></label>
  <input data-role="none" type="number" id="quittung_nachlauf" name="quittung_nachlauf" value="<?= (int) $zd_cfg['quittung_nachlauf'] ?>" min="0" max="900">
  <div class="sm-hilfe"><?= zd_t('EINST.H_QUITTUNG_NACHLAUF') ?></div>
</div>

<h2><?= zd_e(zd_t('EINST.H_WIEDERHOLUNG')) ?></h2>
<div class="sm-hinweis"><?= zd_t('EINST.WIEDERHOLUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="totband_w"><?= zd_e(zd_t('EINST.L_TOTBAND_W')) ?></label>
  <input data-role="none" type="number" id="totband_w" name="totband_w" value="<?= (int) $zd_cfg['totband_w'] ?>" min="0" max="5000">
  <div class="sm-hilfe"><?= zd_t('EINST.H_TOTBAND_W') ?></div>
</div>
<div class="sm-feld">
  <label for="totband_auffrischung"><?= zd_e(zd_t('EINST.L_TOTBAND_AUFFR')) ?></label>
  <input data-role="none" type="number" id="totband_auffrischung" name="totband_auffrischung" value="<?= (int) $zd_cfg['totband_auffrischung'] ?>" min="0" max="86400">
  <div class="sm-hilfe"><?= zd_t('EINST.H_TOTBAND_AUFFR') ?></div>
</div>

<h2><?= zd_e(zd_t('EINST.H_RUECKFALL')) ?></h2>
<div class="sm-warnung"><?= zd_t('EINST.RUECKFALL_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="rueckfall_min"><?= zd_e(zd_t('EINST.L_RUECKFALL_MIN')) ?></label>
  <input data-role="none" type="number" id="rueckfall_min" name="rueckfall_min" value="<?= (int) $zd_cfg['rueckfall_min'] ?>" min="0" max="1440">
  <div class="sm-hilfe"><?= zd_t('EINST.H_RUECKFALL_MIN') ?></div>
</div>
<div class="sm-feld">
  <label for="befehl_verfall_s"><?= zd_e(zd_t('EINST.L_VERFALL')) ?></label>
  <input data-role="none" type="number" id="befehl_verfall_s" name="befehl_verfall_s" value="<?= (int) $zd_cfg['befehl_verfall_s'] ?>" min="0" max="86400">
  <div class="sm-hilfe"><?= zd_t('EINST.H_VERFALL') ?></div>
</div>

<h2><?= zd_e(zd_t('EINST.H_SCHUTZ')) ?></h2>
<div class="sm-hinweis"><?= zd_t('EINST.SCHUTZ_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="schutz_ein" value="1" <?= !empty($zd_cfg['schutz_ein']) ? 'checked' : '' ?>>
    <?= zd_e(zd_t('EINST.L_SCHUTZ_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="schutz_soc_min"><?= zd_e(zd_t('EINST.L_SCHUTZ_SOC_MIN')) ?></label>
  <input data-role="none" type="number" id="schutz_soc_min" name="schutz_soc_min" value="<?= (int) $zd_cfg['schutz_soc_min'] ?>" min="0" max="100">
</div>
<div class="sm-feld">
  <label for="schutz_soc_max"><?= zd_e(zd_t('EINST.L_SCHUTZ_SOC_MAX')) ?></label>
  <input data-role="none" type="number" id="schutz_soc_max" name="schutz_soc_max" value="<?= (int) $zd_cfg['schutz_soc_max'] ?>" min="0" max="100">
</div>
<div class="sm-feld">
  <label for="schutz_temp_min"><?= zd_e(zd_t('EINST.L_SCHUTZ_TEMP_MIN')) ?></label>
  <input data-role="none" type="text" id="schutz_temp_min" name="schutz_temp_min" value="<?= zd_e($zd_cfg['schutz_temp_min']) ?>" size="8">
</div>
<div class="sm-feld">
  <label for="schutz_temp_max"><?= zd_e(zd_t('EINST.L_SCHUTZ_TEMP_MAX')) ?></label>
  <input data-role="none" type="text" id="schutz_temp_max" name="schutz_temp_max" value="<?= zd_e($zd_cfg['schutz_temp_max']) ?>" size="8">
  <div class="sm-hilfe"><?= zd_t('EINST.H_SCHUTZ_TEMP') ?></div>
</div>

<h2><?= zd_e(zd_t('EINST.H_ENERGIE')) ?></h2>
<div class="sm-hinweis"><?= zd_t('EINST.ENERGIE_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="energie_ein" value="1" <?= !empty($zd_cfg['energie_ein']) ? 'checked' : '' ?>>
    <?= zd_e(zd_t('EINST.L_ENERGIE_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="energie_monate"><?= zd_e(zd_t('EINST.L_ENERGIE_MONATE')) ?></label>
  <input data-role="none" type="number" id="energie_monate" name="energie_monate" value="<?= (int) $zd_cfg['energie_monate'] ?>" min="1" max="120">
</div>

<?php /* Broker und MQTT standen hier bis zu dieser Fassung.
         Beides wohnt jetzt vollstaendig im Reiter MQTT. */ ?>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= zd_e(zd_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $zd_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?= zd_e(zd_t('EINST.H_BROKER')) ?></h2>
<div class="sm-hilfe"><?= zd_t('EINST.BROKER_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="broker_host"><?= zd_e(zd_t('EINST.L_BROKER_HOST')) ?></label>
  <input data-role="none" type="text" id="broker_host" name="broker_host" value="<?= zd_e($zd_cfg['broker_host']) ?>" placeholder="<?= zd_e($zd_mqtt['broker']) ?>">
</div>
<div class="sm-feld">
  <label for="broker_port"><?= zd_e(zd_t('EINST.L_BROKER_PORT')) ?></label>
  <input data-role="none" type="number" id="broker_port" name="broker_port" value="<?= (int) $zd_cfg['broker_port'] ?>" min="1" max="65535">
</div>
<div class="sm-feld">
  <label for="broker_user"><?= zd_e(zd_t('EINST.L_BROKER_USER')) ?></label>
  <input data-role="none" type="text" id="broker_user" name="broker_user" value="<?= zd_e($zd_cfg['broker_user']) ?>" placeholder="<?= zd_e($zd_mqtt['user']) ?>">
</div>
<div class="sm-feld">
  <label for="broker_pw"><?= zd_e(zd_t('EINST.L_BROKER_PW')) ?></label>
  <input data-role="none" type="password" id="broker_pw" name="broker_pw" value="" placeholder="<?= $zd_cfg['broker_pw'] !== '' ? zd_e(sprintf(zd_t('EINST.PW_GESETZT'), strlen((string) $zd_cfg['broker_pw']))) : zd_e(zd_t('EINST.PW_LEER')) ?>">
  <div class="sm-hilfe"><?= zd_t('EINST.H_BROKER_PW') ?></div>
</div>
<h2>MQTT</h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($zd_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= zd_e(zd_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= zd_e(zd_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= zd_e($zd_cfg['mqtt_topic']) ?>" placeholder="zendure">
</div>
<div class="sm-feld">
  <label for="mqtt_auffrischung"><?= zd_e(zd_t('EINST.L_MQTT_AUFFR')) ?></label>
  <input data-role="none" type="number" id="mqtt_auffrischung" name="mqtt_auffrischung" value="<?= (int) $zd_cfg['mqtt_auffrischung'] ?>" min="0" max="86400">
  <div class="sm-hilfe"><?= zd_t('EINST.H_MQTT_AUFFR') ?></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= zd_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= zd_e(zd_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= zd_e(zd_t('MQTT.H_ZUSTAND')) ?></h2>
<p class="sm-hilfe"><?= zd_t('MQTT.GATEWAY_ERKLAERUNG') ?></p>
<?php if (!$zd_mqtt['gefunden']) { ?>
<div class="sm-fehler"><?= zd_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$zd_mqtt['autostart']) { ?>
<div class="sm-fehler"><?= zd_t('MQTT.AUTOSTART_AUS') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= zd_t('MQTT.AUTOSTART_EIN') ?></div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('ALLG.EIGENSCHAFT')) ?></th><th><?= zd_e(zd_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= zd_e(zd_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $zd_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $zd_mqtt['autostart'] ? zd_e(zd_t('ALLG.EIN')) : zd_e(zd_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= zd_e(zd_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= zd_e($zd_mqtt['broker']) ?>:<?= zd_e($zd_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= zd_e(zd_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $zd_mqtt['udpport'] ?></span></td></tr>
<tr><td><?= zd_e(zd_t('MQTT.T_LOKAL')) ?></td><td><?= $zd_mqtt['lokal'] ? zd_e(zd_t('ALLG.JA')) : zd_e(zd_t('ALLG.NEIN')) ?></td></tr>
<tr><td><?= zd_e(zd_t('MQTT.T_PLUGIN')) ?></td><td class="<?= !empty($zd_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($zd_cfg['mqtt_ein']) ? zd_e(zd_t('ALLG.EIN')) : zd_e(zd_t('ALLG.AUS')) ?></td></tr>
</table>

<h2><?= zd_e(zd_t('MQTT.H_GERAETE_UMSTELLEN')) ?></h2>
<div class="sm-step"><?= zd_t('MQTT.UMSTELLEN_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('ALLG.EIGENSCHAFT')) ?></th><th><?= zd_e(zd_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= zd_e(zd_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= zd_e($zd_mqtt['broker'] !== '' && $zd_mqtt['broker'] !== 'localhost' ? $zd_mqtt['broker'] : $zd_host) ?></span></td></tr>
<tr><td><?= zd_e(zd_t('MQTT.T_PORT')) ?></td><td><span class="sm-mono"><?= zd_e($zd_mqtt['brokerport'] !== '' ? $zd_mqtt['brokerport'] : '1883') ?></span></td></tr>
<tr><td><?= zd_e(zd_t('MQTT.T_USER')) ?></td><td><span class="sm-mono"><?= zd_e($zd_mqtt['user']) ?></span></td></tr>
</table>
<div class="sm-warnung"><?= zd_t('MQTT.UMSTELLEN_WARNUNG') ?></div>
</div>

<h2><?= zd_e(zd_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= zd_t('MQTT.' . $zd_abo_schl) ?><?php
if ($zd_gw['gefunden']) { ?> <span class="sm-mono"><?= zd_e(sprintf(zd_t('MQTT.ABO_GEMESSEN'), (int) $zd_gw['fassung'])) ?></span><?php } ?></div>
<div class="sm-step"><?= zd_t('MQTT.ABO_SCHRITTE') ?>
<p><span class="sm-mono"><?= zd_e($zd_cfg['mqtt_topic']) ?>/#</span></p>
</div>

<h2><?= zd_e(zd_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= zd_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('MQTT.T_THEMA')) ?></th><th><?= zd_e(zd_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (zd_mqtt_themen() as $zd_thema => $zd_schluessel) { ?>
<tr><td><span class="sm-mono"><?= zd_e($zd_cfg['mqtt_topic'] . '/' . $zd_thema) ?></span></td>
    <td><?= zd_t($zd_schluessel) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= zd_t('MQTT.PLATZHALTER') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $zd_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= zd_e(zd_t('LOX.H_TITEL')) ?></h2>
<p><?= zd_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S1_TITEL')) ?></b><br><?= zd_t('LOX.S1_TEXT') ?></div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S2_TITEL')) ?></b><br>
<?= zd_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= zd_e($zd_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= zd_t('MQTT.' . $zd_abo_schl) ?><?php
if ($zd_gw['gefunden']) { ?> <span class="sm-mono"><?= zd_e(sprintf(zd_t('MQTT.ABO_GEMESSEN'), (int) $zd_gw['fassung'])) ?></span><?php } ?></div>
</div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S3_TITEL')) ?></b><br>
<?= zd_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('ALLG.EIGENSCHAFT')) ?></th><th><?= zd_e(zd_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= zd_e(zd_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= zd_e(zd_endpunkt_url(array('aktion' => 'status', 'geraet' => 1))) ?></span></td></tr>
<tr><td><?= zd_e(zd_t('LOX.T_ZYKLUS')) ?></td><td>60 <?= zd_e(zd_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?= zd_t('LOX.S3_BEFEHLE') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('LOX.T_TITEL')) ?></th><th><?= zd_e(zd_t('LOX.T_BEFEHL')) ?></th>
    <th><?= zd_e(zd_t('LOX.T_EINHEIT')) ?></th><th><?= zd_e(zd_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (zd_status_felder() as $zd_feld => $zd_info) { ?>
<tr><td><span class="sm-mono">ZENDURE_1_<?= zd_e($zd_feld) ?></span></td>
    <td><span class="sm-mono"><?= zd_e(zd_check($zd_feld)) ?></span></td>
    <td><?= zd_e($zd_info[0]) ?></td><td><?= zd_t($zd_info[1]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= zd_t('LOX.S3_STRICH') ?></div>
<?php if (count($zd_werte) > 1) { ?>
<p><b><?= zd_e(zd_t('LOX.MEHRERE')) ?></b></p>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('ALLG.GERAET')) ?></th><th><?= zd_e(zd_t('EINST.T_NAME')) ?></th><th><?= zd_e(zd_t('LOX.T_ADRESSE')) ?></th></tr>
<?php foreach ($zd_werte as $zd_nr => $zd_w) { ?>
<tr><td><?= zd_e($zd_nr) ?></td><td><?= zd_e($zd_w['name']) ?></td>
    <td><span class="sm-mono"><?= zd_e(zd_endpunkt_url(array('aktion' => 'status', 'geraet' => $zd_nr))) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-feld">
  <label for="vorlage_geraet"><?= zd_e(zd_t('LOX.L_VORLAGE_GERAET')) ?></label>
  <select data-role="none" id="vorlage_geraet" name="vorlage_geraet">
<?php for ($zd_vi = 1; $zd_vi <= max(1, count($zd_geraete)); $zd_vi++) { ?>
    <option value="<?= $zd_vi ?>"><?= $zd_vi ?><?php
      echo isset($zd_geraete[$zd_vi]) ? ' - ' . zd_e($zd_geraete[$zd_vi]['name']) : ''; ?></option>
<?php } ?>
  </select>
</div>
<div class="sm-feld">
  <label for="vorlage_zeitraum"><?= zd_e(zd_t('LOX.L_VORLAGE_ZEITRAUM')) ?></label>
  <select data-role="none" id="vorlage_zeitraum" name="vorlage_zeitraum">
<?php foreach (array('tag', 'monat', 'jahr') as $zd_vz) { ?>
    <option value="<?= $zd_vz ?>"><?= zd_e(zd_t('LOX.ZEITRAUM_' . strtoupper($zd_vz))) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= zd_t('LOX.H_VORLAGE_ZEITRAUM') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="status"><?= zd_e(zd_t('LOX.K_VORLAGE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="energie"><?= zd_e(zd_t('LOX.K_VORLAGE_ENERGIE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="befehle"><?= zd_e(zd_t('LOX.K_VORLAGE_BEFEHLE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="alle"><?= zd_e(zd_t('LOX.K_VORLAGE_ALLE')) ?></button>
<?php if (count($zd_geraete) > 1) { ?>
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="vorlage" value="summe"><?= zd_e(zd_t('LOX.K_VORLAGE_SUMME')) ?></button>
<?php } ?>
</div>
</form>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= zd_t('LEGENDE.LESEN') ?></span>
</div>
</div>

<!-- ---------------- Energiefluss-Monitor ---------------- -->
<div class="sm-step"><b><?= zd_e(zd_t('LOX.S9_TITEL')) ?></b><br>
<?= zd_t('LOX.S9_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('LOX.T_BAUSTEIN')) ?></th><th><?= zd_e(zd_t('LOX.T_PARAMETER')) ?></th>
    <th><?= zd_e(zd_t('LOX.T_EINGAENGE')) ?></th></tr>
<tr><td><?= zd_e(zd_t('LOX.S9_ZAEHLER')) ?></td>
    <td><span class="sm-mono">MaxLvl=100</span>, <span class="sm-mono">UnSt=&lt;v&gt;%</span>,
        <span class="sm-mono">Un1=&lt;v.3&gt;kW</span>, <span class="sm-mono">Un2=&lt;v.1&gt;kWh</span></td>
    <td><?= zd_t('LOX.S9_EINGAENGE') ?></td></tr>
</table>
<div class="sm-warnung"><?= zd_t('LOX.S9_KW') ?></div>
<div class="sm-hilfe"><?= zd_t('LOX.S9_HERKUNFT') ?></div>
</div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S4_TITEL')) ?></b><br>
<?= zd_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('ALLG.EIGENSCHAFT')) ?></th><th><?= zd_e(zd_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= zd_e(zd_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= zd_e($zd_host) ?></span></td></tr>
<?php
/* Acht Befehle, eine Schleife, EINE Adressquelle.
 *
 * Bis 0.9.12 stand jede dieser Adressen von Hand in der Tabelle - sieben
 * Kopien derselben Zeichenkette, dazu zwei weitere in den Vorlagen. Solange
 * alle neun gleich lauten, faellt das nicht auf; nach der naechsten
 * Aenderung am Endpunkt muessten sie es aber immer noch, und dafuer gibt es
 * keinen Grund ausser Sorgfalt. Sie kommen jetzt alle aus
 * zd_endpunkt_pfad() - derselben Funktion, aus der auch die Vorlage der
 * Steuerbefehle baut. Was hier steht, ist damit das, was Loxone bekommt.
 *
 * $roh = true: das <v> von Loxone soll <v> bleiben und nicht zu %3Cv%3E
 * werden. */
foreach (array(
    array('LOX.T_VA_ENTLADEN',  'entladen',  array('geraet' => 1, 'watt' => '<v>')),
    array('LOX.T_VA_LADEN',     'laden',     array('geraet' => 1, 'watt' => '<v>')),
    array('LOX.T_VA_AUS',       'aus',       array('geraet' => 1)),
    array('LOX.T_VA_SOCMIN',    'socmin',    array('geraet' => 1, 'prozent' => '<v>')),
    array('LOX.T_VA_SOCMAX',    'socmax',    array('geraet' => 1, 'prozent' => '<v>')),
    array('LOX.T_VA_GRENZEAUS', 'grenzeaus', array('geraet' => 1, 'watt' => '<v>')),
    array('LOX.T_VA_GRENZEEIN', 'grenzeein', array('geraet' => 1, 'watt' => '<v>')),
    array('LOX.T_VA_ABRUF',     'abruf',     array()),
) as $zd_va) { ?>
<tr><td><?= zd_e(zd_t($zd_va[0])) ?></td>
    <td><span class="sm-mono"><?= zd_e(zd_endpunkt_pfad(array('aktion' => $zd_va[1]) + $zd_va[2], true)) ?></span></td></tr>
<?php } ?>
<tr><td><?= zd_e(zd_t('LOX.T_VA_SELFTEST')) ?></td>
    <td><span class="sm-mono"><?= zd_e(zd_endpunkt_pfad(array('selftest' => 1))) ?></span></td></tr>
</table>
<div class="sm-hinweis"><?= zd_t('LOX.S4_VORLAGE') ?></div>
<div class="sm-warnung"><?= zd_t('LOX.S4_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S5_TITEL')) ?></b>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('ALLG.EIGENSCHAFT')) ?></th><th><?= zd_e(zd_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= zd_e(zd_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= zd_e($zd_token) ?></span></td></tr>
</table>
<?= zd_t('LOX.S5_TEXT') ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= zd_e(zd_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= zd_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S6_TITEL')) ?></b><br><?= zd_t('LOX.S6_TEXT') ?></div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Anspruch: Wer die Tabelle von oben nach unten abarbeitet, hat die Funktion
 * nachgebaut, ohne nachzudenken. Loxone Config fuehrt alle Bausteine in der
 * Baustein-Suche (F5).
 *
 * Typ und Name stehen als Sprachschluessel drin, die Eingangsspalte ist
 * symbolisch und damit sprachfrei. Die Parameterspalte traegt dagegen den
 * FERTIGEN Text: die acht Befehlserkennungen der virtuellen Eingaenge
 * standen bis 0.9.12 achtmal ausgeschrieben in beiden Sprachdateien - und
 * zwar ohne das fuehrende Semikolon, das die Vorlage seit 0.9.11 setzt.
 * Wer die Tabelle abtippte statt die Vorlage zu importieren, bekam damit
 * genau den Fehler zurueck, der dort behoben worden war: \iLADEN=\i\v
 * findet in ...;ENTLADEN=250;LADEN=0 die falsche Stelle. Sie kommen jetzt
 * aus zd_check(), derselben Funktion, aus der auch die Vorlage baut.
 */
function zd_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01',
              sprintf(zd_t('BAUSTEIN.P_CHECK'), zd_check('SOC')), '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02',
              sprintf(zd_t('BAUSTEIN.P_CHECK'), zd_check('PV')), '&mdash;'),
        array(3,  'BAUSTEIN.T_VE',      'BAUSTEIN.N03',
              sprintf(zd_t('BAUSTEIN.P_CHECK'), zd_check('BATP')), '&mdash;'),
        array(4,  'BAUSTEIN.T_VE',      'BAUSTEIN.N04',
              sprintf(zd_t('BAUSTEIN.P_CHECK'), zd_check('HAUS')), '&mdash;'),
        array(5,  'BAUSTEIN.T_VE',      'BAUSTEIN.N05',
              sprintf(zd_t('BAUSTEIN.P_CHECK'), zd_check('NETZ')), '&mdash;'),
        array(6,  'BAUSTEIN.T_VE',      'BAUSTEIN.N06',
              sprintf(zd_t('BAUSTEIN.P_CHECK'), zd_check('DVOLT')), '&mdash;'),
        array(7,  'BAUSTEIN.T_VE',      'BAUSTEIN.N07',
              sprintf(zd_t('BAUSTEIN.P_CHECK'), zd_check('ALTER')), '&mdash;'),
        array(8,  'BAUSTEIN.T_VE',      'BAUSTEIN.N08',
              sprintf(zd_t('BAUSTEIN.P_CHECK'), zd_check('OK')), '&mdash;'),
        array(9,  'BAUSTEIN.T_SWS',     'BAUSTEIN.N09', zd_t('BAUSTEIN.P09'), 'I &larr; #7'),
        array(10, 'BAUSTEIN.T_NICHT',   'BAUSTEIN.N10', '',             'I &larr; #8'),
        array(11, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N11', '',             'I1 &larr; #9, I2 &larr; #10'),
        array(12, 'BAUSTEIN.T_EVZ',     'BAUSTEIN.N12', zd_t('BAUSTEIN.P12'), 'I &larr; #11'),
        array(13, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N13', zd_t('BAUSTEIN.P13'), 'I &larr; #12'),
        array(14, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N14', zd_t('BAUSTEIN.P14'), 'I &larr; #6'),
        array(15, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N15', zd_t('BAUSTEIN.P15'), 'I &larr; #14'),
        array(16, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N16', zd_t('BAUSTEIN.P16'), 'I1 &larr; #1, I2 &larr; #3'),
        array(17, 'BAUSTEIN.T_TASTER',  'BAUSTEIN.N17', zd_t('BAUSTEIN.P17'), '&mdash;'),
        array(18, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N18', zd_t('BAUSTEIN.P18'), 'I1 &larr; ' . zd_t('BAUSTEIN.ZAEHLER')),
        array(19, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N19', zd_t('BAUSTEIN.P19'), 'I1 &larr; ' . zd_t('BAUSTEIN.ZAEHLER')),
        array(20, 'BAUSTEIN.T_VEZ',     'BAUSTEIN.N20', zd_t('BAUSTEIN.P20'), '&mdash;'),
        array(21, 'BAUSTEIN.T_VERGL',   'BAUSTEIN.N21', zd_t('BAUSTEIN.P21'), 'I1 &larr; #1, I2 &larr; #20'),
        array(22, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N22', zd_t('BAUSTEIN.P22'), 'I1 &larr; #18, I2 &larr; #21, I3 &larr; #17'),
        array(23, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N23', zd_t('BAUSTEIN.P23'), 'I1 &larr; #19, I2 &larr; #17'),
        array(24, 'BAUSTEIN.T_IMPULS',  'BAUSTEIN.N24', zd_t('BAUSTEIN.P24'), '&mdash;'),
        array(25, 'BAUSTEIN.T_ANALOGSP','BAUSTEIN.N25', zd_t('BAUSTEIN.P25'), 'I &larr; #22, ' . zd_t('BAUSTEIN.TRIGGER') . ' &larr; #24'),
        array(26, 'BAUSTEIN.T_ANALOGSP','BAUSTEIN.N26', zd_t('BAUSTEIN.P26'), 'I &larr; #23, ' . zd_t('BAUSTEIN.TRIGGER') . ' &larr; #24'),
        array(27, 'BAUSTEIN.T_VA',      'BAUSTEIN.N27', zd_t('BAUSTEIN.P27'), 'I &larr; #25'),
        array(28, 'BAUSTEIN.T_VA',      'BAUSTEIN.N28', zd_t('BAUSTEIN.P28'), 'I &larr; #26'),
    );
}
?>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S7_TITEL')) ?></b><br>
<?= zd_t('LOX.S7_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= zd_e(zd_t('LOX.T_BAUSTEIN')) ?></th><th><?= zd_e(zd_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= zd_e(zd_t('LOX.T_PARAMETER')) ?></th><th><?= zd_e(zd_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (zd_bausteine() as $zd_b) { ?>
<tr><td><?= (int) $zd_b[0] ?></td><td><?= zd_t($zd_b[1]) ?></td><td><?= zd_t($zd_b[2]) ?></td>
    <td><?= $zd_b[3] !== '' ? $zd_b[3] : '&mdash;' ?></td><td><?= $zd_b[4] ?></td></tr>
<?php } ?>
</table>
<?= zd_t('LOX.S7_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S8_TITEL')) ?></b><br>
<?= zd_t('LOX.S8_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('LOX.T_PRUEFUNG')) ?></th><th><?= zd_e(zd_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= zd_e(zd_endpunkt_url(array('aktion' => 'status'))) ?></span></td>
    <td><span class="sm-mono">ZENDURE;OK=1;SOC=...</span></td></tr>
<tr><td><span class="sm-mono"><?= zd_e($zd_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= zd_e(zd_endpunkt_url(array('aktion' => 'quatsch'))) ?></span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $zd_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= zd_e(zd_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= zd_t('TEST.EINLEITUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= zd_e(zd_t('TEST.T_FRAGE')) ?></th><th><?= zd_e(zd_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach (zd_pruefungen() as $zd_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($zd_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($zd_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $zd_z['frage'] ?></td><td><?= $zd_z['antwort'] ?></td></tr>
<?php } ?>
</table>

<?php foreach ($zd_werte as $zd_nr => $zd_w) {
    if (empty($zd_w['packliste'])) { continue; } ?>
<h3><?= zd_e(zd_t('TEST.H_PACKS')) ?>: <?= zd_e($zd_w['name']) ?></h3>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('EINST.T_SN')) ?></th><th><?= zd_e(zd_t('TEST.T_PSOC')) ?></th>
    <th><?= zd_e(zd_t('TEST.T_PVOLT')) ?></th><th><?= zd_e(zd_t('TEST.T_PDVOLT')) ?></th>
    <th><?= zd_e(zd_t('TEST.T_PTEMP')) ?></th><th><?= zd_e(zd_t('TEST.T_PWATT')) ?></th></tr>
<?php foreach ($zd_w['packliste'] as $zd_sn => $zd_pk) { ?>
<tr><td><span class="sm-mono"><?= zd_e($zd_sn) ?></span></td>
    <td><?= $zd_pk['soc'] === null ? '&ndash;' : zd_e($zd_pk['soc']) . ' %' ?></td>
    <td><?= $zd_pk['volt'] === null ? '&ndash;' : zd_e($zd_pk['volt']) ?></td>
    <td><?= $zd_pk['dvolt'] === null ? '&ndash;' : zd_e($zd_pk['dvolt']) ?></td>
    <td><?= $zd_pk['temp'] === null ? '&ndash;' : zd_e($zd_pk['temp']) ?></td>
    <td><?= $zd_pk['watt'] === null ? '&ndash;' : zd_e($zd_pk['watt']) . ' W' ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= zd_t('TEST.PACKS_HILFE') ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= zd_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= zd_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= zd_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= zd_e(zd_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a class="sm-btn sm-b-lesen" href="<?= zd_e(zd_endpunkt_url(array('aktion' => 'status', 'geraet' => 1))) ?>" target="_blank"><?= zd_e(zd_t('TEST.K_STATUS')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= zd_e(zd_endpunkt_url(array('aktion' => 'packs', 'geraet' => 1))) ?>" target="_blank"><?= zd_e(zd_t('TEST.K_PACKS')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= zd_e(zd_endpunkt_url(array('aktion' => 'liste'))) ?>" target="_blank"><?= zd_e(zd_t('TEST.K_LISTE')) ?></a>
</div>

<h3><?= zd_e(zd_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= zd_e(zd_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= zd_e(zd_endpunkt_url(array('aktion' => 'rohgeraet'))) ?>" target="_blank"><?= zd_e(zd_t('TEST.K_ROH')) ?></a>
  <a class="sm-btn sm-b-technik" href="<?= zd_e(zd_endpunkt_url(array('aktion' => 'roh'))) ?>" target="_blank"><?= zd_e(zd_t('TEST.K_ABBILD')) ?></a>
</div>
<div class="sm-hilfe"><?= zd_t('TEST.H_ROH') ?></div>
<?php if ($zd_testausgabe !== '') { ?>
<div class="sm-pre"><?= zd_e($zd_testausgabe) ?></div>
<?php } ?>

<!-- ---------------- Geraetesuche ---------------- -->
<h3><?= zd_e(zd_t('TEST.H_SUCHE')) ?></h3>
<p class="sm-hilfe"><?= zd_t('TEST.SUCHE_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= zd_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="suche" value="http"><?= zd_e(zd_t('TEST.K_SUCHE_HTTP')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="suche" value="mqtt"><?= zd_e(zd_t('TEST.K_SUCHE_MQTT')) ?></button>
  </form>
</div>
<?php
$zd_such = zd_json_lesen($zd_p['datadir'] . '/suche.json');
if ($zd_such && isset($zd_such['ts'])) {
?>
<div class="sm-hilfe"><?= zd_e(sprintf(zd_t('TEST.SUCHE_STAND'),
    zd_e($zd_such['art']), max(0, time() - (int) $zd_such['ts']),
    zd_e((string) $zd_such['dauer']), zd_e($zd_such['netz']))) ?></div>
<?php if (!empty($zd_such['fehler'])) { ?>
<div class="sm-warnung"><?= zd_e($zd_such['fehler']) ?></div>
<?php } ?>
<?php if (!empty($zd_such['http'])) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('EINST.T_IP')) ?></th><th><?= zd_e(zd_t('TEST.T_SUCHE_FELDER')) ?></th>
    <th><?= zd_e(zd_t('TEST.T_SUCHE_BEKANNT')) ?></th><th>&nbsp;</th></tr>
<?php foreach ($zd_such['http'] as $zd_k) { ?>
<tr><td><span class="sm-mono"><?= zd_e($zd_k['ip']) ?></span></td>
    <td><?= (int) $zd_k['felder'] ?></td>
    <td><?= $zd_k['zendure']
            ? '<span class="sm-an">' . zd_e(implode(', ', array_slice($zd_k['bekannt'], 0, 4))) . '</span>'
            : '<span class="sm-aus">' . zd_e(zd_t('TEST.SUCHE_FREMD')) . '</span>' ?></td>
    <td><form action="index.php" method="post" style="margin:0;">
        <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
        <input data-role="none" type="hidden" name="activetab" value="tab-test">
        <input data-role="none" type="hidden" name="u_ip" value="<?= zd_e($zd_k['ip']) ?>">
        <button data-role="none" class="sm-btn sm-b-aktion" style="min-width:auto;padding:4px 10px !important;"
                type="submit" name="uebernehmen" value="1"><?= zd_e(zd_t('TEST.K_UEBERNEHMEN')) ?></button>
        </form></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<?php if (!empty($zd_such['mqtt'])) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('EINST.T_PRODKEY')) ?></th><th><?= zd_e(zd_t('EINST.T_DEVICEID')) ?></th>
    <th><?= zd_e(zd_t('TEST.T_SUCHE_THEMEN')) ?></th><th>&nbsp;</th></tr>
<?php foreach ($zd_such['mqtt'] as $zd_k) { ?>
<tr><td><span class="sm-mono"><?= zd_e($zd_k['prodkey']) ?></span></td>
    <td><span class="sm-mono"><?= zd_e($zd_k['deviceid']) ?></span></td>
    <td><?= (int) $zd_k['themen'] ?></td>
    <td><form action="index.php" method="post" style="margin:0;">
        <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
        <input data-role="none" type="hidden" name="activetab" value="tab-test">
        <input data-role="none" type="hidden" name="u_prodkey" value="<?= zd_e($zd_k['prodkey']) ?>">
        <input data-role="none" type="hidden" name="u_deviceid" value="<?= zd_e($zd_k['deviceid']) ?>">
        <button data-role="none" class="sm-btn sm-b-aktion" style="min-width:auto;padding:4px 10px !important;"
                type="submit" name="uebernehmen" value="1"><?= zd_e(zd_t('TEST.K_UEBERNEHMEN')) ?></button>
        </form></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<?php if (empty($zd_such['http']) && empty($zd_such['mqtt']) && empty($zd_such['fehler'])) { ?>
<div class="sm-hinweis"><?= zd_t('TEST.SUCHE_LEER') ?></div>
<?php } ?>
<?php } ?>

<!-- ---------------- Feld-Erkunder ---------------- -->
<h3><?= zd_e(zd_t('TEST.H_ERKUNDER')) ?></h3>
<p class="sm-hilfe"><?= zd_t('TEST.ERKUNDER_ERKLAERUNG') ?></p>
<?php
$zd_erk = zd_erkunder();
if (!$zd_erk) { ?>
<div class="sm-hinweis"><?= zd_t('TEST.ERKUNDER_LEER') ?></div>
<?php } else {
    foreach ($zd_erk as $zd_gnr => $zd_ee) {
        $zd_gname = isset($zd_werte[$zd_gnr]['name']) ? $zd_werte[$zd_gnr]['name'] : ('#' . $zd_gnr);
        $zd_unbenutzt = 0;
        foreach ($zd_ee['felder'] as $zd_f) { if ($zd_f['feld'] === '') { $zd_unbenutzt++; } }
?>
<h4 style="margin:14px 0 2px;font-size:0.95em;"><?= zd_e($zd_gname) ?>
  <span class="sm-hilfe" style="display:inline;"><?= zd_e(sprintf(zd_t('TEST.ERKUNDER_KOPF'),
      count($zd_ee['felder']), $zd_unbenutzt,
      $zd_ee['ts'] ? max(0, time() - $zd_ee['ts']) : -1)) ?></span></h4>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('TEST.T_ERK_NAME')) ?></th><th><?= zd_e(zd_t('TEST.T_ERK_WERT')) ?></th>
    <th><?= zd_e(zd_t('TEST.T_ERK_FELD')) ?></th></tr>
<?php foreach ($zd_ee['felder'] as $zd_f) { ?>
<tr><td><span class="sm-mono"><?= zd_e($zd_f['name']) ?></span><?= $zd_f['stell'] ? ' <span class="sm-hilfe" style="display:inline;">&#9679;</span>' : '' ?></td>
    <td><span class="sm-mono"><?= zd_e($zd_f['wert']) ?></span></td>
    <td><?= $zd_f['feld'] !== ''
            ? '<span class="sm-an sm-mono">' . zd_e($zd_f['feld']) . '</span>'
            : '<span class="sm-hilfe" style="display:inline;">' . zd_e(zd_t('TEST.ERK_UNBENUTZT')) . '</span>' ?></td></tr>
<?php } ?>
</table>
</div>
<?php if ($zd_ee['pack']) { ?>
<div class="sm-hilfe"><?= zd_e(sprintf(zd_t('TEST.ERKUNDER_PACK'), zd_e($zd_ee['packsn']))) ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('TEST.T_ERK_NAME')) ?></th><th><?= zd_e(zd_t('TEST.T_ERK_WERT')) ?></th>
    <th><?= zd_e(zd_t('TEST.T_ERK_FELD')) ?></th></tr>
<?php foreach ($zd_ee['pack'] as $zd_f) { ?>
<tr><td><span class="sm-mono"><?= zd_e($zd_f['name']) ?></span></td>
    <td><span class="sm-mono"><?= zd_e($zd_f['wert']) ?></span></td>
    <td><?= $zd_f['feld'] !== ''
            ? '<span class="sm-an sm-mono">' . zd_e($zd_f['feld']) . '</span>'
            : '<span class="sm-hilfe" style="display:inline;">' . zd_e(zd_t('TEST.ERK_UNBENUTZT')) . '</span>' ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<?php }
} ?>
<div class="sm-hilfe"><?= zd_t('TEST.ERKUNDER_HILFE') ?></div>

<!-- ---------------- Temperatur-Einheit ---------------- -->
<h3><?= zd_e(zd_t('TEST.H_TEMPWAHL')) ?></h3>
<?php
// Den Rohwert des ersten Akkupacks nehmen, der einen liefert.
$zd_troh = null;
foreach ($zd_werte as $zd_w2) {
    foreach ((isset($zd_w2['packliste']) ? $zd_w2['packliste'] : array()) as $zd_pk2) {
        if (isset($zd_pk2['temp_roh']) && $zd_pk2['temp_roh'] !== null) {
            $zd_troh = $zd_pk2['temp_roh'];
            break 2;
        }
    }
}
if ($zd_troh === null) { ?>
<div class="sm-hinweis"><?= zd_t('TEST.TEMPWAHL_LEER') ?></div>
<?php } else {
    $zd_vor = zd_temperatur_vorschlag($zd_troh);
?>
<p class="sm-hilfe"><?= zd_e(sprintf(zd_t('TEST.TEMPWAHL_ROH'), $zd_troh)) ?></p>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('TEST.T_TEMP_ART')) ?></th><th><?= zd_e(zd_t('TEST.T_TEMP_ERGEBNIS')) ?></th>
    <th><?= zd_e(zd_t('TEST.T_TEMP_URTEIL')) ?></th><th>&nbsp;</th></tr>
<?php foreach ($zd_vor['arten'] as $zd_art => $zd_ainfo) { ?>
<tr><td><?= zd_e(zd_t('EINST.TEMP_' . strtoupper($zd_art))) ?>
        <?= $zd_cfg['temp_umrechnung'] === $zd_art
            ? '<br><span class="sm-hilfe" style="display:inline;">' . zd_e(zd_t('TEST.TEMP_AKTIV')) . '</span>' : '' ?></td>
    <td><span class="sm-mono"><?= $zd_ainfo['wert'] === null ? '&ndash;' : zd_e($zd_ainfo['wert']) ?></span>
        <?= $zd_art === 'roh' ? '' : ' &deg;C' ?></td>
    <td><?= $zd_ainfo['plausibel']
            ? '<span class="sm-an">' . zd_e(zd_t('TEST.TEMP_PLAUSIBEL')) . '</span>'
            : '<span class="sm-aus">' . zd_e(zd_t('TEST.TEMP_UNPLAUSIBEL')) . '</span>' ?></td>
    <td><form action="index.php" method="post" style="margin:0;">
        <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
        <input data-role="none" type="hidden" name="activetab" value="tab-test">
        <button data-role="none" class="sm-btn sm-b-aktion" style="min-width:auto;padding:4px 10px !important;"
                type="submit" name="temp_uebernehmen" value="<?= zd_e($zd_art) ?>"><?= zd_e(zd_t('TEST.K_TEMP_UEBERNEHMEN')) ?></button>
        </form></td></tr>
<?php } ?>
</table>
<div class="<?= $zd_vor['vorschlag'] !== '' ? 'sm-hinweis' : 'sm-warnung' ?>">
<?= $zd_vor['vorschlag'] !== ''
    ? sprintf(zd_t('TEST.TEMP_VORSCHLAG'), '<span class="sm-mono">' . zd_e($zd_vor['vorschlag']) . '</span>')
    : ($zd_vor['anzahl'] > 1
       ? sprintf(zd_t('TEST.TEMP_KEIN_VORSCHLAG'), (int) $zd_vor['anzahl'],
                 '<span class="sm-mono">' . zd_e(implode(', ', $zd_vor['plausible'])) . '</span>')
       : zd_t('TEST.TEMP_NICHTS_PLAUSIBEL')) ?>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= zd_t('LEGENDE.AKTION') ?></span>
</div>
<?php } ?>

<h3><?= zd_e(zd_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= zd_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($zd_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= zd_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="test_geraet"><?= zd_e(zd_t('TEST.L_GERAET')) ?></label>
  <input data-role="none" type="number" id="test_geraet" name="test_geraet" value="1" min="1" max="99">
</div>
<div class="sm-feld">
  <label for="test_watt"><?= zd_e(zd_t('TEST.L_WATT')) ?></label>
  <input data-role="none" type="number" id="test_watt" name="test_watt" value="100" min="0" max="5000">
  <div class="sm-hilfe"><?= zd_t('TEST.H_WATT') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="test_trocken" value="1">
    <?= zd_e(zd_t('TEST.L_TROCKEN')) ?>
  </label>
  <div class="sm-hilfe"><?= zd_t('TEST.H_TROCKEN') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= zd_e(zd_t('TEST.K_ABRUF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="entladen"><?= zd_e(zd_t('TEST.K_ENTLADEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="laden"><?= zd_e(zd_t('TEST.K_LADEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="aus"><?= zd_e(zd_t('TEST.K_AUS')) ?></button>
</div>
</form>

<!-- ---------------- Satz-Assistent ---------------- -->
<h3><?= zd_e(zd_t('TEST.H_SATZ')) ?></h3>
<div class="sm-warnung"><?= zd_t('TEST.SATZ_WARNUNG') ?></div>
<p class="sm-hilfe"><?= zd_t('TEST.SATZ_ERKLAERUNG') ?></p>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
  <label for="satz_geraet"><?= zd_e(zd_t('TEST.L_GERAET')) ?></label>
  <input data-role="none" type="number" id="satz_geraet" name="satz_geraet" value="1" min="1" max="99">
</div>
<div class="sm-feld">
  <label for="satz_watt"><?= zd_e(zd_t('TEST.L_SATZ_WATT')) ?></label>
  <input data-role="none" type="number" id="satz_watt" name="satz_watt" value="100" min="0" max="5000">
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="satztest" value="1"><?= zd_e(zd_t('TEST.K_SATZ')) ?></button>
</div>
</form>
<?php
$zd_st = zd_satztest_stand();
if ($zd_st) {
    $zd_fertig = isset($zd_st['phase']) && $zd_st['phase'] === 'fertig';
?>
<div class="<?= $zd_fertig ? 'sm-hinweis' : 'sm-warnung' ?>">
<b><?= $zd_fertig ? zd_e(zd_t('TEST.SATZ_FERTIG')) : zd_e(zd_t('TEST.SATZ_LAEUFT')) ?></b>
<?= zd_e(sprintf(zd_t('TEST.SATZ_STAND'), (int) $zd_st['geraet'], zd_e($zd_st['name']),
                 (int) $zd_st['schritt'], count($zd_st['saetze']), (int) $zd_st['watt'])) ?>
</div>
<?php if (!empty($zd_st['ergebnis'])) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('EINST.T_SATZ')) ?></th><th><?= zd_e(zd_t('TEST.T_SATZ_GESENDET')) ?></th>
    <th><?= zd_e(zd_t('TEST.T_SATZ_STELL')) ?></th><th><?= zd_e(zd_t('TEST.T_SATZ_GEAENDERT')) ?></th></tr>
<?php foreach ($zd_st['ergebnis'] as $zd_er) { ?>
<tr><td><span class="sm-mono"><?= zd_e($zd_er['satz']) ?></span></td>
    <td><?= $zd_er['gesendet'] ? '<span class="sm-an">' . zd_e(zd_t('ALLG.JA')) . '</span>'
                               : '<span class="sm-aus">' . zd_e(zd_t('ALLG.NEIN')) . '</span>'
                                 . '<br><span class="sm-hilfe">' . zd_e($zd_er['meldung']) . '</span>' ?></td>
    <td><?= empty($zd_er['stell']) ? '&ndash;'
            : '<span class="sm-an sm-mono">' . zd_e(implode(', ', $zd_er['stell'])) . '</span>' ?></td>
    <td><?php
        if (empty($zd_er['geaendert'])) { echo '&ndash;'; }
        else {
            $zd_l = array();
            foreach ($zd_er['geaendert'] as $zd_f => $zd_vv) {
                $zd_l[] = zd_e($zd_f) . ': ' . zd_e((string) $zd_vv['vorher'])
                        . ' &rarr; ' . zd_e((string) $zd_vv['nachher']);
            }
            echo '<span class="sm-mono" style="font-size:0.85em;">' . implode('<br>', $zd_l) . '</span>';
        }
    ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<?php if ($zd_fertig) { ?>
<div class="<?= isset($zd_st['bester']) ? 'sm-hinweis' : 'sm-fehler' ?>">
<?= isset($zd_st['bester'])
    ? sprintf(zd_t('TEST.SATZ_BEFUND'), '<span class="sm-mono">' . zd_e($zd_st['bester']) . '</span>',
              '<span class="sm-mono">' . zd_e($zd_st['quittungsfeld']) . '</span>')
    : zd_t('TEST.SATZ_KEIN_BEFUND') ?>
</div>
<?php if (!empty($zd_st['ununterscheidbar'])) { ?>
<div class="sm-warnung"><?= sprintf(zd_t('TEST.SATZ_GLEICH'),
    '<span class="sm-mono">' . zd_e($zd_st['bester']) . '</span>',
    '<span class="sm-mono">' . zd_e(implode(', ', $zd_st['ununterscheidbar'])) . '</span>') ?></div>
<?php } ?>
<?php } ?>
<?php } ?>

<div class="sm-warnung"><b><?= zd_e(zd_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= zd_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $zd_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= zd_e(zd_t('LOG.H_TITEL')) ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<p class="sm-hilfe"><?= zd_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= zd_e($zd_p['log']) ?></span></p>
<?php if ($zd_logzeilen) { ?>
<div class="sm-log"><?= zd_e(implode("\n", $zd_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= zd_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= zd_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= zd_e($zd_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= zd_e(zd_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($zd_tab) ?>);
})();
</script>
<?php
if ($zd_rahmen) {
    LBWeb::lbfooter();
}
