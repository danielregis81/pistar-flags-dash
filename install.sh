#!/bin/bash
#
# install.sh - Instalador do pistar-flags-dash para Pi-Star OFICIAL.
#
# Adiciona bandeira do pais + nome do operador na coluna Callsign do
# Last-Heard do dashboard, de forma leve (roda ate em Pi Zero W) e que
# se reaplica sozinha apos um pistar-update.
#
# USO (no proprio hotspot, como root):
#   sudo ./install.sh              # instala
#   sudo ./install.sh --uninstall  # remove e restaura o dashboard original
#   sudo ./install.sh --status     # mostra o estado atual
#
# Funciona em dois modos de origem de assets:
#   - LOCAL:   se existir ./src ao lado do script (ex: git clone) usa de la.
#   - REMOTO:  senao, baixa os assets do repositorio RAW_BASE.
#
# GPL-3.0. Creditos no README.

set -u

# ----------------------------------------------------------------------
# Config
# ----------------------------------------------------------------------
# Base RAW do repositorio (ajustada na publicacao). Usada no modo remoto.
RAW_BASE="${PFD_RAW_BASE:-https://raw.githubusercontent.com/danielregis81/pistar-flags-dash/main}"

# Pasta propria do projeto. Fica FORA dos repositorios git do Pi-Star
# (/var/www/dashboard, /usr/local/bin e /usr/local/sbin sao repos git que o
# pistar-update sincroniza; nao colocamos nada nosso dentro deles alem do patch).
SHARE="/usr/local/share/lh-flags-patch"
REAPPLY="$SHARE/pistar-lh-reapply"
# Local antigo do script (versoes anteriores). Removido no install e uninstall.
LEGACY_REAPPLY="/usr/local/sbin/pistar-lh-reapply"
CRON="/etc/cron.hourly/zz-pistar-lh-reapply"
LOGF="/var/log/pistar-lh-reapply.log"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say()  { echo -e "[pfd] $*"; }
err()  { echo -e "[pfd] ERRO: $*" >&2; }
die()  { err "$*"; exit 1; }

# ----------------------------------------------------------------------
# Root
# ----------------------------------------------------------------------
if [ "$(id -u)" -ne 0 ]; then
    die "rode como root (use: sudo $0 $*)"
fi

# ----------------------------------------------------------------------
# Deteccao do dashboard
# ----------------------------------------------------------------------
detect_dashboard() {
    for d in /var/www/dashboard /var/www/html/dashboard; do
        if [ -f "$d/mmdvmhost/lh.php" ]; then echo "$d"; return 0; fi
    done
    return 1
}

# ----------------------------------------------------------------------
# Deteccao de fork (WPSD / F1RMB) -> abortar
# ----------------------------------------------------------------------
is_fork() {
    local d="$1"
    # WPSD deixa marcas claras no release e nos arquivos
    if [ -f /etc/pistar-release ] && grep -qiE "WPSD|W0CHP" /etc/pistar-release 2>/dev/null; then return 0; fi
    if [ -d "$d/live" ] || [ -f "$d/mmdvmhost/bm_manager.php" ] && grep -qi "W0CHP" "$d/index.php" 2>/dev/null; then return 0; fi
    return 1
}

# ----------------------------------------------------------------------
# Remount rw/ro com fallback (rpi-rw/rpi-ro podem nao existir)
# ----------------------------------------------------------------------
REMOUNTED_RW=0
fs_rw() {
    if mount | grep -qE " / .*[(,]ro[,)]"; then
        if command -v rpi-rw >/dev/null 2>&1; then rpi-rw && REMOUNTED_RW=1 && return 0; fi
        mount -o remount,rw / && REMOUNTED_RW=1 && return 0
        return 1
    fi
    return 0  # ja estava rw
}
fs_ro() {
    [ "$REMOUNTED_RW" -eq 1 ] || return 0
    sync
    if command -v rpi-ro >/dev/null 2>&1 && rpi-ro; then REMOUNTED_RW=0; return 0; fi
    if mount -o remount,ro /; then REMOUNTED_RW=0; return 0; fi
    err "nao consegui voltar o disco para somente-leitura. Rode: sudo mount -o remount,ro /"
    return 1
}

# Garante que o disco volta para somente-leitura e que o staging e apagado
# em QUALQUER saida: sucesso, erro, die ou interrupcao (Ctrl+C, SSH caiu).
STAGE=""
cleanup() {
    fs_ro
    if [ -n "$STAGE" ]; then rm -rf "$STAGE"; fi
}
trap cleanup EXIT
trap 'err "interrompido"; exit 130' INT TERM HUP

# ----------------------------------------------------------------------
# Obter assets (local ou remoto) para um diretorio de staging
# ----------------------------------------------------------------------
stage_assets() {
    local stage="$1"
    mkdir -p "$stage/flags"
    if [ -f "$SCRIPT_DIR/src/lh_enrich.php" ] && [ -d "$SCRIPT_DIR/src/flags" ]; then
        say "usando assets locais ($SCRIPT_DIR/src)"
        cp "$SCRIPT_DIR/src/lh_enrich.php" "$stage/lh_enrich.php"
        cp "$SCRIPT_DIR/lib/patch-lh.sh"   "$stage/patch-lh.sh"
        cp "$SCRIPT_DIR/pistar-lh-reapply" "$stage/pistar-lh-reapply"
        cp "$SCRIPT_DIR/cron/zz-pistar-lh-reapply" "$stage/zz-pistar-lh-reapply"
        cp "$SCRIPT_DIR"/src/flags/*.svg "$stage/flags/" 2>/dev/null
    else
        say "assets locais ausentes; baixando de $RAW_BASE"
        command -v curl >/dev/null 2>&1 || die "curl necessario no modo remoto"
        curl -fsSL "$RAW_BASE/src/lh_enrich.php" -o "$stage/lh_enrich.php" || die "download lh_enrich.php"
        curl -fsSL "$RAW_BASE/lib/patch-lh.sh"   -o "$stage/patch-lh.sh"   || die "download patch-lh.sh"
        curl -fsSL "$RAW_BASE/pistar-lh-reapply" -o "$stage/pistar-lh-reapply" || die "download reapply"
        curl -fsSL "$RAW_BASE/cron/zz-pistar-lh-reapply" -o "$stage/zz-pistar-lh-reapply" || die "download cron"
        # lista de flags
        curl -fsSL "$RAW_BASE/src/flags/INDEX" -o "$stage/FLAGS_INDEX" \
            || die "download INDEX de flags (gere src/flags/INDEX no repo)"
        local n=0
        while read -r f; do
            [ -z "$f" ] && continue
            curl -fsSL "$RAW_BASE/src/flags/$f" -o "$stage/flags/$f" && n=$((n+1))
        done < "$stage/FLAGS_INDEX"
        say "baixadas $n bandeiras"
    fi
    # normalizar CRLF nos scripts (caso tenham vindo de checkout Windows)
    sed -i 's/\r$//' "$stage/patch-lh.sh" "$stage/pistar-lh-reapply" "$stage/zz-pistar-lh-reapply" 2>/dev/null || true
}

# ----------------------------------------------------------------------
# STATUS
# ----------------------------------------------------------------------
do_status() {
    local d; d="$(detect_dashboard)" || die "dashboard do Pi-Star nao encontrado"
    say "dashboard: $d"
    if grep -q "lh_flag_img" "$d/mmdvmhost/lh.php" 2>/dev/null; then
        say "lh.php: PATCHEADO"
    else
        say "lh.php: original (sem patch)"
    fi
    [ -f "$d/mmdvmhost/lh_enrich.php" ] && say "lh_enrich.php: presente" || say "lh_enrich.php: ausente"
    [ -d "$d/images/flags" ] && say "flags: $(ls "$d"/images/flags/*.svg 2>/dev/null | wc -l) arquivos" || say "flags: ausentes"
    [ -x "$REAPPLY" ] && say "reapply: instalado ($REAPPLY)" || say "reapply: ausente"
    [ -f "$CRON" ] && say "cron.hourly: instalado" || say "cron.hourly: ausente"
    [ -f "$LEGACY_REAPPLY" ] && say "AVISO: versao antiga em $LEGACY_REAPPLY (rode o install de novo para remover)"
    return 0
}

# ----------------------------------------------------------------------
# INSTALL
# ----------------------------------------------------------------------
do_install() {
    local d; d="$(detect_dashboard)" || die "dashboard do Pi-Star nao encontrado (/var/www/dashboard)."
    say "dashboard detectado: $d"

    if is_fork "$d"; then
        die "Detectado um FORK do dashboard (ex: WPSD/W0CHP). Este patch e so para o Pi-Star OFICIAL. Abortando."
    fi

    STAGE="$(mktemp -d)"
    local stage="$STAGE"
    stage_assets "$stage"
    chmod +x "$stage/patch-lh.sh" "$stage/pistar-lh-reapply" "$stage/zz-pistar-lh-reapply"

    # contagem de flags obtidas
    local nflags; nflags="$(ls "$stage"/flags/*.svg 2>/dev/null | wc -l)"
    [ "$nflags" -ge 1 ] || die "nenhuma bandeira obtida; abortando."
    say "bandeiras prontas: $nflags"

    fs_rw || die "nao consegui montar / como rw"

    # UPGRADE: se o lh.php ja estiver patcheado (instalacao anterior) e houver
    # backup, restaura o original ANTES de reaplicar - assim o patch novo entra
    # sobre um lh.php limpo (idempotencia nao impede atualizacao de versao).
    if grep -q "lh_flag_img" "$d/mmdvmhost/lh.php" 2>/dev/null \
       && [ -f "$d/mmdvmhost/lh.php.bak-flags" ]; then
        say "instalacao anterior detectada; restaurando lh.php base para reaplicar"
        cp "$d/mmdvmhost/lh.php.bak-flags" "$d/mmdvmhost/lh.php"
    fi

    # 1) assets de origem (fora do git do dashboard)
    mkdir -p "$SHARE/flags"
    cp "$stage/lh_enrich.php" "$SHARE/lh_enrich.php"
    cp "$stage/patch-lh.sh"   "$SHARE/patch-lh.sh"
    cp "$stage"/flags/*.svg   "$SHARE/flags/"
    chmod 0755 "$SHARE/patch-lh.sh"

    # 2) backup do lh.php original (so na primeira vez)
    if [ ! -f "$d/mmdvmhost/lh.php.bak-flags" ]; then
        cp -n "$d/mmdvmhost/lh.php" "$d/mmdvmhost/lh.php.bak-flags"
        say "backup criado: lh.php.bak-flags"
    fi

    # 3) lh_enrich.php no dashboard
    install -o root -g root -m 0755 "$stage/lh_enrich.php" "$d/mmdvmhost/lh_enrich.php"

    # 4) flags no dashboard
    mkdir -p "$d/images/flags"
    cp "$stage"/flags/*.svg "$d/images/flags/"
    chown -R www-data:www-data "$d/images/flags"
    chmod 0755 "$d/images/flags"
    find "$d/images/flags" -name '*.svg' -exec chmod 0644 {} +

    # 5) patch dinamico do lh.php (idempotente)
    local rc=0
    "$stage/patch-lh.sh" "$d/mmdvmhost/lh.php" || rc=$?
    if [ "$rc" -ne 0 ]; then
        err "patch do lh.php falhou (rc=$rc). Revertendo lh.php do backup."
        cp "$d/mmdvmhost/lh.php.bak-flags" "$d/mmdvmhost/lh.php"
        exit 1   # o trap volta o disco para somente-leitura
    fi

    # 6) reapply (na pasta propria do projeto) + cron
    install -o root -g root -m 0755 "$stage/pistar-lh-reapply" "$REAPPLY"
    install -o root -g root -m 0755 "$stage/zz-pistar-lh-reapply" "$CRON"

    # remove o script do local antigo, que ficava dentro do repo git do Pi-Star
    if [ -f "$LEGACY_REAPPLY" ]; then
        rm -f "$LEGACY_REAPPLY"
        say "removido script do local antigo: $LEGACY_REAPPLY"
    fi

    fs_ro || true

    say "INSTALADO. Abra o dashboard e faca Ctrl+F5."
    say "Desinstalar: sudo $0 --uninstall"
}

# ----------------------------------------------------------------------
# UNINSTALL
# ----------------------------------------------------------------------
do_uninstall() {
    local d; d="$(detect_dashboard)" || die "dashboard do Pi-Star nao encontrado."
    say "dashboard: $d"

    fs_rw || die "nao consegui montar / como rw"

    if [ -f "$d/mmdvmhost/lh.php.bak-flags" ]; then
        cp "$d/mmdvmhost/lh.php.bak-flags" "$d/mmdvmhost/lh.php"
        rm -f "$d/mmdvmhost/lh.php.bak-flags"
        say "lh.php restaurado do backup"
    else
        say "AVISO: backup nao encontrado; lh.php nao restaurado"
    fi
    rm -f "$d/mmdvmhost/lh_enrich.php"
    rm -rf "$d/images/flags"
    rm -f "$CRON" "$LEGACY_REAPPLY"
    rm -rf "$SHARE"
    rm -f "$LOGF"

    fs_ro || true
    say "DESINSTALADO. Faca Ctrl+F5 no dashboard."
}

# ----------------------------------------------------------------------
# main
# ----------------------------------------------------------------------
case "${1:-}" in
    --uninstall|-u) do_uninstall ;;
    --status|-s)    do_status ;;
    ""|--install|-i) do_install ;;
    -h|--help)
        echo "Uso: sudo $0 [--install|--uninstall|--status]"
        ;;
    *) die "opcao desconhecida: $1 (use --help)" ;;
esac
