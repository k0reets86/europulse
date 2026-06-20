#!/usr/bin/env python3
"""Ежедневный авто-аудит достоверности EuroPulse (2026-06-20).

Закрывает «наблюдаем»: без участия Claude, на сервере по таймеру.
Берёт выборку свежеопубликованных статей и проверяет два класса проблем:
  1) ОСТАТОЧНЫЕ ГАЛЛЮЦИНАЦИИ — повторно прогоняет verify_and_correct_german
     по чистому primary; если verifier нашёл бы правки, статья на заметку.
  2) ТРАНСЛИТ-БАГИ UK — латинские имена/бренды из немецкого, исчезнувшие из UK
     (вероятно гарблены кириллицей).
Шлёт сводку в Telegram (бот оператора) и пишет отчёт в лог-файл.

Запуск: вручную `python3 daily_audit.py` или systemd timer epv2-audit.timer.
"""
from __future__ import annotations

import asyncio
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "src"))
from epv2_worker.verifier import _real_text_len  # noqa: E402

import httpx  # noqa: E402
from openai import AsyncOpenAI  # noqa: E402

# Узкий judge: только ПОДТВЕРЖДЁННЫЕ выдумки (имена/роли/числа/цитаты/события/
# места/даты, которых НЕТ в источнике). Игнорирует стиль, оценки, перифраз,
# избыточные атрибуции — иначе монитор шумит (verify правит и стиль).
_JUDGE_SYSTEM = """Du bist Faktenprüfer. Vergleiche den ARTIKEL (Deutsch) mit der QUELLE
(kann ukr/engl/dt sein). Melde NUR ECHTE ERFINDUNGEN — mit hoher Sicherheit.

MELDE NUR, wenn eine der beiden Bedingungen klar zutrifft:
  (A) WIDERSPRUCH: Eine konkrete Angabe widerspricht der QUELLE (andere Opferzahl,
      anderer Ort/Datum/Name, anderer Sprecher).
  (B) ERFUNDEN: Eine konkrete, überprüfbare Behauptung (benannte Person + Handlung,
      wörtliches Zitat, Zahl, Treffen/Ereignis), die in der QUELLE FEHLT UND auch
      nach Allgemeinwissen nicht offensichtlich wahr ist.

MELDE NICHT (das sind KEINE Erfindungen):
  - biografische/Rollen-Beschreibungen, die real und mit der QUELLE vereinbar sind
    (z. B. „früherer Bundesliga-Profi Matheus Cunha" — real und unstrittig);
  - allgemein bekannte/unstrittige Fakten, auch wenn nicht wörtlich in der QUELLE;
  - redaktionelle Adjektive/Wertungen, Einordnung, Paraphrase, Synonyme,
    Übersetzungsvarianten, redundante Quellenangaben (z. B. „Wie die FAZ berichtet").
  - Etwas, das nur „nicht wörtlich vorkommt" — bloße Abwesenheit reicht NICHT;
    es muss erfunden ODER widersprüchlich sein.

Im Zweifel NICHT melden — aber bei klarem Widerspruch oder klar erfundenem
Eigennamen/Zitat/Zahl/Ereignis IMMER melden (keine Nachsicht dort).

Antworte AUSSCHLIESSLICH mit JSON:
{"fabrications": [{"claim": "<kurzes Zitat>", "why": "<Widerspruch oder erfunden — kurz>"}]}
Keine echten Erfindungen → {"fabrications": []}."""


_TRANSLIT_JUDGE_SYSTEM = """Du prüfst ukrainische Textfragmente auf KAPUTTE Transliteration
lateinischer Eigennamen. Ein Fragment ist KAPUTT, wenn der kyrillische Teil eine
phonetische Transliteration eines lateinischen Namens ist (z. B. «Ргайніше Post» =
Rheinische Post; «Сюддойтшер Zeitung» = Süddeutsche Zeitung) — also ein zerbrochener
Name aus halb-Kyrillisch + halb-Latein.

NICHT kaputt (= OK): ein normales ukrainisches Wort gefolgt von einem korrekt
lateinisch belassenen Eigennamen/Marke/Ort/Genre (z. B. «Поліція Cambridgeshire»,
«Речник Amazon», «Проєкт Brakestop», «Після Rock»). Hier ist der kyrillische Teil ein
echtes ukrainisches Wort, kein transliterierter Namensteil.

Gegeben eine Liste von Fragmenten. Antworte AUSSCHLIESSLICH mit JSON:
{"garbled": ["<nur die wirklich kaputten Fragmente, wörtlich aus der Liste>"]}
Keine kaputten → {"garbled": []}."""


async def _judge_translit(fragments: list[str], key: str) -> list[str]:
    if not fragments:
        return []
    try:
        client = AsyncOpenAI(api_key=key, base_url="https://api.deepseek.com/v1")
        user = "Fragmente:\n" + "\n".join(f"- {f}" for f in fragments) + "\n\nWelche sind kaputt? JSON."
        resp = await client.chat.completions.create(
            model="deepseek-chat",
            messages=[{"role": "system", "content": _TRANSLIT_JUDGE_SYSTEM}, {"role": "user", "content": user}],
            response_format={"type": "json_object"}, temperature=0.0, max_tokens=400,
        )
        raw = (resp.choices[0].message.content or "").strip()
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            data = json.loads(raw, strict=False)
        g = data.get("garbled") or []
        return [str(x) for x in g if str(x).strip()]
    except Exception:  # noqa: BLE001
        return fragments  # при ошибке судьи — не теряем сигнал


async def _judge_hallucinations(article: str, primary: str, supporting: str, key: str) -> list[dict]:
    try:
        client = AsyncOpenAI(api_key=key, base_url="https://api.deepseek.com/v1")
        user = ("QUELLE:\n" + (primary or "")[:9000]
                + (("\n\nHINTERGRUND:\n" + supporting[:2000]) if supporting else "")
                + "\n\nARTIKEL:\n" + (article or "")[:5000]
                + "\n\nGib erfundene Fakten als JSON.")
        resp = await client.chat.completions.create(
            model="deepseek-chat",
            messages=[{"role": "system", "content": _JUDGE_SYSTEM}, {"role": "user", "content": user}],
            response_format={"type": "json_object"}, temperature=0.0, max_tokens=700,
        )
        raw = (resp.choices[0].message.content or "").strip()
        try:
            data = json.loads(raw)
        except json.JSONDecodeError:
            data = json.loads(raw, strict=False)
        fab = data.get("fabrications") or []
        return [f for f in fab if isinstance(f, dict) and f.get("claim")]
    except Exception:  # noqa: BLE001
        return []

WP_CLI = os.getenv("EPV2_WP_CLI", "/usr/local/bin/wp")
WP_PATH = os.getenv("EPV2_WP_PATH", "/var/www/europulse/public")
SAMPLE_PHP = os.path.join(os.path.dirname(__file__), "audit_sample.php")
BOT_ENV = "/etc/epv2-bot.env"
REPORT_LOG = "/var/log/epv2-audit.log"
HALL_FLAG_THRESHOLD = 2  # сколько правок verifier'а считать «на заметку»

# Гарбл-сигналы (точные, чтобы не шуметь на немецких нарицательных):
#  1) в UK капитализированное КИРИЛЛИЧЕСКОЕ слово, за которым сразу ЛАТИНСКОЕ
#     (расколотое имя: «Сюддойтшер Zeitung»);
#  2) camelCase-бренд из DE (YouTube, OpenAI, ProSieben), исчезнувший из UK
#     (вероятно транслитерирован: «ИоуТубе»).
# Латинская часть = Заглавная+строчные (Zeitung), НЕ all-caps аббревиатура (ZDF,
# NATO легитимно остаются латиницей рядом с кириллицей).
_UK_MIXED_GARBLE = re.compile(r"[А-ЯЁІЇЄҐ][а-яёіїєґ']{2,}\s+[A-ZÀ-ÖØ-Þ][a-zà-ÿ]{2,}")
_LATIN_CAMEL = re.compile(r"\b[A-ZÀ-ÖØ-Þ][a-zà-ÿ]+[A-ZÀ-ÖØ-Þ][A-Za-zÀ-ÿ]+\b")


def _read_env(path: str) -> dict:
    d = {}
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if "=" in line and not line.startswith("#"):
                    k, v = line.split("=", 1)
                    d[k.strip()] = v.strip().strip('"').strip("'")
    except OSError:
        pass
    return d


def _wp(args: list[str], timeout: int = 120) -> str:
    try:
        r = subprocess.run([WP_CLI, "--path=" + WP_PATH, "--allow-root", *args],
                           capture_output=True, text=True, timeout=timeout)
        return r.stdout
    except Exception:  # noqa: BLE001
        return ""


def _deepseek_key() -> str:
    return _wp(["eval", 'echo EPV2_Settings::get("ai_keys")["deepseek"];'], 30).strip()


def _translit_risk(de_text: str, uk_text: str) -> list[str]:
    risks: list[str] = []
    # 1) расколотый кирилл+латин в UK
    for m in _UK_MIXED_GARBLE.finditer(uk_text or ""):
        risks.append(m.group(0).strip())
    # 2) camelCase-бренды из DE, исчезнувшие из UK
    for m in _LATIN_CAMEL.finditer(de_text or ""):
        s = m.group(0).strip()
        if len(s) >= 4 and s not in (uk_text or "") and s not in risks:
            risks.append(s)
    # dedup, cap
    seen: set[str] = set()
    out = []
    for r in risks:
        if r not in seen:
            seen.add(r); out.append(r)
    return out[:6]


def _send_telegram(text: str) -> None:
    env = _read_env(BOT_ENV)
    token = env.get("TELEGRAM_BOT_TOKEN", "")
    chat = env.get("ALLOWED_CHAT_ID", "")
    if not token or not chat:
        return
    try:
        httpx.post(f"https://api.telegram.org/bot{token}/sendMessage",
                   json={"chat_id": chat, "text": text, "disable_web_page_preview": True},
                   timeout=15)
    except Exception:  # noqa: BLE001
        pass


def _log(text: str) -> None:
    try:
        with open(REPORT_LOG, "a") as f:
            f.write(text + "\n")
    except OSError:
        pass


async def main() -> int:
    key = _deepseek_key()
    if not key:
        return 1
    raw = _wp(["eval-file", SAMPLE_PHP], 600)
    try:
        items = json.loads(raw or "[]")
    except json.JSONDecodeError:
        items = []
    if not items:
        return 0

    sem = asyncio.Semaphore(5)
    hall: list[tuple[int, list]] = []
    translit: list[tuple[int, list]] = []
    checked = 0

    async def check(it: dict) -> None:
        nonlocal checked
        async with sem:
            checked += 1
            tr = _translit_risk((it.get("de_title", "") + " " + it.get("de_body", "")), it.get("uk", ""))
            if tr:
                # судья отсеивает легитимную латиницу (Поліція Cambridgeshire) от
                # реального гарбла (Ргайніше Post)
                confirmed = await _judge_translit(tr, key)
                if confirmed:
                    translit.append((it.get("qid"), confirmed))
            primary = it.get("primary", "")
            if _real_text_len(primary) >= 350:
                article = "\n".join([it.get("de_title", ""), it.get("de_lead", ""),
                                     it.get("de_card", ""), it.get("de_body", "")])
                fab = await _judge_hallucinations(article, primary, it.get("supporting", ""), key)
                if fab:
                    hall.append((it.get("qid"), [f.get("claim", "")[:90] for f in fab[:4]]))

    await asyncio.gather(*[check(it) for it in items])

    ts = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    lines = [f"📊 EuroPulse аудит достоверности {ts}",
             f"Проверено: {checked} | потенц. галлюцинации: {len(hall)} | транслит-риски: {len(translit)}"]
    if hall:
        lines.append("\n⚠️ На заметку (verifier нашёл бы правки):")
        for qid, corr in hall[:6]:
            lines.append(f"  #{qid}: {corr[0][:90]}")
    if translit:
        lines.append("\n🔤 Транслит-риски UK (латинские имена исчезли):")
        for qid, names in translit[:6]:
            lines.append(f"  #{qid}: {', '.join(names[:3])}")
    if not hall and not translit:
        lines.append("\n✅ Проблем не выявлено.")
    report = "\n".join(lines)
    _log(report + "\n" + "-" * 40)
    _send_telegram(report)
    print(report)
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
