// Démarrage de l'API PHP avec Vite ; un serveur déjà lancé est réutilisé.
import { spawn } from 'node:child_process'
import { get } from 'node:http'
import { fileURLToPath } from 'node:url'

export const apiPort = Number(process.env.CAISSE_API_PORT || 8000)
if (!Number.isInteger(apiPort) || apiPort < 1 || apiPort > 65535) throw new Error('CAISSE_API_PORT doit être un port valide.')
export const apiTarget = `http://127.0.0.1:${apiPort}`
const root = fileURLToPath(new URL('../', import.meta.url))

function probe() {
  return new Promise((resolve) => {
    const req = get(`${apiTarget}/api/index.php?r=public-config`, (res) => {
      let body = ''
      res.setEncoding('utf8')
      res.on('data', (chunk) => { body += chunk })
      res.on('end', () => {
        try { resolve({ status: res.statusCode, valid: res.statusCode === 200 && !!JSON.parse(body).entreprise }) }
        catch { resolve({ status: res.statusCode, valid: false }) }
      })
      res.on('error', () => resolve(null))
    })
    req.setTimeout(1000, () => req.destroy())
    req.on('error', () => resolve(null))
  })
}

export function phpApi() {
  return {
    name: 'caisse-php-api',
    apply: 'serve',
    async configureServer(server) {
      const existing = await probe()
      if (existing) {
        if (!existing.valid) throw new Error(`L'API sur ${apiTarget} répond HTTP ${existing.status}. Vérifiez MySQL dans XAMPP, le fichier .env et les migrations (php bin/migrate.php).`)
        server.config.logger.info(`API PHP disponible : ${apiTarget}`)
        return
      }

      const grouped = process.platform !== 'win32'
      const child = spawn('php', ['-S', `127.0.0.1:${apiPort}`, '-t', root], {
        cwd: root, detached: grouped, stdio: ['ignore', 'inherit', 'inherit'],
        env: { ...process.env, PHP_CLI_SERVER_WORKERS: grouped ? '4' : '1' },
      })
      let failure
      child.on('error', (err) => { failure = err })
      const stop = () => {
        if (!child.pid) return
        try { grouped ? process.kill(-child.pid, 'SIGTERM') : child.kill('SIGTERM') } catch { /* déjà arrêté */ }
      }
      process.once('exit', stop)
      server.httpServer?.once('close', () => { stop(); process.removeListener('exit', stop) })

      try {
        for (let i = 0; i < 40; i++) {
          if (failure) throw new Error(`Impossible de démarrer PHP : ${failure.message}. Vérifiez que php est disponible dans le Terminal.`)
          if (child.exitCode !== null) throw new Error(`Le serveur PHP s'est arrêté (code ${child.exitCode}). Consultez son erreur ci-dessus.`)
          const state = await probe()
          if (state?.valid) { server.config.logger.info(`API PHP démarrée : ${apiTarget}`); return }
          if (state) throw new Error(`L'API PHP répond HTTP ${state.status}. Démarrez MySQL dans XAMPP et vérifiez .env puis php bin/migrate.php.`)
          await new Promise((resolve) => setTimeout(resolve, 100))
        }
        throw new Error(`Le serveur PHP ne répond pas sur ${apiTarget}.`)
      } catch (err) {
        stop(); process.removeListener('exit', stop)
        throw err
      }
    },
  }
}
