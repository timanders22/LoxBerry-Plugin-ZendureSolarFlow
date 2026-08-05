#!/bin/bash
# Zendure SolarFlow - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Das Plugin ist reines PHP - es braucht KEINE virtuelle Python-Umgebung und
# damit auch keinen Umweg um PEP 668 herum. Gebraucht werden nur die
# mosquitto-Kommandozeilenwerkzeuge, und zwar ausschliesslich fuer die alten
# Geraete (Hub, Hyper, Ace, AIO), die nur MQTT sprechen. Fuer die neueren
# Geraete mit lokaler HTTP-Schnittstelle sind sie entbehrlich.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-zendure}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Ableitung aus dem eigenen Ablageort - LoxBerry::System taugt hier nicht,
    # weil es den Pluginordner aus dem Aufrufort ableitet und aus
    # postinstall.sh heraus ueberall Leerstring liefert.
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PDATA/verlauf" "$PDATA/mosq" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
# In diesem Ordner liegen die Broker-Zugangsdaten fuer mosquitto_sub/pub.
chmod 700 "$PDATA/mosq" 2>/dev/null

[ -f "$PCONFIG/zendure.json" ] || echo '{}' > "$PCONFIG/zendure.json"

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation)
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/zendure.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

# ---------- PHP pruefen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. LoxBerry bringt PHP normalerweise mit -"
    echo "<FAIL> ohne PHP laeuft weder die Oberflaeche noch der Dienst."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# ---------- mosquitto-Werkzeuge ----------
# Nur fuer die alten Geraete noetig. Fehlen sie, wird das gemeldet und die
# Installation laeuft weiter - ein Plugin, das wegen eines nur teilweise
# gebrauchten Werkzeugs abbricht, waere unverhaeltnismaessig.
if command -v mosquitto_sub >/dev/null 2>&1 && command -v mosquitto_pub >/dev/null 2>&1; then
    echo "<OK> mosquitto-Werkzeuge vorhanden: $(mosquitto_sub --help 2>&1 | head -1)"
else
    echo "<INFO> mosquitto_sub/mosquitto_pub fehlen - versuche sie nachzuinstallieren ..."
    if apt-get install -y mosquitto-clients >/dev/null 2>&1 \
       && command -v mosquitto_sub >/dev/null 2>&1; then
        echo "<OK> mosquitto-clients installiert."
    else
        echo "<INFO> mosquitto-clients liessen sich nicht installieren."
        echo "<INFO> Das ist NUR fuer die aelteren Geraete ein Problem (Hub 1200, Hub 2000,"
        echo "<INFO> Hyper 2000, Ace 1500, AIO 2400) - sie sprechen ausschliesslich MQTT."
        echo "<INFO> Geraete mit lokaler HTTP-Schnittstelle (SolarFlow 800 und neuer)"
        echo "<INFO> laufen auch ohne. Nachholen mit: sudo apt install mosquitto-clients"
    fi
fi

chmod 755 "$PBIN/dienst.sh" 2>/dev/null
chmod 755 "$PBIN/zendure_dienst.php" 2>/dev/null
chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null
chmod 700 "$PDATA/mosq" 2>/dev/null

echo "<OK> Installation abgeschlossen."
echo "<INFO> Bitte die Plugin-Oberflaeche oeffnen, die Geraete eintragen und den"
echo "<INFO> Dienst im Reiter Einstellungen starten."
exit 0
