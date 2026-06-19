"""Groundedness verify-and-CORRECT pass (2026-06-17, ужесточён 2026-06-19).

Audit of 76 articles showed ~36-40% contained fabrications (invented casualties,
officials, quotes, numbers, events) that the rewrite prompt + regex validators
could not stop — the model fabricates on top of a valid source.

This is an extra LLM call AFTER the German rewrite and BEFORE translation. It
compares the article to the source and REMOVES/CORRECTS any fact not supported
by the source, then returns the corrected article. We CORRECT and publish — we
do NOT block. Runs on the cheap primary provider (deepseek by default).

Correcting the German master before translation means the fix propagates to
UK/EN automatically.

2026-06-19 — три класса ошибок проходили мимо первой версии (аудит 18.06):
  1) факт из СОСЕДНЕЙ статьи кластера (supporting) считался грунтованным —
     выжил выдуманный пассаж про Ramstein/Pistorius (12623);
  2) выдуманная прямая цитата с ложной атрибуцией «сообщение министерства»,
     хотя это был пост в Facebook (12625);
  3) журналистка названа автором исследования (12677).
Фиксы: PRIMARY-источник = единственный авторитет для конкретных фактов,
SUPPORTING = только фон (не лицензирует новые конкретные утверждения);
жёсткие правила по цитатам и атрибуции; ДВА прохода (повторная сверка
исправленного текста ловит стохастические промахи).
"""

from __future__ import annotations

import json
import logging
import re

from openai import AsyncOpenAI

logger = logging.getLogger(__name__)

_TAG_RE = re.compile(r"<[^>]+>")
_URL_RE = re.compile(r"https?://\S+")
_WS_RE = re.compile(r"\s+")


def _real_text_len(s: str) -> int:
    """Длина РЕАЛЬНОГО текста источника: без HTML-тегов и URL. Источник вроде
    'голая RSS-ссылка Google News' или 'только <a href=...>' даёт ~0 — сверять
    нечем, и агрессивная чистка выхолостит правдивый контент (баг 12630)."""
    if not s:
        return 0
    t = _URL_RE.sub(" ", _TAG_RE.sub(" ", s))
    return len(_WS_RE.sub(" ", t).strip())

_VERIFY_SYSTEM = """Du bist ein extrem strenger Faktenprüfer und Korrektor einer Nachrichtenredaktion.
Du bekommst eine PRIMÄRQUELLE, optional HINTERGRUND und einen ARTIKEL (generierter deutscher Text).

WICHTIGSTE REGEL — QUELLENHIERARCHIE:
- Die PRIMÄRQUELLE ist die EINZIGE Autorität für konkrete Fakten dieser Meldung.
- HINTERGRUND ist NUR allgemeiner Kontext. Er darf KEINE neuen konkreten Aussagen
  rechtfertigen (keine Namen, Zitate, Zahlen, Opferzahlen, Treffen, Telefonate,
  Ereignisse, Orte). Wenn ein konkreter Fakt NUR im HINTERGRUND steht und NICHT in
  der PRIMÄRQUELLE — ENTFERNE ihn aus dem Artikel (er gehört zu einer anderen Meldung).

AUFGABE: Prüfe JEDEN Fakt im ARTIKEL gegen die PRIMÄRQUELLE und KORRIGIERE den Artikel.
Geprüft werden: Namen + Funktionen/Ämter, Zahlen, OPFERZAHLEN (Tote/Verletzte/Kinder —
auch ein- und zweistellige), Daten, Wochentage, Uhrzeiten, wörtliche Zitate, Orte,
Ereignisse, Treffen/Telefonate, Ursachen, Quellennennungen/Attributionen.

REGELN FÜR DIE KORREKTUR:
- Entferne JEDE Aussage, die NICHT durch die PRIMÄRQUELLE gedeckt ist (erfundene
  Opferzahlen, erfundene Sprecher/Beamte/Analysten, erfundene Zitate, erfundene
  Zahlen/Distanzen, erfundene Ereignisse/Treffen/Telefonate, erfundene Orte).
- ZITATE: Jede direkte Rede in Anführungszeichen MUSS (sinngemäß wörtlich) in der
  PRIMÄRQUELLE vorkommen. Findet sich das Zitat dort NICHT — entferne die
  Anführungszeichen und das erfundene Zitat; gib den Inhalt nur wieder, wenn er
  durch die PRIMÄRQUELLE gedeckt ist.
- ATTRIBUTION: Jede Zuschreibung («laut X», «sagte X», «wie das Ministerium
  mitteilte», «Studie/Autor X») MUSS exakt der PRIMÄRQUELLE entsprechen — richtiger
  Sprecher UND richtiges Format. Ein Facebook-Post ist KEINE „Mitteilung des
  Ministeriums". Der/die Journalist:in eines Artikels ist NICHT der/die Autor:in
  einer darin zitierten Studie. Stimmt die Attribution nicht — korrigiere sie auf
  die PRIMÄRQUELLE oder entferne sie.
- Korrigiere jede Aussage, die der PRIMÄRQUELLE WIDERSPRICHT, auf deren Wert
  (z. B. „3 Verletzte" statt „15 Verletzte", falscher Wochentag, falscher Ort).
- Größenordnung exakt: „60 Millionen" darf nicht „60" werden.
- KEINE neuen Fakten hinzufügen, die nicht in der PRIMÄRQUELLE stehen.
- Erfundene Wochentage entfernen, wenn die PRIMÄRQUELLE nur ein Datum nennt.
- Ist die PRIMÄRQUELLE sehr kurz (Schlagzeile/Teaser): sei MAXIMAL streng — entferne
  ALLE konkreten Spezifika (Namen, Zahlen, Zitate, Daten), die dort nicht wörtlich
  stehen. Lieber kurz und korrekt als lang und erfunden.
- Behalte flüssiges, redaktionelles Deutsch und die Struktur (title, lead, card_lead, body).
- Allgemeiner, klar als Einordnung erkennbarer Kontext (kein konkreter Fakt) darf bleiben.
- Ist bereits ALLES gedeckt: gib den Artikel unverändert zurück (corrections: []).

Antworte AUSSCHLIESSLICH mit einem JSON-Objekt:
{"title": "...", "lead": "...", "card_lead": "...", "body": "...", "corrections": ["kurze Liste der entfernten/korrigierten Fakten"]}"""


def _build_client(provider: str, api_key: str) -> AsyncOpenAI:
    if provider == "deepseek":
        return AsyncOpenAI(api_key=api_key, base_url="https://api.deepseek.com/v1")
    return AsyncOpenAI(api_key=api_key)


async def _run_pass(
    client: AsyncOpenAI,
    model: str,
    primary: str,
    supporting: str,
    article: dict,
    max_tokens: int,
) -> dict | None:
    """Один проход сверки. Возвращает {title,lead,card_lead,body,corrections} или None."""
    article_json = json.dumps(article, ensure_ascii=False)
    user = "PRIMÄRQUELLE (einzige Autorität für konkrete Fakten):\n" + primary[:9000]
    if supporting.strip():
        user += "\n\n----------\nHINTERGRUND (nur Kontext, rechtfertigt KEINE neuen konkreten Fakten):\n" + supporting[:4000]
    user += (
        "\n\n----------\nARTIKEL (zu prüfen und zu korrigieren):\n"
        + article_json
        + "\n\nGib den geprüften, korrigierten ARTIKEL als JSON zurück."
    )
    resp = await client.chat.completions.create(
        model=model,
        messages=[
            {"role": "system", "content": _VERIFY_SYSTEM},
            {"role": "user", "content": user},
        ],
        response_format={"type": "json_object"},
        temperature=0.0,
        max_tokens=max_tokens,
    )
    raw = (resp.choices[0].message.content or "").strip()
    if not raw:
        return None
    # strict=False допускает сырые control-символы (переносы строк) внутри строк —
    # модель иногда кладёт их в body, обычный json.loads падал «Invalid control char».
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        data = json.loads(raw, strict=False)
    out = {
        "title": str(data.get("title", "") or "").strip(),
        "lead": str(data.get("lead", "") or "").strip(),
        "card_lead": str(data.get("card_lead", "") or "").strip(),
        "body": str(data.get("body", "") or "").strip(),
        "corrections": [str(c) for c in (data.get("corrections") or []) if str(c).strip()][:20],
    }
    # Защита ТОЛЬКО от пустого вывода. Сильное сжатие — это НОРМА, когда статья
    # была в основном выдумкой (корректная версия законно короче).
    if not out["title"] or len(out["body"]) < 80:
        logger.warning("verify_and_correct: corrected output empty/near-empty")
        return None
    return out


_SPAN_AUDIT_SYSTEM = """Du bist Faktenprüfer. Finde im ARTIKEL ALLE:
(a) direkten Zitate in Anführungszeichen («…», „…", "…"),
(b) Quellen-/Sprecher-Attributionen («laut X», «sagte X», «wie X mitteilte»,
    «Mitteilung des …», «Studie/Autor:in X», «X erklärte»),
(c) Rollen-/Beziehungsangaben («X als Y», «X von der/dem Y», «X, der/die Y»).

Prüfe JEDE gegen die PRIMÄRQUELLE (einzige Autorität). Für jede Fundstelle ein Objekt:
- "span": EXAKTER, wortgenauer Teilstring aus dem ARTIKEL (so wie er dort steht,
  damit er per String-Ersetzung gefunden wird). Wähle einen vollständigen Satz
  oder Teilsatz — minimal, aber selbsttragend.
- "verdict":
    "delete"  — wenn das Zitat / die konkrete Aussage in der PRIMÄRQUELLE NICHT
                vorkommt (erfundenes Zitat, erfundene Aussage).
    "replace" — wenn Attribution/Rolle/Beziehung FALSCH ist (z. B. Facebook-Post
                fälschlich als „Mitteilung des Ministeriums"; Journalist:in eines
                Artikels fälschlich als Autor:in einer Studie; falscher Sprecher).
    "keep"    — wenn korrekt und durch die PRIMÄRQUELLE gedeckt.
- "replacement": bei "replace" die korrigierte deutsche Fassung gemäß PRIMÄRQUELLE
  (oder Attribution ganz weglassen); sonst "".
- "reason": kurze Begründung.

Sei streng: Im Zweifel, ob ein Zitat/eine Attribution wirklich in der PRIMÄRQUELLE
steht — "delete" bzw. "replace". Antworte AUSSCHLIESSLICH mit JSON:
{"ops": [{"span": "...", "verdict": "delete|replace|keep", "replacement": "...", "reason": "..."}]}"""


def _apply_span_ops(text: str, ops: list[dict]) -> tuple[str, list[str]]:
    """Детерминированно применяет delete/replace по точным подстрокам. Возвращает
    (новый текст, список применённых правок)."""
    applied: list[str] = []
    for op in ops:
        span = str(op.get("span") or "").strip()
        verdict = str(op.get("verdict") or "").lower()
        if not span or verdict not in ("delete", "replace"):
            continue
        repl = str(op.get("replacement") or "").strip() if verdict == "replace" else ""
        # Точное совпадение; при неудаче — нормализация кавычек.
        target = span if span in text else None
        if target is None:
            norm = lambda s: s.replace("„", '"').replace("“", '"').replace("«", '"').replace("»", '"').replace("”", '"')
            nt = norm(text)
            ns = norm(span)
            if ns in nt:
                # находим исходный фрагмент по позиции в нормализованном тексте
                idx = nt.index(ns)
                target = text[idx:idx + len(span)]
        if target is None or target not in text:
            continue
        text = text.replace(target, repl, 1)
        applied.append(f"{verdict}: {span[:120]}")
    # подчистка двойных пробелов/пустых параграфов после удалений
    text = text.replace("<p></p>", "").replace("  ", " ")
    return text.strip(), applied


async def _audit_spans(
    client: AsyncOpenAI, model: str, primary: str, fields: dict, max_tokens: int
) -> dict | None:
    """Целевой аудит цитат/атрибуций/ролей с детерминированным применением."""
    article_json = json.dumps(fields, ensure_ascii=False)
    user = (
        "PRIMÄRQUELLE (einzige Autorität):\n" + primary[:9000]
        + "\n\n----------\nARTIKEL:\n" + article_json
        + "\n\nGib alle Zitate/Attributionen/Rollen als JSON-ops zurück."
    )
    resp = await client.chat.completions.create(
        model=model,
        messages=[
            {"role": "system", "content": _SPAN_AUDIT_SYSTEM},
            {"role": "user", "content": user},
        ],
        response_format={"type": "json_object"},
        temperature=0.0,
        max_tokens=max_tokens,
    )
    raw = (resp.choices[0].message.content or "").strip()
    if not raw:
        return None
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        data = json.loads(raw, strict=False)
    ops = data.get("ops") or []
    if not isinstance(ops, list) or not ops:
        return {"fields": fields, "applied": []}
    applied_all: list[str] = []
    out_fields = {}
    for key, val in fields.items():
        new_val, applied = _apply_span_ops(str(val or ""), ops)
        out_fields[key] = new_val
        applied_all.extend(applied)
    return {"fields": out_fields, "applied": applied_all}


async def verify_and_correct_german(
    *,
    title: str,
    lead: str,
    card_lead: str,
    body: str,
    primary_source: str = "",
    supporting_source: str = "",
    source_text: str = "",  # обратная совместимость: трактуется как primary
    provider: str,
    api_key: str,
    model: str,
    max_tokens: int = 3000,
    passes: int = 2,
) -> dict | None:
    """Сверяет немецкую статью с PRIMARY-источником и исправляет негрунтованное.

    primary_source — ЕДИНСТВЕННЫЙ авторитет для конкретных фактов.
    supporting_source — только фон, не лицензирует новые конкретные утверждения.
    Делает до `passes` проходов (повторная сверка исправленного текста ловит
    стохастические промахи). Возвращает {title,lead,card_lead,body,corrections}
    или None на ЛЮБОЙ ошибке (graceful — вызывающий сохраняет оригинал)."""
    primary = (primary_source or source_text or "").strip()
    supporting = (supporting_source or "").strip()
    if not primary or not (body or "").strip():
        return None
    # GUARD адекватности: если в primary нет реального текста (только RSS-ссылка/
    # тизер/голый <a href>), сверять НЕ с чем. Агрессивная чистка тогда удалит
    # правдивый контент (12630: source = base64 RSS-ссылка Google News, span-аудит
    # выпилил реальные цитаты Appelkamp). Безопаснее НЕ трогать статью — а реальный
    # текст должен добываться апстримом (PHP: резолв Google-News-RSS + full fetch).
    if _real_text_len(primary) < 350:
        logger.info("verify skipped: primary source has no real text (%d chars)", _real_text_len(primary))
        return None
    try:
        client = _build_client(provider, api_key)
        article = {"title": title, "lead": lead, "card_lead": card_lead, "body": body}
        all_corrections: list[str] = []
        result: dict | None = None
        for _ in range(max(1, passes)):
            out = await _run_pass(client, model, primary, supporting, article, max_tokens)
            if out is None:
                break
            result = out
            all_corrections.extend(out["corrections"])
            # Второй проход уже на исправленном тексте.
            article = {
                "title": out["title"],
                "lead": out["lead"],
                "card_lead": out["card_lead"],
                "body": out["body"],
            }
            # Если проход не нашёл что исправлять — текст чист, выходим.
            if not out["corrections"]:
                break
        if result is None:
            return None
        # ФИНАЛЬНАЯ СТАДИЯ: детерминированный span-аудит цитат/атрибуций/ролей.
        # Ловит два класса, которые холистический проход пропускает:
        #   - «рапортует удаление, но оставляет текст» (фейк-цитата 12625);
        #   - реляционные ошибки (журналист как автор исследования 12677).
        # Удаление/замена выполняются в КОДЕ по точной подстроке — гарантированно.
        try:
            span_res = await _audit_spans(
                client, model, primary,
                {"title": result["title"], "lead": result["lead"],
                 "card_lead": result["card_lead"], "body": result["body"]},
                max_tokens,
            )
            if span_res:
                f = span_res["fields"]
                # не опустошаем статью
                if f.get("body") and len(f["body"]) >= 80:
                    result["title"] = f["title"] or result["title"]
                    result["lead"] = f["lead"]
                    result["card_lead"] = f["card_lead"]
                    result["body"] = f["body"]
                if span_res["applied"]:
                    all_corrections.extend(span_res["applied"])
        except Exception as exc:  # noqa: BLE001
            logger.warning("span audit skipped: %s", exc)
        # дедуп корректировок с сохранением порядка
        seen: set[str] = set()
        merged = []
        for c in all_corrections:
            if c not in seen:
                seen.add(c)
                merged.append(c)
        result["corrections"] = merged[:30]
        return result
    except Exception as exc:  # noqa: BLE001
        logger.warning("verify_and_correct failed: %s", exc)
        return None
