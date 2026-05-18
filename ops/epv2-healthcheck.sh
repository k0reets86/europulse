#!/bin/bash
# EuroPulse healthcheck — runs every 5 min via systemd timer.
# Checks system metrics + automation status; raises alerts via:
#   - /var/log/epv2-alerts.log (rotated)
#   - syslog (`logger -t epv2-healthcheck`)
#   - WP option `epv2_active_alerts` (visible in admin)
#   - Optional webhook (env EPV2_ALERT_WEBHOOK if set, POST JSON)
#
# Exit code 0 = all clear, 1 = at least one alert raised, 2 = script error.

set -u
LOG=/var/log/epv2-alerts.log
ALERT_LIST=$(mktemp)
trap 'rm -f "$ALERT_LIST"' EXIT
WP_PATH=/var/www/europulse/public

wp_option_get() {
    local option_name="$1"
    if [ ! -d "$WP_PATH" ]; then
        return 0
    fi
    cd "$WP_PATH" 2>/dev/null && sudo -u www-data wp option get "$option_name" --quiet 2>/dev/null | head -1 | tr -d '[:space:]' || true
}

is_truthy() {
    case "${1:-}" in
        1|true|TRUE|yes|YES|on|ON) return 0 ;;
        *) return 1 ;;
    esac
}

automation_paused_raw=$(wp_option_get epv2_automation_paused)
collect_paused_raw=$(wp_option_get epv2_collect_paused)
automation_paused=false
collect_paused=false
if is_truthy "$automation_paused_raw"; then
    automation_paused=true
fi
if is_truthy "$collect_paused_raw"; then
    collect_paused=true
fi

raise_alert() {
    local severity="$1"  # critical|warning
    local code="$2"      # short machine code
    local message="$3"   # human readable
    local now
    now=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    echo "$now [$severity] $code: $message" >> "$LOG"
    logger -t epv2-healthcheck -p user.warning "$severity:$code $message"
    # Для Telegram сохраняем human-friendly text, для технических каналов code
    local emoji
    case "$severity" in
        critical) emoji="🚨" ;;
        warning)  emoji="⚠️" ;;
        *)        emoji="ℹ️" ;;
    esac
    echo "{\"severity\":\"$severity\",\"code\":\"$code\",\"message\":\"$message\",\"emoji\":\"$emoji\",\"at\":\"$now\"}" >> "$ALERT_LIST"
}

# 1. RAM usage > 85%
ram_pct=$(free | awk '/^Mem:/ {printf "%d", ($3/$2)*100}')
if [ "$ram_pct" -gt 85 ]; then
    raise_alert "critical" "ram_high" "Память забита на ${ram_pct}% — критично"
elif [ "$ram_pct" -gt 75 ]; then
    raise_alert "warning" "ram_warm" "Память на ${ram_pct}% — стоит посмотреть что грузит"
fi

# 2. Disk usage > 80%
disk_pct=$(df / | awk 'NR==2 {gsub("%",""); print $5}')
if [ "$disk_pct" -gt 90 ]; then
    raise_alert "critical" "disk_high" "Диск забит на ${disk_pct}% — нужно срочно чистить"
elif [ "$disk_pct" -gt 80 ]; then
    raise_alert "warning" "disk_warm" "Диск занят на ${disk_pct}% — пора подумать о чистке"
fi

# 3. Swap > 50% used
swap_total=$(free | awk '/^Swap:/ {print $2}')
swap_used=$(free | awk '/^Swap:/ {print $3}')
if [ "$swap_total" -gt 0 ]; then
    swap_pct=$(( swap_used * 100 / swap_total ))
    if [ "$swap_pct" -gt 50 ]; then
        raise_alert "warning" "swap_high" "Swap на ${swap_pct}% — система нервничает с памятью"
    fi
fi

# 4. Worker /health. If automation is intentionally paused, worker downtime is
# expected and should not page the operator.
if [ "$automation_paused" != "true" ]; then
    if ! curl -sf --max-time 5 http://127.0.0.1:8765/health > /dev/null 2>&1; then
        raise_alert "critical" "worker_down" "AI-worker не отвечает — статьи не пишутся"
    fi
fi

# 5. Services active
for svc in php8.3-fpm nginx mariadb; do
    if ! systemctl is-active --quiet "$svc"; then
        raise_alert "critical" "service_down" "Сервис $svc упал — что-то сломалось"
    fi
done
if [ "$automation_paused" != "true" ]; then
    for svc in epv2-worker epv2-orchestrator; do
        if ! systemctl is-active --quiet "$svc"; then
            raise_alert "critical" "service_down" "Сервис $svc упал — что-то сломалось"
        fi
    done
fi

# 6. Queue health — last published in last 4 hours? (only between 06-22 UTC, ночью спокойно)
hour=$(date -u +%H)
hour=${hour#0}
if [ "$automation_paused" != "true" ] && [ "${hour:-0}" -ge 5 ] && [ "${hour:-0}" -le 18 ]; then
    last_pub_age_min=$(cd /var/www/europulse/public 2>/dev/null && \
        sudo -u www-data wp db query --skip-column-names \
        "SELECT IFNULL(TIMESTAMPDIFF(MINUTE, MAX(updated_at), UTC_TIMESTAMP()), 999) FROM ep_epv2_queue WHERE state='published'" 2>/dev/null \
        | head -1 | tr -d '[:space:]')
    if [[ -n "$last_pub_age_min" && "$last_pub_age_min" =~ ^[0-9]+$ ]]; then
        if [ "$last_pub_age_min" -gt 360 ]; then
            raise_alert "critical" "queue_stuck" "Уже ${last_pub_age_min} мин нет ни одной публикации — днём так быть не должно"
        elif [ "$last_pub_age_min" -gt 180 ]; then
            raise_alert "warning" "queue_slow" "${last_pub_age_min} мин без новых публикаций — поток подзамерз"
        fi
    fi
fi

# 7. DB connectivity (basic ping)
if ! cd /var/www/europulse/public 2>/dev/null || ! sudo -u www-data wp db check --quiet 2>/dev/null; then
    raise_alert "critical" "db_unreachable" "База данных не отвечает — сайт не работает"
fi

# Push to WP-option for admin visibility (always — even when empty)
if [ -d /var/www/europulse/public ]; then
    alerts_json="[]"
    if [ -s "$ALERT_LIST" ]; then
        alerts_json=$(jq -s . "$ALERT_LIST" 2>/dev/null || cat "$ALERT_LIST" | tr '\n' ',' | sed 's/^/[/; s/,$/]/')
    fi
    snapshot_json=$(cat <<EOF
{"checked_at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)","ram_pct":$ram_pct,"disk_pct":$disk_pct,"automation_paused":$automation_paused,"collect_paused":$collect_paused,"alerts":$alerts_json}
EOF
)
    cd /var/www/europulse/public && \
        sudo -u www-data wp option update epv2_active_alerts "$snapshot_json" --format=json --quiet 2>/dev/null || true
fi

# Push в каналы доставки если настроены.
if [ -s "$ALERT_LIST" ]; then
    # Telegram-сообщение «как от человека» — без технических кодов,
    # только смысл. Префикс emoji зависит от severity (🚨 critical, ⚠️ warning).
    # Один alert = один абзац; несколько — список.
    alert_count=$(wc -l < "$ALERT_LIST")
    if [ "$alert_count" -eq 1 ]; then
        # Один — короткое сообщение
        tg_text=$(jq -r '.emoji + " " + .message' "$ALERT_LIST")
    else
        # Несколько — список с заголовком
        tg_text=$(jq -rs '
            "Что-то пошло не так на EuroPulse — " + (length | tostring) + " проблемы:\n\n" +
            (map(.emoji + " " + .message) | join("\n"))
        ' "$ALERT_LIST")
    fi

    # Telegram
    if [ -n "${TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${TELEGRAM_CHAT_ID:-}" ]; then
        curl -s --max-time 5 \
            -X POST "https://api.telegram.org/bot$TELEGRAM_BOT_TOKEN/sendMessage" \
            --data-urlencode "chat_id=$TELEGRAM_CHAT_ID" \
            --data-urlencode "text=$tg_text" \
            --data-urlencode "disable_web_page_preview=true" \
            > /dev/null 2>&1 || true
    fi

    # Generic webhook (Slack/Discord/Mattermost JSON-style)
    if [ -n "${EPV2_ALERT_WEBHOOK:-}" ]; then
        payload=$(jq -s --arg text "$tg_text" '{text: $text}' "$ALERT_LIST" 2>/dev/null || \
                  echo "{\"text\":\"EuroPulse: проверь систему\"}")
        curl -s --max-time 5 -X POST -H 'Content-Type: application/json' -d "$payload" "$EPV2_ALERT_WEBHOOK" > /dev/null 2>&1 || true
    fi
fi

# Exit 1 if any alerts raised
if [ -s "$ALERT_LIST" ]; then
    exit 1
fi
exit 0
