#!/bin/bash
# Petites fonctions communes aux tests d'installation : chaque vérification affiche PASS/FAIL,
# les échecs sont comptés et le script se termine en erreur s'il y en a eu au moins un.
NB_PASS=0
NB_FAIL=0
ECHECS=()
CONTEXTE=""

titre() { printf '\n=== %s ===\n' "$*"; }
pass() { NB_PASS=$((NB_PASS + 1)); printf '  PASS %s\n' "$*"; }
fail() { NB_FAIL=$((NB_FAIL + 1)); ECHECS+=("${CONTEXTE:+[$CONTEXTE] }$*"); printf '  FAIL %s\n' "$*"; }
# verifier "description" commande args... : PASS si la commande réussit
verifier() {
    local description="$1"; shift
    if "$@" >/dev/null 2>&1; then pass "$description"; else fail "$description"; fi
}
# verifier_echec "description" commande args... : PASS si la commande échoue
verifier_echec() {
    local description="$1"; shift
    if "$@" >/dev/null 2>&1; then fail "$description"; else pass "$description"; fi
}
# Réessaie une commande jusqu'à ce qu'elle réussisse (secondes max).
attendre() {
    local max="$1"; shift
    local debut=$SECONDS
    until "$@" >/dev/null 2>&1; do
        [ $((SECONDS - debut)) -ge "$max" ] && return 1
        sleep 3
    done
}
bilan() {
    printf '\n%d passed, %d failed\n' "$NB_PASS" "$NB_FAIL"
    if [ "$NB_FAIL" -gt 0 ]; then
        printf 'Échecs :\n'
        printf '  - %s\n' "${ECHECS[@]}"
        return 1
    fi
}
