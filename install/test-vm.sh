#!/usr/bin/env bash
# =============================================================================
#  install/test-vm.sh — Installer in einer wegwerfbaren VM testen (multipass)
# =============================================================================
#  Zieht eine frische Ubuntu-VM hoch, überträgt einen SAUBEREN Stand des Repos
#  (git archive, ohne node_modules/.git) und führt install/install.sh darin aus.
#  Per Snapshot lässt sich vor jedem Testlauf zurücksetzen.
#
#  Warum VM: Der Installer macht apt-Installs, legt systemd-Units an, ändert
#  Apache/PostgreSQL/sudoers/cron — das gehört NICHT auf die Arbeitsmaschine.
#
#  Voraussetzung:  sudo snap install multipass   (oder: brew install multipass)
#
#  Ablauf:
#    ./install/test-vm.sh up            # VM erstellen
#    ./install/test-vm.sh snapshot base # sauberen Zustand sichern
#    ./install/test-vm.sh deploy        # aktuellen Repo-Stand in die VM kopieren
#    ./install/test-vm.sh run           # Installer in der VM ausführen
#    ./install/test-vm.sh run --only apache,sse   # nur Teilschritte
#    ./install/test-vm.sh shell         # in die VM einloggen
#    ./install/test-vm.sh restore base  # auf sauberen Zustand zurücksetzen
#    ./install/test-vm.sh destroy       # VM löschen
#
#  QEMU-Alternative (ohne multipass): siehe Kommentar am Dateiende.
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OSERP_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

VM="${VM:-oserp-test}"
IMAGE="${IMAGE:-24.04}"          # Ubuntu-Release; überschreibbar (z.B. 22.04)
CPUS="${CPUS:-4}"
MEM="${MEM:-8G}"
DISK="${DISK:-40G}"
VM_USER="ubuntu"                 # multipass-Standarduser
VM_DIR="/home/${VM_USER}/opensource-erp"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
info() { echo "${BLUE}[INFO]${NC} $*"; }
ok()   { echo "${GREEN}[OK]${NC}   $*"; }
warn() { echo "${YELLOW}[WARN]${NC} $*"; }
err()  { echo "${RED}[FEHLER]${NC} $*" >&2; }

need_multipass() {
    command -v multipass >/dev/null || {
        err "multipass fehlt. Installieren: sudo snap install multipass"; exit 1; }
}

cmd_up() {
    need_multipass
    if multipass info "$VM" >/dev/null 2>&1; then
        ok "VM '$VM' existiert bereits"
    else
        info "Erstelle VM '$VM' (Ubuntu $IMAGE, ${CPUS} CPU, ${MEM} RAM, ${DISK} Disk)"
        multipass launch "$IMAGE" --name "$VM" --cpus "$CPUS" --memory "$MEM" --disk "$DISK"
        ok "VM läuft"
    fi
}

cmd_deploy() {
    need_multipass
    local tar="/tmp/${VM}-oserp.tar.gz"
    info "Erzeuge sauberen Repo-Stand (git archive, ohne node_modules/.git)"
    git -C "$OSERP_ROOT" archive --format=tar.gz -o "$tar" HEAD
    info "Übertrage nach VM und entpacke nach $VM_DIR"
    multipass exec "$VM" -- bash -c "rm -rf '$VM_DIR' && mkdir -p '$VM_DIR'"
    multipass transfer "$tar" "$VM:/tmp/oserp.tar.gz"
    multipass exec "$VM" -- bash -c "tar -xzf /tmp/oserp.tar.gz -C '$VM_DIR' && rm /tmp/oserp.tar.gz"
    rm -f "$tar"
    ok "Repo-Stand in der VM unter $VM_DIR"
    warn "Hinweis: uncommittete Änderungen sind NICHT enthalten (git archive nutzt HEAD)."
}

cmd_run() {
    need_multipass
    multipass exec "$VM" -- bash -c "test -d '$VM_DIR'" || { err "Erst 'deploy' ausführen"; exit 1; }
    info "Führe Installer in der VM aus (User $VM_USER)"
    # OSERP_USER=ubuntu, damit Rechte/Units auf den VM-User zeigen.
    multipass exec "$VM" -- bash -c \
        "cd '$VM_DIR' && chmod +x install/install.sh && OSERP_USER=$VM_USER ./install/install.sh $*"
}

cmd_snapshot() {
    need_multipass
    local name="${1:-base}"
    info "Snapshot '$name' von '$VM'"
    multipass stop "$VM"
    multipass snapshot "$VM" --name "$name"
    multipass start "$VM"
    ok "Snapshot '$name' erstellt"
}

cmd_restore() {
    need_multipass
    local name="${1:-base}"
    info "Setze '$VM' auf Snapshot '$name' zurück"
    multipass stop "$VM"
    multipass restore "$VM.$name" --destructive
    multipass start "$VM"
    ok "Zurückgesetzt auf '$name'"
}

cmd_shell()   { need_multipass; multipass shell "$VM"; }
cmd_info()    { need_multipass; multipass info "$VM"; }
cmd_ip()      { need_multipass; multipass info "$VM" | awk '/IPv4/{print $2}'; }
cmd_destroy() {
    need_multipass
    info "Lösche VM '$VM'"
    multipass delete --purge "$VM"
    ok "VM entfernt"
}

case "${1:-}" in
    up)       cmd_up ;;
    deploy)   cmd_deploy ;;
    run)      shift; cmd_run "$@" ;;
    snapshot) shift; cmd_snapshot "$@" ;;
    restore)  shift; cmd_restore "$@" ;;
    shell)    cmd_shell ;;
    info)     cmd_info ;;
    ip)       cmd_ip ;;
    destroy)  cmd_destroy ;;
    all)      # Komplett: up -> deploy -> run  (danach 'ip' + Browser)
              cmd_up; cmd_deploy; shift || true; cmd_run "$@"
              echo; ok "Fertig. VM-IP: $(cmd_ip). Test: http://$(cmd_ip)/" ;;
    *)
        cat <<EOF
install/test-vm.sh — Installer in Wegwerf-VM testen

  up                 VM erstellen (Ubuntu $IMAGE)
  snapshot [name]    Snapshot sichern (default: base)
  deploy             sauberen Repo-Stand in die VM kopieren
  run [args]         Installer ausführen (args -> install.sh, z.B. --only apache)
  restore [name]     auf Snapshot zurücksetzen
  shell | info | ip  VM betreten / Infos / IP
  destroy            VM löschen
  all [args]         up + deploy + run in einem

Empfohlener Zyklus:
  ./install/test-vm.sh up
  ./install/test-vm.sh snapshot base
  ./install/test-vm.sh all              # erster Volllauf
  # bei Fehler: fixen, committen, dann:
  ./install/test-vm.sh restore base && ./install/test-vm.sh all

Variablen: VM=$VM IMAGE=$IMAGE CPUS=$CPUS MEM=$MEM DISK=$DISK (per Env überschreibbar)
EOF
        ;;
esac

# -----------------------------------------------------------------------------
#  QEMU-Alternative (falls kein multipass gewünscht)
# -----------------------------------------------------------------------------
#  1) Cloud-Image holen:
#     wget https://cloud-images.ubuntu.com/releases/24.04/release/ubuntu-24.04-server-cloudimg-amd64.img
#  2) Mit cloud-init (user-data: user 'ubuntu', ssh-key) via qemu-system-x86_64 starten,
#     Repo per scp übertragen, install/install.sh ausführen.
#  Snapshots: qemu-img snapshot -c base disk.qcow2  /  -a base zum Zurücksetzen.
#  multipass nimmt einem genau diese Handarbeit ab — daher hier bevorzugt.
# -----------------------------------------------------------------------------
