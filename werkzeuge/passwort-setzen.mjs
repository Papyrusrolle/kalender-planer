// Legt das Kalenderpasswort fest.
// Aufruf im Ordner Kalender-Planer:  node werkzeuge/passwort-setzen.mjs
// Schreibt nur einen Hash (PBKDF2-SHA256 mit Salz) nach kalender-auth.php – nie das Passwort selbst.
import { pbkdf2Sync, randomBytes } from "node:crypto"
import { writeFileSync } from "node:fs"
import { fileURLToPath } from "node:url"
import { dirname, join } from "node:path"

const ITER = 100000

function ask(question) {
  return new Promise((resolve) => {
    const s = process.stdin
    process.stdout.write(question)
    s.setRawMode(true)
    s.resume()
    s.setEncoding("utf8")
    let value = ""
    const onData = (chunk) => {
      for (const ch of chunk) {
        if (ch === "\r" || ch === "\n") {
          s.setRawMode(false)
          s.pause()
          s.off("data", onData)
          process.stdout.write("\n")
          return resolve(value)
        }
        if (ch === "\u0003") process.exit(1)
        if (ch === "\u007f" || ch === "\b") value = value.slice(0, -1)
        else value += ch
      }
    }
    s.on("data", onData)
  })
}

const pw = await ask("Neues Kalenderpasswort (mind. 12 Zeichen): ")
if (pw.length < 12) {
  console.error("Zu kurz. Bitte mindestens 12 Zeichen verwenden.")
  process.exit(1)
}
if ((await ask("Passwort wiederholen: ")) !== pw) {
  console.error("Die Eingaben stimmen nicht überein. Nichts geändert.")
  process.exit(1)
}

const salt = randomBytes(16).toString("hex")
const hash = pbkdf2Sync(pw, salt, ITER, 32, "sha256").toString("hex")
const target = join(dirname(fileURLToPath(import.meta.url)), "..", "kalender-auth.php")
writeFileSync(
  target,
  `<?php\n// Erzeugt von werkzeuge/passwort-setzen.mjs – enthält nur den Hash, nicht das Passwort.\nreturn ['iter' => ${ITER}, 'salt' => '${salt}', 'hash' => '${hash}'];\n`,
)
console.log("Gespeichert in kalender-auth.php. Das Passwort selbst wurde nirgends abgelegt.")
