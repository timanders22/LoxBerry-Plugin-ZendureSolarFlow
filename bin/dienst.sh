#!/bin/bash
# Zendure SolarFlow - Start, Stopp und Waechter des Abrufdienstes.
#
# Wurzel und Ordnername kommen aus der UMGEBUNG, solange sie etwas sagt
# ($LBHOMEDIR, $LBPPLUGINDIR); erst danach aus dem Ablageort - siehe
# "Wurzel und Ordnername" weiter unten. Nicht ueber LoxBerry::System: das
# leitet den Pluginordner aus dem Aufrufort ab; wird dieses Skript aus
# postinstall.sh oder aus dem Cron gestartet, kommt dort ueberall
# Leerstring zurueck - das Skript werkelt dann gegen /-Pfade und meldet
# trotzdem Erfolg.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.
# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei, Sollmerker
# und Protokoll danach root. Die Oberflaeche laeuft als loxberry und koennte
# den Dienst anschliessend weder anhalten noch neu starten: sie darf die
# Dateien nicht mehr schreiben. Schlimmer noch, 'dienst.sh stop' meldet dann
# Erfolg - das kill scheitert, aber das rm der PID-Datei gelingt, weil das
# Verzeichnis loxberry gehoert. Der Dienst laeuft weiter und ist nur noch
# ueber die Prozessliste zu finden.
#
# Deshalb setzt sich das Skript selbst herunter, EINMAL und bevor es
# irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig ohnehin
# unerreichbar (der Cron laeuft bereits als loxberry); er greift nur, wenn
# jemand von Hand mit sudo aufruft.
#
# Woertlich uebernommen aus LoxBerry-Plugin-Dashboard-0.9.12, dort seit dem
# 16.08.2026 in Betrieb. Ueber den Bestand gezaehlt am 31.08.2026: 15 von 17
# dienst.sh hatten den Abstieg nicht, obwohl REGELN_2 ihn seit langem
# verlangt.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

# ------------------------------------------------ Wurzel und Ordnername
#
# GELESEN, nicht geraten (Regeln/03: Stufe 1 ist $LBHOMEDIR; Regeln/06:
# Wurzelsuche mit config/system/general.json; Bestand-2026-09-18/klasse-H/
# Ergebnis.md, Bauart H1). Bis 0.9.23 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# - ein gesetztes $LBHOMEDIR wurde UEBERSCHRIEBEN, und darunter legte ein
# "mkdir -p" bei jedem Aufruf an, was die Rechnung ergab. In WSL gemessen
# (18.09.2026, Pruefung-ZendureSolarFlow-0.9.24, Faelle H4-H8, H17, R2): ein
# "status" aus einem Pruefarchiv unter <Wurzel>/pruefung/<plugin>/bin legte
# in der LAUFENDEN Anlage data/plugins/bin, data/plugins/bin.bestand und
# log/plugins/bin an.
#
# Zwei Stufen fuer die Wurzel, in dieser Reihenfolge:
#   1. $LBHOMEDIR aus der Umgebung, wenn dort config/plugins und
#      data/plugins liegen,
#   2. aufwaerts suchen, bis ein Verzeichnis config/plugins, data/plugins
#      UND config/system/general.json traegt (Regeln/06, Raumklima-Vorfall).
# Findet keine etwas, bricht das Skript ab, BEVOR es etwas anlegt, startet
# oder anhaelt (Regeln/06: ohne brauchbare Wurzel warnen statt vollziehen).
# Die Rechnung "drei Ebenen ueber dem Ablageort" (bis 0.9.23 die einzige,
# im Bau von 0.9.24 zunaechst als dritte Stufe behalten) ist fort: in einem
# fremden Baum ohne general.json
# (<X>/bin/plugins/zendure, kein LBHOMEDIR) war <X> dann die Wurzel, und
# "start" legte dort Ordner an und startete den Dienst - in WSL gemessen
# (Pruefung-ZendureSolarFlow-0.9.24, Faelle F1-F4). Ein LoxBerry hat immer
# config/system/general.json; auf ihm findet Stufe 2 die Wurzel.
# "pwd -P" auf beiden Seiten: liegt die Wurzel hinter einem Verweis, muss
# der Vergleich unten zwei physische Pfade vergleichen (Fall H12).
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd -P)       # <home>/bin/plugins/<ordner>
zd_wurzel_suchen() {
    zd_v="$SELF"
    zd_i=0
    while [ -n "$zd_v" ] && [ "$zd_v" != "/" ] && [ "$zd_i" -lt 8 ]; do
        if [ -d "$zd_v/config/plugins" ] && [ -d "$zd_v/data/plugins" ] \
           && [ -f "$zd_v/config/system/general.json" ]; then
            echo "$zd_v"
            return 0
        fi
        zd_v=$(dirname "$zd_v")
        zd_i=$((zd_i + 1))
    done
    return 1
}
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
   && [ -d "$LBHOMEDIR/data/plugins" ]; then
    LBHOMEDIR=$(cd "$LBHOMEDIR" && pwd -P)
else
    LBHOMEDIR=$(zd_wurzel_suchen) || LBHOMEDIR=""
fi
# Ohne Wurzel: nichts anlegen, nichts starten, nichts anhalten. "status"
# antwortet mit 4 ("Zustand unbekannt"), damit es sich von 1 ("gestoppt")
# unterscheidet; alles andere mit 1. Die Meldung geht nur auf die Ausgabe -
# eine Protokolldatei gibt es ohne Wurzel nicht, und der Cron-Waechter
# leitet seine Ausgabe nach /dev/null (Fall F4).
if [ -z "$LBHOMEDIR" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden."
    echo "FEHLER: \$LBHOMEDIR ist nicht gesetzt, und oberhalb von $SELF traegt"
    echo "FEHLER: kein Verzeichnis config/plugins, data/plugins und config/system/general.json."
    echo "FEHLER: Es wurde nichts angelegt, nichts gestartet und nichts angehalten."
    [ "${1:-}" = "status" ] && exit 4
    exit 1
fi
# Der Ordnername kommt aus $LBPPLUGINDIR, sonst aus dem Ablageort. Am Geraet
# steht $LBPPLUGINDIR in keiner Cron-Schale (Regeln/03) - dann traegt der
# Ablageort, und bei einer regulaeren Installation ist das genau richtig.
PNAME="${LBPPLUGINDIR:-}"
[ -n "$PNAME" ] || PNAME=$(basename "$SELF")
PBIN="$LBHOMEDIR/bin/plugins/$PNAME"
# Laeuft dieses Skript wirklich AUS der Installation? Was schreibt (start,
# restart, waechter), faellt sonst geschlossen aus.
INSTALLIERT=0
[ "$SELF" = "$PBIN" ] && INSTALLIERT=1

PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
# NEBEN dem Datenordner - plugininstall.pl raeumt data/plugins/<ordner>/ bei
# JEDEM Update vollstaendig ab. Bis 0.9.8 lag der Sollmerker darin: nach einem
# Update war er fort, und der Waechter startete den Dienst nie wieder. Der
# Punkt im Namen haelt den Ordner aus dem "rm -rf <ordner>/" heraus.
PBESTAND="$LBHOMEDIR/data/plugins/$PNAME.bestand"
SOLL="$PBESTAND/soll_laufen"
LOGDATEI="$PLOG/zendure.log"
# Aus dem GELESENEN bin-Ordner, nicht aus dem Ablageort: installiert ist das
# derselbe Ordner. Aus einem ausgepackten Archiv mit gesetzter Umgebung sieht
# "status" so den Dienst der Anlage (Fall H4) - gestartet wird von dort
# nichts (siehe INSTALLIERT).
SKRIPT="$PBIN/zendure_dienst.php"
# Ein Sperrmerker gegen zwei gleichzeitige Laeufe. Ohne ihn koennen der
# minuetliche Waechter und ein Klick in der Oberflaeche einander ueberholen.
SPERRE="$PBESTAND/dienst.sperre"
# Die Upgrade-Marke. Sie liegt NEBEN dem Datenordner, wie der Bestandsordner:
# preupgrade.sh legt sie an, purge_installation loescht sie deshalb nicht mit,
# und postinstall.sh raeumt sie wieder weg.
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"

# Angelegt wird erst beim START (und vom Waechter vor seiner Protokollzeile),
# nicht bei jedem Aufruf. Bis 0.9.23 stand hier "mkdir -p" auf oberster
# Ebene - auch "status" und "stop" legten damit Ordner an, in der
# Upgrade-Luecke auch den eben geloeschten Datenordner (Fall H8).
ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" "$PBESTAND" 2>/dev/null
}

# Ein Schutz faellt geschlossen aus (CLAUDE.md 4): wer aus einem Pruefarchiv
# oder einem ausgepackten Archiv startet, schriebe in die laufende Anlage -
# unter einem Ordnernamen, den niemand gewollt hat.
nicht_installiert() {
    echo "FEHLER: dieses Skript liegt nicht unter $PBIN -"
    echo "FEHLER: aus einem ausgepackten Archiv wird nichts gestartet."
}

# Sollmerker aus einer Installation vor 0.9.9 einmalig hinueberziehen - nur
# aus der Installation; der Bestandsordner entsteht dafuer erst, wenn es
# wirklich einen alten Merker gibt (Fall H18).
if [ "$INSTALLIERT" = "1" ] && [ -f "$PDATA/soll_laufen" ] && [ ! -f "$SOLL" ]; then
    mkdir -p "$PBESTAND" 2>/dev/null
    mv "$PDATA/soll_laufen" "$SOLL" 2>/dev/null || touch "$SOLL"
fi

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein
    # Argumentweise pruefen, nicht die ganze Befehlszeile durchsuchen.
    #
    # /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Ein grep
    # darueber traf auch einen Editor mit geoeffneter zendure_dienst.php,
    # wenn die Prozessnummer wiederverwendet wurde. Geprueft werden jetzt
    # zwei Dinge: das zweite Argument ist genau unser Skript, und das erste
    # ist ein PHP - "nano <pfad>/zendure_dienst.php" fuehrt den Pfad sonst
    # ebenfalls als zweites Argument.
    ARGS=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null)
    [ "$(echo "$ARGS" | sed -n '2p')" = "$SKRIPT" ] || return 1
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)php[0-9.]*$' || return 1
    return 0
}

# Laeuft gerade eine Aktualisierung dieses Plugins?
#
# Zwischen der Neuanlage der Cron-Datei und postinstall.sh liegt am Geraet
# fast eine Minute (Regeln/06: preupgrade 03:31:30, Cron neu 03:31:32,
# postinstall 03:32:24). In dieser Luecke laeuft der Minutentakt - und weil
# der Sollmerker seit 0.9.9 NEBEN dem Datenordner liegt, ueberlebt er
# purge_installation und der Waechter startet den Dienst mitten im Upgrade.
# Gemessen 18.09.2026 in WSL (Pruefung-ZendureSolarFlow-0.9.23, Fall 3):
# "in der Luecke startet KEIN Dienst: 1 (erwartet 0)". Die Einstellungen
# gingen dabei nicht verloren - die Selbstheilung aus 0.9.22 holt sie aus der
# Zweitschrift zurueck -, aber ein Dienst, der waehrend des Auspackens und
# waehrend dpkg/apt anlaeuft, ist ein Zustand, den niemand gewollt hat.
#
# Regeln der Marke (AUFTRAG_gemeinsam.md, Abschnitt "Marke"):
#   - juenger als 3600 s  -> sie gilt, es wird nicht gestartet, Rueckgabe 0
#   - aelter, mehr als 300 s aus der Zukunft oder ohne Zeitpunkt -> sie gilt
#     NICHT; eine abgebrochene Installation darf den Dienst nicht fuer immer
#     stilllegen. Die 300 s Vorlauf: die Uhr kann ein Stueck zurueckspringen,
#     nachdem preupgrade.sh die Marke gesetzt hat; in WSL sprang sie gemessen
#     bis 0,64 s zurueck, und eine eben geschriebene Marke galt dann kurz
#     nicht (Pruefung-VolkswagenID-0.9.23, dort 300 s). Hier Faelle M1/M2.
#   - OHNE LESBARE UHR FAELLT DIE PRUEFUNG GESCHLOSSEN AUS: liefert "date"
#     nichts, gilt die Marke. Ein Schutz, der bei fehlender Messung durchlaesst,
#     ist keiner (CLAUDE.md, Abschnitt 4).
#   - ZD_START_TROTZ_MARKE=1 ist die Ausnahme fuer das Hakenskript selbst.
marke_gilt() {
    [ -f "$MARKE" ] || return 1
    [ "${ZD_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    MI=$(head -c 32 "$MARKE" 2>/dev/null | tr -d ' \t\n\r')
    case "$MI" in
        ''|*[!0-9]*) return 1 ;;   # kein Zeitpunkt - die Marke gilt nicht
    esac
    MJ=$(date +%s 2>/dev/null)
    case "$MJ" in
        ''|*[!0-9]*) return 0 ;;   # keine lesbare Uhr - geschlossen
    esac
    [ "$MI" -gt $((MJ + 300)) ] && return 1      # weit aus der Zukunft
    [ $((MJ - MI)) -lt 3600 ] && return 0
    return 1
}

starten() {
    if [ "$INSTALLIERT" != "1" ]; then
        nicht_installiert
        return 1
    fi
    if marke_gilt; then
        echo "Es laeuft gerade eine Aktualisierung dieses Plugins ($MARKE) -"
        echo "der Dienst wird nicht gestartet. Das letzte Hakenskript raeumt die"
        echo "Marke weg; der naechste Waechterlauf startet ihn dann."
        return 0
    fi
    # Erst NACH der Markenpruefung: in der Upgrade-Luecke legt ein Start, der
    # nicht startet, auch keinen Ordner an.
    ordner_anlegen
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if ! command -v php >/dev/null 2>&1; then
        echo "FEHLER: PHP nicht gefunden - ohne PHP laeuft der Dienst nicht."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/zendure.json" ]; then
        echo "FEHLER: Konfiguration fehlt ($PCONFIG/zendure.json). Erst die Oberflaeche oeffnen."
        return 1
    fi
    touch "$SOLL"
    # Ausgabe geht in die Logdatei. Das PHP-Skript protokolliert deshalb NICHT
    # zusaetzlich nach stdout - sonst stuende jede Zeile doppelt darin.
    # 9>&- schliesst den Sperr-Dateizeiger fuer den Dienst. Ohne das haelt er
    # die Sperre, solange er laeuft, und jedes spaetere stop/restart wartet
    # vergeblich - siehe den Block am Ende dieser Datei.
    nohup php "$SKRIPT" >> "$LOGDATEI" 2>&1 9>&- &
    NEUPID=$!
    echo "$NEUPID" > "$PID"

    # Bis zu zehn Sekunden warten, nicht genau eine.
    #
    # Bis 0.9.8 stand hier ein festes "sleep 1". Braucht der Start laenger -
    # auf einem beschaeftigten Raspberry Pi keine Seltenheit -, war der
    # Befund negativ, die PID-Datei wurde geloescht UND der eben gestartete
    # Prozess lief weiter. Der Waechter sah eine Minute spaeter "laeuft
    # nicht" und legte den zweiten nach, dann den dritten. Gemessen an
    # echten Prozessen (Pruefstand p7_dienst.sh): nach einem Waechterlauf
    # liefen zwei Dienste auf derselben Warteschlange.
    #
    # Frueh abbrechen, sobald es steht: der Regelfall bleibt schnell.
    I=0
    while [ "$I" -lt 20 ]; do
        if laeuft; then
            echo "gestartet (PID $(cat "$PID"))"
            return 0
        fi
        # Ist der Prozess schon wieder fort, hat er sich selbst beendet -
        # dann bringt weiteres Warten nichts.
        kill -0 "$NEUPID" 2>/dev/null || break
        sleep 0.5
        I=$((I+1))
    done

    # Es steht nicht. Erst aufraeumen, DANN melden - ein herrenloser Prozess
    # ohne PID-Datei ist schlimmer als ein sauberer Fehlschlag.
    if kill -0 "$NEUPID" 2>/dev/null; then
        kill "$NEUPID" 2>/dev/null
        sleep 1
        kill -9 "$NEUPID" 2>/dev/null
    fi
    rm -f "$PID"
    # Und der Sollmerker geht mit. Bliebe er liegen, liefe der Waechter im
    # Minutentakt in denselben Fehlschlag - ausloesbar allein dadurch, dass
    # jemand "Dienst starten" drueckt, bevor ein Geraet eingetragen ist.
    rm -f "$SOLL"
    echo "FEHLER: Start fehlgeschlagen - siehe $LOGDATEI"
    return 1
}

# Herrenlose Horcher einsammeln - argumentweise, nicht mit "pkill -f".
#
# "pkill -f" durchsucht die GANZE Befehlszeile als Teilzeichenkette und
# trifft damit jeden fremden Prozess, der die Zeichenkette irgendwo fuehrt.
# Gemessen 18.09.2026 (Bestand-2026-09-18/klasse-F-nachmessung): das lose
# Muster in Zeile 182 dieser Datei ("mosquitto_sub .*$PNAME") beendete den
# Horcher der ZWEITEN Installation zendure_01 - "angehalten" ->
# "nachher: Koeder 35662 IST TOT". Der Kommentar sieben Zeilen weiter unten
# verspricht seit je das Gegenteil.
#
# Verglichen wird deshalb argv[0] (muss mosquitto_sub sein) gegen ein GANZES
# Argument "loxberry-<ordner>-<nummer>" - genau so vergibt
# zd_horcher_starten() die Kennung (bin/zendure_dienst.php). "loxberry-
# zendure_01-9911" ist damit kein Treffer fuer den Ordner "zendure", weil
# hinter dem Ordnernamen ein Unterstrich steht und kein Bindestrich.
# Zusaetzlich muss der Prozess dem eigenen Benutzer gehoeren.
horcher_einsammeln() {
    ORD=$1
    UID_SOLL=$(id -u)
    for D in /proc/[0-9]*; do
        [ -r "$D/cmdline" ] || continue
        A0=$(tr '\0' '\n' < "$D/cmdline" 2>/dev/null | sed -n '1p')
        [ "${A0##*/}" = "mosquitto_sub" ] || continue
        [ "$(stat -c %u "$D" 2>/dev/null)" = "$UID_SOLL" ] || continue
        tr '\0' '\n' < "$D/cmdline" 2>/dev/null \
            | grep -qxE "loxberry-$ORD-[0-9]+" || continue
        kill "${D#/proc/}" 2>/dev/null
    done
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        # Herrenlose Horcher trotzdem einsammeln
        horcher_einsammeln "$PNAME"
        echo "laeuft nicht"
        return 0
    fi
    # Die Kennung traegt den ORDNERNAMEN, nicht das feste Wort "zendure".
    # Sonst erschlaegt ein Stopp in der Installation "zendure" den Horcher
    # von "zendure_01" gleich mit - dessen Kennung enthaelt die Zeichenkette
    # ebenfalls. Passend dazu vergibt zd_horcher_starten() sie.
    P=$(cat "$PID")
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    horcher_einsammeln "$PNAME"
    echo "angehalten"
    return 0
}

# Ab hier nur einer auf einmal.
#
# Der minuetliche Waechter und ein Klick in der Oberflaeche koennen einander
# ueberholen: beide sehen "laeuft nicht", beide starten. flock schliesst das
# aus. Fehlt flock (kein util-linux), laeuft es ohne Sperre weiter - eine
# fehlende Sperre ist ein Nachteil, ein abgebrochener Dienststart ein Ausfall.
#
# ACHTUNG, HIER STECKT EINE FALLE, und sie ist beim Bau von 0.9.9 zugeschnappt:
# In der Form "flock <datei> <befehl>" oeffnet flock die Sperrdatei und der
# gestartete Befehl ERBT den Dateizeiger. Der Abrufdienst haelt die Sperre
# damit, solange er laeuft - und jedes spaetere "stop" wartet 60 Sekunden
# vergeblich und tut dann gar nichts. Der Pruefstand hat es gefunden
# (p7_dienst.sh, Zeile "stop beendet den Dienst nicht").
#
# Deshalb die ausdrueckliche Form mit einer eigenen Nummer: unten wird sie
# beim Start des Dienstes mit "9>&-" geschlossen.
#
# Die Sperrdatei liegt im Bestandsordner. Den legt seit 0.9.24 nicht mehr
# jeder Aufruf an; wer schreibt (start, restart, waechter), legt ihn vorher
# an. Fehlt er - frische Anlage vor dem ersten Start, oder ein Aufruf aus
# einem Archiv -, gibt es nichts, was sich ueberholen koennte, und "status"
# sagt ohne <WARNING> "gestoppt" (Fall H17).
if [ "$INSTALLIERT" = "1" ]; then
    case "${1:-}" in start|restart|waechter) mkdir -p "$PBESTAND" 2>/dev/null ;; esac
fi
if command -v flock >/dev/null 2>&1 && [ -d "$PBESTAND" ]; then
    exec 9>"$SPERRE" 2>/dev/null || true
    flock -w 60 9 2>/dev/null || \
        echo "<WARNING> Sperre nicht bekommen - es laeuft offenbar schon ein Vorgang."
fi

case "$1" in
    start)   starten ;;
    stop)
        # Aus einem Archiv heraus haelt "stop" nichts an - wie "start",
        # "restart" und der Waechter. Bis 0.9.25 hielt ein "stop" aus einem
        # ausgepackten Archiv mit gesetztem $LBHOMEDIR/$LBPPLUGINDIR den
        # Dienst der Anlage an, loeschte deren soll_laufen und sammelte deren
        # Horcher ein (in WSL gemessen, Pruefung-ZendureSolarFlow-0.9.26,
        # Faelle S1-S5).
        if [ "$INSTALLIERT" != "1" ]; then
            echo "FEHLER: dieses Skript liegt nicht unter $PBIN -"
            echo "FEHLER: aus einem ausgepackten Archiv wird nichts angehalten."
            exit 1
        fi
        anhalten ;;
    restart)
        # Aus einem Archiv heraus haelt "restart" nichts an: sonst stuende
        # der Dienst der Anlage danach, weil starten() dort verweigert
        # (Fall H15).
        if [ "$INSTALLIERT" != "1" ]; then
            nicht_installiert
            exit 1
        fi
        # Die Marke VOR dem Anhalten pruefen: anhalten() entfernt den
        # Sollmerker, und ohne ihn bliebe der Dienst nach dem Upgrade aus -
        # der Waechter startet nur, was laufen SOLL.
        if marke_gilt; then
            echo "Es laeuft gerade eine Aktualisierung dieses Plugins ($MARKE) -"
            echo "der Dienst wird nicht neu gestartet."
            exit 0
        fi
        anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Waehrend einer Aktualisierung still aussteigen - ohne Protokollzeile.
        # Der Takt laeuft minuetlich; eine Meldung je Lauf waere bis zu einer
        # Stunde lang Rauschen im Protokoll, und Rauschen liest niemand.
        if marke_gilt; then
            exit 0
        fi
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten. Nur aus der Installation:
        # sonst schriebe ein Waechter aus einem Archiv seine Zeile in das
        # Protokoll der Anlage (Fall H14). Der Logordner liegt auf der
        # RAM-Platte und kann fehlen - er wird vor der Zeile angelegt
        # (Fall H11).
        if [ "$INSTALLIERT" = "1" ] && [ -f "$SOLL" ] && ! laeuft; then
            ordner_anlegen
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$LOGDATEI" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
