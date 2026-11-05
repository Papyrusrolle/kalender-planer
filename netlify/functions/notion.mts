// Brücke zwischen der Notiz-App und einer Notion-Datenbank (Netlify-Variante von notizen/notion.php).
//
// Der Notion-Schlüssel bleibt auf Netlify (Blobs-Speicher "notizen" bzw. Umgebungsvariable) und gelangt nie in den Browser.
// Zugriff nur mit dem App-Passwort (Header "X-Kalender-Key"), festgelegt in der Umgebungsvariable NOTIZEN_PASSWORT.
//
//   GET  ?a=status            -> {configured, dbTitle, dbUrl}
//   POST ?a=setup  {token,db} -> prüft Schlüssel + Datenbank, legt fehlende Spalten an, speichert
//   GET  ?a=list              -> {notes:[…], tags:[…]}
//   GET  ?a=get&id=…          -> eine Notiz inkl. Text
//   POST ?a=save   {id?,title,tags,pinned,body,base?,force?} -> legt an / aktualisiert (409 bei Konflikt)
//   POST ?a=delete {id}       -> verschiebt die Seite in den Notion-Papierkorb
//
// Jede Zeile des Notiztexts wird ein Notion-Block:
//   # / ## / ###  Überschriften     - Punkt        1. Nummeriert     [ ] / [x] Aufgabe
//   > Zitat       --- Trennlinie     ``` Code ```   **fett** *kursiv* ~~durch~~ `code` [Text](https://…)

import type { Config } from "@netlify/functions"
import { getStore } from "@netlify/blobs"
import { createHash, timingSafeEqual } from "node:crypto"

const NOTION_VERSION = "2022-06-28"
const PROP_TAGS = "Tags"
const PROP_PIN = "Angepinnt"
const CHUNK = 1800 // Notion erlaubt max. 2000 Zeichen je Textstück

type Cfg = { token: string; db: string; titleProp: string; dbTitle?: string; dbUrl?: string | null }
type Note = { id: string; title: string; tags: string[]; pinned: boolean; edited: string | null; created: string | null; url: string | null; body: string; readonly: boolean; ver?: string }
type Block = Record<string, any>

class Fail extends Error {
  status: number
  data: Record<string, unknown>
  constructor(status: number, data: Record<string, unknown>) {
    super(String(data.message ?? data.error ?? "Fehler"))
    this.status = status
    this.data = data
  }
}

const env = (k: string) => Netlify.env.get(k) ?? ""
const json = (status: number, data: unknown) =>
  new Response(JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store", "X-Content-Type-Options": "nosniff" },
  })
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms))
const sha = (s: string) => createHash("sha256").update(s).digest()

// ---------- Konfiguration ----------
// Testumgebungen können einen eigenen Speicher einhängen; auf Netlify wird immer Blobs genutzt.
const store = () => (globalThis as any).__notizenStore ?? getStore({ name: "notizen", consistency: "strong" })

async function loadCfg(): Promise<Cfg | null> {
  if (env("NOTION_TOKEN") && env("NOTION_DB")) {
    return { token: env("NOTION_TOKEN"), db: dbId(env("NOTION_DB")) ?? env("NOTION_DB"), titleProp: env("NOTION_TITLE_PROP") || "Name" }
  }
  const c = await store().get("config", { type: "json" })
  return c && c.token && c.db ? c : null
}

// ---------- Notion-Aufrufe ----------
let cfg: Cfg | null = null

async function notion(method: string, path: string, body?: unknown, token?: string): Promise<any> {
  const base = env("NOTION_API_BASE") || "https://api.notion.com/v1"
  for (let attempt = 0; ; attempt++) {
    let res: Response
    try {
      res = await fetch(base + path, {
        method,
        headers: { Authorization: "Bearer " + (token ?? cfg!.token), "Notion-Version": NOTION_VERSION, "Content-Type": "application/json" },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(25000),
      })
    } catch (e) {
      if (attempt < 2) { await sleep(1000); continue }
      throw new Fail(502, { error: "notion", message: "Notion nicht erreichbar: " + (e as Error).message })
    }
    if ((res.status === 429 || res.status >= 500) && attempt < 4) {
      await sleep(1000 * (Number(res.headers.get("retry-after")) || 1 + attempt))
      continue
    }
    const data = await res.json().catch(() => ({}))
    if (res.status === 404 && method === "DELETE") return data // Block schon weg – egal
    if (!res.ok) {
      throw new Fail([401, 403, 404].includes(res.status) ? 424 : 502, {
        error: "notion", notionStatus: res.status, code: data.code ?? "", message: data.message ?? "Notion antwortet mit " + res.status,
      })
    }
    return data
  }
}

// Mehrere Aufrufe gleichzeitig. Notion erlaubt im Schnitt 3 Anfragen/Sekunde.
async function notionMulti(reqs: [string, string][]) {
  for (let i = 0; i < reqs.length; i += 3) await Promise.all(reqs.slice(i, i + 3).map(([m, p]) => notion(m, p)))
}

// ---------- Text <-> Notion ----------
const rtPlain = (rts: any[] = []) => rts.map((r) => r.plain_text ?? r.text?.content ?? "").join("")

function rtToMd(rts: any[] = []): string {
  let s = ""
  for (const r of rts) {
    const t: string = r.plain_text ?? r.text?.content ?? ""
    if (t === "") continue
    const an = r.annotations ?? {}
    const href = r.href ?? r.text?.link?.url ?? null
    let [, lead, core, trail] = t.match(/^(\s*)([\s\S]*?)(\s*)$/)!
    if (core !== "") {
      if (an.code) core = "`" + core + "`"
      else {
        if (an.strikethrough) core = "~~" + core + "~~"
        if (an.bold && an.italic) core = "***" + core + "***"
        else if (an.bold) core = "**" + core + "**"
        else if (an.italic) core = "*" + core + "*"
      }
      if (href) core = "[" + core + "](" + href + ")"
    }
    s += lead + core + trail
  }
  return s
}

function rtPush(out: any[], text: string, an: Record<string, boolean>, link: string | null) {
  if (text === "") return
  const cps = Array.from(text) // nach Zeichen, nicht nach UTF-16-Einheiten trennen
  for (let o = 0; o < cps.length; o += CHUNK) {
    const item: any = { type: "text", text: { content: cps.slice(o, o + CHUNK).join("") } }
    if (link) item.text.link = { url: link }
    if (Object.keys(an).length) item.annotations = { ...an }
    out.push(item)
  }
}

const INLINE = new RegExp(
  String.raw`\[([^\]\n]+)\]\(((?:https?:\/\/|mailto:)[^)\s]+)\)` + // 1,2 Link
    String.raw`|\*\*\*(?!\s)(.+?)(?<!\s)\*\*\*` + //                  3 fett+kursiv
    String.raw`|\*\*(?!\s)(.+?)(?<!\s)\*\*` + //                      4 fett
    String.raw`|~~(?!\s)(.+?)(?<!\s)~~` + //                          5 durchgestrichen
    "|`([^`\\n]+)`" + //                                              6 Code
    String.raw`|(?<![*\w])\*(?![\s*])([^*\n]+?)(?<!\s)\*(?![*\w])`, // 7 kursiv
  "gu",
)

function mdToRt(s: string, an: Record<string, boolean> = {}, link: string | null = null): any[] {
  const out: any[] = []
  const re = new RegExp(INLINE)
  let pos = 0
  let m: RegExpExecArray | null
  while ((m = re.exec(s))) {
    rtPush(out, s.slice(pos, m.index), an, link)
    if (m[1] !== undefined) out.push(...mdToRt(m[1], an, m[2]))
    else if (m[3] !== undefined) out.push(...mdToRt(m[3], { ...an, bold: true, italic: true }, link))
    else if (m[4] !== undefined) out.push(...mdToRt(m[4], { ...an, bold: true }, link))
    else if (m[5] !== undefined) out.push(...mdToRt(m[5], { ...an, strikethrough: true }, link))
    else if (m[6] !== undefined) rtPush(out, m[6], { ...an, code: true }, link)
    else if (m[7] !== undefined) out.push(...mdToRt(m[7], { ...an, italic: true }, link))
    pos = m.index + m[0].length
  }
  rtPush(out, s.slice(pos), an, link)
  return out
}

const CODE_LANGS = ["bash", "c", "c#", "c++", "css", "go", "html", "java", "javascript", "json", "kotlin", "markdown",
  "php", "powershell", "python", "ruby", "rust", "shell", "sql", "swift", "typescript", "xml", "yaml"]

// Ein Block als Textzeile(n). null = kann die App nicht bearbeiten (Bild, Tabelle, Unterseite, eingerückt …)
function blockToMd(b: Block): string | null {
  const t: string = b.type ?? ""
  if (b.has_children) return null
  const d = b[t] ?? {}
  const rt = () => rtToMd(d.rich_text)
  switch (t) {
    case "paragraph": return rt()
    case "heading_1": case "heading_2": case "heading_3":
      return d.is_toggleable ? null : "#".repeat(Number(t.slice(-1))) + " " + rt()
    case "bulleted_list_item": return "- " + rt()
    case "numbered_list_item": return "1. " + rt()
    case "to_do": return (d.checked ? "[x] " : "[ ] ") + rt()
    case "quote": return "> " + rt()
    case "divider": return "---"
    case "code": {
      const lang = d.language ?? "plain text"
      return "```" + (lang === "plain text" ? "" : lang) + "\n" + rtPlain(d.rich_text) + "\n```"
    }
  }
  return null
}

const blk = (type: string, data: unknown): Block => ({ object: "block", type, [type]: data })

function mdToBlocks(body: string): Block[] {
  body = body.replace(/\r\n?/g, "\n")
  if (body === "") return []
  const lines = body.split("\n")
  const out: Block[] = []
  for (let i = 0; i < lines.length; i++) {
    const l = lines[i]
    let m: RegExpMatchArray | null
    if ((m = l.match(/^```\s*([\w#+ -]*)\s*$/))) {
      const code: string[] = []
      for (i++; i < lines.length && lines[i].trimEnd() !== "```"; i++) code.push(lines[i])
      const lang = m[1].trim().toLowerCase()
      const rt: any[] = []
      rtPush(rt, code.join("\n"), {}, null)
      out.push(blk("code", { rich_text: rt, language: CODE_LANGS.includes(lang) ? lang : "plain text" }))
    } else if (l.trim() === "---") out.push(blk("divider", {}))
    else if ((m = l.match(/^(#{1,3}) (.*)$/))) out.push(blk("heading_" + m[1].length, { rich_text: mdToRt(m[2]) }))
    else if ((m = l.match(/^(?:- )?\[( |x|X)\] (.*)$/))) out.push(blk("to_do", { rich_text: mdToRt(m[2]), checked: m[1] !== " " }))
    else if ((m = l.match(/^[-*•] (.*)$/u))) out.push(blk("bulleted_list_item", { rich_text: mdToRt(m[1]) }))
    else if ((m = l.match(/^\d+[.)] (.*)$/))) out.push(blk("numbered_list_item", { rich_text: mdToRt(m[1]) }))
    else if ((m = l.match(/^> ?(.*)$/))) out.push(blk("quote", { rich_text: mdToRt(m[1]) }))
    else out.push(blk("paragraph", { rich_text: mdToRt(l) }))
  }
  return out
}

async function loadBlocks(id: string): Promise<[Block[], string[], boolean]> {
  const blocks: Block[] = []
  let cursor: string | null = null
  do {
    const r = await notion("GET", `/blocks/${encodeURIComponent(id)}/children?page_size=100` + (cursor ? "&start_cursor=" + encodeURIComponent(cursor) : ""))
    blocks.push(...r.results)
    cursor = r.has_more ? r.next_cursor : null
  } while (cursor)
  let readonly = false
  const lines = blocks.map((b) => {
    const md = blockToMd(b)
    if (md !== null) return md
    readonly = true
    return "⟦" + (b.has_children ? "verschachtelter Inhalt" : String(b.type).replace(/_/g, " ")) + " – nur in Notion bearbeitbar⟧"
  })
  return [blocks, lines, readonly]
}

async function appendBlocks(parent: string, blocks: Block[], after: string | null) {
  for (let i = 0; i < blocks.length; i += 100) {
    const body: any = { children: blocks.slice(i, i + 100) }
    if (after) body.after = after
    const r = await notion("PATCH", `/blocks/${encodeURIComponent(parent)}/children`, body)
    if (after) after = r.results?.at(-1)?.id ?? null
  }
}

// ---------- Notizen ----------
const titleProp = (props: Record<string, any>) => Object.keys(props).find((k) => props[k]?.type === "title") ?? "Name"

function pageMeta(p: any) {
  const props = p.properties ?? {}
  return {
    id: p.id as string,
    title: rtPlain(props[titleProp(props)]?.title),
    tags: (props[PROP_TAGS]?.multi_select ?? []).map((o: any) => o.name) as string[],
    pinned: !!props[PROP_PIN]?.checkbox,
    edited: p.last_edited_time ?? null,
    created: p.created_time ?? null,
    url: p.url ?? null,
  }
}

const ver = (n: Note) => createHash("sha1").update(JSON.stringify([n.title, [...n.tags].sort(), !!n.pinned, n.body])).digest("hex")

async function fullNote(id: string): Promise<[Note, Block[], string[]]> {
  const page = await notion("GET", "/pages/" + encodeURIComponent(id))
  if (page.archived || page.in_trash) throw new Fail(404, { error: "gone", message: "Notiz wurde in Notion gelöscht." })
  const [blocks, lines, readonly] = await loadBlocks(id)
  const n: Note = { ...pageMeta(page), body: lines.join("\n"), readonly }
  n.ver = ver(n)
  return [n, blocks, lines]
}

function cleanTags(tags: unknown): string[] {
  const out = new Map<string, string>()
  for (const t0 of Array.isArray(tags) ? tags : []) {
    const t = String(t0).replace(/,/g, " ").trim()
    if (t) out.set(t.toLowerCase(), Array.from(t).slice(0, 100).join(""))
  }
  return [...out.values()]
}

function propsFor(title: string, tags: string[], pinned: boolean) {
  const tt: any[] = []
  rtPush(tt, title, {}, null)
  return {
    [cfg!.titleProp]: { title: tt },
    [PROP_TAGS]: { multi_select: tags.map((name) => ({ name })) },
    [PROP_PIN]: { checkbox: pinned },
  }
}

function dbId(s: string): string | null {
  let path = s
  try { path = new URL(s).pathname } catch {}
  const m = path.match(/[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}/i)
  return m ? m[0].replace(/-/g, "").toLowerCase() : null
}

// ---------- Anfragen ----------
async function handle(req: Request): Promise<Response> {
  // Anmeldung
  const pw = env("NOTIZEN_PASSWORT")
  if (!pw) return json(503, { error: "nopassword" })
  let given = ""
  try { given = decodeURIComponent(req.headers.get("x-kalender-key") ?? "") } catch {}
  if (!given) return json(401, { error: "unauthorized" })
  if (!timingSafeEqual(sha(given), sha(pw))) {
    await sleep(2000) // bremst das Durchprobieren von Passwörtern
    return json(401, { error: "unauthorized" })
  }

  const url = new URL(req.url)
  const a = url.searchParams.get("a") ?? ""
  let input: any = null
  if (req.method === "POST") {
    const raw = await req.text()
    if (raw.length > 2 * 1024 * 1024) return json(413, { error: "zu gross" })
    try { input = JSON.parse(raw) } catch {}
    if (!input || typeof input !== "object") return json(400, { error: "ungueltig" })
  }

  cfg = await loadCfg()
  const fromEnv = !!(env("NOTION_TOKEN") && env("NOTION_DB"))

  if (a === "status") {
    if (cfg && fromEnv && !cfg.dbTitle) {
      try { const d = await notion("GET", "/databases/" + cfg.db); cfg.dbTitle = rtPlain(d.title) || "Notion-Datenbank"; cfg.dbUrl = d.url } catch {}
    }
    return json(200, { configured: !!cfg, dbTitle: cfg?.dbTitle ?? null, dbUrl: cfg?.dbUrl ?? null })
  }

  if (a === "setup") {
    if (fromEnv) return json(400, { error: "setup", message: "Notion ist über Netlify-Umgebungsvariablen eingestellt (NOTION_TOKEN/NOTION_DB) – bitte dort ändern." })
    const token = String(input.token ?? "").trim() || cfg?.token || "" // leer = nur Datenbank wechseln
    const db = dbId(String(input.db ?? ""))
    if (!token || !db) return json(400, { error: "input", message: "Bitte Schlüssel und Datenbank-Link angeben." })
    let d: any
    try {
      d = await notion("GET", "/databases/" + db, undefined, token)
    } catch (e) {
      const s = e instanceof Fail ? e.data.notionStatus : 0
      const message = s === 401 ? "Der Notion-Schlüssel ist ungültig."
        : s === 404 ? "Datenbank nicht gefunden. Ist die Integration in Notion mit der Datenbank verbunden (••• → Verbindungen)? Und ist es wirklich eine Datenbank, keine normale Seite?"
        : (e as Error).message
      return json(400, { error: "setup", message })
    }
    const props = d.properties ?? {}
    const add: Record<string, unknown> = {}
    for (const [name, type] of [[PROP_TAGS, "multi_select"], [PROP_PIN, "checkbox"]]) {
      if (!props[name]) add[name] = { [type]: {} }
      else if (props[name].type !== type) return json(400, { error: "setup", message: `Die Spalte „${name}“ hat in Notion den falschen Typ. Bitte umbenennen oder löschen.` })
    }
    if (Object.keys(add).length) await notion("PATCH", "/databases/" + db, { properties: add }, token)
    const c: Cfg = { token, db, titleProp: titleProp(props), dbTitle: rtPlain(d.title) || "Notion-Datenbank", dbUrl: d.url ?? null }
    await store().setJSON("config", c)
    return json(200, { configured: true, dbTitle: c.dbTitle, dbUrl: c.dbUrl })
  }

  if (!cfg) return json(409, { error: "setup", message: "Notion ist noch nicht verbunden." })

  if (a === "list") {
    const notes: ReturnType<typeof pageMeta>[] = []
    let cursor: string | null = null
    do {
      const q: any = { page_size: 100, sorts: [{ timestamp: "last_edited_time", direction: "descending" }] }
      if (cursor) q.start_cursor = cursor
      const r = await notion("POST", `/databases/${cfg.db}/query`, q)
      for (const p of r.results) notes.push(pageMeta(p))
      cursor = r.has_more ? r.next_cursor : null
    } while (cursor && notes.length < 5000)
    const d = await notion("GET", "/databases/" + cfg.db)
    const tags = (d.properties?.[PROP_TAGS]?.multi_select?.options ?? []).map((o: any) => o.name)
    return json(200, { notes, tags })
  }

  if (a === "get") return json(200, (await fullNote(url.searchParams.get("id") ?? ""))[0])

  if (a === "save") {
    const title = String(input.title ?? "").trim()
    const tags = cleanTags(input.tags)
    const pinned = !!input.pinned
    const body: string | null = input.body == null ? null : String(input.body).replace(/\r\n?/g, "\n")
    const id = String(input.id ?? "")

    if (!id) {
      const blocks = mdToBlocks(body ?? "")
      const page = await notion("POST", "/pages", {
        parent: { database_id: cfg.db }, properties: propsFor(title, tags, pinned), children: blocks.slice(0, 100),
      })
      if (blocks.length > 100) await appendBlocks(page.id, blocks.slice(100), null)
      return json(200, (await fullNote(page.id))[0])
    }

    const [cur, oldBlocks, oldMd] = await fullNote(id)
    if (!input.force && input.base && input.base !== cur.ver) return json(409, { error: "conflict", note: cur })
    if (cur.title !== title || JSON.stringify([...cur.tags].sort()) !== JSON.stringify([...tags].sort()) || cur.pinned !== pinned) {
      await notion("PATCH", "/pages/" + encodeURIComponent(id), { properties: propsFor(title, tags, pinned) })
    }
    if (body !== null && !cur.readonly && body !== cur.body) {
      // Nur den geänderten Mittelteil ersetzen: gleicher Anfang und gleiches Ende bleiben stehen.
      const neu = mdToBlocks(body)
      const newMd = neu.map(blockToMd)
      const no = oldMd.length, nn = newMd.length
      let k = 0
      while (k < no && k < nn && oldMd[k] === newMd[k]) k++
      let s = 0
      if (k > 0) while (s < no - k && s < nn - k && oldMd[no - 1 - s] === newMd[nn - 1 - s]) s++
      await notionMulti(oldBlocks.slice(k, no - s).map((b) => ["DELETE", "/blocks/" + b.id] as [string, string]))
      const ins = neu.slice(k, nn - s)
      if (ins.length) await appendBlocks(id, ins, s > 0 ? oldBlocks[k - 1].id : null)
    }
    return json(200, (await fullNote(id))[0])
  }

  if (a === "delete") {
    await notion("PATCH", "/pages/" + encodeURIComponent(String(input.id ?? "")), { archived: true })
    return json(200, { ok: true })
  }

  return json(400, { error: "action" })
}

export default async (req: Request) => {
  try {
    return await handle(req)
  } catch (e) {
    if (e instanceof Fail) return json(e.status, e.data)
    console.error(e)
    return json(500, { error: "intern", message: (e as Error).message })
  }
}

// Gleiche Adresse wie auf IONOS, damit die App unverändert bleibt.
export const config: Config = { path: "/notion.php" }
