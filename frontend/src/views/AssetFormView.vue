<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon, { type IconName } from '../components/AppIcon.vue'
import AuthImage from '../components/AuthImage.vue'
import LoadState from '../components/LoadState.vue'
import { api, ApiError } from '../lib/api'
import { ASSET_STATUS, TYPE_FIELDS } from '../lib/format'
import { notify } from '../lib/toast'
import type { AssetDetail, Lookups } from '../lib/types'
import { Payload } from '../lib/payloadKey'

// Cadastro manual (/assets/new) e edição (/assets/:uuid/edit) de qualquer ativo.
const props = defineProps<{ uuid?: string }>()
const router = useRouter()
const editing = computed(() => !!props.uuid)

const TYPE_ICON: Record<string, IconName> = {
  computer: 'assets', monitor: 'monitor', printer: 'printer', phone: 'phone', tablet: 'tablet', data_collector: 'barcode',
  network_equipment: 'network', peripheral: 'box', pdu: 'bolt', rack: 'box', enclosure: 'box', unmanaged: 'box',
}

const lookups = ref<Lookups | null>(null)
const original = ref<AssetDetail | null>(null)
const nextNumber = ref('')
const loading = ref(true)
const error = ref<string | null>(null)
const saving = ref(false)
const fieldErrors = ref<Record<string, string>>({})

const form = reactive({
  type: '', name: '', status: 'in_use', manufacturer: '', model: '', serial_number: '', inventory_number: '',
  location: '', user: '', comment: '', branch_id: '' as number | '',
})
const attrs = ref<{ key: string; value: string }[]>([])
const originalKeys = ref<string[]>([])
const photo = ref<File | null>(null)
const photoPreview = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  try {
    const [l, n] = await Promise.all([api.lookups(), editing.value ? Promise.resolve({ next: '' }) : api.nextInventoryNumber()])
    lookups.value = l
    nextNumber.value = n.next
    if (props.uuid) {
      const a = await api.asset(props.uuid)
      original.value = a
      Object.assign(form, {
        type: a.type, name: a.name, status: a.status, manufacturer: a.manufacturer ?? '', model: a.model ?? '',
        serial_number: a.serial_number ?? '', inventory_number: a.inventory_number ?? '', location: a.location ?? '',
        user: a.user ?? '', comment: a.comment ?? '', branch_id: a.branch_id ?? '',
      })
      // atributos manuais editáveis (os do agente — memória, BIOS... — ficam só leitura no detalhe)
      attrs.value = Object.entries(a.attributes)
        .filter(([k, v]) => typeof v === 'string' && !AGENT_KEYS.has(k))
        .map(([key, value]) => ({ key, value: String(value) }))
      originalKeys.value = attrs.value.map((a) => a.key)
    } else {
      const fromQuery = new URLSearchParams(location.search).get('type')
      form.type = fromQuery && l.asset_types.some((t) => t.code === fromQuery) ? fromQuery : ''
      const qBranch = Number(new URLSearchParams(location.search).get('branch'))
      if (l.branches.some((b) => b.id === qBranch)) form.branch_id = qBranch
      else if (l.branches.length === 1) form.branch_id = l.branches[0]!.id
    }
    ensureTypeFields()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

const AGENT_KEYS = new Set(['memory_mib', 'swap_mib', 'chassis_type', 'workgroup', 'last_logged_user', 'last_logged_user_at', 'windows_owner',
  'windows_company', 'windows_product_id', 'vm_system', 'bios_version', 'bios_date', 'bios_manufacturer', 'motherboard_model',
  'motherboard_serial', 'asset_tag', 'sku', 'secure_boot'])

/** Ao escolher o tipo, sugere os campos típicos dele (IMEI, IP, polegadas...). */
function ensureTypeFields(): void {
  for (const label of TYPE_FIELDS[form.type] ?? []) {
    const key = Payload.key(label)
    if (!attrs.value.some((a) => Payload.key(a.key) === key)) attrs.value.push({ key: label, value: '' })
  }
}
function chooseType(code: string): void {
  form.type = code
  attrs.value = attrs.value.filter((a) => a.value.trim() !== '')
  ensureTypeFields()
}

function onPhoto(files: FileList | null): void {
  const f = files?.[0]
  if (!f) return
  if (!f.type.startsWith('image/')) { notify('warn', 'A foto precisa ser uma imagem'); return }
  photo.value = f
  if (photoPreview.value) URL.revokeObjectURL(photoPreview.value)
  photoPreview.value = URL.createObjectURL(f)
}
onBeforeUnmount(() => { if (photoPreview.value) URL.revokeObjectURL(photoPreview.value) })

async function save(): Promise<void> {
  saving.value = true
  fieldErrors.value = {}
  const attributes: Record<string, string> = {}
  for (const a of attrs.value) if (a.key.trim()) attributes[a.key.trim()] = a.value.trim()
  // campos removidos na edição: envia vazio para o backend apagar
  for (const k of originalKeys.value) {
    if (!Object.keys(attributes).some((x) => Payload.key(x) === k)) attributes[k] = ''
  }
  const body: Record<string, unknown> = { ...form, branch_id: form.branch_id === '' ? null : form.branch_id, attributes }
  if (!editing.value && !form.inventory_number.trim()) delete body.inventory_number
  if (editing.value) delete body.type
  try {
    const uuid = editing.value ? (await api.updateAsset(props.uuid!, body)).uuid : (await api.createAsset(body)).uuid
    if (photo.value) await api.upload('asset', uuid, photo.value, 'photo')
    notify('ok', editing.value ? 'Ativo atualizado' : 'Ativo cadastrado', editing.value ? undefined : 'Patrimônio gerado automaticamente.')
    await router.push(`/assets/${uuid}`)
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.fields).length) fieldErrors.value = e.fields
    notify('warn', 'Não foi possível salvar', e instanceof Error ? e.message : String(e))
  } finally {
    saving.value = false
  }
}

const willLock = computed(() => editing.value && original.value?.dynamic)
void load()
</script>

<template>
  <div class="page">
    <RouterLink :to="editing ? `/assets/${uuid}` : '/assets'" class="back"><AppIcon name="back" :size="16" /> {{ editing ? 'Voltar ao ativo' : 'Ativos' }}</RouterLink>
    <header class="page-head"><h1>{{ editing ? `Editar ${original?.name ?? 'ativo'}` : 'Novo ativo' }}</h1></header>

    <LoadState :loading="loading" :error="error" :rows="6" @retry="load">
      <form v-if="lookups" class="layout" novalidate @submit.prevent="save">
        <section v-if="!editing" class="panel types">
          <div class="panel-head"><h2>Tipo de ativo</h2></div>
          <div class="type-grid" role="radiogroup" aria-label="Tipo de ativo">
            <button
              v-for="t in lookups.asset_types" :key="t.code" type="button" role="radio" class="type"
              :class="{ on: form.type === t.code }" :aria-checked="form.type === t.code" @click="chooseType(t.code)"
            >
              <AppIcon :name="TYPE_ICON[t.code] ?? 'box'" :size="22" />
              <span>{{ t.label }}</span>
            </button>
          </div>
          <p v-if="fieldErrors.type" class="err pad">{{ fieldErrors.type }}</p>
        </section>

        <div class="cols">
          <section class="panel pad-all">
            <p v-if="willLock" class="lock-note"><AppIcon name="lock" :size="14" /> Ativo do inventário automático: nome, serial, fabricante e modelo editados aqui ficam <strong>travados</strong> — o agente não sobrescreve.</p>
            <div class="form-grid">
              <div class="field full">
                <label for="f-name">Nome / identificação *</label>
                <input id="f-name" v-model="form.name" class="input" required maxlength="255" :aria-invalid="!!fieldErrors.name" placeholder="ex.: IMP-RH-01, TAB-EXPEDICAO-03" />
                <span v-if="fieldErrors.name" class="err">{{ fieldErrors.name }}</span>
              </div>
              <div class="field">
                <label for="f-inv">Patrimônio</label>
                <input id="f-inv" v-model="form.inventory_number" class="input mono" maxlength="255" :placeholder="nextNumber ? `automático: ${nextNumber}` : ''" :aria-invalid="!!fieldErrors.inventory_number" />
                <span v-if="fieldErrors.inventory_number" class="err">{{ fieldErrors.inventory_number }}</span>
                <span v-else-if="!editing" class="hint">Deixe vazio para gerar o próximo número.</span>
              </div>
              <div class="field">
                <label for="f-status">Situação</label>
                <select id="f-status" v-model="form.status" class="input">
                  <option v-for="(label, code) in ASSET_STATUS" :key="code" :value="code">{{ label }}</option>
                </select>
              </div>
              <div class="field">
                <label for="f-man">Fabricante</label>
                <input id="f-man" v-model="form.manufacturer" class="input" list="dl-man" maxlength="255" placeholder="ex.: Epson, Zebra, Samsung" />
                <datalist id="dl-man"><option v-for="m in lookups.manufacturers" :key="m.id" :value="m.name" /></datalist>
              </div>
              <div class="field">
                <label for="f-model">Modelo</label>
                <input id="f-model" v-model="form.model" class="input" maxlength="255" placeholder="ex.: L6270, TC21, Galaxy Tab A9" />
              </div>
              <div class="field">
                <label for="f-serial">Número de série</label>
                <input id="f-serial" v-model="form.serial_number" class="input mono" maxlength="255" />
              </div>
              <div class="field">
                <label for="f-branch">Filial</label>
                <select id="f-branch" v-model="form.branch_id" class="input" :aria-invalid="!!fieldErrors.branch_id">
                  <option value="">— sem filial —</option>
                  <option v-for="b in lookups.branches" :key="b.id" :value="b.id">{{ b.name }}{{ b.kind === 'headquarters' ? ' (matriz)' : '' }}</option>
                </select>
                <span v-if="fieldErrors.branch_id" class="err">{{ fieldErrors.branch_id }}</span>
                <span v-else-if="!lookups.branches.length" class="hint"><RouterLink to="/branches">Cadastre a matriz e as filiais</RouterLink></span>
              </div>
              <div class="field">
                <label for="f-loc">Local (setor / sala)</label>
                <input id="f-loc" v-model="form.location" class="input" list="dl-loc" maxlength="255" placeholder="ex.: RH - 2º andar" />
                <datalist id="dl-loc"><option v-for="l in lookups.locations" :key="l.id" :value="l.name" /></datalist>
              </div>
              <div class="field full">
                <label for="f-user">Usuário responsável</label>
                <input id="f-user" v-model="form.user" class="input" maxlength="255" placeholder="login ou e-mail (ex.: maria.souza)" />
              </div>
              <div class="field full">
                <label for="f-comment">Observações</label>
                <textarea id="f-comment" v-model="form.comment" class="input" maxlength="5000" rows="3" />
              </div>
            </div>
          </section>

          <aside class="side">
            <section class="panel pad-all">
              <h2 class="h">Foto</h2>
              <label class="photo" for="f-photo">
                <img v-if="photoPreview" :src="photoPreview" alt="Pré-visualização da foto" />
                <AuthImage v-else-if="original?.photo_uuid" :uuid="original.photo_uuid" :alt="`Foto de ${original.name}`" />
                <span v-else class="ph"><AppIcon name="camera" :size="28" /><span>Selecionar foto</span></span>
              </label>
              <input id="f-photo" type="file" accept="image/*" capture="environment" class="sr-only" @change="onPhoto(($event.target as HTMLInputElement).files)" />
              <p class="hint">No celular, abre a câmera. JPG/PNG/WebP até 20 MB.</p>
            </section>

            <section class="panel pad-all">
              <h2 class="h">Características</h2>
              <div v-for="(a, i) in attrs" :key="i" class="attr">
                <input v-model="a.key" class="input" :aria-label="`Nome do campo ${i + 1}`" placeholder="campo" />
                <input v-model="a.value" class="input" :aria-label="`Valor de ${a.key || 'campo ' + (i + 1)}`" placeholder="valor" />
                <button class="btn btn-icon" type="button" :aria-label="`Remover ${a.key}`" @click="attrs.splice(i, 1)"><AppIcon name="close" :size="14" /></button>
              </div>
              <button class="btn" type="button" @click="attrs.push({ key: '', value: '' })"><AppIcon name="plus" :size="14" /> Campo</button>
            </section>
          </aside>
        </div>

        <div class="form-actions">
          <RouterLink :to="editing ? `/assets/${uuid}` : '/assets'" class="btn">Cancelar</RouterLink>
          <button class="btn btn-primary" type="submit" :disabled="saving || !form.name.trim() || (!editing && !form.type)">
            {{ saving ? 'Salvando…' : editing ? 'Salvar alterações' : 'Cadastrar ativo' }}
          </button>
        </div>
      </form>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; max-width: 1200px; }
.back { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 13px; width: fit-content; }
.layout { display: flex; flex-direction: column; gap: 16px; }
.type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; padding: 0 20px 20px; }
.type { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 14px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--bg-elev); color: var(--text-2); cursor: pointer; font: inherit; font-size: 13px; text-align: center; min-height: 44px; }
.type:hover { border-color: var(--accent); color: var(--text); }
.type.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-text); font-weight: 600; }
.cols { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.pad-all { padding: 20px; }
.pad { padding: 0 20px 16px; margin: 0; }
.side { display: flex; flex-direction: column; gap: 16px; }
.h { margin-bottom: 12px; }
.photo { display: grid; place-items: center; aspect-ratio: 4 / 3; border: 1px dashed var(--border); border-radius: var(--radius-sm); overflow: hidden; cursor: pointer; background: var(--bg-elev); }
.photo:hover { border-color: var(--accent); }
.photo :deep(img), .photo img { width: 100%; height: 100%; object-fit: contain; }
.ph { display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--text-3); font-size: 13px; }
.hint { font-size: 12px; color: var(--text-3); margin: 8px 0 0; }
.attr { display: grid; grid-template-columns: 1fr 1fr auto; gap: 6px; margin-bottom: 6px; }
.lock-note { display: flex; gap: 6px; align-items: flex-start; font-size: 12.5px; color: var(--text-2); background: var(--accent-soft); border-radius: var(--radius-sm); padding: 8px 10px; margin: 0 0 14px; }
.err { color: var(--warn-text); font-size: 12px; }
@media (max-width: 960px) { .cols { grid-template-columns: 1fr; } }
</style>
