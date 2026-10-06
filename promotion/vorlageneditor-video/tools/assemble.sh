#!/bin/bash
# Setzt die aufgenommenen Szenen (rec/sNN/*.jpg) mit den Tonspuren (audio/NN.wav)
# zu einem Video zusammen. Ist die Tonspur länger als die Aufnahme, steht das
# letzte Bild; ist sie kürzer, wird Stille angehängt. Ohne Tonspur bleibt die
# Szene stumm.
#
#   ./assemble.sh [ausgabe.mp4] [audio-verzeichnis]
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
OUT="${1:-$DIR/vorlageneditor.mp4}"
AUDIO="${2:-$DIR/audio}"
FPS=12
WORK="$DIR/build"
rm -rf "$WORK" && mkdir -p "$WORK"

for sdir in "$DIR"/rec/s[0-9]*; do
    n=$(basename "$sdir" | sed 's/^s//')
    frames=$(ls "$sdir"/f*.jpg | wc -l)
    [ "$frames" -gt 0 ] || continue
    # Tatsächliche Bildrate der Aufnahme (rec/scenes.json), sonst nominell
    sfps=$(python3 -c "
import json,sys
try:
    sc=[x for x in json.load(open('$DIR/rec/scenes.json')) if x['n']==int('$n')]
    print(round(sc[0]['frames']/sc[0]['seconds'],3) if sc else $FPS)
except Exception: print($FPS)")
    vdur=$(python3 -c "print($frames / $sfps)")
    wav="$AUDIO/$n.wav"
    if [ -f "$wav" ]; then
        adur=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$wav")
    else
        adur=0
    fi
    # Zieldauer: die längere von beiden, plus eine kurze Atempause
    tdur=$(python3 -c "print(round(max($vdur, $adur) + 0.6, 3))")
    echo "Szene $n: Video ${vdur}s, Ton ${adur}s -> ${tdur}s"

    if [ -f "$wav" ]; then
        ffmpeg -v error -y -framerate $sfps -pattern_type glob -i "$sdir/f*.jpg" -i "$wav" \
            -filter_complex "[0:v]tpad=stop_mode=clone:stop_duration=60,trim=duration=$tdur,setpts=PTS-STARTPTS,format=yuv420p[v];[1:a]apad,atrim=duration=$tdur,asetpts=PTS-STARTPTS[a]" \
            -map "[v]" -map "[a]" -c:v libx264 -preset medium -crf 20 -r $FPS -c:a aac -b:a 160k -ar 48000 "$WORK/$n.mp4"
    else
        ffmpeg -v error -y -framerate $sfps -pattern_type glob -i "$sdir/f*.jpg" -f lavfi -i anullsrc=r=48000:cl=stereo \
            -filter_complex "[0:v]tpad=stop_mode=clone:stop_duration=60,trim=duration=$tdur,setpts=PTS-STARTPTS,format=yuv420p[v];[1:a]atrim=duration=$tdur[a]" \
            -map "[v]" -map "[a]" -c:v libx264 -preset medium -crf 20 -r $FPS -c:a aac -b:a 160k -ar 48000 "$WORK/$n.mp4"
    fi
    echo "file '$WORK/$n.mp4'" >> "$WORK/list.txt"
done

ffmpeg -v error -y -f concat -safe 0 -i "$WORK/list.txt" -c copy "$OUT"
echo "fertig: $OUT ($(ffprobe -v error -show_entries format=duration -of csv=p=0 "$OUT") s)"
