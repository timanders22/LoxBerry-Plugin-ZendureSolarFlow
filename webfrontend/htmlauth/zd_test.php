<?php
/**
 * Zendure SolarFlow - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone, ob die Einrichtung traegt. Was
 * sich nur mit Geraet pruefen liesse, wird als solches benannt statt geraten.
 */

function zd_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

/**
 * Den EIGENEN Endpunkt wirklich abrufen.
 *
 * Bis 0.9.11 bot der Reiter Test drei Links an, die jemand anklicken sollte.
 * Ein Link ist keine Messung: er sagt nichts darueber, ob der Webserver den
 * Endpunkt ueberhaupt ausliefert, ob PHP dort durchlaeuft und ob das Token
 * stimmt. Genau das sind die Fragen, an denen eine Einrichtung haengt - und
 * genau die beantwortet ?selftest=1, ohne irgendetwas auszuloesen.
 *
 * Drei Ausgaenge, nicht zwei. "Nicht feststellbar" ist ein eigener Befund:
 * ein LoxBerry, der sich selbst ueber 127.0.0.1 nicht erreicht (anderer Port,
 * Anmeldeabfrage vor dem unangemeldeten Bereich), ist deshalb nicht kaputt -
 * man weiss es nur nicht. Ein Haken waere dort gelogen, ein Kreuz auch.
 *
 * Rueckgabe: array(stand, Text) mit stand 1, 0 oder -1.
 */
function zd_endpunkt_probe()
{
    $p = zd_paths();
    $token = zd_token();
    if ($token === '') {
        return array(0, zd_t('TEST.A_EP_KEIN_TOKEN'));
    }
    $pfad = '/plugins/' . $p['plugin'] . '/index.php?selftest=1&token=' . rawurlencode($token);
    // Ueber 127.0.0.1, nicht ueber den Rechnernamen: gemessen werden soll der
    // eigene Webserver, nicht die Namensaufloesung.
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'timeout' => 5, 'ignore_errors' => true,
        'header' => "Accept: text/plain\r\nUser-Agent: LoxBerry-Zendure-Selbsttest\r\n",
    )));
    $roh = @file_get_contents('http://127.0.0.1' . $pfad, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', (string) $z, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    if ($roh === false) {
        return array(-1, sprintf(zd_t('TEST.A_EP_UNERREICHBAR'), zd_e($pfad)));
    }
    $erste = trim(strtok((string) $roh, "\n"));
    if ($erste === 'SELFTEST;OK=1;TOKEN=OK') {
        return array(1, sprintf(zd_t('TEST.A_EP_OK'), $code));
    }
    if (strpos($erste, 'SELFTEST;OK=0') === 0) {
        return array(0, sprintf(zd_t('TEST.A_EP_TOKEN'), zd_e($erste), $code));
    }
    if ($code === 401 || $code === 403) {
        // Eine Anmeldeabfrage vor dem unangemeldeten Bereich ist eine
        // Eigenheit der Installation, kein Fehler dieses Plugins.
        return array(-1, sprintf(zd_t('TEST.A_EP_AUTH'), $code));
    }
    return array(0, sprintf(zd_t('TEST.A_EP_FREMD'), $code, zd_e(substr($erste, 0, 60))));
}

/**
 * Traegt JEDES Formular das Merkmal gegen fremde Absender?
 *
 * Der Wachposten am Eingang nuetzt nichts, wenn ein Formular das Merkmal
 * nicht mitschickt: dann tut es einfach nichts mehr, und der Bediener sucht
 * den Fehler bei sich. Diese Zeile zaehlt nach.
 *
 * Geeicht durch Rueckbau: nimmt man das versteckte Feld an EINEM Formular
 * weg, wird die Zeile rot und nennt die Zahl. Und die leere Menge kommt
 * zuerst - "alle 0 von 0 sind in Ordnung" ist kein Haken, sondern eine
 * Pruefung, die ins Leere zeigt.
 */
function zd_formularprobe($datei)
{
    $s = (string) @file_get_contents($datei);
    if ($s === '') {
        return array(0, sprintf(zd_t('TEST.A_FORM_UNLESBAR'), basename($datei)));
    }
    $gesamt = 0;
    $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk = substr($s, $f[1], ($ende === false ? 400 : $ende - $f[1]));
            if (strpos($blk, 'name="fmt"') === false) {
                $ohne++;
            }
        }
    }
    if ($gesamt === 0) {
        return array(0, zd_t('TEST.A_FORM_KEINS'));
    }
    if ($ohne > 0) {
        return array(0, sprintf(zd_t('TEST.A_FORM_OHNE'), $ohne, $gesamt));
    }
    return array(1, sprintf(zd_t('TEST.A_FORM_ALLE'), $gesamt));
}

/**
 * Traegt die Reiterleiste OHNE JavaScript?
 *
 * Der Fall, gegen den das schuetzt, ist einmal eingetreten: bis 0.9.0 setzte
 * erst das Skript die Klasse sm-active. Weil .sm-seite auf display:none
 * steht, war die Seite ohne JavaScript vollstaendig leer - und der Kommentar
 * an der Stelle behauptete das Gegenteil.
 *
 * Drei Mengen muessen zusammenpassen, und nur die erste entsteht von selbst:
 *
 *   1. die Reiterliste $zd_reiter - daraus wird die Positivliste erzeugt,
 *      die kann also gar nicht danebenliegen
 *   2. die Bereiche <div class="sm-seite..." id="tab-..."> - die stehen von
 *      HAND im HTML
 *   3. die serverseitige Zuweisung sm-active je Bereich - ebenfalls von Hand
 *
 * Ein Reiter ohne Bereich ist ein Knopf, der auf eine leere Seite fuehrt.
 * Ein Bereich ohne Reiter ist unerreichbar. Ein Bereich ohne die
 * serverseitige Zuweisung ist ohne JavaScript unsichtbar - und das ist der
 * Fehler, der nur bei dem auffaellt, der kein JavaScript hat.
 *
 * WARUM DAS PLUGIN SICH SELBST PRUEFT: hausstandard_pruefen.py meldet fuer
 * dieses Plugin in der Spalte "tab" einen STRICH - "trifft nicht zu". Nicht
 * weil alles in Ordnung waere, sondern weil die Klasse hier zusammengesetzt
 * entsteht (class="sm-tab<?= ... ?>") und das Werkzeug sie nicht lesen kann.
 * Ein Strich sammelt sich beim Ueberfliegen wie ein Haken ein. Diese Zeile
 * beantwortet die Frage stattdessen dort, wo die Antwort bekannt ist.
 *
 * Rueckgabe: array(stand, Text) mit stand 1 oder 0.
 */
function zd_smactive_probe(array $reiter, $datei)
{
    $s = (string) @file_get_contents($datei);
    if ($s === '') {
        return array(0, sprintf(zd_t('TEST.A_TAB_UNLESBAR'), basename($datei)));
    }
    $soll = array();
    foreach (array_keys($reiter) as $k) {
        $soll[] = 'tab-' . $k;
    }
    // Die Bereiche: id="tab-..." an einem Element mit der Klasse sm-seite.
    $bereiche = array();
    if (preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $s, $y)) {
        $bereiche = $y[1];
    }
    $fehlt = array_values(array_diff($soll, $bereiche));
    $ueber = array_values(array_diff($bereiche, $soll));
    if ($fehlt) {
        return array(0, sprintf(zd_t('TEST.A_TAB_OHNE_BEREICH'), zd_e(implode(', ', $fehlt))));
    }
    if ($ueber) {
        return array(0, sprintf(zd_t('TEST.A_TAB_UNERREICHBAR'), zd_e(implode(', ', $ueber))));
    }
    // Und bekommt jeder Bereich die Klasse serverseitig?
    $ohne = array();
    foreach ($soll as $id) {
        if (!preg_match('/\$zd_tab === \x27' . preg_quote($id, '/') . '\x27\s*\?\s*\x27 sm-active\x27/', $s)) {
            $ohne[] = $id;
        }
    }
    if ($ohne) {
        return array(0, sprintf(zd_t('TEST.A_TAB_OHNE_ACTIVE'), zd_e(implode(', ', $ohne))));
    }
    return array(1, sprintf(zd_t('TEST.A_TAB_OK'), count($soll)));
}

function zd_pruefungen()
{
    $p = zd_paths();
    $cfg = zd_config();
    $geraete = zd_geraete();
    $werte = zd_werte();
    $zeilen = array();

    $pid = zd_dienst_pid();
    $zeilen[] = zd_pruefzeile($pid > 0 ? 1 : 0, zd_t('TEST.F_DIENST'),
        $pid > 0 ? zd_t('TEST.A_DIENST_LAEUFT') . ' ' . $pid
                 : (zd_dienst_soll() ? zd_t('TEST.A_DIENST_SOLL_TOT') : zd_t('TEST.A_DIENST_GESTOPPT')));

    $zeilen[] = zd_pruefzeile(count($geraete) > 0 ? 1 : 0, zd_t('TEST.F_GERAETE'),
        count($geraete) > 0 ? sprintf(zd_t('TEST.A_GERAETE'), count($geraete))
                            : zd_t('TEST.A_KEINE_GERAETE'));

    // Braucht ueberhaupt ein Geraet die mosquitto-Werkzeuge?
    $braucht = false;
    foreach ($geraete as $g) {
        if ($g['art'] === 'mqtt') {
            $braucht = true;
        }
    }
    $a = array();
    @exec('command -v mosquitto_sub 2>/dev/null', $a);
    $b = array();
    @exec('command -v mosquitto_pub 2>/dev/null', $b);
    $mosq = (count($a) > 0 && count($b) > 0);
    if ($mosq) {
        $zeilen[] = zd_pruefzeile(1, zd_t('TEST.F_MOSQ'), zd_t('TEST.A_MOSQ_DA'));
    } elseif ($braucht) {
        $zeilen[] = zd_pruefzeile(0, zd_t('TEST.F_MOSQ'), zd_t('TEST.A_MOSQ_FEHLT'));
    } else {
        $zeilen[] = zd_pruefzeile(-1, zd_t('TEST.F_MOSQ'), zd_t('TEST.A_MOSQ_EGAL'));
    }

    // Je Geraet: antwortet es?
    foreach ($werte as $nr => $w) {
        $zeilen[] = zd_pruefzeile($w['ok'] ? 1 : 0,
            zd_e($w['name']) . ' <span class="sm-mono">' . zd_e($w['art']) . '</span>',
            $w['ok'] ? sprintf(zd_t('TEST.A_GERAET_OK'), (int) $w['alter'], (int) $w['packs'])
                     : ($w['alter'] < 0 ? zd_t('TEST.A_GERAET_NIE')
                                        : sprintf(zd_t('TEST.A_GERAET_ALT'), (int) $w['alter'])));
    }

    $zu = zd_zustand();
    if (!empty($zu['fehler'])) {
        $zeilen[] = zd_pruefzeile(0, zd_t('TEST.F_LETZTER_FEHLER'), zd_e($zu['fehler']));
    }

    $m = zd_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = zd_pruefzeile(0, zd_t('TEST.F_MQTT'), zd_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = zd_pruefzeile(1, zd_t('TEST.F_MQTT'),
            zd_e($m['broker']) . ':' . zd_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } else {
        $zeilen[] = zd_pruefzeile(0, zd_t('TEST.F_MQTT'), zd_t('TEST.A_MQTT_AUS'));
    }

    $zeilen[] = zd_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, zd_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? zd_t('TEST.A_STEUERUNG_EIN') : zd_t('TEST.A_STEUERUNG_AUS'));

    $zeilen[] = zd_pruefzeile(-1, zd_t('TEST.F_BREMSE'),
        sprintf(zd_t('TEST.A_BREMSE'), (int) $cfg['schreibbremse'], (int) $cfg['schrittweite']));

    // --- Energiezaehler ---
    if (empty($cfg['energie_ein'])) {
        $zeilen[] = zd_pruefzeile(-1, zd_t('TEST.F_ENERGIE'), zd_t('TEST.A_ENERGIE_AUS'));
    } else {
        $est = zd_energie_stand(1);
        $gel = isset($est['stand']['laden']) ? (float) $est['stand']['laden'] : 0.0;
        $ent = isset($est['stand']['entladen']) ? (float) $est['stand']['entladen'] : 0.0;
        if (empty($est['ts'])) {
            $zeilen[] = zd_pruefzeile(-1, zd_t('TEST.F_ENERGIE'), zd_t('TEST.A_ENERGIE_LEER'));
        } else {
            $zeilen[] = zd_pruefzeile(1, zd_t('TEST.F_ENERGIE'),
                sprintf(zd_t('TEST.A_ENERGIE'), round($gel / 1000, 2), round($ent / 1000, 2),
                        max(0, time() - (int) $est['ts']), (int) $cfg['intervall']));
        }
    }

    // --- Schutzschwellen ---
    $zeilen[] = empty($cfg['schutz_ein'])
        ? zd_pruefzeile(-1, zd_t('TEST.F_SCHUTZ'), zd_t('TEST.A_SCHUTZ_AUS'))
        : zd_pruefzeile(1, zd_t('TEST.F_SCHUTZ'),
            sprintf(zd_t('TEST.A_SCHUTZ_EIN'), (int) $cfg['schutz_soc_min'],
                    (int) $cfg['schutz_soc_max'], zd_e($cfg['schutz_temp_min']),
                    zd_e($cfg['schutz_temp_max'])));

    // --- Rueckfall ---
    $zeilen[] = ((int) $cfg['rueckfall_min'] === 0)
        ? zd_pruefzeile(-1, zd_t('TEST.F_RUECKFALL'), zd_t('TEST.A_RUECKFALL_AUS'))
        : zd_pruefzeile(1, zd_t('TEST.F_RUECKFALL'),
            sprintf(zd_t('TEST.A_RUECKFALL_EIN'), (int) $cfg['rueckfall_min']));

    // --- Der eigene Endpunkt, wirklich abgerufen ---
    list($epstand, $eptext) = zd_endpunkt_probe();
    $zeilen[] = zd_pruefzeile($epstand, zd_t('TEST.F_ENDPUNKT'), $eptext);

    // --- Herzschlag ---
    $zae = zd_herzstand();
    $zeilen[] = zd_pruefzeile($zae >= 0 ? 1 : -1, zd_t('TEST.F_HERZ'),
        $zae >= 0 ? sprintf(zd_t('TEST.A_HERZ'), $zae, (int) $cfg['intervall'])
                  : zd_t('TEST.A_HERZ_KEINER'));

    /* Steht in der DATEI, was in den Vorgaben steht? zd_config() ergaenzt
     * Fehlendes bei jedem Lesen im Arbeitsspeicher - auf der Platte bleibt
     * es unvollstaendig, und genau das faellt erst bei einer Sicherung auf. */
    $lage = zd_cfg_lage();
    if ($lage['fehlend']) {
        $zeilen[] = zd_pruefzeile(0, zd_t('TEST.F_CFG'),
            sprintf(zd_t('TEST.A_CFG_FEHLT'), count($lage['fehlend']), (int) $lage['anzahl'],
                    zd_e(implode(', ', $lage['fehlend']))));
    } elseif ($lage['fremd']) {
        $zeilen[] = zd_pruefzeile(0, zd_t('TEST.F_CFG'),
            sprintf(zd_t('TEST.A_CFG_FREMD'), zd_e(implode(', ', $lage['fremd']))));
    } else {
        $zeilen[] = zd_pruefzeile(1, zd_t('TEST.F_CFG'),
            sprintf(zd_t('TEST.A_CFG_OK'), (int) $lage['anzahl']));
    }

    list($fstand, $ftext) = zd_formularprobe(__DIR__ . '/index.php');
    $zeilen[] = zd_pruefzeile($fstand, zd_t('TEST.F_FORMULARE'), zd_e($ftext));

    /* $zd_reiter gehoert index.php - zd_pruefungen() wird von dort aufgerufen,
     * also ist die Liste da. Fehlt sie doch einmal (weil jemand diese Datei
     * einzeln einbindet), misst die Zeile NICHTS und sagt das auch. */
    if (isset($GLOBALS['zd_reiter']) && is_array($GLOBALS['zd_reiter'])) {
        list($tstand, $ttext) = zd_smactive_probe($GLOBALS['zd_reiter'], __DIR__ . '/index.php');
        $zeilen[] = zd_pruefzeile($tstand, zd_t('TEST.F_TABS'), $ttext);
    } else {
        $zeilen[] = zd_pruefzeile(-1, zd_t('TEST.F_TABS'), zd_t('TEST.A_TAB_KEINE_LISTE'));
    }

    // Ueberlebt der Verlauf ein Update? Die Frage beantwortet der ABLAGEORT,
    // nicht eine Absichtserklaerung: liegt er unter data/plugins/<ordner>,
    // raeumt ihn purge_installation bei jedem Update weg.
    $zeilen[] = zd_pruefzeile(
        strpos($p['verlaufdir'], $p['datadir'] . '/') === 0 ? 0 : 1,
        zd_t('TEST.F_BESTAND'),
        strpos($p['verlaufdir'], $p['datadir'] . '/') === 0
            ? zd_t('TEST.A_BESTAND_DRIN')
            : sprintf(zd_t('TEST.A_BESTAND_NEBEN'),
                      zd_e(basename($p['bestand'])),
                      count(glob($p['verlaufdir'] . '/geraet*_*.csv') ?: array())));

    return $zeilen;
}

/** Ausgabe von zendure_dienst.php --selbsttest. */
function zd_selbsttest_ausgabe()
{
    $p = zd_paths();
    $skript = $p['bindir'] . '/zendure_dienst.php';
    if (!is_file($skript)) {
        return "[FEHL] zendure_dienst.php fehlt.\n       Erwartet: " . $skript
             . "\n       Abhilfe: Plugin neu installieren.";
    }
    $ausgabe = array();
    @exec('php ' . escapeshellarg($skript) . ' --selbsttest 2>&1', $ausgabe);
    return implode("\n", $ausgabe);
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung) - stand wie bei zd_befehl_absetzen.
 */
function zd_test_aktion($aktion)
{
    $roh = isset($_POST['test_geraet']) ? trim((string) $_POST['test_geraet']) : '1';
    /* Erst pruefen, DANN giessen.
     *
     * Bis 0.9.9 stand der Guss davor: (string)(int)'abc' ergibt '0', und das
     * besteht das Muster anschliessend. Gemessen an 0.9.9 - 'abc' und '0'
     * wurden eingereiht und liefen als geraet=0 ins Leere, waehrend die
     * Meldung "1 bis 99" verspricht. Nur '-5' und '100' wurden abgewiesen. */
    if (!preg_match('/^[0-9]{1,2}$/', $roh) || (int) $roh < 1) {
        return array(0, zd_t('TEST.M_GERAET_UNGUELTIG'));
    }
    // Als Ganzzahl weitergeben: "01" besteht die Pruefung, der Dienst
    // vergleicht aber mit Zahlen. Ohne den Guss faende er das Geraet nicht.
    $nr = (int) $roh;
    // Trockenlauf: rechnen, zeigen, nicht senden.
    $trocken = !empty($_POST['test_trocken']) ? 1 : 0;
    switch ($aktion) {
        case 'abruf':
            return zd_befehl_absetzen(array('aktion' => 'abruf'), 8);

        case 'aus':
            return zd_befehl_absetzen(array('aktion' => 'aus', 'geraet' => $nr,
                                            'trocken' => $trocken));

        case 'laden':
        case 'entladen':
            $watt = isset($_POST['test_watt']) ? (string) $_POST['test_watt'] : '';
            if (!preg_match('/^[0-9]{1,5}$/', $watt)) {
                return array(0, zd_t('TEST.M_WATT_UNGUELTIG'));
            }
            return zd_befehl_absetzen(array('aktion' => $aktion, 'geraet' => $nr,
                                            'watt' => (int) $watt, 'trocken' => $trocken));

        default:
            return array(0, zd_t('TEST.M_UNBEKANNT'));
    }
}

/**
 * Den Satz-Assistenten anstossen.
 *
 * Er laeuft danach im Dienst ueber mehrere Durchgaenge weiter; hier wird nur
 * der Auftrag eingereiht. Die Antwort lautet deshalb "gestartet", nicht
 * "fertig" - das Ergebnis steht spaeter in der Tabelle darunter.
 */
function zd_satztest_ausloesen()
{
    $roh = isset($_POST['satz_geraet']) ? trim((string) $_POST['satz_geraet']) : '1';
    if (!preg_match('/^[0-9]{1,2}$/', $roh) || (int) $roh < 1) {
        return array(0, zd_t('TEST.M_GERAET_UNGUELTIG'));
    }
    $watt = isset($_POST['satz_watt']) ? (string) $_POST['satz_watt'] : '';
    if (!preg_match('/^[0-9]{1,5}$/', $watt)) {
        return array(0, zd_t('TEST.M_WATT_UNGUELTIG'));
    }
    return zd_befehl_absetzen(array('aktion' => 'satztest', 'geraet' => (int) $roh,
                                    'watt' => (int) $watt), 8);
}

/**
 * Der Feld-Erkunder: was hat das Geraet WIRKLICH geliefert?
 *
 * Die groesste Unsicherheit dieses Plugins sind die Eigenschaftsnamen der
 * jeweiligen Firmware. Diese Liste stellt sie neben die Zuordnung: welcher
 * Name kam, welchen Wert trug er, und benutzt das Plugin ihn?
 *
 * Rueckgabe je Eintrag: name, wert, feld ('' = unbenutzt).
 */
function zd_erkunder($nr = null)
{
    $c = zd_cache();
    $z = isset($c['zustaende']) && is_array($c['zustaende']) ? $c['zustaende'] : array();
    if ($nr !== null && isset($z[(string) (int) $nr])) {
        $z = array((string) (int) $nr => $z[(string) (int) $nr]);
    }
    $cfg = zd_config();
    $karte = array_flip(zd_zuordnung($cfg));
    $packkarte = array_flip(zd_zuordnung($cfg, true));
    // isset() laesst sich nicht auf das Ergebnis eines Funktionsaufrufs
    // anwenden - deshalb einmal in eine Variable.
    $stell = zd_stellfelder_liste();
    $out = array();
    foreach ($z as $gnr => $zustand) {
        if (!is_array($zustand)) {
            continue;
        }
        $eig = isset($zustand['eigenschaften']) && is_array($zustand['eigenschaften'])
             ? $zustand['eigenschaften'] : array();
        $liste = array();
        foreach ($eig as $name => $wert) {
            $liste[] = array(
                'name'  => (string) $name,
                'wert'  => is_scalar($wert) ? (string) $wert : json_encode($wert),
                'feld'  => isset($karte[$name]) ? $karte[$name] : '',
                'stell' => isset($stell[$name]) ? 1 : 0,
            );
        }
        usort($liste, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

        $packliste = array();
        $packs = isset($zustand['packs']) && is_array($zustand['packs']) ? $zustand['packs'] : array();
        $erste = $packs ? reset($packs) : array();
        if (is_array($erste)) {
            foreach ($erste as $name => $wert) {
                if ($name === '_gesehen') {
                    continue;
                }
                $packliste[] = array(
                    'name' => (string) $name,
                    'wert' => is_scalar($wert) ? (string) $wert : json_encode($wert),
                    'feld' => isset($packkarte[$name]) ? $packkarte[$name] : '',
                    'stell' => 0,
                );
            }
            usort($packliste, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }
        $out[(int) $gnr] = array(
            'ts'    => isset($zustand['ts']) ? (int) $zustand['ts'] : 0,
            'felder'=> $liste,
            'pack'  => $packliste,
            'packsn'=> $packs ? (string) key($packs) : '',
        );
    }
    return $out;
}

/**
 * Die Stellgroessen - auch fuer die Oberflaeche.
 *
 * zd_stellfelder() steht im Dienst und ist von der Oberflaeche aus nicht
 * erreichbar. Statt einer zweiten Liste (zwei Listen sind zwei Wahrheiten)
 * wird die vorhandene benutzt, wenn sie da ist.
 */
function zd_stellfelder_liste()
{
    if (function_exists('zd_stellfelder')) {
        return zd_stellfelder();
    }
    $bin = zd_paths()['bindir'] . '/zendure_dienst.php';
    static $gelesen = null;
    if ($gelesen === null) {
        $gelesen = array();
        $s = (string) @file_get_contents($bin);
        if (preg_match('/function zd_stellfelder\(\).*?return array\((.*?)\);/s', $s, $m)) {
            preg_match_all("/'([A-Za-z0-9_]+)'\s*=>/", $m[1], $y);
            foreach ($y[1] as $f) {
                $gelesen[$f] = 1;
            }
        }
    }
    return $gelesen;
}

/**
 * Mini-SVG: Ladezustand und Batterieleistung ueber einen Tag.
 *
 * Die Batterieleistung stand seit jeher in der dritten Spalte der
 * Verlaufsdatei und wurde vom Bild ignoriert. Sie bekommt eine eigene
 * Nulllinie in der Mitte und einen eigenen Massstab, der sich nach dem
 * groessten vorkommenden Betrag richtet - eine feste Skala waere bei einem
 * SolarFlow 800 zu grob und bei einem AIO 2400 zu fein.
 *
 * $tag ist ein Datum als Ymd; leer heisst heute.
 */
function zd_soc_svg($punkte, $tag = '')
{
    $w = 720; $h = 120; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 20;
    // Der Tagesanfang richtet sich nach dem GEZEIGTEN Tag, nicht nach heute -
    // sonst laegen alle Punkte eines vergangenen Tages ausserhalb des Bildes.
    $tag0 = ($tag !== '' && preg_match('/^\d{8}$/', $tag))
          ? (int) strtotime(substr($tag, 0, 4) . '-' . substr($tag, 4, 2) . '-' . substr($tag, 6, 2))
          : strtotime('today 00:00');
    // Massstab der zweiten Kurve: der groesste vorkommende Betrag, mindestens
    // 100 W. Eine feste Skala waere bei einem SolarFlow 800 zu grob und bei
    // einem AIO 2400 zu fein.
    $pmax = 100.0;
    foreach ($punkte as $pt) {
        if (isset($pt[2]) && abs((float) $pt[2]) > $pmax) {
            $pmax = abs((float) $pt[2]);
        }
    }
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w
         . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;"'
         . ' xmlns="http://www.w3.org/2000/svg">';
    foreach (array(0, 25, 50, 75, 100) as $pct) {
        $y = $y0 + $ph - $ph * $pct / 100;
        $svg .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . ($x0 + $pw) . '" y2="' . $y
              . '" stroke="#e5e5e5" stroke-width="1"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . ($y + 3)
              . '" font-size="9" fill="#999" text-anchor="end">' . $pct . '</text>';
    }
    foreach (array(0, 6, 12, 18, 24) as $hh) {
        $x = $x0 + $pw * $hh / 24;
        $svg .= '<line x1="' . $x . '" y1="' . $y0 . '" x2="' . $x . '" y2="' . ($y0 + $ph)
              . '" stroke="#eeeeee" stroke-width="1"/>';
        $svg .= '<text x="' . $x . '" y="' . ($h - 6)
              . '" font-size="9" fill="#999" text-anchor="middle">' . $hh . ':00</text>';
    }
    $poly = array();
    $poly2 = array();
    foreach ($punkte as $pt) {
        $anteil = ($pt[0] - $tag0) / 86400;
        if ($anteil < 0 || $anteil > 1) {
            continue;
        }
        $x = round($x0 + $pw * $anteil, 1);
        $poly[] = $x . ',' . round($y0 + $ph - $ph * max(0, min(100, $pt[1])) / 100, 1);
        // Die Batterieleistung um die Mitte: positiv nach oben (laedt).
        $p2 = max(-$pmax, min($pmax, (float) (isset($pt[2]) ? $pt[2] : 0)));
        $poly2[] = $x . ',' . round($y0 + $ph / 2 - ($ph / 2) * $p2 / $pmax, 1);
    }
    if (count($poly2) >= 2) {
        // Nulllinie der Leistung, damit man sieht, wo oben und unten ist.
        $svg .= '<line x1="' . $x0 . '" y1="' . ($y0 + $ph / 2) . '" x2="' . ($x0 + $pw)
              . '" y2="' . ($y0 + $ph / 2) . '" stroke="#d8c9a8" stroke-width="1"'
              . ' stroke-dasharray="4 3"/>';
        $svg .= '<polyline points="' . implode(' ', $poly2) . '" fill="none"'
              . ' stroke="#e0620d" stroke-width="1.4" opacity="0.85"/>';
        $svg .= '<text x="' . ($x0 + $pw - 4) . '" y="' . ($y0 + 10)
              . '" font-size="9" fill="#e0620d" text-anchor="end">&#177;'
              . (int) round($pmax) . ' W</text>';
    }
    if (count($poly) >= 2) {
        $erst = explode(',', $poly[0]);
        $letzt = explode(',', $poly[count($poly) - 1]);
        $svg .= '<polygon points="' . $erst[0] . ',' . ($y0 + $ph) . ' ' . implode(' ', $poly) . ' '
              . $letzt[0] . ',' . ($y0 + $ph) . '" fill="#6dac20" opacity="0.15"/>';
        $svg .= '<polyline points="' . implode(' ', $poly) . '" fill="none" stroke="#6dac20" stroke-width="2"/>';
        $svg .= '<circle cx="' . $letzt[0] . '" cy="' . $letzt[1] . '" r="3" fill="#6dac20"/>';
    } else {
        $svg .= '<text x="' . ($x0 + $pw / 2) . '" y="' . ($y0 + $ph / 2)
              . '" font-size="11" fill="#aaa" text-anchor="middle">'
              . zd_e(zd_t('TEST.KEINE_MESSPUNKTE')) . '</text>';
    }
    return $svg . '</svg>';
}
