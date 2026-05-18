#!/bin/bash
# Pipeline health snapshot для monitoring loop.
# Запуск: ./snapshot.sh
# Output: TSV в /root/projects/europulse/monitoring/snap-YYYYMMDD-HHMM.tsv
set -e
TS=$(date -u +%Y%m%d-%H%M)
OUT="/root/projects/europulse/monitoring/snap-${TS}.tsv"
cd /var/www/europulse/public

sudo -u www-data wp db query "
SELECT 'state_new' k, COUNT(*) v FROM ep_epv2_queue WHERE state='new'
UNION ALL SELECT 'state_processing', COUNT(*) FROM ep_epv2_queue WHERE state IN ('processing_de','retry_process')
UNION ALL SELECT 'state_ready_publish', COUNT(*) FROM ep_epv2_queue WHERE state IN ('ready_publish','retry_publish','publishing')
UNION ALL SELECT 'state_manual_review', COUNT(*) FROM ep_epv2_queue WHERE state IN ('manual_review','ready_review')
UNION ALL SELECT 'state_rejected', COUNT(*) FROM ep_epv2_queue WHERE state='rejected'
UNION ALL SELECT 'state_published', COUNT(*) FROM ep_epv2_queue WHERE state='published'
UNION ALL SELECT 'state_error', COUNT(*) FROM ep_epv2_queue WHERE state IN ('error','duplicate')
UNION ALL SELECT 'new_age_lt_1h', COUNT(*) FROM ep_epv2_queue WHERE state='new' AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) < 60
UNION ALL SELECT 'new_age_1_6h', COUNT(*) FROM ep_epv2_queue WHERE state='new' AND TIMESTAMPDIFF(HOUR, created_at, NOW()) BETWEEN 1 AND 6
UNION ALL SELECT 'new_age_6h_plus', COUNT(*) FROM ep_epv2_queue WHERE state='new' AND TIMESTAMPDIFF(HOUR, created_at, NOW()) >= 6
UNION ALL SELECT 'high_attempts_active', COUNT(*) FROM ep_epv2_queue
  WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(admin_notes,'\$._system.workflow_step_attempts')) AS UNSIGNED) >= 5
    AND state IN ('new','retry_process','processing_de')
UNION ALL SELECT 'published_last_24h', COUNT(*) FROM ep_posts
  WHERE post_type='post' AND post_status='publish'
    AND post_date_gmt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
UNION ALL SELECT 'published_last_30m', COUNT(*) FROM ep_posts
  WHERE post_type='post' AND post_status='publish'
    AND post_date_gmt >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
UNION ALL SELECT 'active_id', COALESCE(option_value, '0') FROM ep_options WHERE option_name='epv2_active_automation_item'
UNION ALL SELECT 'orchestrator_alive_secs',
  TIMESTAMPDIFF(SECOND,
    (SELECT MAX(created_at) FROM ep_epv2_log WHERE module='orchestrator' OR JSON_EXTRACT(context,'\$.step') IS NOT NULL),
    NOW())
" 2>/dev/null | tee "$OUT" >/dev/null

# Service status
echo -e "service_worker\t$(systemctl is-active epv2-worker)" >> "$OUT"
echo -e "service_orchestrator\t$(systemctl is-active epv2-orchestrator)" >> "$OUT"

# Worker health
HEALTH=$(curl -sS --max-time 3 http://127.0.0.1:8765/health 2>/dev/null | head -c 50 || echo "FAIL")
echo -e "worker_health\t$HEALTH" >> "$OUT"

echo "$OUT"
