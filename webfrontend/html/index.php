<?php
/**
 * Zendure SolarFlow - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit - ein einfaches == liesse sich
 * ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesend:
 *   status  [&geraet=N]   Leistungswerte eines Geraets
 *   packs   [&geraet=N]   Werte der einzelnen Akkupacks
 *   liste                 alle eingerichteten Geraete
 *   summe                 alle Geraete zusammen, Ladezustand nach Kapazitaet
 *                         gewichtet
 *   energie [&zeitraum=]  Zaehlerstaende: tag (Vorgabe), monat, jahr
 *   roh                   das umgesetzte Abbild als JSON - mit den Feldnamen
 *                         DIESES Plugins
 *   rohgeraet             die Antwort des Geraets, unveraendert - mit den
 *                         Eigenschaftsnamen der jeweiligen Firmware
 *
 * Schaltend (nur wenn im Reiter Einstellungen zugelassen):
 *   laden     &watt=W    [&geraet=N]
 *   entladen  &watt=W    [&geraet=N]
 *   aus                  [&geraet=N]
 *   socmin    &prozent=P [&geraet=N]
 *   socmax    &prozent=P [&geraet=N]
 *   grenzeaus &watt=W    [&geraet=N]
 *   grenzeein &watt=W    [&geraet=N]
 *   abruf                sofort abrufen statt auf den Takt zu warten
 *
 * Ein schaltender Aufruf an eine Geraetenummer, die in den Einstellungen nicht
 * eingerichtet ist: HTTP 404, SET;OK=0;AKTION=..;GRUND=GERAET_UNBEKANNT;N=n
 * (n = eingerichtete Geraete), es wird nichts gesendet. geraet nimmt die
 * Nummer ohne fuehrende Null; geraet=01 bekommt 400 GRUND=PARAMETER.
 *
 * Und unabhaengig von der Aktion:
 *   ?selftest=1&token=T  prueft NUR das Token, ohne irgendetwas auszuloesen
 *
 * Gleichwert-Unterdrueckung (X-7, B-Nachzug 01.10.2026): laden, entladen,
 * aus, socmin, socmax, grenzeaus und grenzeein mit DEMSELBEN Wert je Geraet
 * innerhalb von 60 s werden nicht erneut eingereiht: HTTP 200,
 * SET;OK=1;AKTION=..;UNVERAENDERT=1;SEIT_S=n. laden, entladen und aus gelten
 * als ein Sollwert (die Leistung). Ein anderer Wert geht sofort hinaus (kein
 * 429). Ohne nutzbaren Merker: 503 GRUND=GLEICHWERT_MERKER, nichts
 * eingereiht. Die Schreibbremse des Dienstes bleibt.
 *
 * Jeder schaltende Aufruf nimmt zusaetzlich &dry=1: dann wird der Befehl
 * vollstaendig fertiggerechnet - Grenzen, Rasterung, Befehlssatz, Nutzlast -
 * und NICHT gesendet. Die Antwort nennt die fertige Nutzlast. Dafuer muss die
 * Steuerung NICHT freigegeben sein; es wird ja nichts geschrieben.
 *
 * Der Endpunkt spricht NIE selbst mit einem Geraet. Lesende Aufrufe beantwortet
 * er aus dem Zwischenspeicher, schaltende legt er in einer Warteschlange ab,
 * die der Dienst abarbeitet.
 *
 * Ein Strich als Wert bedeutet: das Geraet hat dieses Feld nicht geliefert. Es
 * wird bewusst keine 0 gesendet - eine 0 waere eine stille Falschaussage.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/zd_lib.php';
header('Content-Type: text/plain; charset=utf-8');

/* zd_config(false): der unangemeldete Endpunkt legt nichts an und stellt
 * nichts wieder her. Bis 0.9.22 rief er zd_config() ohne Schalter - und die
 * Selbstheilung darin schrieb. Gemessen 18.09.2026 (Pruefstand
 * Pruefung-ZendureSolarFlow-0.9.22, Fall "endpunkt_leer"): bei einer
 * Konfiguration "{}" schrieb ein einziger Aufruf dieses Endpunkts die Datei
 * aus der Zweitschrift neu, noch bevor das Token geprueft war. Wer sich nicht
 * ausweisen kann, soll am Dateisystem nichts bewegen; geheilt wird in der
 * angemeldeten Oberflaeche und beim Dienststart. */
$zd_cfg = zd_config(false);

/* Ein Anfragewert: null (fehlt), false (keine Zeichenkette, etwa token[]=x)
 * oder die Zeichenkette. Erst is_string, dann alles andere (Befund Code 10,
 * 29.09.2026): bis 0.9.27 gab (string) auf eine Liste unter PHP 8 eine
 * Warnung, und die Abweisung kam danach als HTTP 200. */
function zd_get_text($name)
{
    if (!isset($_GET[$name])) {
        return null;
    }
    return is_string($_GET[$name]) ? $_GET[$name] : false;
}

/* Jede Abweisung ins Protokoll, mit der Adresse des Anrufers, hoechstens
 * eine Zeile je Minute und Grund; nie das Token (Befund Code 8, 29.09.2026:
 * bis 0.9.27 schrieb dieser Endpunkt auf keinem Weg eine Zeile). */
function zd_ep_abweisung($grund, $aktion = '')
{
    $wer = isset($_SERVER['REMOTE_ADDR']) ? preg_replace('/[^0-9A-Fa-f:.]/', '', (string) $_SERVER['REMOTE_ADDR']) : '';
    $akt = is_string($aktion) ? substr(preg_replace('/[^a-z0-9_]/i', '', $aktion), 0, 20) : '';
    zd_log_gebremst('ep_' . strtolower($grund), 'Endpunkt: Anfrage von ' . ($wer === '' ? '?' : $wer)
        . ' abgewiesen, Grund ' . $grund . ($akt !== '' ? ', Aktion ' . $akt : '') . '.', 60);
}

/* ---------------- Token ---------------- */
$zd_soll = $zd_cfg['aktionstoken'];
$zd_ist = zd_get_text('token');
if (!is_string($zd_ist)) {
    $zd_ist = '';
}
/* Ein falsches Token bekommt beim Selbsttest DIESELBE Abweisung wie sonst
 * auch - er darf keine Abkuerzung an der Sicherheit vorbei sein. */
$zd_selftest = zd_get_text('selftest') === '1';
if ($zd_soll === null || (is_string($zd_soll) && trim($zd_soll) === '')) {
    zd_ep_abweisung('KEIN_TOKEN_GESETZT');
    http_response_code(403);
    echo $zd_selftest ? "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n"
                      : "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
/* Ein gespeichertes Token, das nicht dem Muster des Erzeugers entspricht,
 * schaltet nichts frei (Befund U4, 29.09.2026: eine Sicherung mit Token als
 * Liste machte bis 0.9.27 "token=Array" zum gueltigen Token). */
if (!zd_token_gueltig($zd_soll)) {
    zd_ep_abweisung('TOKEN_UNGUELTIG');
    http_response_code(403);
    echo $zd_selftest ? "SELFTEST;OK=0;ERR=TOKEN_UNGUELTIG\n" : "FEHLER;OK=0;GRUND=TOKEN_UNGUELTIG\n";
    echo "Das gespeicherte Aktionstoken hat nicht die Form, die das Plugin erzeugt. Reiter Einbindung in Loxone, Neues Token erzeugen.\n";
    exit;
}
if (!hash_equals($zd_soll, $zd_ist)) {
    zd_ep_abweisung('TOKEN');
    http_response_code(403);
    echo $zd_selftest ? "SELFTEST;OK=0;ERR=TOKEN\n" : "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Selbsttest ----------------
 *
 * Ein Token muss sich pruefen lassen, OHNE dass etwas passiert. Ohne das gibt
 * es nur zwei schlechte Moeglichkeiten: entweder man schaltet wirklich - dann
 * faehrt der Speicher um -, oder man erfaehrt nie, ob die Adresse im
 * Miniserver noch stimmt. Beides ist unbrauchbar, wenn man eine Anlage
 * pruefen will.
 *
 * Er steht hier, unmittelbar hinter der Tokenpruefung: die sitzt bei diesem
 * Endpunkt ohnehin ganz oben und hat also bereits gegriffen. Kein
 * Geraetekontakt, kein Schreibzugriff, kein Protokolleintrag - er beantwortet
 * genau eine Frage.
 */
if ($zd_selftest) {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$zd_lesend = array('status', 'packs', 'liste', 'roh', 'rohgeraet', 'summe', 'energie');
$zd_schaltend = array('laden', 'entladen', 'aus', 'socmin', 'socmax', 'grenzeaus', 'grenzeein', 'abruf');
$zd_aktion = zd_get_text('aktion');
if ($zd_aktion === null) {
    $zd_aktion = 'status';
}
if (!is_string($zd_aktion) || !in_array($zd_aktion, array_merge($zd_lesend, $zd_schaltend), true)) {
    zd_ep_abweisung('UNBEKANNTE_AKTION', is_string($zd_aktion) ? $zd_aktion : '');
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($zd_lesend, $zd_schaltend)) . "\n";
    exit;
}

/* ---------------- Parameter ----------------
 * Was nicht ins Muster passt, wird abgewiesen und gemeldet. Nie Zeichen
 * entfernen, nie zurechtbiegen.
 */
function zd_param($name, $muster, $vorgabe = '')
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $vorgabe;
    }
    $w = $_GET[$name];
    if (!is_string($w) || !preg_match($muster, $w)) {
        zd_ep_abweisung('PARAMETER', $GLOBALS['zd_aktion']);
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        exit;
    }
    return $w;
}

/* Die Muster enden auf \z, nicht auf $ (Befund Code 5, 29.09.2026; Regeln/05):
 * "$" laesst einen abschliessenden Zeilenumbruch durch. Bis 0.9.27 bestand
 * "dry=1%0A" das Muster, der Vergleich mit '1' aber nicht - ein als
 * Trockenlauf gemeinter Aufruf schaltete den Speicher wirklich (1 POST). */
/* Geraetenummer ohne fuehrende Null (Nachzug Zendure-g1, 01.10.2026;
 * Entscheidung Nr. 19). Bis 0.9.31 bestand "01" das Muster. Still als Geraet 1
 * gedeutet wurde es dort nicht - das Abbild kennt nur den Schluessel "1", die
 * Antwort war GERAET_UNBEKANNT, bei rohgeraet eine leere Liste mit HTTP 200 -,
 * aber die Antwort sprach von einem fehlenden Geraet, wo die Nummer falsch
 * geschrieben war. An den Dienst geht die Nummer mit (int): ob "01" dort als 1
 * ankaeme, soll nicht davon abhaengen, dass jede Stelle davor mit der
 * Zeichenkette nachschlaegt. Jetzt 400 GRUND=PARAMETER, lesend wie schaltend. */
$zd_nr      = zd_param('geraet', '/^(0|[1-9][0-9]?)\z/', '1');
$zd_zeitraum = zd_param('zeitraum', '/^(tag|monat|jahr)\z/', 'tag');
$zd_watt    = zd_param('watt', '/^[0-9]{1,5}\z/', '');
$zd_prozent = zd_param('prozent', '/^[0-9]{1,3}\z/', '');
/* Trockenlauf: rechnet den Befehl vollstaendig fertig und sendet ihn NICHT.
 * Nur 0 oder 1 - alles andere wird abgewiesen wie jeder andere Parameter. */
$zd_dry     = zd_param('dry', '/^[01]\z/', '0') === '1';

/** Ein Strich statt einer erfundenen 0. Loxone behaelt dann den letzten Wert. */
function zd_w($v)
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

$zd_lox = zd_loxone();
/* OK und ALTER zur Abrufzeit (Befund Code 2, Entscheidung 4): siehe
 * zd_werte_jetzt(). */
$zd_alle = zd_werte_jetzt($zd_cfg);
$zd_alter = zd_alter();
$zd_g = isset($zd_alle[$zd_nr]) ? $zd_alle[$zd_nr] : null;

/* ================= Lesende Aktionen ================= */

if ($zd_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($zd_lox, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* Die Antwort des Geraets, unveraendert.
 *
 * Das Plugin nennt als seinen groessten Zweifel die Eigenschaftsnamen der
 * jeweiligen Firmware und verweist dafuer auf den Knopf "Rohdaten als JSON
 * ansehen". Bis 0.9.8 lieferte der aber "roh", also das bereits UMGESETZTE
 * Abbild mit den Feldnamen dieses Plugins - die Namen des Geraets kamen darin
 * ueberhaupt nicht vor. Gemessen mit einer Antwort, die zwei unbekannte
 * Eigenschaften mitbrachte (Pruefstand p5_roh.php): keine davon war zu sehen.
 * Der einzige dokumentierte Ausweg aus der Hauptunsicherheit fuehrte ins
 * Leere.
 *
 * Gelesen wird der Zwischenspeicher, nicht das Geraet - auch dieser Endpunkt
 * spricht nie selbst mit einem Speicher. */
if ($zd_aktion === 'rohgeraet') {
    header('Content-Type: application/json; charset=utf-8');
    $zd_c = zd_cache();
    $zd_z = isset($zd_c['zustaende']) && is_array($zd_c['zustaende']) ? $zd_c['zustaende'] : array();
    if (isset($_GET['geraet']) && $_GET['geraet'] !== '') {
        $zd_z = isset($zd_z[$zd_nr]) ? array($zd_nr => $zd_z[$zd_nr]) : array();
    }
    echo json_encode(array(
        'hinweis'   => 'Unveraenderte Antwort der Geraete. Die Schluessel unter '
                     . '"eigenschaften" und "packs" sind die Namen IHRER Firmware.',
        'ts'        => isset($zd_c['ts']) ? (int) $zd_c['ts'] : 0,
        'alter'     => isset($zd_c['ts']) ? max(0, time() - (int) $zd_c['ts']) : -1,
        'zustaende' => $zd_z,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* Alle Geraete zusammen.
 *
 * SOC und RESTKWH sind FAIL CLOSED: fehlt bei einem Geraet die Kapazitaet
 * oder antwortet eines nicht, kommt ein Strich statt einer Teilsumme. Eine
 * Teilsumme, die wie eine Gesamtsumme aussieht, waere die gefaehrlichere
 * Auskunft - nach ihr wuerde geregelt. NOK sagt, wie viele fehlen. */
if ($zd_aktion === 'summe') {
    $zd_s = zd_summe($zd_alle, zd_geraete());
    printf("SUMME;OK=%d;N=%d;NOK=%d;SOC=%s;KAPAZ=%s;RESTKWH=%s;PV=%s;HAUS=%s;NETZ=%s;"
         . "BATP=%s;ALTER=%s\n",
        (int) $zd_s['ok'], (int) $zd_s['n'], (int) $zd_s['nok'],
        zd_w($zd_s['soc']), zd_w($zd_s['kapaz']), zd_w($zd_s['restkwh']),
        zd_w($zd_s['pv']), zd_w($zd_s['haus']), zd_w($zd_s['netz']), zd_w($zd_s['batp']),
        $zd_s['alter'] >= 0 ? (string) (int) $zd_s['alter'] : '-');
    exit;
}

/* Zaehlerstaende.
 *
 * Der Dienst integriert die Leistung selbst - das Geraet meldet keine
 * Zaehler. Der Abfragetakt steht deshalb mit in der Antwort: er sagt, wie
 * fein integriert wurde, und das gehoert zu jeder dieser Zahlen dazu. */
if ($zd_aktion === 'energie') {
    if (empty($zd_cfg['energie_ein'])) {
        echo "ENERGIE;OK=0;GRUND=AUSGESCHALTET\n";
        echo "Die Energiezaehler sind im Reiter Einstellungen abgeschaltet.\n";
        exit;
    }
    $zd_ger = zd_geraete();
    if (!isset($zd_ger[$zd_nr])) {
        printf("ENERGIE;OK=0;GRUND=GERAET_UNBEKANNT;N=%d\n", count($zd_ger));
        exit;
    }
    $zd_su = zd_energie_summe($zd_nr, $zd_zeitraum);
    $zd_kz = zd_energie_kennzahlen($zd_nr, $zd_ger[$zd_nr]);
    $zd_es = zd_energie_stand($zd_nr);
    printf("ENERGIE;OK=1;ZEITRAUM=%s;PV=%s;HAUS=%s;NETZ=%s;LADEN=%s;ENTLADEN=%s;"
         . "GLADEN=%s;GENTLADEN=%s;WIRKUNG=%s;ZYKLEN=%s;TAKT=%d;ALTER=%s\n",
        $zd_zeitraum,
        zd_w(round($zd_su['pv'] / 1000, 3)), zd_w(round($zd_su['haus'] / 1000, 3)),
        zd_w(round($zd_su['netz'] / 1000, 3)), zd_w(round($zd_su['laden'] / 1000, 3)),
        zd_w(round($zd_su['entladen'] / 1000, 3)),
        zd_w(round($zd_kz['geladen_wh'] / 1000, 3)),
        zd_w(round($zd_kz['entladen_wh'] / 1000, 3)),
        zd_w($zd_kz['wirkungsgrad']), zd_w($zd_kz['zyklen']),
        (int) $zd_cfg['intervall'],
        (isset($zd_es['ts']) && $zd_es['ts'] > 0)
            ? (string) max(0, time() - (int) $zd_es['ts']) : '-');
    exit;
}

if ($zd_aktion === 'liste') {
    $zd_lok = 0;
    foreach ($zd_alle as $zd_lg) {
        if (!empty($zd_lg['ok'])) {
            $zd_lok = 1;
        }
    }
    echo 'LISTE;OK=' . $zd_lok . ';N=' . count($zd_alle) . ';ALTER=' . $zd_alter . "\n";
    foreach ($zd_alle as $nr => $g) {
        /* Der Name geht durch zd_zeilenwert(): er ist frei getippt, und ein
         * Semikolon oder Gleichheitszeichen darin schoebe die Felder dieser
         * Zeile. Gemessen an 0.9.8 mit dem Namen "Keller;OK=1;SOC=99":
         *     1;Keller;OK=1;SOC=99;http;zensdk;Packs=1;OK=1
         * Eine Befehlserkennung \iOK=\i\v greift dann den Namen. */
        echo $nr . ';' . zd_zeilenwert($g['name']) . ';' . $g['art'] . ';' . $g['satz'] . ';'
           . 'Packs=' . (int) $g['packs'] . ';OK=' . (int) $g['ok'] . "\n";
    }
    exit;
}

/* Nur die lesenden Aktionen (packs, status) fragen hier das Abbild; ihre
 * Antwort bleibt HTTP 200 mit OK=0. Bis 0.9.31 stand diese Abfrage auch vor
 * jeder schaltenden Aktion - die pruefen ihr Geraet seit dem Nachzug
 * Zendure-g1 weiter unten gegen die Geraeteliste. */
if ($zd_g === null && !in_array($zd_aktion, $zd_schaltend, true)) {
    printf("%s;OK=0;GRUND=GERAET_UNBEKANNT;N=%d;ALTER=%d\n",
        $zd_aktion === 'packs' ? 'PACKS' : 'ZENDURE', count($zd_alle), $zd_alter);
    exit;
}

if ($zd_aktion === 'packs') {
    $liste = isset($zd_g['packliste']) && is_array($zd_g['packliste']) ? $zd_g['packliste'] : array();
    printf("PACKS;OK=%d;N=%d;DVOLT=%s;TEMP=%s;ALTER=%d\n",
        (int) $zd_g['ok'], count($liste), zd_w($zd_g['dvolt']), zd_w($zd_g['temp']), (int) $zd_g['alter']);
    foreach ($liste as $sn => $p) {
        // Auch die Seriennummer steht am Anfang einer semikolongetrennten
        // Zeile - und sie kommt vom Geraet, nicht aus einem Eingabefeld.
        printf("%s;SOC=%s;VOLT=%s;DVOLT=%s;TEMP=%s;WATT=%s\n",
            zd_zeilenwert($sn), zd_w($p['soc']), zd_w($p['volt']), zd_w($p['dvolt']),
            zd_w($p['temp']), zd_w($p['watt']));
    }
    exit;
}

if ($zd_aktion === 'status') {
    /* SOLL, SOLLALTER und SOLLOK beantworten die Frage, die eine reine
     * Messwertzeile nicht beantwortet: hat das Geraet den zuletzt gesetzten
     * Wert uebernommen? SOLLOK=- heisst "noch keine Aussage" und ist bei den
     * drei invoke-Befehlssaetzen der Regelfall, solange kein Quittungsfeld
     * eingetragen ist - siehe zd_soll_quittung(). */
    printf("ZENDURE;OK=%d;SOC=%s;SOCMIN=%s;SOCMAX=%s;PV=%s;HAUS=%s;NETZ=%s;LADEN=%s;ENTLADEN=%s;"
         . "BATP=%s;GRENZEAUS=%s;GRENZEEIN=%s;ACMODUS=%s;PACKS=%s;DVOLT=%s;TEMP=%s;"
         . "ZAEHLER=%d;MS=%s;FW=%s;SOLL=%s;SOLLALTER=%s;SOLLOK=%s;RESTZEIT=%s;ALTER=%d\n",
        (int) $zd_g['ok'], zd_w($zd_g['soc']), zd_w($zd_g['soc_min']), zd_w($zd_g['soc_max']),
        zd_w($zd_g['pv']), zd_w($zd_g['haus']), zd_w($zd_g['netz']), zd_w($zd_g['laden']),
        zd_w($zd_g['entladen']), zd_w($zd_g['batp']), zd_w($zd_g['grenze_aus']),
        zd_w($zd_g['grenze_ein']), zd_w($zd_g['acmodus']), zd_w($zd_g['packs']),
        zd_w($zd_g['dvolt']), zd_w($zd_g['temp']),
        zd_herzstand(),
        zd_w(isset($zd_g['ms']) ? $zd_g['ms'] : null),
        zd_w(isset($zd_g['firmware']) ? $zd_g['firmware'] : null),
        zd_w(isset($zd_g['soll']) ? $zd_g['soll'] : null),
        (isset($zd_g['soll_alter']) && (int) $zd_g['soll_alter'] >= 0)
            ? (string) (int) $zd_g['soll_alter'] : '-',
        zd_w(isset($zd_g['sollok']) ? $zd_g['sollok'] : null),
        zd_w(isset($zd_g['rueckrest']) ? $zd_g['rueckrest'] : null),
        (int) $zd_g['alter']);
    exit;
}

/* ================= Schaltende Aktionen ================= */

/* Erst die Anfrage, dann Freigabe und Dienst (Befund Code 12, 29.09.2026;
 * Regeln/03): bis 0.9.27 bekam "laden" ohne watt bei stehendem Dienst 503
 * DIENST_LAEUFT_NICHT statt 400 WATT_FEHLT. */
$zd_befehl = array('aktion' => $zd_aktion, 'geraet' => (int) $zd_nr);
if ($zd_dry) {
    $zd_befehl['trocken'] = 1;
}
if (in_array($zd_aktion, array('laden', 'entladen', 'grenzeaus', 'grenzeein'), true)) {
    if ($zd_watt === '') {
        zd_ep_abweisung('WATT_FEHLT', $zd_aktion);
        http_response_code(400);
        echo "SET;OK=0;GRUND=WATT_FEHLT\n";
        exit;
    }
    $zd_befehl['watt'] = (int) $zd_watt;
} elseif (in_array($zd_aktion, array('socmin', 'socmax'), true)) {
    if ($zd_prozent === '') {
        zd_ep_abweisung('PROZENT_FEHLT', $zd_aktion);
        http_response_code(400);
        echo "SET;OK=0;GRUND=PROZENT_FEHLT\n";
        exit;
    }
    $zd_befehl['prozent'] = (int) $zd_prozent;
}

/* Das Geraet muss eingerichtet sein (Nachzug Zendure-g1, 01.10.2026).
 * Geprueft wird gegen die Geraeteliste der Konfiguration - dieselbe, aus der
 * der Dienst arbeitet -, nicht gegen das Abbild. Bis 0.9.31 entschied das
 * Abbild: ein schaltender Befehl an ein Geraet, das darin fehlte (geraet=2 bei
 * einem Geraet, oder noch kein Abbild geschrieben), bekam HTTP 200 mit der
 * Statuszeile "ZENDURE;OK=0;GRUND=GERAET_UNBEKANNT" statt einer
 * Befehlsantwort, und ein eingerichtetes Geraet ohne Abbild liess sich nicht
 * schalten. Jetzt 404: die Anfrage ist wohlgeformt (sonst 400), aber das
 * angesprochene Geraet gibt es nicht. Kein 503 - das gehoert dem Ausfall
 * einer Quelle (Regeln/07), und ein eingerichtetes Geraet kommt hier nie hin.
 * Die Pruefung steht vor Freigabe, Dienst und Gleichwert-Merker: nichts wird
 * eingereiht, der Merker nicht geoeffnet. Gilt auch fuer abruf und dry=1.
 * zd_geraete() liest mit Selbstheilung; die greift nur ohne Aktionstoken,
 * und ohne gueltiges Token kaeme der Aufruf nicht bis hier. */
$zd_ger = zd_geraete();
if (!isset($zd_ger[$zd_nr])) {
    zd_ep_abweisung('GERAET_UNBEKANNT', $zd_aktion);
    http_response_code(404);
    printf("SET;OK=0;AKTION=%s;GRUND=GERAET_UNBEKANNT;N=%d\n", $zd_aktion, count($zd_ger));
    printf("Geraet %d ist nicht eingerichtet (eingerichtet: %d) - es wurde nichts gesendet. Reiter Einstellungen.\n",
        (int) $zd_nr, count($zd_ger));
    exit;
}

// Der Trockenlauf sendet nichts und braucht die Freigabe deshalb nicht.
if ($zd_aktion !== 'abruf' && !$zd_dry && empty($zd_cfg['steuerung_ein'])) {
    zd_ep_abweisung('STEUERUNG_AUS', $zd_aktion);
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n";
    exit;
}
if (zd_dienst_pid() === 0) {
    // Nicht stillschweigend einreihen: ohne laufenden Dienst passiert nichts.
    zd_ep_abweisung('DIENST_LAEUFT_NICHT', $zd_aktion);
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

/* Sofortabruf mit Bremse (Befund Code 7, 29.09.2026; Regeln/03 "Jeder
 * Ausloeser, den eine fremde Anlage bedient, braucht eine Bremse"): bis
 * 0.9.27 ergaben 10 Aufrufe in 2 s zehn Geraeteabrufe mit je fuenf
 * Schreibvorgaengen. Hoechstens ein Abruf je max(10 s, Takt/2); der
 * Taktabruf (ts des Abbilds) zaehlt mit. Ein zu frueher Aufruf bekommt das
 * vorhandene Abbild mit seinem ALTER. Die Pruefung laeuft unter flock, damit
 * gleichzeitige Aufrufe einander nicht ueberholen. Auch ein Trockenlauf
 * zaehlt: der Dienst ruft bei "abruf" in jedem Fall ab. */
if ($zd_aktion === 'abruf') {
    $zd_abstand = max(10, (int) floor(max(5, (int) $zd_cfg['intervall']) / 2));
    $zd_warten = 0;
    $zd_fh = @fopen(zd_paths()['datadir'] . '/.abruf_endpunkt', 'c+');
    if ($zd_fh !== false) {
        if (@flock($zd_fh, LOCK_EX)) {
            $zd_letzt = (int) trim((string) stream_get_contents($zd_fh));
            $zd_basis = max($zd_letzt, isset($zd_lox['ts']) ? (int) $zd_lox['ts'] : 0);
            $zd_warten = $zd_basis + $zd_abstand - time();
            if ($zd_warten <= 0) {
                ftruncate($zd_fh, 0);
                rewind($zd_fh);
                fwrite($zd_fh, (string) time());
                fflush($zd_fh);
            }
            flock($zd_fh, LOCK_UN);
        }
        fclose($zd_fh);
    }
    if ($zd_warten > 0) {
        printf("SET;OK=1;AKTION=abruf;GRUND=ZU_FRUEH;WARTEN=%d;ALTER=%d\n", $zd_warten, $zd_alter);
        echo 'Hoechstens ein Sofortabruf je ' . $zd_abstand . " s - es gilt das vorhandene Abbild.\n";
        exit;
    }
}

/* Gleichwert-Unterdrueckung (X-7, B-Nachzug 01.10.2026, Entscheidung
 * Nr. 19; Vorbild EVCC 0.9.37). Erst sind Aktion, Wert, Freigabe und Dienst
 * geprueft (oben), dann kommt die Unterdrueckung, dann das Einreihen. Der
 * Merker bleibt bis nach der Antwort des Dienstes gesperrt; laesst er sich
 * nicht oeffnen, faellt es geschlossen aus (503) - eingereiht wird dann
 * nichts. abruf und der Trockenlauf sind nicht betroffen (Schluessel ''). */
$zd_gw_schl = zd_gleichwert_schluessel($zd_befehl);
$zd_gw = null;
$zd_gw_merker = array();
$zd_gw_wert = zd_gleichwert_wert($zd_befehl);
if ($zd_gw_schl !== '') {
    $zd_gw = zd_gleichwert_oeffnen();
    if ($zd_gw === false) {
        zd_ep_abweisung('GLEICHWERT_MERKER', $zd_aktion);
        http_response_code(503);
        echo 'SET;OK=0;AKTION=' . $zd_aktion . ";GRUND=GLEICHWERT_MERKER\n";
        echo "Der Merker der Gleichwert-Unterdrueckung laesst sich nicht oeffnen - es wurde nichts gesendet. Protokoll im Reiter Logdateien.\n";
        exit;
    }
    $zd_gw_merker = zd_gleichwert_lesen($zd_gw);
    $zd_seit = zd_gleichwert_seit($zd_gw_merker, $zd_gw_schl, $zd_gw_wert);
    if ($zd_seit >= 0) {
        zd_gleichwert_schliessen($zd_gw, null);
        printf("SET;OK=1;AKTION=%s;UNVERAENDERT=1;SEIT_S=%d;MELDUNG=Derselbe Wert ging vor %d s hinaus - nichts gesendet.\n",
            $zd_aktion, $zd_seit, $zd_seit);
        exit;
    }
}

list($zd_erg, $zd_meldung) = zd_befehl_absetzen($zd_befehl);
if ($zd_gw !== null) {
    zd_gleichwert_schliessen($zd_gw, zd_gleichwert_nachher($zd_gw_merker, $zd_gw_schl, $zd_gw_wert, $zd_erg === 1));
}
if ($zd_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $zd_erg, $zd_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $zd_meldung));
