# EuroPulse AutoPilot v21 — Инструкция по установке

## Что входит в актуальный runtime v21

- ✅ Google News RSS — исправлен (больше нет 503)
- ✅ Python Worker — постоянный systemd-сервис на порту 8765
- ✅ Семантический анализ — определение языка, ключевые фразы, качество текста
- ✅ Расписание — 06-11 каждый час, 11-18 каждые 30 мин, 18-00 каждый час, 00-06 каждые 2 часа
- ✅ Per-block регенерация — кнопки «AI ↺» в интерфейсе проверки (Заголовок / Лид / Текст / Медиа / SEO)
- ✅ Медиа-pipeline — автоматический поиск через Pexels и Wikimedia Commons
- ✅ Еженедельная аналитика — каждое воскресенье в 08:00 (берлинское время)

---

## Шаг 1 — Резервная копия (ОБЯЗАТЕЛЬНО)

```bash
# Backup текущего live v21 plugin
cp -r /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/ \
      /root/backup-epv21-$(date +%Y%m%d)/

# Backup базы данных
mysqldump -u root YOUR_DB_NAME > /root/backup-db-$(date +%Y%m%d).sql
```

## Шаг 2 — Деактивация старого плагина

1. Войдите в WordPress Admin → Плагины
2. Убедитесь, что активным останется только **EuroPulse AutoPilot v21**
3. Любые старые `v2`/`v3` сборки рассматриваются только как архив и не используются в runtime

## Шаг 3 — Загрузка нового плагина

**Вариант A — через WordPress Admin (рекомендуется):**
1. Скачайте `europulse-autopilot-v21.zip`
2. WordPress Admin → Плагины → Добавить → Загрузить плагин
3. Выберите `europulse-autopilot-v21.zip` → Установить
4. Активируйте плагин

**Вариант B — через SSH:**
```bash
cd /var/www/europulse/public/wp-content/plugins/
unzip /root/europulse-autopilot-v21.zip
```

## Шаг 4 — Установка Python Worker

```bash
# Скопируйте папку worker-v21 на сервер
scp -r worker-v21/ root@YOUR_SERVER_IP:/opt/epv2-worker/

# На сервере:
cd /opt/epv2-worker
sudo bash worker-setup.sh
```

Скрипт автоматически:
- Создаёт virtual environment в `/opt/epv2-worker/.venv`
- Устанавливает зависимости (FastAPI, OpenAI, langdetect и др.)
- Создаёт systemd сервис `epv2-worker`
- Запускает сервис

### Проверка Worker

```bash
# Статус сервиса
systemctl status epv2-worker

# Логи
journalctl -u epv2-worker -f

# Проверка health
curl -s http://127.0.0.1:8765/health
# Ожидаемый ответ: {"status":"ok","version":"2.1"}
```

## Шаг 5 — Активация и настройка плагина

1. WordPress Admin → Активируйте **EuroPulse AutoPilot v21**
2. Перейдите в **EuroPulse → Настройки**
3. Убедитесь что API-ключи заполнены:
   - OpenAI API Key
   - DeepSeek API Key (fallback)
   - Pexels API Key
4. Нажмите **Сохранить**

## Шаг 6 — Проверка

```bash
# Проверить что worker работает
curl -s http://127.0.0.1:8765/health

# Проверить systemd
systemctl is-active epv2-worker
```

В WordPress Admin → EuroPulse → Дашборд должна появиться надпись:
> ✓ Python Worker активен (v21)

---

## Управление сервисом

```bash
sudo systemctl start   epv2-worker   # запустить
sudo systemctl stop    epv2-worker   # остановить
sudo systemctl restart epv2-worker   # перезапустить
sudo systemctl status  epv2-worker   # статус
journalctl -u epv2-worker -n 100     # последние 100 строк логов
```

## Откат на v21 backup

Если что-то пошло не так:

```bash
# Деактивируйте v21 через WordPress Admin
# Затем восстановите backup
cp -r /root/backup-epv21-YYYYMMDD/ /var/www/europulse/public/wp-content/plugins/europulse-autopilot-v21/
# Активируйте v21 backup в WordPress Admin
```

---

## Поддержка

Если worker не запускается — проверьте:
```bash
journalctl -u epv2-worker -n 50
```
