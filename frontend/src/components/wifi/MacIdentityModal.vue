<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import AppModal from '../AppModal.vue'
import AppIcon from '../AppIcon.vue'
import HelpTip from '../HelpTip.vue'
import { api } from '../../lib/api'
import type { BranchRow } from '../../lib/types'
import { notify } from '../../lib/toast'
import { applySummary, filterTree, macCheck, macUp, useOpenSites, wifi, type Identity, type MacLookup, type TreeController, type WifiChoices } from '../../lib/wifi'
import { clean, useForm } from '../forms/useForm'

// Cadastro do MAC com pessoa + aparelho + validade e as redes onde liberar (ADR-0020).
// `adoptWlan`: identificar um MAC que já estava no filtro do UniFi (não regrava a rede).
const props = defineProps<{ identity?: Identity | null; presetWlan?: number | null; adoptWlan?: number | null; adoptMac?: string | null }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const i = props.identity
const adopting = computed(() => !!props.adoptWlan)
const f = reactive({
  mac: i?.mac ?? props.adoptMac ?? '', person_name: i?.person_name ?? '', registration: i?.registration ?? '', department: i?.department ?? '',
  email: i?.email ?? '', phone: i?.phone ?? '', device_kind: i?.device_kind ?? 'phone', device_brand: i?.device_brand ?? '', device_model: i?.device_model ?? '',
  hostname: i?.hostname ?? '', asset_uuid: i?.asset_uuid ?? '', branch_id: i?.branch_id ?? ('' as number | ''), valid_until: i?.valid_until?.slice(0, 10) ?? '',
  notes: i?.notes ?? '',
})
const wlanIds = ref<number[]>(i ? i.wlans.filter((w) => w.state !== 'removing').map((w) => w.wlan_id) : props.presetWlan ? [props.presetWlan] : [])
const applyNow = ref(true)
const ch = ref<WifiChoices | null>(null)
const tree = ref<TreeController[]>([])
const branches = ref<BranchRow[]>([])
void wifi.choices().then((c) => { ch.value = c })
void wifi.tree().then((r) => { tree.value = r.items })
void api.branches().then((r) => { branches.value = r.items })

const check = computed(() => macCheck(f.mac))
const macChanged = computed(() => !i || (check.value.mac ?? '') !== i.mac)
const confirmRandom = ref(false)
const needsRandomConfirm = computed(() => check.value.ok && check.value.random && macChanged.value && !adopting.value)

// seletor de redes: sites recolhíveis + busca (66 redes não cabem como lista solta)
const sites = useOpenSites('bianchi.wifi.modalSites')
const tick = ref(0)
const wq = ref('')
const pickTree = computed(() => filterTree(tree.value, wq.value, false))
const siteOpen = (id: number): boolean => { void tick.value; return !!wq.value || sites.isOpen(id) }
const toggleSite = (id: number) => { sites.toggle(id); tick.value++ }
const selectedIn = (ws: { id: number }[]) => ws.filter((w) => wlanIds.value.includes(w.id)).length
const allWlans = computed(() => tree.value.flatMap((c) => c.sites.flatMap((s) => s.wlans.map((w) => ({ ...w, site: s.name, siteId: s.id })))))
const chosen = computed(() => allWlans.value.filter((w) => wlanIds.value.includes(w.id)))
const full = (w: { id: number; remote_count: number }) => w.remote_count >= 512 && !wlanIds.value.includes(w.id)
watch(tree, () => { for (const w of chosen.value) sites.ensure(w.siteId); tick.value++ })
const blocker = computed(() => {
  if (!check.value.ok) return 'Informe um MAC válido.'
  if (needsRandomConfirm.value && !confirmRandom.value) return 'Confirme o MAC aleatório.'
  if (!adopting.value && !i && !wlanIds.value.length) return 'Escolha ao menos uma rede.'
  return null
})

const info = ref<MacLookup | null>(null)
const assetName = ref(i?.asset_name ?? '')
let t: number | undefined
watch(() => f.mac, (m) => {
  window.clearTimeout(t)
  if (!macCheck(m).ok) { info.value = null; return }
  t = window.setTimeout(async () => {
    try {
      info.value = await wifi.lookup(m)
      if (!f.device_brand && info.value.vendor && !info.value.randomized) f.device_brand = info.value.vendor.split(/[ ,]/)[0] ?? ''
    } catch { info.value = null }
  }, 300)
}, { immediate: true })
// onde o MAC já está liberado no UniFi sem identidade (ou fora deste cadastro)
const mine = computed(() => new Set(i?.wlans.map((w) => w.wlan_id) ?? []))
const loose = computed(() => (info.value?.in_wlans ?? []).filter((w) => !w.identified && !w.deny))
const identifiedElsewhere = computed(() => (info.value?.in_wlans ?? []).filter((w) => w.identified && !mine.value.has(w.id)))
const pendingLoose = computed(() => loose.value.filter((w) => !wlanIds.value.includes(w.id)))
function markLoose(): void {
  for (const w of pendingLoose.value) { if (!wlanIds.value.includes(w.id)) wlanIds.value = [...wlanIds.value, w.id]; sites.ensure(w.site_id) }
  tick.value++
}
const duplicate = computed(() => info.value?.identity && info.value.identity.id !== i?.id ? info.value.identity : null)
function useAsset(a: MacLookup['assets'][number]): void {
  f.asset_uuid = a.asset_uuid
  assetName.value = a.name
  if (!f.branch_id && a.branch_id) f.branch_id = a.branch_id
  if (!f.hostname) f.hostname = a.name
}
const denyWlan = (policy: string | null) => policy === 'deny'
function toggle(id: number): void {
  if (!wlanIds.value.includes(id) && full(allWlans.value.find((w) => w.id === id) ?? { id, remote_count: 0 })) return
  wlanIds.value = wlanIds.value.includes(id) ? wlanIds.value.filter((x) => x !== id) : [...wlanIds.value, id]
}

async function save(): Promise<void> {
  if (blocker.value) { notify('warn', blocker.value); return }
  const body = { ...clean({ ...f }), wlan_ids: adopting.value ? undefined : wlanIds.value, apply: applyNow.value, confirm_random: confirmRandom.value || undefined }
  if (adopting.value) {
    const r = await submit(() => wifi.adopt(props.adoptWlan!, body), 'MAC identificado')
    if (r) emit('saved', r.id)
    return
  }
  const r = await submit(() => wifi.saveIdentity(i?.id ?? null, body), i ? 'Identidade atualizada' : 'MAC cadastrado')
  if (!r) return
  if (r.applied.length) {
    const s = applySummary(r.applied)
    notify(s.tone, s.title, s.detail)
  }
  emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="adopting ? `Identificar ${macUp(f.mac)}` : i ? `Editar ${macUp(i.mac)}` : 'Cadastrar MAC no Wi-Fi'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <fieldset class="full block">
        <legend><AppIcon name="fingerprint" :size="15" /> Aparelho</legend>
        <div class="field"><label for="mi-mac">Endereço MAC *<HelpTip ctx="mac" k="mac" label="MAC" /></label>
          <input id="mi-mac" v-model="f.mac" class="input mono" placeholder="A4:5E:60:12:34:56" autocomplete="off" :disabled="adopting" :aria-invalid="!!errors.mac" />
          <span v-if="errors.mac" class="err">{{ errors.mac }}</span>
          <span v-else-if="f.mac && !check.ok && check.msg" class="err">{{ check.msg }}</span>
          <span v-else-if="duplicate" class="err">Já cadastrado ({{ duplicate.person_name ?? 'sem nome' }}{{ duplicate.status === 'revoked' ? ', revogado — abra esse cadastro para reativar' : '' }}).</span>
          <span v-else-if="info" class="hint">
            <template v-if="info.randomized"><b class="warn-t">MAC aleatório/privado</b> — o aparelho troca por rede: desligue "endereço privado" nesta rede antes de cadastrar.</template>
            <template v-else>{{ info.vendor ?? 'Fabricante desconhecido' }}</template>
          </span></div>
        <div v-if="loose.length" class="full found">
          <div class="found-head"><AppIcon name="conflict" :size="15" />
            <b>Este MAC já está liberado no UniFi sem identificação</b> em {{ loose.length }} rede(s):</div>
          <ul>
            <li v-for="w in loose" :key="w.id">
              <AppIcon name="building" :size="13" /> <b>{{ w.site }}</b> <span class="muted">›</span> <AppIcon name="wifi" :size="13" /> <b>{{ w.name }}</b>
              <span class="muted dc-small">{{ tree.length > 1 ? `· ${w.controller} ` : '' }}{{ w.filter_enabled ? '· filtro ligado' : '· filtro desligado' }}</span>
              <span v-if="!adopting && wlanIds.includes(w.id)" class="pill pill-ok">marcada</span>
            </li>
          </ul>
          <p class="muted dc-small">{{ adopting ? 'Ao salvar, a identidade é ligada a todas essas redes.' : 'Marque essas redes para que o cadastro passe a identificar o MAC nelas — o UniFi não muda, ele já está liberado.' }}</p>
          <button v-if="!adopting && pendingLoose.length" type="button" class="btn btn-sm" @click="markLoose">
            <AppIcon name="check" :size="14" /> Marcar {{ pendingLoose.length === 1 ? 'esta rede' : `estas ${pendingLoose.length} redes` }}</button>
        </div>
        <p v-if="identifiedElsewhere.length" class="full hint">Já identificado em: {{ identifiedElsewhere.map((w) => `${w.site} › ${w.name}`).join(', ') }}.</p>
        <label v-if="needsRandomConfirm" class="check full rnd"><input v-model="confirmRandom" type="checkbox" />
          <span><b>MAC aleatório/privado.</b> No aparelho, desliguei "Endereço privado" (iPhone) / "MAC aleatório" (Android) / "Endereços de hardware aleatórios" (Windows)
          para esta rede e o MAC acima é o que aparece com isso desligado.</span></label>
        <div class="field"><label for="mi-kind">Tipo<HelpTip ctx="mac" k="device_kind" label="Tipo" /></label>
          <select id="mi-kind" v-model="f.device_kind" class="input"><option v-for="o in ch?.device_kinds ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select></div>
        <div class="field"><label for="mi-brand">Marca</label><input id="mi-brand" v-model="f.device_brand" class="input" placeholder="Samsung" /></div>
        <div class="field"><label for="mi-model">Modelo<HelpTip ctx="mac" k="device_model" label="Modelo" /></label><input id="mi-model" v-model="f.device_model" class="input" placeholder="Galaxy S23" /></div>
        <div class="field"><label for="mi-host">Nome na rede<HelpTip ctx="mac" k="hostname" label="Nome na rede" /></label><input id="mi-host" v-model="f.hostname" class="input mono" placeholder="Galaxy-S23-Maria" /></div>
        <div class="field"><label>Patrimônio<HelpTip ctx="mac" k="asset" label="Patrimônio" /></label>
          <div v-if="f.asset_uuid" class="linked"><AppIcon name="pc" :size="15" /> <RouterLink :to="`/assets/${f.asset_uuid}`" target="_blank">{{ assetName || 'ativo' }}</RouterLink>
            <button type="button" class="btn btn-icon" aria-label="Desvincular" @click="f.asset_uuid = ''; assetName = ''"><AppIcon name="close" :size="14" /></button></div>
          <template v-else-if="info?.assets.length">
            <button v-for="a in info.assets" :key="a.asset_uuid" type="button" class="btn btn-sm sugg" @click="useAsset(a)">
              <AppIcon name="plus" :size="13" /> {{ a.name }}<span class="muted"> · {{ a.port }}{{ a.branch ? ` · ${a.branch}` : '' }}</span></button>
          </template>
          <span v-else class="hint">Nenhum ativo do inventário com este MAC.</span>
          <span v-if="errors.asset_uuid" class="err">{{ errors.asset_uuid }}</span></div>
      </fieldset>

      <fieldset class="full block">
        <legend><AppIcon name="user" :size="15" /> Pessoa</legend>
        <div class="field"><label for="mi-person">Nome<HelpTip ctx="mac" k="person_name" label="Nome" /></label>
          <input id="mi-person" v-model="f.person_name" class="input" placeholder="Maria Souza" :aria-invalid="!!errors.person_name" />
          <span v-if="errors.person_name" class="err">{{ errors.person_name }}</span></div>
        <div class="field"><label for="mi-reg">Matrícula<HelpTip ctx="mac" k="registration" label="Matrícula" /></label><input id="mi-reg" v-model="f.registration" class="input mono" placeholder="004512" /></div>
        <div class="field"><label for="mi-dept">Setor<HelpTip ctx="mac" k="department" label="Setor" /></label><input id="mi-dept" v-model="f.department" class="input" placeholder="Financeiro" /></div>
        <div class="field"><label for="mi-branch">Filial<HelpTip k="branch_id" label="Filial" /></label>
          <select id="mi-branch" v-model="f.branch_id" class="input"><option value="">—</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
        <div class="field"><label for="mi-mail">E-mail</label><input id="mi-mail" v-model="f.email" class="input" type="email" placeholder="maria@empresa.com.br" :aria-invalid="!!errors.email" />
          <span v-if="errors.email" class="err">{{ errors.email }}</span></div>
        <div class="field"><label for="mi-phone">Telefone / ramal</label><input id="mi-phone" v-model="f.phone" class="input" placeholder="(11) 9 9999-0000" /></div>
      </fieldset>

      <fieldset v-if="!adopting" class="full block nets">
        <legend><AppIcon name="wifi" :size="15" /> Redes Wi-Fi *<HelpTip ctx="mac" k="wlans" label="Redes" /></legend>
        <p v-if="!tree.length" class="hint full">Nenhum controller sincronizado. Um administrador cadastra em Wi-Fi → Controllers UniFi.</p>
        <template v-else>
          <div class="full chosen">
            <span v-if="!chosen.length" class="muted">Nenhuma rede escolhida.</span>
            <button v-for="w in chosen" :key="w.id" type="button" class="chip" :title="`Tirar ${w.name} (${w.site})`" @click="toggle(w.id)">
              {{ w.name }} <span class="muted">· {{ w.site }}</span> <AppIcon name="close" :size="11" /></button>
          </div>
          <input v-model="wq" class="input full" type="search" placeholder="Buscar rede ou site (ex.: CelGestores, Maringa)…" aria-label="Buscar rede" />
          <div class="full picker">
            <template v-for="c in pickTree" :key="c.id">
              <div v-for="s in c.sites" :key="s.id" class="psite" :class="{ open: siteOpen(s.id) }">
                <button type="button" class="psite-head" :aria-expanded="siteOpen(s.id)" @click="toggleSite(s.id)">
                  <AppIcon name="chevron" :size="13" class="chev" /><AppIcon name="building" :size="13" />
                  <span class="grow">{{ s.name }}</span>
                  <span v-if="selectedIn(s.wlans)" class="cnt sel">{{ selectedIn(s.wlans) }} escolhida(s)</span>
                  <span class="muted dc-small">{{ s.wlans.length }} rede(s)</span>
                </button>
                <div v-show="siteOpen(s.id)" class="pwlans">
                  <label v-for="w in s.wlans" :key="w.id" class="wl" :class="{ on: wlanIds.includes(w.id), off: denyWlan(w.filter_policy) || full(w) }">
                    <input type="checkbox" :checked="wlanIds.includes(w.id)" :disabled="denyWlan(w.filter_policy) || full(w)" @change="toggle(w.id)" />
                    <span class="wn">{{ w.name }}</span>
                    <span v-if="denyWlan(w.filter_policy)" class="pill pill-bad">bloqueio</span>
                    <span v-else-if="full(w)" class="pill pill-bad">cheia (512)</span>
                    <span v-else-if="!w.filter_enabled" class="pill pill-warn" title="O filtro está desligado no UniFi: a lista é gravada mas não barra ninguém">filtro desligado</span>
                    <span v-else class="pill pill-ok" :title="`${w.remote_count} de 512 MACs`">{{ w.remote_count }}/512</span>
                  </label>
                </div>
              </div>
            </template>
            <p v-if="!pickTree.some((c) => c.sites.length)" class="muted pad">Nenhuma rede com "{{ wq }}".</p>
          </div>
        </template>
        <span v-if="errors.wlan_ids" class="err full">{{ errors.wlan_ids }}</span>
      </fieldset>
      <p v-else class="hint full adopt-hint"><AppIcon name="wifi" :size="14" /><span>O MAC já está no UniFi: ao salvar, o cadastro é ligado a <b>todas</b> as redes deste controller
        onde ele aparece — nada é regravado no UniFi.</span></p>

      <div class="field"><label for="mi-valid">Válido até<HelpTip ctx="mac" k="valid_until" label="Validade" /></label>
        <input id="mi-valid" v-model="f.valid_until" class="input" type="date" :aria-invalid="!!errors.valid_until" /><span v-if="errors.valid_until" class="err">{{ errors.valid_until }}</span></div>
      <div class="field"><label for="mi-notes">Observações<HelpTip k="comments" label="Observações" /></label><textarea id="mi-notes" v-model="f.notes" class="input" rows="1" placeholder="Chamado 4521 — notebook de terceiro" /></div>
      <label v-if="!adopting" class="check full"><input v-model="applyNow" type="checkbox" /> Aplicar no UniFi agora (senão vai na próxima sincronização, em até 10 min)</label>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <span v-if="blocker && f.mac" class="muted dc-small why">{{ blocker }}</span>
        <button class="btn btn-primary" type="submit" :disabled="saving || !!duplicate || !!blocker">{{ saving ? (applyNow ? 'Salvando e aplicando…' : 'Salvando…') : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.block { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; border: 1px solid var(--border); border-radius: 14px; padding: 10px 14px 14px; margin: 0;
  background: linear-gradient(135deg, color-mix(in srgb, var(--accent) 6%, transparent), transparent); }
.block legend { display: inline-flex; align-items: center; gap: 6px; padding: 0 6px; font-weight: 600; font-size: 13px; color: var(--text-2); }
.block .full { grid-column: 1 / -1; }
.linked { display: flex; align-items: center; gap: 6px; min-height: 36px; }
.sugg { display: block; margin: 0 0 4px; text-align: left; }
.warn-t { color: var(--warn-text); }
.nets { grid-template-columns: 1fr; }
.chosen { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; min-height: 30px; }
.chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 99px; border: 1px solid color-mix(in srgb, var(--accent) 50%, transparent);
  background: var(--accent-soft); color: var(--accent-text); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.chip:hover { border-color: var(--bad); }
.picker { max-height: 300px; overflow: auto; border: 1px solid var(--border); border-radius: 12px; padding: 4px; }
.psite-head { display: flex; align-items: center; gap: 6px; width: 100%; padding: 8px; border: 0; border-radius: 8px; background: none; color: var(--text); font: inherit; font-weight: 600;
  font-size: 13px; cursor: pointer; text-align: left; }
.psite-head:hover { background: var(--panel-hover); }
.psite .chev { transition: transform .15s ease; }
.psite.open .chev { transform: rotate(90deg); }
.grow { flex: 1; min-width: 0; }
.pwlans { display: flex; flex-wrap: wrap; gap: 6px; padding: 2px 8px 10px 28px; }
.cnt { font-size: 11px; padding: 0 7px; border-radius: 99px; background: var(--muted-soft); color: var(--text-2); }
.cnt.sel { background: var(--accent-soft); color: var(--accent-text); }
.pad { padding: 8px; margin: 0; }
.found { border: 1px solid color-mix(in srgb, var(--warn) 45%, transparent); background: var(--warn-soft); border-radius: 12px; padding: 10px 12px; display: grid; gap: 6px; }
.found-head { display: flex; align-items: center; gap: 6px; color: var(--warn-text); }
.found ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 4px; }
.found li { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; font-size: 13px; }
.found p { margin: 0; }
.found .btn { justify-self: start; }
.rnd { align-items: flex-start; padding: 8px 10px; border-radius: 10px; background: var(--warn-soft); color: var(--warn-text); line-height: 1.45; }
.rnd input { margin-top: 3px; }
.adopt-hint { display: flex; gap: 8px; align-items: flex-start; }
.adopt-hint :deep(svg) { flex: none; margin-top: 2px; }
.why { margin-right: auto; align-self: center; }
.ctrl { display: grid; gap: 10px; }
.site-name { font-size: 12.5px; font-weight: 600; margin: 2px 0 6px; display: flex; align-items: center; gap: 6px; }
.site { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.site-name { flex-basis: 100%; }
.wl { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid var(--border); border-radius: 10px; cursor: pointer; transition: all .15s ease; }
.wl:hover { border-color: var(--accent); }
.wl.on { border-color: var(--accent); background: var(--accent-soft); box-shadow: 0 0 0 1px var(--accent) inset; }
.wl.off { opacity: .55; cursor: not-allowed; }
.wn { font-weight: 600; font-size: 13px; }
.wl .pill { font-size: 10.5px; }
@media (max-width: 720px) { .block { grid-template-columns: 1fr; } }
</style>
