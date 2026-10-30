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
 * Schreiber-Wache (Energie-1 C1, Entscheidung Nr. 25): jeder schaltende Aufruf
 * nimmt optional &von=<kennung> (1 bis 32 Zeichen aus A-Z a-z 0-9 _ -; die Vorlage
 * setzt von=loxone). Eine ungueltige Kennung: HTTP 400 SET;OK=0;AKTION=..;GRUND=VON,
 * nichts eingereiht. An den Sollwert-Befehlen (alle schaltenden ausser abruf) merkt
 * sich der Endpunkt je Geraet Kennung@Absender; mehr als ein Schreiber im Fenster (ab
 * Werk 15 min): Protokoll, Reiter Test, Antwort ;SCHREIBER=n vor MELDUNG - abgewiesen
 * wird nichts. Nur mit "Fremde Schreiber abweisen" (ab Werk aus) bekommt ein nicht
 * erlaubter Schreiber HTTP 409 SET;OK=0;AKTION=..;GRUND=FREMDSCHREIBER, auch im
 * Trockenlauf (dann ;DRY=1), und nichts wird eingereiht. Merker nicht nutzbar: der
 * Befehl geht trotzdem, ;WACHE=MERKER. Lesende Aufrufe bleiben unberuehrt.
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
 * bis 0.9.27 schrieb dieser Endpunkt auf keinem Weg eine Zeile).
 * Seit 0.9.38 steht zd_ep_abweisung() wortgleich in zd_lib.php - die
 * Annahmefunktion zd_befehl_annehmen() braucht sie auch fuer den MQTT-Weg. */

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

/* Gruppe 43 (0.9.38, Z4; Hausform G39 E-E1 A wie G27/G22): der LESENDE Teil des Endpunkts laesst sich abschalten
 * (endpunkt_ein, Reiter MQTT). Neue Anlagen holen die Werte ueber das MQTT-Gateway V2; eine Neuinstallation hat ihn
 * aus, ein Update laesst ihn an (postinstall.sh). Geprueft NACH Token und Selbsttest - ?selftest=1 beantwortet weiter
 * die Frage nach dem Token. Die schaltenden Aktionen bleiben mit Token erreichbar. */
if (in_array($zd_aktion, $zd_lesend, true) && empty($zd_cfg['endpunkt_ein'])) {
    zd_ep_abweisung('ENDPUNKT_AUS', $zd_aktion);
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=ENDPUNKT_AUS\n";
    echo "Der lesende HTTP-Endpunkt ist ausgeschaltet (Reiter MQTT, Haken HTTP-Endpunkt). Neue Anlagen holen die Werte ueber das MQTT-Gateway V2.\n";
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

/* Schreiber-Wache (Energie-1 C1): &von= lesen, nur an schaltenden Aktionen - lesende
 * bleiben, wie sie waren. Fehlt es: '' (ohne Kennung). Eine Kennung, die nicht ins
 * Muster passt (1..32 aus A-Z a-z 0-9 _ -, auch leer, als Liste oder mit Zeilenumbruch),
 * wird abgewiesen wie ein falscher Wert - abweisen statt zurechtbiegen (Nr. 19); ein
 * Tippfehler faellt beim Einrichten auf. Eine Adresse OHNE von geht immer. */
$zd_von = '';
if (in_array($zd_aktion, $zd_schaltend, true) && isset($_GET['von'])) {
    $zd_von = is_string($_GET['von']) ? $_GET['von'] : '';
    if (!zd_wache_kennung_gueltig($zd_von)) {
        zd_ep_abweisung('VON', $zd_aktion);
        http_response_code(400);
        echo 'SET;OK=0;AKTION=' . $zd_aktion . ";GRUND=VON;ERLAUBT=A-Z,a-z,0-9,_,-;LAENGE=1..32\n";
        echo "Der Wert von von ist keine gueltige Kennung (1 bis 32 Zeichen aus Buchstaben, Ziffern, _ und -) - es wurde nichts eingereiht.\n";
        exit;
    }
}

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

/* Seit 0.9.38 (Gruppe 43, Z3/E8) in EINER Annahmefunktion zd_befehl_annehmen() in zd_lib.php - dieselbe Funktion
 * ruft die Befehlsbruecke fuer Befehle ueber MQTT (bin/befehle_zuordnung.php, von=mqtt). Reihenfolge, Antwortzeilen,
 * HTTP-Codes und Protokollzeilen unveraendert (Probe P3: alle Abweisungen byte-gleich mit 0.9.37): Wert fehlt 400,
 * Geraet 404, Freigabe 403, Dienst 503, Schreiber-Wache 409, Sofortabruf-Bremse, Gleichwert-Unterdrueckung,
 * Einreihen (500 nur bei OK=0 des Dienstes). */
$zd_ant = zd_befehl_annehmen($zd_aktion, $zd_nr, $zd_watt, $zd_prozent, $zd_dry, $zd_von, $zd_cfg, 'http');
if ($zd_ant['http'] !== 200) {
    http_response_code($zd_ant['http']);
}
echo $zd_ant['aus'];
