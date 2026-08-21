#!/usr/bin/env bash

set -uo pipefail

usage() {
    cat <<'EOF'
Usage: sudo ./manual-agi-call-test.sh CAMPAIGN DESTINATION [PREFIX]

Places one real test call through the selected dialplan prefix and confirms that
did_optimizer.agi ran. PREFIX defaults to 7686.

Example:
  sudo ./manual-agi-call-test.sh OUTBOUND 2057035347
EOF
}

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

[[ $# -ge 2 && $# -le 3 ]] || { usage >&2; exit 2; }

campaign=$1
destination=$2
prefix=${3:-7686}
asterisk_log=/var/log/asterisk/messages
spool_root=/var/spool/asterisk
outgoing_dir=$spool_root/outgoing
call_file=''

[[ $campaign =~ ^[A-Za-z0-9_-]+$ ]] \
    || fail 'campaign may contain only letters, digits, underscores, and hyphens'
[[ $destination =~ ^[0-9]{7,15}$ ]] \
    || fail 'destination must contain 7 to 15 digits'
[[ $prefix =~ ^[0-9]+$ ]] || fail 'prefix must contain digits only'
[[ $EUID -eq 0 ]] || fail 'run this script as root (sudo)'

for command_name in asterisk awk grep mktemp mv chown chmod sleep tail; do
    command -v "$command_name" >/dev/null 2>&1 \
        || fail "required command missing: $command_name"
done

[[ -f $asterisk_log ]] || fail "Asterisk log not found: $asterisk_log"
[[ -d $outgoing_dir ]] || fail "Asterisk outgoing spool not found: $outgoing_dir"

dialed_number="${prefix}${destination}"
if ! asterisk -rx "dialplan show _${prefix}X.@default" 2>/dev/null \
    | grep -Fq 'did_optimizer.agi'; then
    fail "no active DID optimizer dialplan route found for prefix $prefix"
fi

cleanup() {
    if [[ -n $call_file && -f $call_file ]]; then
        rm -f -- "$call_file"
    fi
}
trap cleanup EXIT INT TERM

start_line=$(awk 'END { print NR + 1 }' "$asterisk_log")
call_file=$(mktemp "$spool_root/.didopt-manual-test.XXXXXX.call") \
    || fail 'could not create the temporary Asterisk call file'

printf '%s\n' \
    "Channel: Local/${dialed_number}@default/n" \
    "Callerid: DID Optimizer Test <${destination}>" \
    'MaxRetries: 0' \
    'RetryTime: 60' \
    'WaitTime: 30' \
    "Setvar: CAMPCUST=${campaign}" \
    'Setvar: lead_id=0' \
    'Application: Wait' \
    'Data: 30' > "$call_file"

chown asterisk:asterisk "$call_file"
chmod 0640 "$call_file"

printf 'Submitting real test call: campaign=%s destination=%s prefix=%s\n' \
    "$campaign" "$destination" "$prefix"
mv -- "$call_file" "$outgoing_dir/didopt-manual-test-$$.call"
call_file=''

matched=''
for _attempt in $(seq 1 15); do
    matched=$(tail -n "+$start_line" "$asterisk_log" \
        | grep -F "DID Optimizer: campaign=${campaign}" \
        | grep -F "${dialed_number}@default" \
        | tail -n 1 || true)
    [[ -n $matched ]] && break
    sleep 1
done

if [[ -z $matched ]]; then
    printf '%s\n' "Recent log entries for $dialed_number:"
    tail -n "+$start_line" "$asterisk_log" | grep -F "$dialed_number" || true
    fail 'did_optimizer.agi result was not observed within 15 seconds'
fi

printf 'PASS: AGI ran successfully\n'
printf '%s\n' "$matched" | sed -n 's/.*DID Optimizer: /Result: /p'

# Give the trunk a moment to report an immediate rejection or connection state.
sleep 2
printf '%s\n' 'Call routing log:'
tail -n "+$start_line" "$asterisk_log" \
    | grep -E "${dialed_number}|SIP response|circuit-busy|Everyone is busy|answered" \
    | tail -n 12 || true
