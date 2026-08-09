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
    $nr = isset($_POST['test_geraet']) ? (string) $_POST['test_geraet'] : '1';
    // Als Ganzzahl weitergeben: "01" besteht die Pruefung, der Dienst
    // vergleicht aber mit Zahlen. Ohne den Guss faende er das Geraet nicht.
    $nr = (string) (int) $nr;
    if (!preg_match('/^[0-9]{1,2}$/', $nr)) {
        return array(0, zd_t('TEST.M_GERAET_UNGUELTIG'));
    }
    switch ($aktion) {
        case 'abruf':
            return zd_befehl_absetzen(array('aktion' => 'abruf'), 8);

        case 'aus':
            return zd_befehl_absetzen(array('aktion' => 'aus', 'geraet' => (int) $nr));

        case 'laden':
        case 'entladen':
            $watt = isset($_POST['test_watt']) ? (string) $_POST['test_watt'] : '';
            if (!preg_match('/^[0-9]{1,5}$/', $watt)) {
                return array(0, zd_t('TEST.M_WATT_UNGUELTIG'));
            }
            return zd_befehl_absetzen(array('aktion' => $aktion, 'geraet' => (int) $nr, 'watt' => (int) $watt));

        default:
            return array(0, zd_t('TEST.M_UNBEKANNT'));
    }
}

/** Mini-SVG: Ladezustand ueber den heutigen Tag (0 bis 24 h, 0 bis 100 %). */
function zd_soc_svg($punkte)
{
    $w = 720; $h = 120; $x0 = 34; $y0 = 8; $pw = $w - $x0 - 8; $ph = $h - $y0 - 20;
    $tag0 = strtotime('today 00:00');
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
    foreach ($punkte as $pt) {
        $anteil = ($pt[0] - $tag0) / 86400;
        if ($anteil < 0 || $anteil > 1) {
            continue;
        }
        $poly[] = round($x0 + $pw * $anteil, 1) . ','
                . round($y0 + $ph - $ph * max(0, min(100, $pt[1])) / 100, 1);
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
