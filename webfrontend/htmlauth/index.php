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

/* ---------------- Vorlage herunterladen ---------------- */
if ($zd_post && isset($_POST['vorlage'])) {
    $zd_nr = preg_match('/^[0-9]{1,2}$/', (string) $_POST['vorlage']) ? (int) $_POST['vorlage'] : 1;
    list($zd_name, $zd_inhalt) = zd_vorlage($zd_nr);
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $zd_name . '"');
    echo $zd_inhalt;
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
if ($zd_post && isset($_POST['speichern'])) {
    $zd_cfg = zd_config();
    $zd_saetze = zd_befehlssaetze();
    $zd_modelle = zd_modelle();

    /* Geraetetabelle: bis zu sechs Zeilen. Nur Zeilen mit den noetigen Angaben
     * werden uebernommen; unvollstaendige werden gemeldet, nicht verschluckt. */
    $zd_neu = array();
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
        if ($art === 'http') {
            if ($ip === '') {
                $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_IP_FEHLT'), $i + 1);
                continue;
            }
            // IPv4 oder Hostname zulassen - beides ist gebraeuchlich.
            if (!preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)
                && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{1,80}$/', $ip)) {
                $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_IP'), $i + 1);
                continue;
            }
        } else {
            if ($prodkey === '' || $deviceid === '') {
                $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_MQTT_IDS'), $i + 1);
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $prodkey)
                || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $deviceid)) {
                $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_MQTT_MUSTER'), $i + 1);
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
        );
        foreach (array('max_laden', 'max_entladen') as $feld) {
            $w = $hol('g_' . $feld);
            if ($w === '') {
                continue;   // leer = Werksgrenze des Modells nehmen
            }
            if (!preg_match('/^[0-9]{1,4}$/', $w)) {
                $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_GRENZE'), $i + 1);
                continue;
            }
            $zd_zeile[$feld] = (int) $w;
        }
        $zd_neu[] = $zd_zeile;
    }
    $zd_cfg['geraete'] = $zd_neu;

    foreach (array(
        'intervall'     => array(5, 900),
        'schreibbremse' => array(0, 600),
        'schrittweite'  => array(1, 500),
        'verlauf_tage'  => array(1, 90),
        'wartezeit'     => array(0, 20),
        'broker_port'   => array(1, 65535),
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

    $zd_cfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $zd_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;

    $zd_topic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['mqtt_topic']));
    if ($zd_topic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $zd_topic)) {
        $zd_fehler[] = zd_t('EINST.FEHLER_TOPIC');
    } else {
        $zd_cfg['mqtt_topic'] = trim($zd_topic, '/');
    }

    $zd_bh = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['broker_host']));
    if ($zd_bh !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{0,80}$/', $zd_bh)) {
        $zd_fehler[] = zd_t('EINST.FEHLER_BROKER');
    } else {
        $zd_cfg['broker_host'] = $zd_bh;
    }
    $zd_cfg['broker_user'] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST['broker_user']));
    // Leeres Passwortfeld loescht nichts - sonst stuende irgendwann ein leeres
    // Passwort in der Konfiguration, ohne dass es jemand merkt.
    $zd_bpw = isset($_POST['broker_pw']) ? (string) $_POST['broker_pw'] : '';
    if ($zd_bpw !== '') {
        $zd_cfg['broker_pw'] = $zd_bpw;
    }

    $zd_tu = isset($_POST['temp_umrechnung']) ? (string) $_POST['temp_umrechnung'] : 'roh';
    $zd_cfg['temp_umrechnung'] = in_array($zd_tu, array('roh', 'kelvin10', 'zehntel'), true) ? $zd_tu : 'roh';

    if (!$zd_fehler) {
        if (zd_config_speichern($zd_cfg)) {
            $zd_meldungen[] = zd_t('EINST.GESPEICHERT');
        } else {
            $zd_fehler[] = sprintf(zd_t('EINST.FEHLER_SPEICHERN'), $zd_p['config']);
        }
    }
    $zd_tab = 'tab-settings';
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

/* ---------------- Laden ---------------- */
$zd_cfg = zd_config();
$zd_token = zd_token();
$zd_geraete = zd_geraete();
$zd_werte = zd_werte();
$zd_zustand = zd_zustand();
$zd_alter = zd_alter();
$zd_pid = zd_dienst_pid();
$zd_mqtt = zd_mqtt_zustand();
$zd_host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
    ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
    : (gethostname() ?: 'loxberry');
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
<div style="margin-top:8px;"><?= zd_soc_svg(zd_verlauf_lesen((int) $zd_nr)) ?></div>
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
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= zd_e(zd_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= zd_e(zd_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= zd_e(zd_t('EINST.K_STOPP')) ?></button>
  </form>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= zd_e(zd_t('EINST.H_GERAETE')) ?></h2>
<div class="sm-hinweis"><?= zd_t('EINST.GERAETE_ERKLAERUNG') ?></div>
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
    <th style="width:80px;"><?= zd_e(zd_t('EINST.T_MAXENTLADEN')) ?></th></tr>
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
</tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= zd_t('EINST.GERAETE_HILFE') ?></div>

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
    <option value="<?= $zd_tu ?>"<?= (isset($zd_cfg['temp_umrechnung']) ? $zd_cfg['temp_umrechnung'] : 'roh') === $zd_tu ? ' selected' : '' ?>><?= zd_e(zd_t('EINST.TEMP_' . strtoupper($zd_tu))) ?></option>
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

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= zd_e(zd_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $zd_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
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
<div class="sm-warnung"><?= zd_t('MQTT.ABO_WARNUNG') ?></div>
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
<div class="sm-warnung"><?= zd_t('LOX.S2_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S3_TITEL')) ?></b><br>
<?= zd_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('ALLG.EIGENSCHAFT')) ?></th><th><?= zd_e(zd_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= zd_e(zd_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= zd_e($zd_basis) ?>?token=<?= zd_e($zd_token) ?>&amp;aktion=status&amp;geraet=1</span></td></tr>
<tr><td><?= zd_e(zd_t('LOX.T_ZYKLUS')) ?></td><td>60 <?= zd_e(zd_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<?= zd_t('LOX.S3_BEFEHLE') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('LOX.T_TITEL')) ?></th><th><?= zd_e(zd_t('LOX.T_BEFEHL')) ?></th>
    <th><?= zd_e(zd_t('LOX.T_EINHEIT')) ?></th><th><?= zd_e(zd_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (zd_status_felder() as $zd_feld => $zd_info) { ?>
<tr><td><span class="sm-mono">ZENDURE_1_<?= zd_e($zd_feld) ?></span></td>
    <td><span class="sm-mono">\i<?= zd_e($zd_feld) ?>=\i\v</span></td>
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
    <td><span class="sm-mono"><?= zd_e($zd_basis) ?>?token=<?= zd_e($zd_token) ?>&amp;aktion=status&amp;geraet=<?= zd_e($zd_nr) ?></span></td></tr>
<?php } ?>
</table>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="vorlage" value="1">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= zd_e(zd_t('LOX.K_VORLAGE')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= zd_t('LEGENDE.LESEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S4_TITEL')) ?></b><br>
<?= zd_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('ALLG.EIGENSCHAFT')) ?></th><th><?= zd_e(zd_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= zd_e(zd_t('LOX.T_VA_ADRESSE')) ?></td><td><span class="sm-mono">http://<?= zd_e($zd_host) ?></span></td></tr>
<tr><td><?= zd_e(zd_t('LOX.T_VA_ENTLADEN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= zd_e($zd_p['plugin']) ?>/index.php?token=<?= zd_e($zd_token) ?>&amp;aktion=entladen&amp;geraet=1&amp;watt=&lt;v&gt;</span></td></tr>
<tr><td><?= zd_e(zd_t('LOX.T_VA_LADEN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= zd_e($zd_p['plugin']) ?>/index.php?token=<?= zd_e($zd_token) ?>&amp;aktion=laden&amp;geraet=1&amp;watt=&lt;v&gt;</span></td></tr>
<tr><td><?= zd_e(zd_t('LOX.T_VA_AUS')) ?></td>
    <td><span class="sm-mono">/plugins/<?= zd_e($zd_p['plugin']) ?>/index.php?token=<?= zd_e($zd_token) ?>&amp;aktion=aus&amp;geraet=1</span></td></tr>
<tr><td><?= zd_e(zd_t('LOX.T_VA_SOCMIN')) ?></td>
    <td><span class="sm-mono">/plugins/<?= zd_e($zd_p['plugin']) ?>/index.php?token=<?= zd_e($zd_token) ?>&amp;aktion=socmin&amp;geraet=1&amp;prozent=&lt;v&gt;</span></td></tr>
</table>
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
 * Typ, Name und Parameter stehen als Sprachschluessel drin, die Eingangsspalte
 * ist symbolisch und damit sprachfrei.
 */
function zd_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01', 'BAUSTEIN.P01', '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02', 'BAUSTEIN.P02', '&mdash;'),
        array(3,  'BAUSTEIN.T_VE',      'BAUSTEIN.N03', 'BAUSTEIN.P03', '&mdash;'),
        array(4,  'BAUSTEIN.T_VE',      'BAUSTEIN.N04', 'BAUSTEIN.P04', '&mdash;'),
        array(5,  'BAUSTEIN.T_VE',      'BAUSTEIN.N05', 'BAUSTEIN.P05', '&mdash;'),
        array(6,  'BAUSTEIN.T_VE',      'BAUSTEIN.N06', 'BAUSTEIN.P06', '&mdash;'),
        array(7,  'BAUSTEIN.T_VE',      'BAUSTEIN.N07', 'BAUSTEIN.P07', '&mdash;'),
        array(8,  'BAUSTEIN.T_VE',      'BAUSTEIN.N08', 'BAUSTEIN.P08', '&mdash;'),
        array(9,  'BAUSTEIN.T_SWS',     'BAUSTEIN.N09', 'BAUSTEIN.P09', 'I &larr; #7'),
        array(10, 'BAUSTEIN.T_NICHT',   'BAUSTEIN.N10', '',             'I &larr; #8'),
        array(11, 'BAUSTEIN.T_ODER',    'BAUSTEIN.N11', '',             'I1 &larr; #9, I2 &larr; #10'),
        array(12, 'BAUSTEIN.T_EVZ',     'BAUSTEIN.N12', 'BAUSTEIN.P12', 'I &larr; #11'),
        array(13, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N13', 'BAUSTEIN.P13', 'I &larr; #12'),
        array(14, 'BAUSTEIN.T_SWS',     'BAUSTEIN.N14', 'BAUSTEIN.P14', 'I &larr; #6'),
        array(15, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N15', 'BAUSTEIN.P15', 'I &larr; #14'),
        array(16, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N16', 'BAUSTEIN.P16', 'I1 &larr; #1, I2 &larr; #3'),
        array(17, 'BAUSTEIN.T_TASTER',  'BAUSTEIN.N17', 'BAUSTEIN.P17', '&mdash;'),
        array(18, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N18', 'BAUSTEIN.P18', 'I1 &larr; ' . zd_t('BAUSTEIN.ZAEHLER')),
        array(19, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N19', 'BAUSTEIN.P19', 'I1 &larr; ' . zd_t('BAUSTEIN.ZAEHLER')),
        array(20, 'BAUSTEIN.T_VEZ',     'BAUSTEIN.N20', 'BAUSTEIN.P20', '&mdash;'),
        array(21, 'BAUSTEIN.T_VERGL',   'BAUSTEIN.N21', 'BAUSTEIN.P21', 'I1 &larr; #1, I2 &larr; #20'),
        array(22, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N22', 'BAUSTEIN.P22', 'I1 &larr; #18, I2 &larr; #21, I3 &larr; #17'),
        array(23, 'BAUSTEIN.T_FORMEL',  'BAUSTEIN.N23', 'BAUSTEIN.P23', 'I1 &larr; #19, I2 &larr; #17'),
        array(24, 'BAUSTEIN.T_IMPULS',  'BAUSTEIN.N24', 'BAUSTEIN.P24', '&mdash;'),
        array(25, 'BAUSTEIN.T_ANALOGSP','BAUSTEIN.N25', 'BAUSTEIN.P25', 'I &larr; #22, ' . zd_t('BAUSTEIN.TRIGGER') . ' &larr; #24'),
        array(26, 'BAUSTEIN.T_ANALOGSP','BAUSTEIN.N26', 'BAUSTEIN.P26', 'I &larr; #23, ' . zd_t('BAUSTEIN.TRIGGER') . ' &larr; #24'),
        array(27, 'BAUSTEIN.T_VA',      'BAUSTEIN.N27', 'BAUSTEIN.P27', 'I &larr; #25'),
        array(28, 'BAUSTEIN.T_VA',      'BAUSTEIN.N28', 'BAUSTEIN.P28', 'I &larr; #26'),
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
    <td><?= $zd_b[3] !== '' ? zd_t($zd_b[3]) : '&mdash;' ?></td><td><?= $zd_b[4] ?></td></tr>
<?php } ?>
</table>
<?= zd_t('LOX.S7_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= zd_e(zd_t('LOX.S8_TITEL')) ?></b><br>
<?= zd_t('LOX.S8_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= zd_e(zd_t('LOX.T_PRUEFUNG')) ?></th><th><?= zd_e(zd_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= zd_e($zd_basis) ?>?token=<?= zd_e($zd_token) ?>&amp;aktion=status</span></td>
    <td><span class="sm-mono">ZENDURE;OK=1;SOC=...</span></td></tr>
<tr><td><span class="sm-mono"><?= zd_e($zd_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= zd_e($zd_basis) ?>?token=<?= zd_e($zd_token) ?>&amp;aktion=quatsch</span></td>
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
  <a class="sm-btn sm-b-lesen" href="<?= zd_e($zd_basis) ?>?token=<?= zd_e($zd_token) ?>&amp;aktion=status&amp;geraet=1" target="_blank"><?= zd_e(zd_t('TEST.K_STATUS')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= zd_e($zd_basis) ?>?token=<?= zd_e($zd_token) ?>&amp;aktion=packs&amp;geraet=1" target="_blank"><?= zd_e(zd_t('TEST.K_PACKS')) ?></a>
  <a class="sm-btn sm-b-lesen" href="<?= zd_e($zd_basis) ?>?token=<?= zd_e($zd_token) ?>&amp;aktion=liste" target="_blank"><?= zd_e(zd_t('TEST.K_LISTE')) ?></a>
</div>

<h3><?= zd_e(zd_t('TEST.H_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= zd_e(zd_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <a class="sm-btn sm-b-technik" href="<?= zd_e($zd_basis) ?>?token=<?= zd_e($zd_token) ?>&amp;aktion=roh" target="_blank"><?= zd_e(zd_t('TEST.K_ROH')) ?></a>
</div>
<?php if ($zd_testausgabe !== '') { ?>
<div class="sm-pre"><?= zd_e($zd_testausgabe) ?></div>
<?php } ?>

<h3><?= zd_e(zd_t('TEST.H_SCHALTEN')) ?></h3>
<div class="sm-warnung"><?= zd_t('TEST.SCHALTEN_WARNUNG') ?></div>
<?php if (empty($zd_cfg['steuerung_ein'])) { ?>
<div class="sm-hinweis"><?= zd_t('TEST.SCHALTEN_GESPERRT') ?></div>
<?php } ?>
<form action="index.php" method="post">
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
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="abruf"><?= zd_e(zd_t('TEST.K_ABRUF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="entladen"><?= zd_e(zd_t('TEST.K_ENTLADEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="laden"><?= zd_e(zd_t('TEST.K_LADEN')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="aus"><?= zd_e(zd_t('TEST.K_AUS')) ?></button>
</div>
</form>

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
