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
 * Und unabhaengig von der Aktion:
 *   ?selftest=1&token=T  prueft NUR das Token, ohne irgendetwas auszuloesen
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

$zd_cfg = zd_config();

/* ---------------- Token ---------------- */
$zd_soll = (string) $zd_cfg['aktionstoken'];
$zd_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
/* Ein falsches Token bekommt beim Selbsttest DIESELBE Abweisung wie sonst
 * auch - er darf keine Abkuerzung an der Sicherheit vorbei sein. */
$zd_selftest = isset($_GET['selftest']) && (string) $_GET['selftest'] === '1';
if ($zd_soll === '') {
    http_response_code(403);
    echo $zd_selftest ? "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n"
                      : "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($zd_soll, $zd_ist)) {
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
$zd_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($zd_aktion, array_merge($zd_lesend, $zd_schaltend), true)) {
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
    $w = (string) $_GET[$name];
    if (!preg_match($muster, $w)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PARAMETER\n";
        echo 'Der Wert von ' . $name . " passt nicht ins erlaubte Muster.\n";
        exit;
    }
    return $w;
}

$zd_nr      = zd_param('geraet', '/^[0-9]{1,2}$/', '1');
$zd_zeitraum = zd_param('zeitraum', '/^(tag|monat|jahr)$/', 'tag');
$zd_watt    = zd_param('watt', '/^[0-9]{1,5}$/', '');
$zd_prozent = zd_param('prozent', '/^[0-9]{1,3}$/', '');
/* Trockenlauf: rechnet den Befehl vollstaendig fertig und sendet ihn NICHT.
 * Nur 0 oder 1 - alles andere wird abgewiesen wie jeder andere Parameter. */
$zd_dry     = zd_param('dry', '/^[01]$/', '0') === '1';

/** Ein Strich statt einer erfundenen 0. Loxone behaelt dann den letzten Wert. */
function zd_w($v)
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '-';
    }
    return (string) (0 + $v);
}

$zd_lox = zd_loxone();
$zd_alle = zd_werte();
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
    echo 'LISTE;OK=' . (int) (!empty($zd_lox['ok'])) . ';N=' . count($zd_alle) . ';ALTER=' . $zd_alter . "\n";
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

if ($zd_g === null) {
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

// Der Trockenlauf sendet nichts und braucht die Freigabe deshalb nicht.
if ($zd_aktion !== 'abruf' && !$zd_dry && empty($zd_cfg['steuerung_ein'])) {
    http_response_code(403);
    echo "SET;OK=0;GRUND=STEUERUNG_AUS\n";
    echo "Schreibende Befehle sind gesperrt. Reiter Einstellungen, Haken 'Schreibende Befehle zulassen'.\n";
    exit;
}
if (zd_dienst_pid() === 0) {
    // Nicht stillschweigend einreihen: ohne laufenden Dienst passiert nichts.
    http_response_code(503);
    echo "SET;OK=0;GRUND=DIENST_LAEUFT_NICHT\n";
    echo "Der Abrufdienst laeuft nicht. Reiter Einstellungen, Knopf 'Dienst starten'.\n";
    exit;
}

$zd_befehl = array('aktion' => $zd_aktion, 'geraet' => (int) $zd_nr);
if ($zd_dry) {
    $zd_befehl['trocken'] = 1;
}
if (in_array($zd_aktion, array('laden', 'entladen', 'grenzeaus', 'grenzeein'), true)) {
    if ($zd_watt === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=WATT_FEHLT\n";
        exit;
    }
    $zd_befehl['watt'] = (int) $zd_watt;
} elseif (in_array($zd_aktion, array('socmin', 'socmax'), true)) {
    if ($zd_prozent === '') {
        http_response_code(400);
        echo "SET;OK=0;GRUND=PROZENT_FEHLT\n";
        exit;
    }
    $zd_befehl['prozent'] = (int) $zd_prozent;
}

list($zd_erg, $zd_meldung) = zd_befehl_absetzen($zd_befehl);
if ($zd_erg === 0) {
    http_response_code(500);
}
printf("SET;OK=%d;AKTION=%s;MELDUNG=%s\n", $zd_erg, $zd_aktion,
    str_replace(array("\r", "\n", ';'), ' ', $zd_meldung));
