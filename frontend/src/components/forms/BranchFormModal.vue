<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import AppModal from '../AppModal.vue'
import BranchMap from '../BranchMap.vue'
import { api } from '../../lib/api'
import { geocode, googleMapsUrl, lookupCep, parseCoordinates } from '../../lib/geo'
import { notify } from '../../lib/toast'
import type { BranchDetail, BranchKind } from '../../lib/types'
import { clean, useForm } from './useForm'

const props = defineProps<{ branch?: BranchDetail | null; hasHeadquarters?: boolean }>()
const emit = defineEmits<{ close: []; saved: [id: number] }>()
const { saving, errors, submit } = useForm()
const b = props.branch
const f = reactive({
  kind: (b?.kind ?? (props.hasHeadquarters ? 'branch' : 'headquarters')) as BranchKind,
  name: b?.name ?? '', code: b?.code ?? '', tax_id: b?.tax_id ?? '', phone: b?.phone ?? '', email: b?.email ?? '',
  zip_code: b?.zip_code ?? '', street: b?.street ?? '', number: b?.number ?? '', complement: b?.complement ?? '',
  district: b?.district ?? '', city: b?.city ?? '', state: b?.state ?? '',
  networks: (b?.networks ?? []).join('\n'), notes: b?.notes ?? '', active: b?.active ?? true,
})
const point = ref<{ lat: number; lng: number } | null>(b?.latitude != null && b?.longitude != null ? { lat: b.latitude, lng: b.longitude } : null)
const mapsLink = ref('')
const busy = ref<'cep' | 'geo' | null>(null)

const latText = computed({
  get: () => (point.value ? String(point.value.lat) : ''),
  set: (v: string) => setCoord('lat', v),
})
const lngText = computed({
  get: () => (point.value ? String(point.value.lng) : ''),
  set: (v: string) => setCoord('lng', v),
})
function setCoord(which: 'lat' | 'lng', v: string): void {
  const n = Number(v.replace(',', '.'))
  if (v.trim() === '') { point.value = null; return }
  if (Number.isNaN(n)) return
  point.value = { lat: which === 'lat' ? n : point.value?.lat ?? 0, lng: which === 'lng' ? n : point.value?.lng ?? 0 }
}

async function fillCep(): Promise<void> {
  busy.value = 'cep'
  try {
    const a = await lookupCep(f.zip_code)
    if (!a) { notify('warn', 'CEP não encontrado', 'Confira os 8 dígitos ou preencha o endereço manualmente.'); return }
    f.street = a.street || f.street
    f.district = a.district || f.district
    f.city = a.city || f.city
    f.state = a.state || f.state
    if (!f.complement && a.complement) f.complement = a.complement
    if (!point.value) await locate(true)
  } catch {
    notify('warn', 'Consulta de CEP indisponível', 'Sem acesso a viacep.com.br a partir deste navegador.')
  } finally { busy.value = null }
}

async function locate(silent = false): Promise<void> {
  busy.value = 'geo'
  try {
    const p = await geocode({ street: f.street, number: f.number, city: f.city, state: f.state, zip: f.zip_code })
    if (p) {
      point.value = p
      if (!silent) notify('ok', 'Localização encontrada', 'Confira o marcador e arraste se precisar de ajuste fino.')
    } else if (!silent) {
      notify('warn', 'Endereço não localizado no mapa', 'Clique no mapa no ponto certo ou cole um link do Google Maps.')
    }
  } catch {
    if (!silent) notify('warn', 'Serviço de mapa indisponível', 'Sem acesso a nominatim.openstreetmap.org a partir deste navegador.')
  } finally { busy.value = null }
}

function applyLink(): void {
  const p = parseCoordinates(mapsLink.value)
  if (p) { point.value = p; mapsLink.value = ''; return }
  notify('warn', 'Não achei coordenadas no link', /goo\.gl|maps\.app/.test(mapsLink.value)
    ? 'Link curto não traz coordenadas: abra-o no navegador e copie o endereço completo da barra.'
    : 'No Google Maps, clique com o botão direito no local e copie as coordenadas (ex.: -23.561414, -46.655881).')
}

const fullAddress = computed(() => [f.street, f.number, f.district, f.city, f.state].filter(Boolean).join(', '))

async function save(): Promise<void> {
  const body = clean({ ...f, latitude: point.value?.lat ?? null, longitude: point.value?.lng ?? null })
  const r = await submit(() => api.saveBranch(b?.id ?? null, body), b ? 'Filial atualizada' : (f.kind === 'headquarters' ? 'Matriz cadastrada' : 'Filial cadastrada'))
  if (!r) return
  if (r.assigned > 0) notify('ok', `${r.assigned} ativo(s) vinculados pela rede`, 'Ativos sem filial cujo IP está nas redes informadas.')
  emit('saved', r.id)
}
</script>

<template>
  <AppModal :title="b ? `Editar ${b.name}` : 'Nova unidade'" wide @close="emit('close')">
    <form class="form-grid" novalidate @submit.prevent="save">
      <fieldset class="field full kind">
        <legend>Tipo *</legend>
        <label class="seg" :class="{ on: f.kind === 'headquarters' }"><input v-model="f.kind" type="radio" value="headquarters" /> <AppIcon name="building" :size="15" /> Matriz</label>
        <label class="seg" :class="{ on: f.kind === 'branch' }"><input v-model="f.kind" type="radio" value="branch" /> <AppIcon name="pin" :size="15" /> Filial</label>
        <span v-if="errors.kind" class="err">{{ errors.kind }}</span>
      </fieldset>
      <div class="field"><label for="b-name">Nome *</label><input id="b-name" v-model="f.name" class="input" placeholder="ex.: Filial Campinas" :aria-invalid="!!errors.name" /><span v-if="errors.name" class="err">{{ errors.name }}</span></div>
      <div class="field"><label for="b-code">Sigla</label><input id="b-code" v-model="f.code" class="input mono" maxlength="32" placeholder="ex.: MTZ, FIL-CPS" :aria-invalid="!!errors.code" /><span v-if="errors.code" class="err">{{ errors.code }}</span><span v-else class="hint">Aparece no mapa e nas listas.</span></div>
      <div class="field"><label for="b-tax">CNPJ</label><input id="b-tax" v-model="f.tax_id" class="input mono" inputmode="numeric" placeholder="00.000.000/0000-00" :aria-invalid="!!errors.tax_id" /><span v-if="errors.tax_id" class="err">{{ errors.tax_id }}</span></div>
      <div class="field"><label for="b-phone">Telefone</label><input id="b-phone" v-model="f.phone" class="input" type="tel" /></div>
      <div class="field"><label for="b-email">E-mail</label><input id="b-email" v-model="f.email" class="input" type="email" :aria-invalid="!!errors.email" /><span v-if="errors.email" class="err">{{ errors.email }}</span></div>

      <h3 class="full section">Endereço</h3>
      <div class="field">
        <label for="b-cep">CEP</label>
        <div class="inline">
          <input id="b-cep" v-model="f.zip_code" class="input mono" inputmode="numeric" placeholder="00000-000" maxlength="9" :aria-invalid="!!errors.zip_code" @keydown.enter.prevent="fillCep" />
          <button class="btn" type="button" :disabled="busy !== null || f.zip_code.replace(/\D/g, '').length !== 8" @click="fillCep">{{ busy === 'cep' ? 'Buscando…' : 'Buscar' }}</button>
        </div>
        <span v-if="errors.zip_code" class="err">{{ errors.zip_code }}</span>
      </div>
      <div class="field"><label for="b-street">Logradouro</label><input id="b-street" v-model="f.street" class="input" /></div>
      <div class="field"><label for="b-num">Número</label><input id="b-num" v-model="f.number" class="input" /></div>
      <div class="field"><label for="b-comp">Complemento</label><input id="b-comp" v-model="f.complement" class="input" /></div>
      <div class="field"><label for="b-dist">Bairro</label><input id="b-dist" v-model="f.district" class="input" /></div>
      <div class="field city">
        <div><label for="b-city">Cidade</label><input id="b-city" v-model="f.city" class="input" /></div>
        <div><label for="b-uf">UF</label><input id="b-uf" v-model="f.state" class="input mono uf" maxlength="2" :aria-invalid="!!errors.state" /></div>
      </div>
      <span v-if="errors.state" class="err full">{{ errors.state }}</span>

      <h3 class="full section">Localização no mapa</h3>
      <div class="full map-tools">
        <button class="btn" type="button" :disabled="busy !== null || !(f.city || f.zip_code)" @click="locate()"><AppIcon name="search" :size="14" /> {{ busy === 'geo' ? 'Localizando…' : 'Localizar pelo endereço' }}</button>
        <label for="b-link" class="sr-only">Link ou coordenadas do Google Maps</label>
        <input id="b-link" v-model="mapsLink" class="input" placeholder="Cole um link do Google Maps ou “-23.56, -46.65”" @keydown.enter.prevent="applyLink" />
        <button class="btn" type="button" :disabled="!mapsLink.trim()" @click="applyLink">Usar</button>
      </div>
      <div class="full">
        <BranchMap v-model="point" picker height="300px" />
        <p class="hint">Clique no mapa para marcar a unidade; arraste o marcador para ajustar.</p>
      </div>
      <div class="field"><label for="b-lat">Latitude</label><input id="b-lat" v-model.lazy="latText" class="input mono" inputmode="decimal" placeholder="-23.561414" :aria-invalid="!!errors.latitude" /><span v-if="errors.latitude" class="err">{{ errors.latitude }}</span></div>
      <div class="field">
        <label for="b-lng">Longitude</label>
        <input id="b-lng" v-model.lazy="lngText" class="input mono" inputmode="decimal" placeholder="-46.655881" :aria-invalid="!!errors.longitude" />
        <span v-if="errors.longitude" class="err">{{ errors.longitude }}</span>
        <a v-if="point || fullAddress" class="hint link" :href="googleMapsUrl(point?.lat ?? null, point?.lng ?? null, fullAddress)" target="_blank" rel="noopener noreferrer">Conferir no Google Maps ↗</a>
      </div>

      <h3 class="full section">Rede</h3>
      <div class="field full">
        <label for="b-nets">Redes da unidade (CIDR)</label>
        <textarea id="b-nets" v-model="f.networks" class="input mono" rows="3" placeholder="192.168.10.0/24&#10;10.20.5.0/24" :aria-invalid="!!errors.networks" />
        <span v-if="errors.networks" class="err">{{ errors.networks }}</span>
        <span v-else class="hint">Uma por linha. Computadores com o GLPI Agent <strong>sem filial</strong> cujo IP esteja nessas redes são vinculados automaticamente (a rede mais específica vence). Escolha manual nunca é sobrescrita.</span>
      </div>
      <div class="field full"><label for="b-notes">Observações</label><textarea id="b-notes" v-model="f.notes" class="input" rows="2" placeholder="Horário, acesso ao rack, responsável local…" /></div>
      <label class="check full"><input v-model="f.active" type="checkbox" /> Unidade ativa</label>
      <div class="form-actions full">
        <button class="btn" type="button" @click="emit('close')">Cancelar</button>
        <button class="btn btn-primary" type="submit" :disabled="saving || !f.name.trim()">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
      </div>
    </form>
  </AppModal>
</template>

<style scoped>
.kind { border: 0; padding: 0; margin: 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.kind legend { font-size: 12.5px; color: var(--text-2); margin-bottom: 6px; padding: 0; }
.seg { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; color: var(--text-2); }
.seg input { position: absolute; opacity: 0; pointer-events: none; }
.seg.on { border-color: var(--accent); background: var(--accent-soft); color: var(--accent-text); }
.seg:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
.section { margin: 10px 0 -4px; font-size: 13px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: .04em; }
.inline { display: flex; gap: 6px; }
.inline .input { flex: 1; min-width: 0; }
.city { display: grid; grid-template-columns: 1fr 72px; gap: 8px; }
.city > div { display: flex; flex-direction: column; gap: 6px; }
.city label { font-size: 12.5px; color: var(--text-2); }
.uf { text-transform: uppercase; }
.map-tools { display: flex; gap: 8px; flex-wrap: wrap; }
.map-tools .input { flex: 1; min-width: 220px; }
.link { color: var(--accent-text); }
.check { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-2); }
.check input { width: 16px; height: 16px; accent-color: var(--accent); }
</style>
