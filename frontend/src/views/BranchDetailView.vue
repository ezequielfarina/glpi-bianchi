<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AssetPicker from '../components/AssetPicker.vue'
import AttachmentsPanel from '../components/AttachmentsPanel.vue'
import BranchMap, { type MapPoint } from '../components/BranchMap.vue'
import BranchFormModal from '../components/forms/BranchFormModal.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { typeIcon, typeStyle } from '../lib/assetTypes'
import { taxId } from '../lib/format'
import { directionsUrl, formatAddress, googleMapsUrl } from '../lib/geo'
import { notify } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

const props = defineProps<{ id: string }>()
const router = useRouter()
const { data: b, loading, error, reload } = useAsync(() => api.branch(Number(props.id)))
const editing = ref(false)
const adding = ref(false)

const points = computed<MapPoint[]>(() => b.value && b.value.latitude !== null && b.value.longitude !== null
  ? [{ id: b.value.id, name: b.value.name, code: b.value.code, kind: b.value.kind, latitude: b.value.latitude, longitude: b.value.longitude }]
  : [])
const address = computed(() => (b.value ? formatAddress(b.value) : ''))

async function addAsset(uuid: string): Promise<void> {
  if (!b.value) return
  const r = await api.moveToBranch(b.value.id, [uuid])
  notify('ok', r.updated ? `Ativo vinculado a ${b.value.name}` : 'O ativo já estava nesta unidade')
  adding.value = false
  await reload()
}

async function remove(): Promise<void> {
  if (!b.value) return
  const msg = `Excluir "${b.value.name}"?` + (b.value.assets ? `\n\n${b.value.assets} ativo(s) ficarão sem filial (não são excluídos).` : '')
  if (!window.confirm(msg)) return
  await api.deleteBranch(b.value.id)
  notify('ok', 'Unidade excluída')
  await router.push('/branches')
}
</script>

<template>
  <div class="page">
    <RouterLink to="/branches" class="back"><AppIcon name="back" :size="16" /> Matriz e filiais</RouterLink>
    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="b">
        <header class="hero panel">
          <div class="top">
            <div class="ident">
              <span class="kind" :class="b.kind"><AppIcon :name="b.kind === 'headquarters' ? 'building' : 'pin'" :size="22" /></span>
              <div>
                <div class="title-row">
                  <h1>{{ b.name }}</h1>
                  <span v-if="b.code" class="pill mono">{{ b.code }}</span>
                  <span class="pill" :class="b.kind === 'headquarters' ? 'pill-warn' : 'pill-accent'">{{ b.kind === 'headquarters' ? 'Matriz' : 'Filial' }}</span>
                  <span v-if="!b.active" class="pill pill-muted">inativa</span>
                </div>
                <p class="muted">{{ address || 'Endereço não informado' }}</p>
              </div>
            </div>
            <div class="actions">
              <a v-if="b.latitude !== null || address" class="btn" :href="googleMapsUrl(b.latitude, b.longitude, address)" target="_blank" rel="noopener noreferrer"><AppIcon name="map" :size="16" /> Google Maps</a>
              <a v-if="b.latitude !== null && b.longitude !== null" class="btn" :href="directionsUrl(b.latitude, b.longitude)" target="_blank" rel="noopener noreferrer"><AppIcon name="pin" :size="16" /> Rota</a>
              <button class="btn" type="button" @click="editing = true"><AppIcon name="edit" :size="16" /> Editar</button>
              <button class="btn btn-danger" type="button" @click="remove"><AppIcon name="trash" :size="16" /> Excluir</button>
            </div>
          </div>
          <dl class="facts">
            <div><dt>CNPJ</dt><dd class="mono">{{ taxId(b.tax_id) || '—' }}</dd></div>
            <div><dt>Telefone</dt><dd>{{ b.phone ?? '—' }}</dd></div>
            <div><dt>E-mail</dt><dd><a v-if="b.email" :href="`mailto:${b.email}`">{{ b.email }}</a><template v-else>—</template></dd></div>
            <div><dt>Coordenadas</dt><dd class="mono">{{ b.latitude !== null ? `${b.latitude}, ${b.longitude}` : '—' }}</dd></div>
          </dl>
          <p v-if="b.notes" class="notes">{{ b.notes }}</p>
        </header>

        <div class="grid">
          <section class="panel map-panel">
            <BranchMap v-if="points.length" :points="points" height="340px" />
            <div v-else class="no-map">
              <AppIcon name="map" :size="28" />
              <p>Localização ainda não marcada.</p>
              <button class="btn" type="button" @click="editing = true">Marcar no mapa</button>
            </div>
          </section>

          <section class="panel">
            <div class="panel-head">
              <h2>Ativos <span class="muted">({{ b.assets }})</span></h2>
              <div class="head-actions">
                <button class="btn" type="button" @click="adding = !adding"><AppIcon name="plus" :size="14" /> Vincular ativo</button>
                <RouterLink class="btn" :to="{ path: '/assets', query: { branch: String(b.id) } }">Ver lista <AppIcon name="chevron" :size="14" /></RouterLink>
              </div>
            </div>
            <div v-if="adding" class="picker"><AssetPicker id="branch-add-asset" label="Buscar ativo (nome, patrimônio, serial, IP)" @pick="(a) => addAsset(a.uuid)" /></div>
            <ul v-if="b.by_type.length" class="types">
              <li v-for="t in b.by_type" :key="t.type">
                <RouterLink :to="{ path: '/assets', query: { branch: String(b.id), type: t.type } }">
                  <span class="t-label"><span class="type-badge sm" :style="typeStyle(t.type)"><AppIcon :name="typeIcon(t.type)" :size="14" /></span>{{ t.type_label }}</span><strong>{{ t.total }}</strong>
                </RouterLink>
              </li>
            </ul>
            <p v-else class="empty">Nenhum ativo vinculado. Use "Vincular ativo", escolha a filial no cadastro do ativo ou informe as redes da unidade.</p>
            <p v-if="b.stale" class="stale warn-text"><AppIcon name="alert" :size="14" /> {{ b.stale }} computador(es) sem inventário há mais de 7 dias.</p>
            <RouterLink v-if="b.unassigned_assets" class="unassigned" :to="{ path: '/assets', query: { branch: 'none' } }">{{ b.unassigned_assets }} ativo(s) da empresa ainda sem filial →</RouterLink>
          </section>
        </div>

        <section class="panel">
          <div class="panel-head"><h2>Redes</h2></div>
          <div class="nets">
            <code v-for="n in b.networks" :key="n" class="net">{{ n }}</code>
            <p v-if="!b.networks.length" class="muted small">Nenhuma rede cadastrada. Informe as sub-redes da unidade para vincular automaticamente os computadores com o GLPI Agent.</p>
          </div>
        </section>

        <AttachmentsPanel owner-type="branch" :owner="b.id" :items="b.attachments" title="Fotos e documentos (fachada, alvará, planta, rack…)" @changed="reload" />

        <BranchFormModal v-if="editing" :branch="b" @close="editing = false" @saved="editing = false; reload()" />
      </template>
    </LoadState>
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 16px; max-width: 1480px; }
.back { display: inline-flex; align-items: center; gap: 4px; color: var(--text-3); font-size: 13px; width: fit-content; }
.hero { padding: 22px 24px; display: flex; flex-direction: column; gap: 16px; }
.top { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.ident { display: flex; gap: 14px; align-items: flex-start; }
.kind { width: 44px; height: 44px; flex: none; border-radius: 10px; display: grid; place-items: center; background: var(--accent-soft); color: var(--accent-text); }
.kind.headquarters { background: var(--warn-soft); color: var(--warn-text); }
.title-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.title-row h1 { margin: 0; }
.top p { margin: 6px 0 0; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; }
.facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px 24px; margin: 0; }
.facts dt { font-size: 12px; color: var(--text-3); margin-bottom: 3px; }
.facts dd { margin: 0; overflow-wrap: anywhere; }
.notes { margin: 0; color: var(--text-2); font-size: 13px; white-space: pre-line; }
.grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.map-panel { padding: 12px; }
.no-map { height: 340px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: var(--text-3); }
.no-map p { margin: 0; }
.head-actions { display: flex; gap: 6px; }
.picker { padding: 0 20px 12px; }
.types { list-style: none; margin: 0; padding: 0 20px 12px; }
.types a { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid var(--border-soft); color: var(--text); }
.types a:hover { color: var(--accent-text); }
.t-label { display: inline-flex; align-items: center; gap: 10px; }
.type-badge.sm { width: 26px; height: 26px; border-radius: 7px; }
.types a { align-items: center; }
.types strong { font-variant-numeric: tabular-nums; }
.stale { margin: 0 20px 12px; display: flex; align-items: center; gap: 6px; font-size: 13px; }
.unassigned { display: block; margin: 0 20px 16px; font-size: 12.5px; color: var(--text-3); }
.nets { display: flex; gap: 8px; flex-wrap: wrap; padding: 0 20px 18px; }
.net { font-family: var(--mono); font-size: 12.5px; background: var(--bg-elev); border: 1px solid var(--border); border-radius: 6px; padding: 4px 8px; }
.small { font-size: 12.5px; }
.warn-text { color: var(--warn-text); }
@media (max-width: 1100px) { .grid { grid-template-columns: 1fr; } .facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
