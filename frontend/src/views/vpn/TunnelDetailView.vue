<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../../components/AppIcon.vue'
import AppModal from '../../components/AppModal.vue'
import HelpTip from '../../components/HelpTip.vue'
import LoadState from '../../components/LoadState.vue'
import { clean, useForm } from '../../components/forms/useForm'
import type { IconName } from '../../components/AppIcon.vue'
import { api } from '../../lib/api'
import { circuits, type CircuitRow } from '../../lib/dcim'
import { dateTime, relative } from '../../lib/format'
import { canEdit } from '../../lib/session'
import { notify } from '../../lib/toast'
import { diagCli, TOPOLOGY, TSTATUS, VERDICT, vpn, type Phase2, type TunnelDetail, type TunnelRow } from '../../lib/vpn'

const props = defineProps<{ id: string }>()
const router = useRouter()
const t = ref<TunnelDetail | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)
async function load(): Promise<void> {
  loading.value = true
  try { t.value = await vpn.tunnel(Number(props.id)); error.value = null } catch (e) { error.value = e instanceof Error ? e.message : String(e) } finally { loading.value = false }
}
watch(() => props.id, load, { immediate: true })

// lado a lado: o que cada FortiGate tem em cada parâmetro (diferença em destaque)
const P1_DEF: Record<string, string> = { p1_proposal: 'aes128-sha256 aes256-sha256 aes128-sha1 aes256-sha1 (padrão)', p1_dhgroups: '14 5 (padrão)', p1_keylife: '86400 (padrão)' }
const rows = computed(() => {
  const a = t.value
  if (!a) return []
  const b = a.peer
  const v = (x: TunnelRow | undefined, k: keyof TunnelRow): string => {
    if (!x) return ''
    const val = x[k]
    if (val === null || val === undefined || val === '') return P1_DEF[k as string] ?? '—'
    return String(val)
  }
  const list: [string, keyof TunnelRow, string?][] = [
    ['FortiGate', 'device'], ['Interface WAN', 'local_interface'], ['IP local', 'local_ip'], ['Remote-gw', 'remote_gw'], ['Versão IKE', 'ike_version'],
    ['Modo (IKEv1)', 'ike_mode'], ['Autenticação', 'auth_method'], ['Proposta fase 1', 'p1_proposal'], ['Grupos DH', 'p1_dhgroups'], ['Keylife (s)', 'p1_keylife'],
    ['DPD', 'dpd'], ['NAT-T', 'nat_traversal'], ['IP do túnel', 'tunnel_ip'], ['IP remoto do túnel', 'tunnel_remote_ip'], ['Zona SD-WAN', 'sdwan_zone'],
  ]
  return list.map(([label, k]) => {
    const va = v(a, k)
    const vb = b ? v(b, k) : ''
    const cmpable = !['device', 'local_interface', 'local_ip', 'remote_gw', 'tunnel_ip', 'tunnel_remote_ip', 'sdwan_zone'].includes(k as string)
    // diferença = os dois lados configuram valores distintos; quem negocia ou não é o diagnóstico ao lado
    return { label, a: va, b: vb, diff: !!b && cmpable && va !== vb && va !== '—' && vb !== '—' }
  }).filter((r) => r.a !== '—' || (r.b && r.b !== '—'))
})
const crossIp = computed(() => {
  const a = t.value
  const b = a?.peer
  if (!a || !b) return null
  return { ab: a.remote_gw === b.local_ip, ba: b.remote_gw === a.local_ip }
})
const sel = (p: Phase2, side: 'src' | 'dst') => p[side].join(', ') + (p[`${side}_name`] ? ` (${p[`${side}_name`]})` : '')
const levelIcon: Record<string, IconName> = { ok: 'check', info: 'pulse', warn: 'alert', error: 'alert' }
const cli = computed(() => (t.value ? diagCli(t.value) : ''))
async function copyCli(): Promise<void> {
  try { await navigator.clipboard.writeText(cli.value); notify('ok', 'Comandos copiados') } catch { notify('warn', 'Não foi possível copiar — selecione o texto') }
}

// documentação (os parâmetros técnicos vêm da importação)
const editing = ref(false)
const { saving, errors, submit } = useForm()
const f = reactive({ status: 'active', peer_label: '', supplier_id: '' as number | '', circuit_id: '' as number | '', peer_tunnel_id: '' as number | '',
  local_ip: '', psk_location: '', description: '', comments: '' })
const suppliers = ref<{ id: number; name: string }[]>([])
const circs = ref<CircuitRow[]>([])
const others = ref<TunnelRow[]>([])
async function openEdit(): Promise<void> {
  const x = t.value!
  Object.assign(f, { status: x.status, peer_label: x.peer_label ?? '', supplier_id: x.supplier_id ?? '', circuit_id: x.circuit_id ?? '', peer_tunnel_id: x.peer_tunnel_id ?? '',
    local_ip: x.local_ip ?? '', psk_location: x.psk_location ?? '', description: x.description ?? '', comments: x.comments ?? '' })
  editing.value = true
  const [s, c, o] = await Promise.all([api.suppliers(), circuits.list(), vpn.tunnels()])
  suppliers.value = s.items
  circs.value = c.items
  others.value = o.items.filter((r) => r.device_id !== x.device_id)
}
async function save(): Promise<void> {
  const r = await submit(() => vpn.update(Number(props.id), clean({ ...f })), 'Documentação salva')
  if (r) { editing.value = false; await load() }
}
async function remove(): Promise<void> {
  if (!t.value || !window.confirm(`Excluir a documentação do túnel ${t.value.name}? Se ele ainda existir no FortiGate, volta na próxima importação.`)) return
  await vpn.remove(t.value.id)
  notify('ok', 'Túnel excluído')
  void router.push('/vpn')
}
</script>

<template>
  <div class="dc-page">
    <LoadState :loading="loading && !t" :error="error" :rows="8" @retry="load">
      <template v-if="t">
        <header class="dc-head">
          <div>
            <p class="crumbs"><RouterLink to="/vpn">Túneis VPN</RouterLink> › <RouterLink :to="`/dcim/devices/${t.device_id}`">{{ t.device }}</RouterLink></p>
            <h1 class="mono">{{ t.name }} <span class="pill" :class="TSTATUS[t.status]?.cls">{{ TSTATUS[t.status]?.label }}</span>
              <span v-if="t.compat" class="pill" :class="VERDICT[t.compat.verdict]?.cls">{{ VERDICT[t.compat.verdict]?.label }}</span></h1>
            <p class="muted">{{ TOPOLOGY[t.topology]?.label }} · {{ t.mode === 'policy' ? 'policy-based' : 'route-based (interface)' }}{{ t.vdom ? ` · VDOM ${t.vdom}` : '' }}
              · importado {{ relative(t.imported_at) }}{{ t.description ? ` · ${t.description}` : '' }}</p>
          </div>
          <div class="actions">
            <button v-if="canEdit" class="btn" type="button" @click="openEdit"><AppIcon name="edit" :size="15" /> Documentar</button>
            <button v-if="canEdit" class="btn btn-icon btn-danger" type="button" aria-label="Excluir" @click="remove"><AppIcon name="trash" :size="15" /></button>
          </div>
        </header>
        <div v-if="!t.present" class="banner warn"><AppIcon name="alert" :size="16" /><span>Este túnel <b>não estava</b> na última configuração importada de {{ t.device }}
          ({{ dateTime(t.imported_at) }}). Se foi removido do FortiGate, mude a situação para "Em desativação" ou exclua.</span></div>

        <div class="grid">
          <section class="panel card">
            <h2><AppIcon name="tunnel" :size="16" /> Fase 1 {{ t.peer ? '— os dois lados' : '' }}</h2>
            <div v-if="crossIp && (!crossIp.ab || !crossIp.ba)" class="banner warn sm"><AppIcon name="alert" :size="14" /><span>Os IPs não se cruzam: confira remote-gw × IP local.</span></div>
            <table class="kv">
              <thead v-if="t.peer"><tr><th /><th>Este lado</th><th><RouterLink :to="`/vpn/tunnels/${t.peer.id}`">{{ t.peer.name }}</RouterLink></th></tr></thead>
              <tbody><tr v-for="r in rows" :key="r.label" :class="{ diff: r.diff }"><th>{{ r.label }}</th><td class="mono">{{ r.a }}</td><td v-if="t.peer" class="mono">{{ r.b }}</td></tr></tbody>
            </table>
            <p v-if="t.peer && rows.some((r) => r.diff)" class="hint">Em amarelo: configurado diferente nos dois lados. Se negocia ou não, veja o diagnóstico.</p>
            <p v-if="!t.peer && t.topology === 'site_to_site'" class="hint">O outro lado não está no inventário{{ t.peer_label || t.supplier ? ` (${t.peer_label ?? t.supplier})` : '' }}.
              Se for um FortiGate da empresa, importe a configuração dele: o par é encontrado pelos IPs.</p>
            <div v-if="t.spokes.length" class="spokes"><b>Spokes / remotos apontando para este túnel:</b>
              <RouterLink v-for="s in t.spokes" :key="s.id" :to="`/vpn/tunnels/${s.id}`" class="chip">{{ s.device }} · {{ s.name }}</RouterLink></div>
          </section>

          <section v-if="t.compat" class="panel card">
            <h2><AppIcon name="pulse" :size="16" /> Diagnóstico dos dois lados</h2>
            <ul class="checks"><li v-for="(c, i) in t.compat.checks" :key="i" :class="c.level"><AppIcon :name="levelIcon[c.level] ?? 'pulse'" :size="14" /><span>{{ c.msg }}</span></li></ul>
            <p class="hint">Baseado na configuração importada. Campo omitido no <code>show</code> é tratado como o padrão do FortiOS 7.x — use <code>show full-configuration</code> para conferir.</p>
          </section>

          <section class="panel card wide">
            <h2><AppIcon name="layers" :size="16" /> Fase 2 ({{ t.phase2.length }})</h2>
            <div class="table-wrap"><table class="table">
              <thead><tr><th>Nome</th><th>Rede local</th><th>Rede remota</th><th>Proposta</th><th>PFS / DH</th><th class="num">Keylife</th><th>Auto</th></tr></thead>
              <tbody><tr v-for="p in t.phase2" :key="p.id">
                <td class="mono">{{ p.name }}</td><td class="mono dc-small">{{ sel(p, 'src') }}</td><td class="mono dc-small">{{ sel(p, 'dst') }}</td>
                <td class="mono dc-small">{{ p.proposal ?? 'padrão' }}</td>
                <td class="dc-small">{{ p.pfs === false ? 'sem PFS' : `PFS · DH ${p.dhgroups ?? '14 5 (padrão)'}` }}</td>
                <td class="num dc-small">{{ p.keylife_seconds ?? '43200' }}</td><td>{{ p.auto_negotiate ? 'sim' : '' }}</td>
              </tr></tbody>
            </table></div>
          </section>

          <section class="panel card wide">
            <h2><AppIcon name="network" :size="16" /> Redes que passam pelo túnel</h2>
            <p v-if="!t.networks.length" class="hint">Sem seletores nem rotas.</p>
            <div v-else class="table-wrap"><table class="table">
              <thead><tr><th>Rede</th><th>Papel</th><th>Prefixo no IPAM</th><th>Filial / VLAN</th></tr></thead>
              <tbody><tr v-for="n in t.networks" :key="n.net">
                <td class="mono">{{ n.net }}</td>
                <td class="dc-small"><span v-if="n.local" class="tag">local (fase 2)</span><span v-if="n.remote" class="tag">remota (fase 2)</span><span v-if="n.route" class="tag rt">rota estática</span></td>
                <td><RouterLink v-if="n.prefix" :to="`/ipam/prefixes/${n.prefix.id}`" class="mono">{{ n.prefix.prefix }}</RouterLink>
                  <span v-else-if="n.net === '0.0.0.0/0'" class="muted dc-small">qualquer (route-based: o que manda é a rota)</span>
                  <span v-else class="muted dc-small">não cadastrado</span>
                  <div v-if="n.prefix?.description" class="muted dc-small">{{ n.prefix.description }}</div></td>
                <td class="dc-small">{{ [n.prefix?.branch, n.prefix?.vlan_vid ? `VLAN ${n.prefix.vlan_vid} ${n.prefix.vlan ?? ''}` : null].filter(Boolean).join(' · ') }}</td>
              </tr></tbody>
            </table></div>
          </section>

          <section class="panel card">
            <h2><AppIcon name="file" :size="16" /> Documentação</h2>
            <dl class="doc">
              <dt>Outro lado</dt><dd>{{ t.peer_device ? `${t.peer_device}${t.peer_branch ? ` (${t.peer_branch})` : ''}` : t.peer_label ?? '—' }}</dd>
              <dt>Fornecedor</dt><dd><RouterLink v-if="t.supplier_id" :to="`/suppliers/${t.supplier_id}`">{{ t.supplier }}</RouterLink><template v-else>—</template></dd>
              <dt>Circuito</dt><dd><RouterLink v-if="t.circuit_id" :to="`/circuits/${t.circuit_id}`" class="mono">{{ t.circuit }}</RouterLink><template v-else>—</template></dd>
              <dt>PSK</dt><dd>{{ t.psk_location ?? 'local não informado' }} <span class="muted dc-small">· a chave não fica no sistema</span></dd>
              <dt>Observações</dt><dd class="pre">{{ t.comments ?? '—' }}</dd>
            </dl>
            <details v-if="Object.keys(t.params).length" class="params"><summary>Outros parâmetros da fase 1 ({{ Object.keys(t.params).length }})</summary>
              <table class="kv"><tbody><tr v-for="(v, k) in t.params" :key="k"><th class="mono">{{ k }}</th><td class="mono">{{ v }}</td></tr></tbody></table></details>
          </section>

          <section class="panel card">
            <h2><AppIcon name="terminal" :size="16" /> Troubleshooting no FortiGate
              <button class="btn btn-sm copy" type="button" @click="copyCli"><AppIcon name="copy" :size="13" /> Copiar</button></h2>
            <pre class="cli">{{ cli }}</pre>
          </section>
        </div>
      </template>
    </LoadState>

    <AppModal v-if="editing && t" :title="`Documentar ${t.name}`" wide @close="editing = false">
      <form class="form-grid" novalidate @submit.prevent="save">
        <div class="field"><label for="vt-status">Situação</label>
          <select id="vt-status" v-model="f.status" class="input"><option v-for="(v, k) in TSTATUS" :key="k" :value="k">{{ v.label }}</option></select></div>
        <div class="field"><label for="vt-peer">Quem está do outro lado<HelpTip ctx="vpn" k="peer_label" label="Outro lado" /></label>
          <input id="vt-peer" v-model="f.peer_label" class="input" placeholder="Filial Cascavel / Banco X — cobrança" /></div>
        <div class="field"><label for="vt-sup">Fornecedor / parceiro</label>
          <select id="vt-sup" v-model="f.supplier_id" class="input"><option value="">—</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
        <div class="field"><label for="vt-circ">Circuito (link de saída)</label>
          <select id="vt-circ" v-model="f.circuit_id" class="input"><option value="">—</option><option v-for="c in circs" :key="c.id" :value="c.id">{{ c.cid }} · {{ c.provider }}</option></select></div>
        <div class="field"><label for="vt-pt">Túnel do outro lado (manual)<HelpTip ctx="vpn" k="peer_tunnel_id" label="Par" /></label>
          <select id="vt-pt" v-model="f.peer_tunnel_id" class="input"><option value="">automático pelos IPs</option>
            <option v-for="o in others" :key="o.id" :value="o.id">{{ o.device }} · {{ o.name }}</option></select>
          <span v-if="errors.peer_tunnel_id" class="err">{{ errors.peer_tunnel_id }}</span></div>
        <div class="field"><label for="vt-lip">IP público local<HelpTip ctx="vpn" k="local_ip" label="IP local" /></label>
          <input id="vt-lip" v-model="f.local_ip" class="input mono" placeholder="WAN com DHCP/PPPoE: informe o IP fixo" :aria-invalid="!!errors.local_ip" />
          <span v-if="errors.local_ip" class="err">{{ errors.local_ip }}</span></div>
        <div class="field full"><label for="vt-psk">Onde está a PSK<HelpTip ctx="vpn" k="psk_location" label="PSK" /></label>
          <input id="vt-psk" v-model="f.psk_location" class="input" placeholder="Cofre TI › VPN › Cascavel" autocomplete="off" :aria-invalid="!!errors.psk_location" />
          <span v-if="errors.psk_location" class="err">{{ errors.psk_location }}</span></div>
        <div class="field full"><label for="vt-desc">Descrição</label><input id="vt-desc" v-model="f.description" class="input" placeholder="Interligação Matriz ↔ Cascavel (ERP, voz)" /></div>
        <div class="field full"><label for="vt-com">Observações</label><textarea id="vt-com" v-model="f.comments" class="input" rows="3"
          placeholder="Contato do parceiro, janela de manutenção, histórico de troca de PSK…" /></div>
        <div class="form-actions full"><button class="btn" type="button" @click="editing = false">Cancelar</button>
          <button class="btn btn-primary" type="submit" :disabled="saving">{{ saving ? 'Salvando…' : 'Salvar' }}</button></div>
      </form>
    </AppModal>
  </div>
</template>

<style scoped>
.crumbs { margin: 0 0 4px; font-size: 12.5px; color: var(--text-3); }
.crumbs a { color: var(--text-2); }
h1.mono { font-size: 24px; letter-spacing: -.01em; }
h1 .pill { font-family: var(--font, inherit); vertical-align: 4px; margin-left: 6px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
.wide { grid-column: 1 / -1; }
.card { padding: 16px 18px; min-width: 0; }
.card h2 { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 15px; }
.kv { width: 100%; border-collapse: collapse; font-size: 13px; }
.kv th, .kv td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--border); vertical-align: top; word-break: break-word; }
.kv thead th { font-size: 12px; color: var(--text-3); font-weight: 600; }
.kv tbody th { color: var(--text-3); font-weight: 500; width: 32%; }
.kv tr.diff td { background: var(--warn-soft); color: var(--warn-text); }
.checks { list-style: none; margin: 0 0 10px; padding: 0; display: grid; gap: 6px; }
.checks li { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; line-height: 1.45; padding: 6px 8px; border-radius: 8px; }
.checks li :deep(svg) { flex: none; margin-top: 2px; }
.checks .ok { color: var(--ok); }
.checks .info { color: var(--text-2); }
.checks .warn { background: var(--warn-soft); color: var(--warn-text); }
.checks .error { background: var(--bad-soft); color: var(--bad-text); }
.tag { display: inline-block; margin: 0 4px 2px 0; font-size: 11px; padding: 1px 7px; border-radius: 6px; background: var(--muted-soft); color: var(--text-2); }
.tag.rt { background: var(--accent-soft); color: var(--accent-text); }
.doc { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 0; font-size: 13px; }
.doc dt { color: var(--text-3); }
.doc dd { margin: 0; }
.pre { white-space: pre-wrap; }
.params { margin-top: 12px; font-size: 13px; }
.params summary { cursor: pointer; color: var(--text-2); }
.cli { margin: 0; padding: 12px; border-radius: 10px; background: var(--bg, #0b0f14); border: 1px solid var(--border); font-size: 12px; line-height: 1.5; overflow: auto; max-height: 360px; }
.copy { margin-left: auto; }
.spokes { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 10px; font-size: 13px; }
.chip { padding: 3px 8px; border-radius: 99px; background: var(--accent-soft); color: var(--accent-text); font-size: 12px; }
.banner { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 14px; padding: 10px 12px; border-radius: 12px; font-size: 13px; line-height: 1.45; }
.banner.sm { margin-bottom: 10px; padding: 6px 10px; font-size: 12.5px; }
.banner :deep(svg) { flex: none; margin-top: 2px; }
.banner.warn { background: var(--warn-soft); color: var(--warn-text); }
@media (max-width: 1100px) { .grid { grid-template-columns: 1fr; } }
</style>
