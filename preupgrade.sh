#!/bin/bash
# Zendure SolarFlow - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-zendure}"
BASE="${ARGV5:-$LBHOMEDIR}"

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
SKRIPT="$BASE/bin/plugins/$PFOLDER/zendure_dienst.php"

# ---- Die Upgrade-Marke, als ERSTES ----
#
# Zwischen der Neuanlage der Cron-Datei und postinstall.sh liegt am Geraet fast
# eine Minute (Regeln/06, "Der Minutentakt startet den Dienst MITTEN im
# Upgrade"). Der Sollmerker dieses Plugins liegt seit 0.9.9 NEBEN dem
# Datenordner und ueberlebt purge_installation - der Waechter startet den
# Dienst in dieser Luecke also tatsaechlich. Gemessen 18.09.2026 in WSL
# (Pruefung-ZendureSolarFlow-0.9.23/Pruefstaende/vorher.txt, Fall 3: ein
# Dienst, wo keiner erwartet war).
#
# Die Marke liegt NEBEN data/plugins/<ordner>, sonst nimmt purge_installation
# sie mit. Sie traegt die Unixzeit; bin/dienst.sh achtet sie, solange sie
# juenger als 3600 s ist.
#
# Als Erstes, noch vor dem Anhalten des Dienstes: das Anhalten wartet bis zu
# zehn Sekunden je Prozess, und in dieser Zeit kann der Minutentakt laufen.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
mkdir -p "$BASE/data/plugins" 2>/dev/null
if date +%s > "$MARKE" 2>/dev/null; then
    echo "<INFO> Upgrade-Marke gesetzt: $MARKE"
else
    # Ohne Marke wird die Luecke nicht gedeckt - das gehoert gesagt, nicht
    # verschwiegen. Abgebrochen wird deswegen nicht: der Zustand ohne Marke ist
    # genau der von 0.9.22, und der hat in der Messung nichts verloren.
    rm -f "$MARKE" 2>/dev/null
    echo "<WARNING> Die Upgrade-Marke liess sich nicht anlegen ($MARKE)."
    echo "<WARNING> Der Minutentakt kann den Dienst waehrend der Aktualisierung starten."
fi

# Der Dienst wird NICHT ueber dienst.sh angehalten: "dienst.sh stop" entfernt
# den Sollmerker, und dann startet der minuetliche Waechter den Dienst nach
# dem Upgrade nicht wieder. Angehalten wird deshalb hier - aber nur der
# eigene Prozess.
#
# Gehoert diese Prozessnummer wirklich dem eigenen Dienst?
#
# Gemessen 18.09.2026 (Bestand-2026-09-18/klasse-F-nachmessung): ein fremder
# "sleep 600", dessen Nummer in der PID-Datei stand, wurde ungeprueft
# beendet - "<INFO> Laufender Dienst angehalten." -> "nachher: Koeder 5889
# IST TOT". Prozessnummern werden wiederverwendet; die PID-Datei kann aus
# einem abgestuerzten Lauf stammen, vom Installer ueberlebt haben oder von
# Hand gesetzt sein.
#
# Geprueft wird argumentweise ueber /proc/<pid>/cmdline, nicht ueber die
# ganze Befehlszeile: argv[0] muss ein PHP sein, argv[1] gegen den
# Arbeitsordner des Prozesses aufgeloest genau das eigene Dienstskript, und
# der Prozess muss dem erwarteten Benutzer gehoeren. "nano
# <pfad>/zendure_dienst.php" fuehrt den Pfad sonst ebenfalls als zweites
# Argument. Bauart: Midea2Lox 4.5.7 (eigener_dienst), APC-UPS 1.2.11
# (ap_ist_dienst); dieselbe Pruefung wie laeuft() in bin/dienst.sh.
zd_dienst_uid() {
    if [ "$(id -u)" = "0" ]; then
        id -u loxberry 2>/dev/null
    else
        id -u
    fi
}

zd_eigener_dienst() {   # $1 PID, $2 erwartete UID ("" = nicht pruefen)
    local a0 a1 wd ziel soll
    case "$1" in
        ''|*[!0-9]*) return 1 ;;
    esac
    { IFS= read -r -d '' a0 && IFS= read -r -d '' a1; } 2>/dev/null < "/proc/$1/cmdline" || return 1
    case "${a0##*/}" in
        php|php[0-9]|php[0-9].[0-9]|php[0-9].[0-9][0-9]) ;;
        *) return 1 ;;
    esac
    [ "${a1##*/}" = "zendure_dienst.php" ] || return 1
    if [ -n "$2" ]; then
        [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$2" ] || return 1
    fi
    case "$a1" in
        /*) ziel=$a1 ;;
        *)  wd=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            ziel="${wd% (deleted)}/$a1" ;;
    esac
    ziel=$(readlink -f "$ziel" 2>/dev/null || echo "$ziel")
    soll=$(readlink -f "$SKRIPT" 2>/dev/null || echo "$SKRIPT")
    [ "$ziel" = "$soll" ]
}

# Den eigenen Dienst anhalten - erst den aus der PID-Datei, dann die, die an
# ihr vorbei laufen.
#
# Die PID-Datei kennt nur den Lauf, den dienst.sh zuletzt gestartet hat. Ein
# Dienst ohne PID-Datei war fuer dieses Skript bisher unsichtbar und lief
# waehrend des Upgrades weiter - mit offener Warteschlange und offenem
# Horcher. Gesucht wird mit derselben strengen Pruefung wie oben; wer nicht
# sagen kann, wem der Prozess gehoeren muesste, toetet ihn auch nicht.
zd_dienst_beenden() {   # $1 PID, $2 erwartete UID
    kill "$1" 2>/dev/null || true
    W=0
    while [ "$W" -lt 20 ] && kill -0 "$1" 2>/dev/null; do
        sleep 0.5
        W=$((W + 1))
    done
    # Vor dem KILL erneut nachsehen: nach zehn Sekunden kann die Nummer schon
    # einem anderen Programm gehoeren, und ein kill -9 an den Falschen laesst
    # sich nicht zuruecknehmen.
    if zd_eigener_dienst "$1" "$2"; then
        kill -9 "$1" 2>/dev/null || true
    fi
}

if [ -f "$PID" ]; then
    DPID=$(cat "$PID" 2>/dev/null)
    DUID=$(zd_dienst_uid)
    if zd_eigener_dienst "$DPID" "$DUID"; then
        zd_dienst_beenden "$DPID" "$DUID"
        rm -f "$PID"
        echo "<INFO> Laufender Dienst angehalten."
    else
        # Die PID-Datei bleibt NICHT liegen: sie zeigt nachweislich nicht auf
        # den eigenen Dienst, und der Waechter wuerde sie sonst weiter lesen.
        rm -f "$PID"
        echo "<INFO> Die PID-Datei zeigte nicht auf den eigenen Dienst - es wurde"
        echo "<INFO> kein Signal gesendet. Die Datei wurde entfernt."
    fi
fi

# Waisen: Dienste, die an der PID-Datei vorbei laufen.
DUID=$(zd_dienst_uid)
if [ -n "$DUID" ]; then
    for D in /proc/[0-9]*; do
        WPID=${D#/proc/}
        zd_eigener_dienst "$WPID" "$DUID" || continue
        zd_dienst_beenden "$WPID" "$DUID"
        echo "<INFO> Ein Dienst ohne PID-Datei wurde angehalten (PID $WPID)."
    done
else
    echo "<INFO> Der Dienstbenutzer ist unbekannt - nach Diensten ohne PID-Datei"
    echo "<INFO> wurde nicht gesucht."
fi
# Herrenlose Horcher aufraeumen, damit sie sich nicht doppeln.
#
# Argumentweise, nicht mit "pkill -f": das durchsucht die ganze Befehlszeile
# als Teilzeichenkette. Gemessen 18.09.2026 mit einem Koeder, der die
# Zeichenkette nur in seiner Befehlszeile trug - "nachher: Koeder 8945 IST
# TOT". Verglichen wird jetzt argv[0] (muss mosquitto_sub sein) gegen ein
# GANZES Argument "loxberry-<ordner>-<nummer>", so wie zd_horcher_starten()
# die Kennung vergibt, und der Prozess muss dem Dienstbenutzer gehoeren.
#
# Die Kennung traegt den ORDNERNAMEN. Bis 0.9.8 stand dort das feste Wort
# "zendure" - bei JEDER Installation. Ein Update von "zendure_01" erschlug
# damit den Horcher der Installation "zendure" gleich mit, und die las
# danach bis zu ihrem naechsten Dienstneustart kein MQTT mehr.
HUID=$(zd_dienst_uid)
for D in /proc/[0-9]*; do
    [ -r "$D/cmdline" ] || continue
    A0=$(tr '\0' '\n' < "$D/cmdline" 2>/dev/null | sed -n '1p')
    [ "${A0##*/}" = "mosquitto_sub" ] || continue
    [ -z "$HUID" ] || [ "$(stat -c %u "$D" 2>/dev/null)" = "$HUID" ] || continue
    tr '\0' '\n' < "$D/cmdline" 2>/dev/null \
        | grep -qxE "loxberry-$PFOLDER-[0-9]+" || continue
    kill "${D#/proc/}" 2>/dev/null || true
done

CF="$BASE/config/plugins/$PFOLDER/zendure.json"

# Die Zweitschrift wird nur mit einem Stand ueberschrieben, der das
# Aktionstoken traegt.
#
# Bis 0.9.22 stand hier ein unbedingtes "cp -p". Das ist derselbe Fehler wie
# in zd_config() (Klasse A, gemessen 18.09.2026): eine ABGESCHNITTENE
# zendure.json ist vorhanden und nicht leer, "[ -f ]" trifft also zu - und
# damit ging der einzige Rueckweg zum Aktionstoken beim naechsten Upgrade
# verloren. Geprueft wird deshalb nach INHALT, nicht nach Form: die Datei
# muss sich als JSON-Objekt lesen lassen UND ein nicht leeres aktionstoken
# fuehren. Ohne PHP wird NICHT ueberschrieben - eine alte Zweitschrift zu
# behalten ist die harmlose Richtung.
zd_traegt_token() {
    command -v php >/dev/null 2>&1 || return 1
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
            $t = (is_array($d) && isset($d["aktionstoken"])) ? trim((string) $d["aktionstoken"]) : "";
            exit($t === "" ? 1 : 0);' "$1" 2>/dev/null
}

if [ -f "$CF" ] && zd_traegt_token "$CF"; then
    cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.json"
elif [ -f "$CF" ]; then
    echo "<WARNING> Die Konfiguration traegt kein Aktionstoken - die vorhandene"
    echo "<WARNING> Zweitschrift bleibt unveraendert stehen."
fi
echo "<OK> preupgrade abgeschlossen."

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-zendure}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
# "[ -s ]" heisst nur "nicht leer" und ist zu schwach (Regeln/05): eine
# abgeschnittene Datei ist nicht leer. Derselbe Inhaltsentscheid wie oben.
if [ -f "$NETZ_CFG/zendure.json" ] && zd_traegt_token "$NETZ_CFG/zendure.json"; then
    cp -p "$NETZ_CFG/zendure.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.zendure.json" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.zendure.json" 2>/dev/null
    echo "<INFO> Zweitschrift der Einstellungen angelegt."
else
    echo "<INFO> Keine Konfiguration mit Aktionstoken - die Zweitschrift bleibt, wie sie ist."
fi

exit 0
