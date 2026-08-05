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
 *   roh                   vollstaendiges Abbild als JSON
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
if ($zd_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($zd_soll, $zd_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$zd_lesend = array('status', 'packs', 'liste', 'roh');
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
$zd_watt    = zd_param('watt', '/^[0-9]{1,5}$/', '');
$zd_prozent = zd_param('prozent', '/^[0-9]{1,3}$/', '');

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

if ($zd_aktion === 'liste') {
    echo 'LISTE;OK=' . (int) (!empty($zd_lox['ok'])) . ';N=' . count($zd_alle) . ';ALTER=' . $zd_alter . "\n";
    foreach ($zd_alle as $nr => $g) {
        echo $nr . ';' . $g['name'] . ';' . $g['art'] . ';' . $g['satz'] . ';'
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
        printf("%s;SOC=%s;VOLT=%s;DVOLT=%s;TEMP=%s;WATT=%s\n",
            $sn, zd_w($p['soc']), zd_w($p['volt']), zd_w($p['dvolt']), zd_w($p['temp']), zd_w($p['watt']));
    }
    exit;
}

if ($zd_aktion === 'status') {
    printf("ZENDURE;OK=%d;SOC=%s;SOCMIN=%s;SOCMAX=%s;PV=%s;HAUS=%s;NETZ=%s;LADEN=%s;ENTLADEN=%s;"
         . "BATP=%s;GRENZEAUS=%s;GRENZEEIN=%s;ACMODUS=%s;PACKS=%s;DVOLT=%s;TEMP=%s;ALTER=%d\n",
        (int) $zd_g['ok'], zd_w($zd_g['soc']), zd_w($zd_g['soc_min']), zd_w($zd_g['soc_max']),
        zd_w($zd_g['pv']), zd_w($zd_g['haus']), zd_w($zd_g['netz']), zd_w($zd_g['laden']),
        zd_w($zd_g['entladen']), zd_w($zd_g['batp']), zd_w($zd_g['grenze_aus']),
        zd_w($zd_g['grenze_ein']), zd_w($zd_g['acmodus']), zd_w($zd_g['packs']),
        zd_w($zd_g['dvolt']), zd_w($zd_g['temp']), (int) $zd_g['alter']);
    exit;
}

/* ================= Schaltende Aktionen ================= */

if ($zd_aktion !== 'abruf' && empty($zd_cfg['steuerung_ein'])) {
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
