#!/bin/bash

# Defaults
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
LOGFILE="$SCRIPT_DIR/../php_errorlog"
SENDER="info@mauihikes.org"
RECIPIENT="robw@worldspot.com"
ALL_MODE=0

# Parse named args
while [[ $# -gt 0 ]]; do
    case "$1" in
        --logfile=*)
            LOGFILE="${1#*=}"
            shift
            ;;
        --logfile)
            LOGFILE="$2"
            shift 2
            ;;
        --sender=*)
            SENDER="${1#*=}"
            shift
            ;;
        --sender)
            SENDER="$2"
            shift 2
            ;;
        --recipient=*)
            RECIPIENT="${1#*=}"
            shift
            ;;
        --recipient)
            RECIPIENT="$2"
            shift 2
            ;;
        --all)
            ALL_MODE=1
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [--logfile PATH] [--sender EMAIL] [--recipient EMAIL]"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

echo "Reading $LOGFILE"

HOSTNAME=$(hostname)

# Time window: last hour
END_TS=$(date -u +%s)
START_TS=$((END_TS - 3600))

TMPFILE=$(mktemp)

awk -v start="$START_TS" -v end="$END_TS" -v all="$ALL_MODE" '
BEGIN {
    ts_re = "^\\[..-...-.... ..:..:.. UTC\\]"
    err_re = "(PHP (Fatal|Parse|Warning|Notice) error:|[A-Z][a-zA-Z]*Exception|Uncaught |Unhandled exception)"
    in_block = 0
    block_ok = 0

    # Month name to number lookup
    split("Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec", m_arr)
    for (i = 1; i <= 12; i++) month_num[m_arr[i]] = i
}

# Timestamped line
$0 ~ ts_re {
    # If we were capturing a previous block and it was valid, print a separator
    if (in_block && block_ok) print ""

    # Reset block state
    in_block = 0
    block_ok = 0

    # Extract timestamp components: [09-Feb-2026 14:30:45 UTC]
    if (match($0, /\[([0-9]{2})-([A-Za-z]{3})-([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) +UTC\]/)) {
        # Parse out the components using substr on the matched portion
        ts_part = substr($0, RSTART + 1, RLENGTH - 2)  # Remove [ and ]
        split(ts_part, parts, /[- :]/)
        # parts: 1=day, 2=mon, 3=year, 4=hour, 5=min, 6=sec, 7=UTC

        day = parts[1] + 0
        mon = month_num[parts[2]]
        year = parts[3] + 0
        hour = parts[4] + 0
        min = parts[5] + 0
        sec = parts[6] + 0

        # mktime expects "YYYY MM DD HH MM SS" and returns epoch (assumes local TZ)
        # Since log is UTC and we compare against UTC epoch, use mktime with UTC
        ts = mktime(year " " mon " " day " " hour " " min " " sec, 1)
    } else {
        ts = 0
    }

    # Determine if block is inside time window
    inside_window = (all == 1 || (ts >= start && ts <= end))

    # Start block only if inside window AND header is an error
    if (inside_window && $0 ~ err_re) {
        in_block = 1
        block_ok = 1
        print $0
    }

    next
}

# Non‑timestamped line
{
    if (in_block && block_ok) print
}
' "$LOGFILE" > "$TMPFILE"


if [[ -s "${TMPFILE}" ]]; then
    echo "Mailing ${TMPFILE}"
    mail -s "PHP Errors (last hour) on $HOSTNAME in $LOGFILE" --from-address $SENDER "$RECIPIENT" < "${TMPFILE}"
    #rm -f $TMPFILE
fi
