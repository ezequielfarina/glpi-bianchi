<script setup lang="ts">
import { reactive, ref } from 'vue'
import AppIcon from '../../components/AppIcon.vue'
import AppModal from '../../components/AppModal.vue'
import HelpTip from '../../components/HelpTip.vue'
import LoadState from '../../components/LoadState.vue'
import { clean, useForm } from '../../components/forms/useForm'
import { ApiError } from '../../lib/api'
import { relative } from '../../lib/format'
import { notify } from '../../lib/toast'
import { wifi, type ControllerRow, type TestResult, type TlsInfo } from '../../lib/wifi'

// Controllers UniFi (ADR-0020) — só administrador: a credencial dá poder sobre o Wi-Fi de todos os sites.
const items = ref<ControllerRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const busy = ref<string | null>(null)
const tests = ref<Record<number, TestResult>>({})
async function load(): Promise<void> {
  try { items.value = (await wifi.controllers()).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
void load()

const open = ref(false)
const editingId = ref<number | null>(null)
const hasSecret = ref(false)
const { saving, errors, submit } = useForm()
const blank = () => ({ name: '', auth_mode: 'local', base_url: 'https://', username: '', secret: '', cloud_host_id: '', verify_tls: false, tls_pin: '', write_client_alias: true, enabled: true })
const f = reactive(blank())
const tls = ref<TlsInfo | null>(null)
function edit(c: ControllerRow | null): void {
  Object.assign(f, blank(), c ? { ...c, secret: '', username: c.username ?? '', cloud_host_id: c.cloud_host_id ?? '', tls_pin: c.tls_pin ?? '' } : {})
  editingId.value = c?.id ?? null
  hasSecret.value = c?.has_secret ?? false
  tls.value = null
  open.value = true
}
async function readCert(): Promise<void> {
  busy.value = 'tls'
  try { tls.value = await wifi.inspectTls(f.base_url) } catch (e) { notify('warn', 'Não leu o certificado', e instanceof Error ? e.message : String(e)) } finally { busy.value = null }
}
async function save(): Promise<void> {
  const body = clean({ ...f })
  if (!f.secret) body.secret = null
  const r = await submit(() => wifi.saveController(editingId.value, body), editingId.value ? 'Controller atualizado' : 'Controller cadastrado')
  if (!r) return
  open.value = false
  await load()
  await test(r.id)
}
async function test(id: number): Promise<void> {
  busy.value = `test-${id}`
  try {
    const r = await wifi.test(id)
    tests.value = { ...tests.value, [id]: r }
    notify(r.ok ? 'ok' : 'warn', r.ok ? `Conectado: ${r.sites} site(s)${r.version ? `, Network ${r.version}` : ''}` : 'Falha na conexão', r.error ?? undefined)
    await load()
  } finally { busy.value = null }
}
async function sync(c: ControllerRow): Promise<void> {
  busy.value = `sync-${c.id}`
  try { const r = await wifi.sync(c.id); notify('ok', `${r.sites} site(s) e ${r.wlans} rede(s) Wi-Fi sincronizados`); await load() }
  catch (e) { notify('warn', 'Sincronização falhou', e instanceof Error ? e.message : String(e)) } finally { busy.value = null }
}
async function remove(c: ControllerRow): Promise<void> {
  if (!window.confirm(`Excluir o controller "${c.name}"? Os sites, redes e vínculos de MAC dele saem do painel (o UniFi não é alterado).`)) return
  try { await wifi.deleteController(c.id); notify('ok', 'Controller excluído'); await load() } catch (e) { notify('warn', 'Não excluído', e instanceof Error ? e.message : String(e)) }
}
// Trocar senha/API key: o backend testa no UniFi antes de gravar
const cred = ref<ControllerRow | null>(null)
const credForm = reactive({ username: '', secret: '', show: false })
const credError = ref<string | null>(null)
function openCred(c: ControllerRow): void {
  cred.value = c
  Object.assign(credForm, { username: c.username ?? '', secret: '', show: false })
  credError.value = null
}
async function saveCred(): Promise<void> {
  if (!cred.value || !credForm.secret) return
  busy.value = 'cred'
  credError.value = null
  try {
    const r = await wifi.updateCredential(cred.value.id, { secret: credForm.secret, username: cred.value.auth_mode === 'local' ? credForm.username : undefined })
    notify('ok', 'Credencial trocada e testada', `${r.sites} site(s) acessíveis${r.version ? ` · Network ${r.version}` : ''}`)
    tests.value = { ...tests.value, [cred.value.id]: r }
    cred.value = null
    await load()
  } catch (e) {
    credError.value = e instanceof ApiError && e.fields.secret ? e.fields.secret : e instanceof Error ? e.message : String(e)
  } finally { busy.value = null; credForm.secret = '' }
}
const PLATFORM: Record<string, string> = { unifi_os: 'UniFi OS', classic: 'Controller clássico' }
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Controllers UniFi</h1><p class="muted">De onde vêm os sites e as redes Wi-Fi. A credencial fica cifrada e nunca volta para a tela.</p></div>
      <div class="actions"><button class="btn btn-primary" type="button" @click="edit(null)"><AppIcon name="plus" :size="16" /> Novo controller</button></div>
    </header>

    <LoadState :loading="loading" :error="error" :rows="3" @retry="load">
      <section v-if="!items.length" class="panel guide">
        <h2><AppIcon name="wifi" :size="18" /> Como ligar o GLPI Bianchi ao UniFi da VM</h2>
        <ol>
          <li>No UniFi Network: <b>Settings → Admins &amp; Users → Create New Admin</b>, marque <b>Restrict to local access only</b>, papel <b>Site Admin</b> (ou Super Admin), sem 2FA. Use um usuário só para a integração.</li>
          <li>Se a VM roda <b>UniFi OS Server</b> (porta 11443), prefira uma API key: <b>Settings → Control Plane → Integrations → Create API Key</b>.</li>
          <li>Libere no firewall o CT (192.168.110.55) → VM do controller na porta do UniFi (8443 clássico / 11443 UniFi OS Server).</li>
          <li>Aqui: <b>Novo controller</b> → <b>Ler certificado</b> (fixa o certificado autoassinado) → <b>Salvar</b> → <b>Sincronizar</b>.</li>
        </ol>
      </section>
      <div v-else class="cards">
        <article v-for="c in items" :key="c.id" class="panel card">
          <div class="c-head">
            <span class="dot" :class="c.last_error ? 'bad' : c.last_sync_at ? 'ok' : ''" />
            <div class="grow"><h3>{{ c.name }}</h3><p class="muted mono dc-small">{{ c.auth_mode === 'cloud' ? `nuvem · ${c.cloud_host_id}` : c.base_url }}</p></div>
            <span v-if="!c.enabled" class="pill pill-muted">desativado</span>
          </div>
          <dl>
            <dt>Acesso</dt><dd>{{ c.auth_label }}{{ c.username ? ` · ${c.username}` : '' }}</dd>
            <dt>Plataforma</dt><dd>{{ c.platform ? PLATFORM[c.platform] : '—' }}{{ c.version ? ` · Network ${c.version}` : '' }}</dd>
            <dt>Certificado</dt><dd>{{ c.auth_mode === 'cloud' || c.verify_tls ? 'validado pela CA' : 'fixado (pin da chave pública)' }}</dd>
            <dt>Conteúdo</dt><dd>{{ c.sites }} site(s) · {{ c.wlans }} rede(s)</dd>
            <dt>Última leitura</dt><dd>{{ relative(c.last_sync_at) }}</dd>
          </dl>
          <p v-if="c.last_error" class="c-err">{{ c.last_error }}</p>
          <p v-else-if="tests[c.id]?.ok" class="c-ok"><AppIcon name="check" :size="14" /> Conexão OK · {{ tests[c.id]!.sites }} site(s)</p>
          <div class="c-actions">
            <button class="btn btn-sm" type="button" :disabled="!!busy" @click="test(c.id)"><AppIcon name="pulse" :size="14" /> {{ busy === `test-${c.id}` ? 'Testando…' : 'Testar' }}</button>
            <button class="btn btn-sm" type="button" :disabled="!!busy || !c.enabled" @click="sync(c)"><AppIcon name="refresh" :size="14" /> {{ busy === `sync-${c.id}` ? 'Sincronizando…' : 'Sincronizar' }}</button>
            <RouterLink to="/wifi" class="btn btn-sm"><AppIcon name="wifi" :size="14" /> Redes</RouterLink>
            <button class="btn btn-sm" type="button" :disabled="!!busy" @click="openCred(c)"><AppIcon name="key" :size="14" /> {{ c.auth_mode === 'local' ? 'Trocar senha' : 'Trocar API key' }}</button>
            <span class="grow" />
            <button class="btn btn-icon" type="button" aria-label="Editar" @click="edit(c)"><AppIcon name="edit" :size="15" /></button>
            <button class="btn btn-icon btn-danger" type="button" aria-label="Excluir" @click="remove(c)"><AppIcon name="trash" :size="15" /></button>
          </div>
        </article>
      </div>
    </LoadState>

    <AppModal v-if="open" :title="editingId ? 'Editar controller UniFi' : 'Novo controller UniFi'" wide @close="open = false">
      <form class="form-grid" novalidate autocomplete="off" @submit.prevent="save">
        <div class="field"><label for="uc-name">Nome *<HelpTip ctx="unifi" k="name" label="Nome" /></label>
          <input id="uc-name" v-model="f.name" class="input" placeholder="UniFi VM Matriz" :aria-invalid="!!errors.name" /><span v-if="errors.name" class="err">{{ errors.name }}</span></div>
        <div class="field"><label for="uc-mode">Forma de acesso<HelpTip ctx="unifi" k="auth_mode" label="Acesso" /></label>
          <select id="uc-mode" v-model="f.auth_mode" class="input"><option value="local">Admin local (usuário e senha)</option><option value="api_key">API key (UniFi OS / OS Server)</option>
            <option value="cloud">Nuvem — Site Manager (experimental)</option></select></div>
        <div v-if="f.auth_mode !== 'cloud'" class="field full"><label for="uc-url">Endereço do controller *<HelpTip ctx="unifi" k="base_url" label="Endereço" /></label>
          <div class="row"><input id="uc-url" v-model="f.base_url" class="input mono" placeholder="https://192.168.10.5:8443" :aria-invalid="!!errors.base_url" />
            <button class="btn" type="button" :disabled="busy === 'tls' || f.base_url.length < 12" @click="readCert"><AppIcon name="lock" :size="15" /> {{ busy === 'tls' ? 'Lendo…' : 'Ler certificado' }}</button></div>
          <span v-if="errors.base_url" class="err">{{ errors.base_url }}</span></div>
        <div v-else class="field full"><label for="uc-host">ID do console *<HelpTip ctx="unifi" k="cloud_host_id" label="ID do console" /></label>
          <input id="uc-host" v-model="f.cloud_host_id" class="input mono" placeholder="6a86c7e5-fd03-4c7c-984f-521f0f76e9ff:1537819997" :aria-invalid="!!errors.cloud_host_id" />
          <span v-if="errors.cloud_host_id" class="err">{{ errors.cloud_host_id }}</span>
          <span v-else class="hint">A API da nuvem só alcança consoles UniFi OS e a escrita por ela ainda é instável. Com o CT na mesma rede da VM, prefira o acesso direto.</span></div>

        <div v-if="tls" class="full cert" :class="{ self: tls.self_signed }">
          <div><b>{{ tls.self_signed ? 'Certificado autoassinado' : 'Certificado' }}</b> · {{ tls.subject }}<span v-if="!tls.self_signed" class="muted"> · emitido por {{ tls.issuer }}</span></div>
          <div class="mono dc-small">SHA-256 {{ tls.sha256 }}</div>
          <div class="muted dc-small">Válido até {{ tls.valid_to.slice(0, 10) }}. Confira a impressão digital no UniFi (ou com <code>openssl s_client</code> na VM) antes de fixar.</div>
          <button class="btn btn-sm btn-primary" type="button" @click="f.tls_pin = tls.pin; f.verify_tls = false">Fixar este certificado</button>
        </div>
        <div v-if="f.auth_mode !== 'cloud'" class="field full"><label class="check"><input v-model="f.verify_tls" type="checkbox" /> Validar pela CA (certificado de autoridade confiável instalada no CT)
          <HelpTip ctx="unifi" k="verify_tls" label="Certificado" /></label>
          <span v-if="!f.verify_tls" class="hint mono">{{ f.tls_pin ? `pin-sha256 ${f.tls_pin}` : 'Nenhum certificado fixado — use "Ler certificado".' }}</span>
          <span v-if="errors.tls_pin" class="err">{{ errors.tls_pin }}</span></div>

        <div v-if="f.auth_mode === 'local'" class="field"><label for="uc-user">Usuário (admin local) *<HelpTip ctx="unifi" k="username" label="Usuário" /></label>
          <input id="uc-user" v-model="f.username" class="input" autocomplete="off" data-lpignore="true" placeholder="glpi-bianchi" :aria-invalid="!!errors.username" /><span v-if="errors.username" class="err">{{ errors.username }}</span></div>
        <div class="field" :class="{ full: f.auth_mode !== 'local' }"><label for="uc-secret">{{ f.auth_mode === 'local' ? 'Senha' : 'API key' }}{{ hasSecret ? '' : ' *' }}<HelpTip ctx="unifi" k="secret" label="Credencial" /></label>
          <input id="uc-secret" v-model="f.secret" class="input" type="password" autocomplete="new-password" readonly data-lpignore="true" data-1p-ignore
            @focus="($event.target as HTMLInputElement).removeAttribute('readonly')" :placeholder="hasSecret ? '•••••• (mantida — preencha só para trocar)' : ''" :aria-invalid="!!errors.secret" />
          <span v-if="errors.secret" class="err">{{ errors.secret }}</span></div>
        <label class="check full"><input v-model="f.write_client_alias" type="checkbox" /> Gravar "Pessoa — Aparelho" como nome do cliente no UniFi<HelpTip ctx="unifi" k="write_client_alias" label="Apelido" /></label>
        <label class="check full"><input v-model="f.enabled" type="checkbox" /> Ativo (sincroniza a cada 10 minutos)</label>
        <div class="form-actions full">
          <button class="btn" type="button" @click="open = false">Cancelar</button>
          <button class="btn btn-primary" type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar e testar' }}</button>
        </div>
      </form>
    </AppModal>
    <AppModal v-if="cred" :title="`${cred.auth_mode === 'local' ? 'Trocar senha' : 'Trocar API key'} — ${cred.name}`" @close="cred = null">
      <form class="cred" autocomplete="off" @submit.prevent="saveCred">
        <p class="muted">Troque primeiro no UniFi (Admins &amp; Users → o usuário da integração → Change password) e depois informe aqui.
          A credencial nova é <b>testada no controller antes de ser salva</b>; se o UniFi recusar, a atual continua valendo.</p>
        <div v-if="cred.auth_mode === 'local'" class="field"><label for="cr-user">Usuário</label>
          <input id="cr-user" v-model="credForm.username" class="input" autocomplete="off" /></div>
        <div class="field"><label for="cr-secret">{{ cred.auth_mode === 'local' ? 'Senha nova' : 'API key nova' }}</label>
          <div class="row"><input id="cr-secret" v-model="credForm.secret" class="input" :type="credForm.show ? 'text' : 'password'" autocomplete="new-password" readonly data-lpignore="true" data-1p-ignore
              @focus="($event.target as HTMLInputElement).removeAttribute('readonly')" :aria-invalid="!!credError" />
            <button class="btn btn-icon" type="button" :aria-label="credForm.show ? 'Ocultar' : 'Mostrar'" @click="credForm.show = !credForm.show"><AppIcon name="eye" :size="15" /></button></div>
          <span v-if="credError" class="err">{{ credError }}</span></div>
        <div class="form-actions"><button class="btn" type="button" @click="cred = null">Cancelar</button>
          <button class="btn btn-primary" type="submit" :disabled="!credForm.secret || busy === 'cred'">{{ busy === 'cred' ? 'Testando no UniFi…' : 'Testar e salvar' }}</button></div>
      </form>
    </AppModal>
  </div>
</template>

<style scoped>
.guide { padding: 20px 24px; }
.guide h2 { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 17px; }
.guide ol { margin: 0; padding-left: 20px; display: grid; gap: 8px; line-height: 1.5; }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 16px; }
.card { padding: 16px 18px; display: grid; gap: 10px; }
.c-head { display: flex; align-items: center; gap: 10px; }
.c-head h3 { margin: 0; font-size: 16px; }
.c-head p { margin: 2px 0 0; }
.grow { flex: 1; min-width: 0; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--text-3); }
.dot.ok { background: var(--ok); box-shadow: 0 0 10px var(--ok); }
.dot.bad { background: var(--bad); box-shadow: 0 0 10px var(--bad); }
dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 12px; margin: 0; font-size: 13px; }
dt { color: var(--text-3); }
dd { margin: 0; }
.c-err { margin: 0; padding: 8px 10px; border-radius: 10px; background: var(--bad-soft); color: var(--bad-text); font-size: 12.5px; }
.c-ok { margin: 0; display: flex; align-items: center; gap: 6px; color: var(--ok); font-size: 12.5px; }
.c-actions { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.row { display: flex; gap: 8px; }
.row .input { flex: 1; }
.cert { display: grid; gap: 4px; padding: 10px 12px; border-radius: 12px; border: 1px solid var(--border); background: var(--accent-soft); font-size: 13px; }
.cert.self { background: var(--warn-soft); }
.cert .btn { justify-self: start; margin-top: 4px; }
.cred { display: grid; gap: 12px; }
.cred p { margin: 0; line-height: 1.5; }
</style>
