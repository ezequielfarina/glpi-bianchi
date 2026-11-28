<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AppModal from '../../components/AppModal.vue'
import LoadState from '../../components/LoadState.vue'
import MacIdentityModal from '../../components/wifi/MacIdentityModal.vue'
import type { IconName } from '../../components/AppIcon.vue'
import { dateOnly, relative } from '../../lib/format'
import { canEdit, isAdmin } from '../../lib/session'
import { notify } from '../../lib/toast'
import { applySummary, KIND_ICON, macUp, STATE, wifi, type Identity, type TreeController, type TreeWlan, type WlanDetail } from '../../lib/wifi'

// Acesso Wi-Fi por MAC (ADR-0020): escolhe a rede → vê quem está liberado e o que está no UniFi sem identificação.
const route = useRoute()
const router = useRouter()
const tree = ref<TreeController[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const selected = ref<number | null>(typeof route.query.wlan === 'string' ? Number(route.query.wlan) : null)
const detail = ref<WlanDetail | null>(null)
const tab = ref<'ok' | 'unknown'>('ok')
const busy = ref<string | null>(null)
const editing = ref<Identity | null | undefined>(undefined) // undefined = fechado, null = novo
const adoptMac = ref<string | null>(null)
const filterOpen = ref(false)
const confirmName = ref('')
const q = ref('')

async function loadTree(): Promise<void> {
  try { tree.value = (await wifi.tree()).items; error.value = null }
  catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
  if (!selected.value) {
    // padrão: a rede (permissão) com mais MACs no filtro — a corporativa, normalmente
    const all = tree.value.flatMap((c) => c.sites.flatMap((s) => s.wlans)).filter((w) => w.filter_policy !== 'deny')
    selected.value = all.sort((a, b) => b.remote_count - a.remote_count)[0]?.id ?? null
  }
}
async function loadWlan(): Promise<void> {
  if (!selected.value) { detail.value = null; return }
  try { detail.value = await wifi.wlan(selected.value) } catch (e) { notify('warn', 'Rede não carregou', e instanceof Error ? e.message : String(e)) }
}
void loadTree()
watch(selected, (id) => { void router.replace({ query: id ? { wlan: String(id) } : {} }); void loadWlan() }, { immediate: true })
async function refresh(): Promise<void> { await Promise.all([loadTree(), loadWlan()]) }

const current = computed<TreeWlan | null>(() => tree.value.flatMap((c) => c.sites.flatMap((s) => s.wlans)).find((w) => w.id === selected.value) ?? null)
const shown = computed(() => {
  const s = q.value.trim().toLowerCase()
  const all = detail.value?.identities ?? []
  return s ? all.filter((i) => [i.mac, i.person_name, i.department, i.registration, i.device_model, i.hostname].some((v) => v?.toLowerCase().includes(s))) : all
})
const stateIn = (i: Identity) => i.wlans.find((w) => w.wlan_id === selected.value)

async function sync(c: TreeController): Promise<void> {
  busy.value = `sync-${c.id}`
  try {
    const r = await wifi.sync(c.id)
    notify(r.drift ? 'warn' : 'ok', `${c.name}: ${r.sites} site(s), ${r.wlans} rede(s)`, r.drift ? `${r.drift} MAC(s) foram removidos direto no UniFi — veja os marcados "Com erro".` : undefined)
    await refresh()
  } catch (e) { notify('warn', 'Sincronização falhou', e instanceof Error ? e.message : String(e)) } finally { busy.value = null }
}
async function apply(): Promise<void> {
  if (!selected.value) return
  busy.value = 'apply'
  try { const s = applySummary([await wifi.apply(selected.value)]); notify(s.tone, s.title, s.detail); await refresh() }
  catch (e) { notify('warn', 'Não aplicado', e instanceof Error ? e.message : String(e)) } finally { busy.value = null }
}
async function revoke(i: Identity): Promise<void> {
  if (!window.confirm(`Revogar o acesso de ${i.person_name ?? macUp(i.mac)} em TODAS as redes? O MAC sai do filtro do UniFi.`)) return
  busy.value = `rev-${i.id}`
  try { const r = await wifi.revoke(i.id, true); const s = applySummary(r.applied); notify(s.tone, 'Acesso revogado — ' + s.title, s.detail); await refresh() }
  catch (e) { notify('warn', 'Não revogado', e instanceof Error ? e.message : String(e)) } finally { busy.value = null }
}
async function enableFilter(): Promise<void> {
  if (!selected.value) return
  busy.value = 'filter'
  try { const r = await wifi.enableFilter(selected.value, confirmName.value); notify('ok', `Filtro de MAC ligado — ${r.total} aparelho(s) liberado(s)`); filterOpen.value = false; await refresh() }
  catch (e) { notify('warn', 'Filtro não ligado', e instanceof Error ? e.message : String(e)) } finally { busy.value = null }
}
async function saved(): Promise<void> { editing.value = undefined; adoptMac.value = null; await refresh() }
const kindIcon = (k: string) => (KIND_ICON[k] ?? 'wifi') as IconName
const totalUnknown = computed(() => tree.value.reduce((n, c) => n + c.sites.reduce((m, s) => m + s.wlans.reduce((x, w) => x + w.unknown, 0), 0), 0))
</script>

<template>
  <div class="dc-page">
    <header class="dc-head">
      <div><h1>Acesso Wi-Fi por MAC</h1>
        <p class="muted">Quem é cada MAC liberado no filtro das redes do UniFi. O painel só acrescenta e retira os MACs que ele cadastrou — o resto do UniFi fica intocado.</p></div>
      <div class="actions">
        <RouterLink to="/wifi/identities" class="btn"><AppIcon name="fingerprint" :size="16" /> Todas as identidades</RouterLink>
        <button v-if="canEdit" class="btn btn-primary" type="button" :disabled="!selected" @click="editing = null"><AppIcon name="plus" :size="16" /> Cadastrar MAC</button>
      </div>
    </header>

    <LoadState :loading="loading" :error="error" :rows="6" @retry="loadTree">
      <section v-if="!tree.length" class="panel empty-state">
        <AppIcon name="wifi" :size="40" />
        <h2>Nenhum controller UniFi</h2>
        <p class="muted">Cadastre o controller (a VM do UniFi Network) para trazer os sites e as redes Wi-Fi.</p>
        <RouterLink v-if="isAdmin" to="/wifi/controllers" class="btn btn-primary">Cadastrar controller</RouterLink>
        <p v-else class="muted">Peça a um administrador.</p>
      </section>

      <div v-else class="wf">
        <aside class="panel tree">
          <div v-for="c in tree" :key="c.id" class="ctl">
            <div class="ctl-head">
              <span class="dot" :class="c.last_error ? 'bad' : c.last_sync_at ? 'ok' : ''" />
              <div class="grow"><b>{{ c.name }}</b>
                <div class="muted dc-small">{{ c.version ? `Network ${c.version} · ` : '' }}{{ c.last_sync_at ? `lido ${relative(c.last_sync_at)}` : 'nunca sincronizado' }}</div></div>
              <button v-if="canEdit" class="btn btn-icon" type="button" :disabled="!!busy" :aria-label="`Sincronizar ${c.name}`" title="Sincronizar sites e redes"
                @click="sync(c)"><AppIcon name="refresh" :size="15" :class="{ spin: busy === `sync-${c.id}` }" /></button>
            </div>
            <p v-if="c.last_error" class="ctl-err">{{ c.last_error }}</p>
            <div v-for="s in c.sites" :key="s.id" class="site">
              <div class="site-name"><AppIcon name="building" :size="13" /> {{ s.name }}<span v-if="s.branch" class="muted"> · {{ s.branch }}</span></div>
              <button v-for="w in s.wlans" :key="w.id" type="button" class="wlan" :class="{ active: w.id === selected }" @click="selected = w.id">
                <AppIcon name="wifi" :size="15" />
                <span class="grow wn">{{ w.name }}</span>
                <span v-if="w.pending" class="cnt warn" :title="`${w.pending} aguardando aplicar`">{{ w.pending }}</span>
                <span v-if="w.errors" class="cnt bad" :title="`${w.errors} com erro`">{{ w.errors }}</span>
                <span class="cnt" :title="`${w.remote_count} MAC(s) no filtro do UniFi`">{{ w.remote_count }}</span>
                <span class="lock" :class="w.filter_policy === 'deny' ? 'deny' : w.filter_enabled ? 'on' : 'off'"
                  :title="w.filter_policy === 'deny' ? 'Filtro em modo bloqueio' : w.filter_enabled ? 'Filtro de MAC ligado' : 'Filtro de MAC desligado'" />
              </button>
              <p v-if="!s.wlans.length" class="muted dc-small pad">Sem redes Wi-Fi.</p>
            </div>
            <p v-if="!c.sites.length" class="muted dc-small pad">Sincronize para trazer os sites.</p>
          </div>
          <p v-if="totalUnknown" class="tree-foot muted dc-small"><AppIcon name="conflict" :size="13" /> {{ totalUnknown }} MAC(s) no UniFi sem identificação</p>
        </aside>

        <section v-if="detail && current" class="panel main">
          <div class="wl-head">
            <div class="wl-title">
              <span class="big-ico" :class="current.filter_policy === 'deny' ? 'deny' : current.filter_enabled ? 'on' : 'off'"><AppIcon name="wifi" :size="22" /></span>
              <div><h2>{{ detail.name }}</h2>
                <p class="muted">{{ detail.site }} · {{ detail.controller }} · {{ detail.security || 'segurança ?' }}{{ detail.enabled ? '' : ' · rede desativada' }}</p></div>
            </div>
            <div class="actions">
              <button v-if="canEdit" class="btn" type="button" :disabled="!!busy || current.filter_policy === 'deny'" @click="apply">
                <AppIcon name="upload" :size="15" :class="{ spin: busy === 'apply' }" /> {{ current.pending || current.errors ? `Aplicar ${current.pending + current.errors} pendência(s)` : 'Conferir e aplicar' }}</button>
              <button v-if="isAdmin && !current.filter_enabled && current.filter_policy !== 'deny'" class="btn btn-danger" type="button" @click="confirmName = ''; filterOpen = true">
                <AppIcon name="lock" :size="15" /> Ligar filtro</button>
            </div>
          </div>

          <div v-if="current.filter_policy === 'deny'" class="banner bad"><AppIcon name="alert" :size="16" /><span>
            Esta rede usa o filtro em modo <b>bloqueio</b> (a lista é de quem NÃO entra). O painel só gerencia listas de permissão — altere no UniFi se quiser usar aqui.</span></div>
          <div v-else-if="!current.filter_enabled" class="banner warn"><AppIcon name="alert" :size="16" /><span>
            O filtro de MAC desta rede está <b>desligado</b> no UniFi: os MACs ficam registrados, mas qualquer aparelho com a senha conecta.
            Cadastre todos os aparelhos legítimos (aba "Sem identificação" ajuda) e então um administrador liga o filtro.</span></div>

          <div class="kpis">
            <div><b>{{ current.synced }}</b><span>identificados no UniFi</span></div>
            <div :class="{ warn: current.pending }"><b>{{ current.pending }}</b><span>aguardando aplicar</span></div>
            <div :class="{ bad: current.errors }"><b>{{ current.errors }}</b><span>com erro</span></div>
            <div :class="{ warn: detail.unknown.length }"><b>{{ detail.unknown.length }}</b><span>sem identificação</span></div>
            <div><b>{{ detail.remote_count }}<small>/512</small></b><span>no filtro do UniFi</span></div>
          </div>

          <div class="tabs dc-tabs">
            <button class="tab" :class="{ active: tab === 'ok' }" type="button" @click="tab = 'ok'">Identificados <span class="count">{{ detail.identities.length }}</span></button>
            <button class="tab" :class="{ active: tab === 'unknown' }" type="button" @click="tab = 'unknown'">Sem identificação <span class="count" :class="{ bad: detail.unknown.length }">{{ detail.unknown.length }}</span></button>
            <input v-if="tab === 'ok'" v-model="q" class="input search tab-search" type="search" placeholder="MAC, pessoa, setor, aparelho…" aria-label="Filtrar" />
          </div>

          <template v-if="tab === 'ok'">
            <p v-if="!shown.length" class="empty muted">{{ q ? 'Nada com esse filtro.' : 'Nenhum MAC cadastrado nesta rede ainda.' }}</p>
            <div v-else class="table-wrap"><table class="table">
              <thead><tr><th>MAC</th><th>Pessoa</th><th>Aparelho</th><th>Validade</th><th>Situação</th><th /></tr></thead>
              <tbody><tr v-for="i in shown" :key="i.id" :class="{ dim: i.status === 'revoked' }">
                <td class="mono dc-nowrap">{{ macUp(i.mac) }}<div class="muted dc-small">{{ i.randomized ? 'aleatório' : i.vendor ?? '' }}</div></td>
                <td><b>{{ i.person_name ?? '—' }}</b><div class="muted dc-small">{{ [i.department, i.registration ? `mat. ${i.registration}` : null].filter(Boolean).join(' · ') }}</div></td>
                <td><span class="dev"><AppIcon :name="kindIcon(i.device_kind)" :size="15" /> {{ [i.device_brand, i.device_model].filter(Boolean).join(' ') || i.hostname || '—' }}</span>
                  <RouterLink v-if="i.asset_uuid" :to="`/assets/${i.asset_uuid}`" class="dc-link dc-small">{{ i.asset_name }}</RouterLink></td>
                <td class="dc-nowrap" :class="{ 'bad-t': i.expired }">{{ i.valid_until ? dateOnly(i.valid_until) : 'sem prazo' }}</td>
                <td><span v-if="stateIn(i)" class="pill" :class="STATE[stateIn(i)!.state].cls" :title="stateIn(i)!.last_error ?? ''">{{ STATE[stateIn(i)!.state].label }}</span>
                  <div v-if="stateIn(i)?.last_error" class="bad-t dc-small err-line">{{ stateIn(i)!.last_error }}</div></td>
                <td class="row-actions">
                  <template v-if="canEdit">
                    <button class="btn btn-icon" type="button" aria-label="Editar" title="Editar" @click="editing = i"><AppIcon name="edit" :size="15" /></button>
                    <button v-if="i.status === 'active'" class="btn btn-icon" type="button" aria-label="Revogar" title="Revogar acesso" :disabled="!!busy" @click="revoke(i)"><AppIcon name="close" :size="15" /></button>
                  </template>
                </td>
              </tr></tbody>
            </table></div>
          </template>
          <template v-else>
            <p class="hint tab-hint">MACs que já estavam no filtro desta rede no UniFi e o painel não conhece. Ficam como estão; identifique para saber de quem é cada um.</p>
            <p v-if="!detail.unknown.length" class="empty muted">Tudo identificado. 👍</p>
            <div v-else class="table-wrap"><table class="table">
              <thead><tr><th>MAC</th><th>Fabricante</th><th>Cadastro</th><th /></tr></thead>
              <tbody><tr v-for="u in detail.unknown" :key="u.mac">
                <td class="mono">{{ macUp(u.mac) }}</td>
                <td>{{ u.randomized ? 'MAC aleatório (celular/notebook com endereço privado)' : u.vendor ?? 'desconhecido' }}</td>
                <td class="muted">{{ u.identity ? `já identificado (${u.identity.person_name ?? 'sem nome'}) em outra rede` : '—' }}</td>
                <td class="row-actions"><button v-if="canEdit" class="btn btn-sm" type="button" @click="adoptMac = u.mac"><AppIcon name="fingerprint" :size="14" /> {{ u.identity ? 'Vincular' : 'Identificar' }}</button></td>
              </tr></tbody>
            </table></div>
          </template>
        </section>
        <section v-else class="panel main empty-state"><AppIcon name="wifi" :size="36" /><p class="muted">Escolha uma rede Wi-Fi à esquerda.</p></section>
      </div>
    </LoadState>

    <MacIdentityModal v-if="editing !== undefined" :identity="editing" :preset-wlan="selected" @close="editing = undefined" @saved="saved" />
    <MacIdentityModal v-if="adoptMac && selected" :adopt-wlan="selected" :adopt-mac="adoptMac" @close="adoptMac = null" @saved="saved" />
    <AppModal v-if="filterOpen && detail" title="Ligar o filtro de MAC" @close="filterOpen = false">
      <div class="confirm">
        <p>A partir de agora <b>só os {{ detail.remote_count }} MAC(s) da lista</b> conectam em <b>{{ detail.name }}</b> ({{ detail.site }}). Quem não estiver na lista cai na hora.</p>
        <p v-if="detail.unknown.length" class="warn-t">{{ detail.unknown.length }} MAC(s) da lista ainda não têm identificação — continuam liberados.</p>
        <p class="muted">Lembrete: filtro de MAC dificulta, mas não impede quem clona MAC. Para rede corporativa o caminho é 802.1X (WPA2/3-Enterprise).</p>
        <label class="field">Digite o nome da rede para confirmar<input v-model="confirmName" class="input mono" :placeholder="detail.name" autocomplete="off" /></label>
        <div class="form-actions"><button class="btn" type="button" @click="filterOpen = false">Cancelar</button>
          <button class="btn btn-danger" type="button" :disabled="confirmName !== detail.name || busy === 'filter'" @click="enableFilter">{{ busy === 'filter' ? 'Ligando…' : 'Ligar filtro' }}</button></div>
      </div>
    </AppModal>
  </div>
</template>

<style scoped>
.wf { display: grid; grid-template-columns: minmax(260px, 320px) 1fr; gap: 16px; align-items: start; }
.tree { padding: 10px; position: sticky; top: 12px; max-height: calc(100vh - 120px); overflow: auto; }
.ctl + .ctl { margin-top: 12px; border-top: 1px solid var(--border); padding-top: 12px; }
.ctl-head { display: flex; align-items: center; gap: 8px; padding: 4px 6px 8px; }
.ctl-err { margin: 0 6px 8px; padding: 6px 8px; border-radius: 8px; background: var(--bad-soft); color: var(--bad-text); font-size: 12px; }
.grow { flex: 1; min-width: 0; }
.dot { width: 9px; height: 9px; border-radius: 50%; background: var(--text-3); flex: none; }
.dot.ok { background: var(--ok); box-shadow: 0 0 8px var(--ok); }
.dot.bad { background: var(--bad); box-shadow: 0 0 8px var(--bad); }
.site { margin: 4px 0 8px; }
.site-name { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--text-2); padding: 4px 6px; text-transform: uppercase; letter-spacing: .03em; }
.wlan { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 1px solid transparent; border-radius: 10px; background: none; color: var(--text); cursor: pointer; text-align: left; font: inherit; }
.wlan:hover { background: var(--panel-hover); }
.wlan.active { background: var(--accent-soft); border-color: color-mix(in srgb, var(--accent) 45%, transparent); color: var(--accent-text); }
.wn { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; font-size: 13px; }
.cnt { font-size: 11px; padding: 0 7px; border-radius: 99px; background: var(--muted-soft); color: var(--text-2); }
.cnt.warn { background: var(--warn-soft); color: var(--warn-text); }
.cnt.bad { background: var(--bad-soft); color: var(--bad-text); }
.lock { width: 8px; height: 8px; border-radius: 2px; flex: none; }
.lock.on { background: var(--ok); }
.lock.off { background: var(--warn); }
.lock.deny { background: var(--bad); }
.pad { padding: 0 6px; }
.tree-foot { display: flex; align-items: center; gap: 6px; margin: 10px 6px 2px; }
.main { padding: 0 0 8px; min-width: 0; }
.wl-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 18px 20px 12px; flex-wrap: wrap; }
.wl-title { display: flex; align-items: center; gap: 14px; }
.wl-title h2 { margin: 0; font-size: 20px; }
.wl-title p { margin: 2px 0 0; }
.big-ico { width: 44px; height: 44px; border-radius: 14px; display: grid; place-items: center; color: #fff; }
.big-ico.on { background: linear-gradient(135deg, var(--ok), color-mix(in srgb, var(--ok) 60%, var(--accent))); box-shadow: 0 6px 18px color-mix(in srgb, var(--ok) 35%, transparent); }
.big-ico.off { background: linear-gradient(135deg, var(--warn), color-mix(in srgb, var(--warn) 60%, var(--bad))); }
.big-ico.deny { background: var(--bad); }
.banner { display: flex; gap: 10px; align-items: flex-start; margin: 0 20px 12px; padding: 10px 12px; border-radius: 12px; font-size: 13px; line-height: 1.45; }
.banner.warn { background: var(--warn-soft); color: var(--warn-text); }
.banner.bad { background: var(--bad-soft); color: var(--bad-text); }
.kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; padding: 0 20px 14px; }
.kpis > div { border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px; display: flex; flex-direction: column; gap: 2px; }
.kpis b { font-size: 20px; }
.kpis small { font-size: 12px; color: var(--text-3); font-weight: 500; }
.kpis span { font-size: 12px; color: var(--text-3); }
.kpis .warn b { color: var(--warn-text); }
.kpis .bad b { color: var(--bad-text); }
.tab-search { margin-left: auto; max-width: 280px; height: 32px; align-self: center; }
.empty { padding: 18px 20px; }
.tab-hint { margin: 12px 20px 0; }
.banner :deep(svg) { flex: none; margin-top: 2px; }
.dev { display: inline-flex; align-items: center; gap: 6px; }
.dim { opacity: .5; }
.bad-t { color: var(--bad-text); }
.warn-t { color: var(--warn-text); }
.err-line { max-width: 260px; }
.row-actions { text-align: right; white-space: nowrap; }
.empty-state { display: grid; justify-items: center; gap: 8px; padding: 48px 20px; text-align: center; color: var(--text-2); }
.empty-state h2 { margin: 6px 0 0; }
.confirm { display: grid; gap: 10px; }
.confirm p { margin: 0; line-height: 1.5; }
.spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 960px) { .wf { grid-template-columns: 1fr; } .tree { position: static; max-height: none; } .kpis { grid-template-columns: repeat(2, 1fr); } }
</style>
