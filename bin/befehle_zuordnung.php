<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Zendure SolarFlow (Gruppe 43, 0.9.38).
 *
 * Die Befehlsbruecke (bin/befehlsbruecke.php, byte-gleich aus der Stammfassung Werkzeuge/gemeinsam/, 1.0.2) abonniert
 * <praefix>/befehl/# und gibt jeden gueltigen Befehl an tun() dieser Tabelle. tun() ruft DIESELBE Annahmefunktion wie
 * der HTTP-Endpunkt webfrontend/html/index.php - zd_befehl_annehmen(), mit Quelle 'mqtt' und Kennung von=mqtt fuer die
 * Schreiber-Wache (Entwurf Gruppe 43 E8, Vorschlag 1) - und gibt array(ok, grund) fuer die Quittung zurueck
 * (zd_befehl_quittung()). Ein Token gibt es auf diesem Weg nicht: das Plugin ist selbst der Empfaenger. Ein
 * Trockenlauf (dry=1) bleibt dem HTTP-Weg vorbehalten.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * Kein Name endet auf "quittung"; zwei Eintraege treffen nie dasselbe Thema (befehlsbruecke.php --pruefen).
 *
 * Die Befehle (wie die schaltenden Aktionen des Endpunkts, Geraet 1; Geraet 2 bis 9 unter <praefix>/befehl/<nr>/...):
 *   laden, entladen   ganze Zahl in W (0 bis 99999 wie das Muster des Endpunkts; begrenzt wird im Dienst)
 *   aus               Puls (nur 1): Sollwert 0
 *   socmin, socmax    ganze Zahl in % (0 bis 100)
 *   grenzeaus/-ein    ganze Zahl in W (0 bis 99999)
 *   abruf             Puls (nur 1): sofort abrufen statt auf den Takt zu warten
 * Alle ausser abruf greifen ein: zweiter Haken ist der vorhandene Hauptschalter "Schreibende Befehle zulassen"
 * (steuerung_ein, ab Werk aus) - KEIN neuer zweiter Haken (E8). Der eigentliche Riegel ist "Befehle ueber MQTT
 * annehmen" (befehle_mqtt_ein, ab Werk und beim Update aus). Sperrminute 0 s fuer die Sollwerte: die Schreibbremse des
 * Dienstes (30 s je Geraet) und die Gleichwert-Unterdrueckung des Endpunkts (60 s) bleiben die eine Pruefung; 60 s
 * fuer abruf (dazu die Abrufbremse des Endpunkts). "angenommen" heisst: die Annahmefunktion meldet OK=1 (auch
 * UNVERAENDERT=1).
 */
if (!function_exists('zd_befehl_annehmen')) {
    $zd_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $zd_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/zd_lib.php';
    }
    $zd_kand[] = dirname(__DIR__) . '/webfrontend/html/zd_lib.php';
    foreach ($zd_kand as $zd_k) {
        if (is_file($zd_k)) {
            require_once $zd_k;
            break;
        }
    }
}
$zd_bb_grund = 'Sollwerte kommen aus der Lade-/Entladeregelung in Loxone oft im Takt; derselbe Wert muss nach '
    . 'totband_auffrischung erneut durch. Die Schreibbremse des Dienstes (30 s je Geraet) und die Gleichwert-'
    . 'Unterdrueckung (60 s) bleiben die eine Pruefung.';
$zd_bb_tun = function ($aktion, $wert, $nr) {
    $watt = in_array($aktion, array('laden', 'entladen', 'grenzeaus', 'grenzeein'), true) ? (string) (int) $wert : '';
    $proz = in_array($aktion, array('socmin', 'socmax'), true) ? (string) (int) $wert : '';
    return zd_befehl_quittung(zd_befehl_annehmen($aktion, (string) (int) $nr, $watt, $proz, false, 'mqtt', null, 'mqtt'));
};
$zd_bb = array();
foreach (array('' => 1, '{nr}/' => 0) as $zd_vor => $zd_eins) {
    $zd_gn = $zd_eins ? '' : ' Geraet N';
    foreach (array('laden' => array('W', 99999, 'K_LADEN', 'Laden'), 'entladen' => array('W', 99999, 'K_ENTLADEN', 'Entladen'),
                   'socmin' => array('%', 100, 'K_SOCMIN', 'SOC-Minimum'), 'socmax' => array('%', 100, 'K_SOCMAX', 'SOC-Maximum'),
                   'grenzeaus' => array('W', 99999, 'K_GRENZEAUS', 'Grenze Einspeisung'),
                   'grenzeein' => array('W', 99999, 'K_GRENZEEIN', 'Grenze Netzbezug')) as $zd_a => $zd_d) {
        $zd_bb[$zd_vor . $zd_a] = array('art' => 'ganz', 'min' => 0, 'max' => $zd_d[1], 'eingreifend' => true,
            'sperre_s' => 0, 'begruendung' => $zd_bb_grund,
            'titel' => 'Zendure ' . $zd_d[3] . $zd_gn . ' (MQTT)', 'kachel' => 'BEFEHL.' . $zd_d[2], 'quittung' => 'ausgefuehrt',
            'tun' => function ($w, $t) use ($zd_bb_tun, $zd_a, $zd_eins) {
                return $zd_bb_tun($zd_a, $w, $zd_eins ? 1 : (int) $t['nr']);
            });
    }
    $zd_bb[$zd_vor . 'aus'] = array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 0, 'begruendung' => $zd_bb_grund,
        'titel' => 'Zendure Aus' . $zd_gn . ' (MQTT)', 'kachel' => 'BEFEHL.K_AUS', 'quittung' => 'ausgefuehrt',
        'tun' => function ($w, $t) use ($zd_bb_tun, $zd_eins) {
            return $zd_bb_tun('aus', 0, $zd_eins ? 1 : (int) $t['nr']);
        });
    $zd_bb[$zd_vor . 'abruf'] = array('art' => 'puls', 'eingreifend' => false,
        'titel' => 'Zendure Sofortabruf' . $zd_gn . ' (MQTT)', 'kachel' => 'BEFEHL.K_ABRUF', 'quittung' => 'ausgefuehrt',
        'tun' => function ($w, $t) use ($zd_bb_tun, $zd_eins) {
            return $zd_bb_tun('abruf', 0, $zd_eins ? 1 : (int) $t['nr']);
        });
}
return array(
    'fassung' => 1,
    'linie'   => 'zendure',
    'plugin'  => 'Zendure',
    'geladen' => function_exists('zd_befehl_annehmen') && function_exists('zd_befehl_quittung'),
    'zweiter_haken' => 'steuerung_ein',
    'einstellungen' => function () {
        // zd_config(false): wie der unangemeldete Endpunkt legt die Bruecke nichts an und heilt nichts.
        $c = zd_config(false);
        return array('praefix'     => zd_praefix($c),
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_ein']),
                     'eingreifend' => !empty($c['steuerung_ein']),
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    'protokoll' => function ($stufe, $text) {
        // 0.9.40: bb_log() setzt "Befehlsbruecke: " schon vor den Text - bis 0.9.39 stand es doppelt (Nachzug 01.11.2026, wie MGiSmart 1.1.30).
        zd_log(($stufe === 'INFO' ? '' : $stufe . ' ') . $text);
    },
    'teile' => array('nr' => '[2-9]'),
    'befehle' => $zd_bb,
);
