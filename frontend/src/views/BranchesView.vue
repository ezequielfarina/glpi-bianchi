<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import BranchMap, { type MapPoint } from '../components/BranchMap.vue'
import BranchFormModal from '../components/forms/BranchFormModal.vue'
import LoadState from '../components/LoadState.vue'
import { api } from '../lib/api'
import { taxId } from '../lib/format'
import { formatAddress } from '../lib/geo'
import { useAsync } from '../lib/useAsync'

const router = useRouter()
const { data, loading, error, reload } = useAsync(async () => {
  const [b, none] = await Promise.all([api.branches(), api.assets({ branch: 'none', perPage: 1 })])
  return { items: b.items, unassigned: none.total }
})
const creating = ref(false)

const items = computed(() => data.value?.items ?? [])
const points = computed<MapPoint[]>(() => items.value
  .filter((b) => b.latitude !== null && b.longitude !== null && b.active)
  .map((b) => ({ id: b.id, name: b.name, code: b.code, kind: b.kind, latitude: b.latitude!, longitude: b.longitude!, subtitle: `${b.assets} ativo(s) · ${[b.city, b.state].filter(Boolean).join('/')}` })))
const withoutCoords = computed(() => items.value.filter((b) => b.active && b.latitude === null).length)
const hasHq = computed(() => items.value.some((b) => b.kind === 'headquarters'))
const totals = computed(() => items.value.reduce((t, b) => ({ assets: t.assets + b.assets, computers: t.computers + b.computers }), { assets: 0, computers: 0 }))
</script>

<template>
  <div class="page">
    <header class="page-head">
      <div>
        <h1>Matriz e filiais</h1>
        <p class="muted">{{ items.length }} unidade(s) · {{ totals.assets }} ativo(s) vinculados</p>
      </div>
      <div class="actions"><button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Nova unidade</button></div>
    </header>

    <LoadState :loading="loading" :error="error" :rows="6" @retry="reload">
      <template v-if="data">
        <div v-if="!items.length" class="panel empty-state">
          <AppIcon name="building" :size="32" />
          <h2>Cadastre a matriz e as filiais</h2>
          <p class="muted">Com as unidades cadastradas você vincula cada ativo ao local onde está, vê tudo no mapa e os computadores com o GLPI Agent podem ser vinculados automaticamente pela rede (IP).</p>
          <button class="edit-only btn btn-primary" type="button" @click="creating = true"><AppIcon name="plus" :size="16" /> Cadastrar a matriz</button>
        </div>

        <template v-else>
          <section class="panel map-panel">
            <BranchMap :points="points" height="420px" @select="(id) => router.push(`/branches/${id}`)" />
            <div class="legend">
              <span><i class="dot hq" /> Matriz</span>
              <span><i class="dot" /> Filial</span>
              <span v-if="withoutCoords" class="warn-text">{{ withoutCoords }} unidade(s) sem localização — edite e marque no mapa.</span>
              <RouterLink v-if="data.unassigned" :to="{ path: '/assets', query: { branch: 'none' } }" class="unassigned">
                <AppIcon name="alert" :size="14" /> {{ data.unassigned }} ativo(s) sem filial
              </RouterLink>
            </div>
          </section>

          <div class="cards">
            <RouterLink v-for="b in items" :key="b.id" :to="`/branches/${b.id}`" class="card panel" :class="{ inactive: !b.active }">
              <div class="c-top">
                <span class="kind" :class="b.kind"><AppIcon :name="b.kind === 'headquarters' ? 'building' : 'pin'" :size="16" /></span>
                <div class="c-title">
                  <strong>{{ b.name }}</strong>
                  <span class="muted small">
                    <span v-if="b.code" class="mono">{{ b.code }}</span>
                    <template v-if="b.code && (b.city || b.tax_id)"> · </template>
                    {{ [b.city, b.state].filter(Boolean).join('/') || taxId(b.tax_id) }}
                  </span>
                </div>
                <span v-if="b.kind === 'headquarters'" class="pill pill-warn">Matriz</span>
                <span v-if="!b.active" class="pill pill-muted">inativa</span>
              </div>
              <p class="addr muted small">{{ formatAddress(b) || 'Endereço não informado' }}</p>
              <div class="stats">
                <div><span class="num-big">{{ b.assets }}</span><span class="muted small">ativos</span></div>
                <div><span class="num-big">{{ b.computers }}</span><span class="muted small">computadores</span></div>
                <div><span class="num-big" :class="{ 'warn-text': b.stale }">{{ b.stale }}</span><span class="muted small">sem inventário 7d</span></div>
              </div>
              <div class="foot muted small">
                <span v-if="b.networks.length" class="mono">{{ b.networks.slice(0, 2).join(', ') }}<template v-if="b.networks.length > 2"> +{{ b.networks.length - 2 }}</template></span>
                <span v-else>sem redes cadastradas</span>
                <span v-if="b.latitude === null" class="warn-text">sem mapa</span>
              </div>
            </RouterLink>
          </div>
        </template>
      </template>
    </LoadState>

    <BranchFormModal v-if="creating" :has-headquarters="hasHq" @close="creating = false" @saved="(id) => router.push(`/branches/${id}`)" />
  </div>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: 20px; max-width: 1480px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.page-head p { margin: 4px 0 0; font-size: 13px; }
.empty-state { padding: 40px 24px; display: flex; flex-direction: column; align-items: center; gap: 10px; text-align: center; color: var(--text-3); }
.empty-state h2 { margin: 6px 0 0; color: var(--text); font-size: 18px; }
.empty-state p { max-width: 560px; margin: 0 0 8px; }
.map-panel { padding: 12px; display: flex; flex-direction: column; gap: 10px; }
.legend { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; font-size: 12.5px; color: var(--text-2); padding: 0 4px; }
.legend > span { display: inline-flex; align-items: center; gap: 6px; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); display: inline-block; }
.dot.hq { background: var(--warn); }
.unassigned { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; color: var(--warn-text); }
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
.card { display: flex; flex-direction: column; gap: 10px; padding: 16px 18px; color: inherit; text-decoration: none; transition: border-color .15s, background .15s; }
.card:hover { border-color: var(--accent); background: var(--panel-hover); }
.card.inactive { opacity: .6; }
.c-top { display: flex; gap: 10px; align-items: flex-start; }
.c-title { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.c-title strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.kind { width: 32px; height: 32px; flex: none; border-radius: 8px; display: grid; place-items: center; background: var(--accent-soft); color: var(--accent-text); }
.kind.headquarters { background: var(--warn-soft); color: var(--warn-text); }
.addr { margin: 0; min-height: 2.6em; }
.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 10px 0; border-top: 1px solid var(--border-soft); border-bottom: 1px solid var(--border-soft); }
.stats > div { display: flex; flex-direction: column; }
.num-big { font-size: 20px; font-weight: 600; font-variant-numeric: tabular-nums; }
.foot { display: flex; justify-content: space-between; gap: 8px; }
.small { font-size: 12px; }
.warn-text { color: var(--warn-text); }
</style>
