<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'
import { dcimChoices, type DcimChoices } from '../lib/dcim'
import { lookupSource, lookupState, removeLookup, saveLookup, type LookupItem, type LookupSource } from '../lib/lookups'
import { iconOf, iconUrl } from '../lib/netIcons'
import { canEdit } from '../lib/session'

// Campo de lista editável (ADR-0018): abre logo abaixo do campo, filtra ao digitar e, para quem edita,
// cadastra, renomeia e exclui itens ali mesmo. `by="name"` grava o texto (ex.: sistema operacional da VM),
// `by="id"` grava o id (função, plataforma do cluster…).
const props = withDefaults(defineProps<{
  source: LookupSource
  modelValue: number | string | null | undefined
  by?: 'id' | 'name'
  inputId?: string
  placeholder?: string
  invalid?: boolean
  required?: boolean
  /** aceita texto fora da lista (só com by="name") */
  free?: boolean
}>(), { by: 'id', inputId: undefined, placeholder: 'Selecione…', invalid: false, required: false, free: false })
const emit = defineEmits<{ 'update:modelValue': [v: number | string | ''] }>()

const src = computed(() => lookupSource(props.source))
const st = computed(() => lookupState(props.source))
const ctl = ref<HTMLElement | null>(null)
const inp = ref<HTMLInputElement | null>(null)
const pop = ref<HTMLElement | null>(null)
const open = ref(false)
const query = ref('')
const typed = ref(false)
const active = ref(0)
const pos = ref<Record<string, string>>({})
const editing = ref<{ id: number | null; name: string; color: string; icon: string } | null>(null)
const confirmDel = ref<LookupItem | null>(null)
const err = ref<string | null>(null)
const busy = ref(false)
const choices = ref<DcimChoices | null>(null)
if (lookupSource(props.source).extra === 'role') void dcimChoices().then((c) => { choices.value = c })

const selected = computed(() => st.value.items.find((i) => (props.by === 'id' ? i.id === Number(props.modelValue) : i.name === props.modelValue)) ?? null)
const display = computed(() => (props.by === 'name' ? String(props.modelValue ?? '') : selected.value?.name ?? ''))
const norm = (s: string) => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()
const filtered = computed(() => {
  const q = norm(query.value.trim())
  return !typed.value || !q ? st.value.items : st.value.items.filter((i) => norm(i.name).includes(q))
})
const exact = computed(() => st.value.items.some((i) => norm(i.name) === norm(query.value.trim())))
const canCreate = computed(() => canEdit.value && typed.value && query.value.trim().length > 0 && !exact.value)
const nounNew = computed(() => `${src.value.feminine ? 'Nova' : 'Novo'} ${src.value.noun}`)

function place(): void {
  const r = ctl.value?.getBoundingClientRect()
  if (!r) return
  const below = window.innerHeight - r.bottom
  const up = below < 280 && r.top > below
  pos.value = { left: `${r.left}px`, width: `${Math.max(r.width, 260)}px`, ...(up ? { bottom: `${window.innerHeight - r.top + 6}px`, maxHeight: `${Math.min(380, r.top - 16)}px` }
    : { top: `${r.bottom + 6}px`, maxHeight: `${Math.min(380, below - 16)}px` }) }
}
function show(): void {
  if (open.value) return
  open.value = true
  typed.value = false
  query.value = props.by === 'name' ? display.value : ''
  active.value = Math.max(0, filtered.value.findIndex((i) => i.id === selected.value?.id))
  place()
  window.addEventListener('scroll', place, true)
  window.addEventListener('resize', place)
  document.addEventListener('pointerdown', outside, true)
  void nextTick(() => { scrollActive(); inp.value?.select() })
}
function hide(): void {
  if (!open.value) return
  open.value = false
  editing.value = null; confirmDel.value = null; err.value = null
  window.removeEventListener('scroll', place, true)
  window.removeEventListener('resize', place)
  document.removeEventListener('pointerdown', outside, true)
  if (props.by === 'name' && props.free && typed.value) emit('update:modelValue', query.value.trim())
}
function outside(e: PointerEvent): void {
  const t = e.target as Node
  if (!ctl.value?.contains(t) && !pop.value?.contains(t)) hide()
}
function pick(i: LookupItem): void {
  emit('update:modelValue', props.by === 'id' ? i.id : i.name)
  typed.value = false
  hide()
  inp.value?.focus()
}
function clear(): void {
  emit('update:modelValue', '')
  query.value = ''
  inp.value?.focus()
}
function onInput(e: Event): void {
  query.value = (e.target as HTMLInputElement).value
  typed.value = true
  active.value = 0
  if (!open.value) { show(); typed.value = true }
  if (props.by === 'name' && props.free) emit('update:modelValue', query.value)
}
function scrollActive(): void {
  pop.value?.querySelector<HTMLElement>('[data-active="true"]')?.scrollIntoView({ block: 'nearest' })
}
function onKey(e: KeyboardEvent): void {
  if (editing.value || confirmDel.value) return
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    if (!open.value) return show()
    const n = filtered.value.length
    if (n) active.value = (active.value + (e.key === 'ArrowDown' ? 1 : n - 1)) % n
    void nextTick(scrollActive)
  } else if (e.key === 'Enter' && open.value) {
    e.preventDefault()
    const i = filtered.value[active.value]
    if (i && (!typed.value || filtered.value.length)) pick(i)
    else if (canCreate.value) void create(query.value.trim())
  } else if (e.key === 'Escape' && open.value) {
    e.stopPropagation()
    hide()
  } else if (e.key === 'Tab') hide()
}

async function create(name: string): Promise<void> {
  if (src.value.extra === 'role') { startEdit(null, name); return }
  await run(async () => {
    const id = await saveLookup(props.source, null, { name })
    const it = st.value.items.find((x) => x.id === id)
    if (it) pick(it)
  })
}
function startEdit(i: LookupItem | null, name = ''): void {
  confirmDel.value = null; err.value = null
  editing.value = { id: i?.id ?? null, name: i?.name ?? name, color: i?.color ?? '607d8b', icon: i?.icon ?? '' }
  void nextTick(() => pop.value?.querySelector<HTMLInputElement>('.ed input')?.focus())
}
function cancelEdit(): void {
  editing.value = null
  inp.value?.focus()
}
async function saveEdit(): Promise<void> {
  const e = editing.value
  if (!e || !e.name.trim()) return
  await run(async () => {
    const body: Record<string, unknown> = { name: e.name.trim() }
    if (src.value.extra === 'role') Object.assign(body, { color: e.color.replace('#', ''), icon: e.icon || null })
    const id = await saveLookup(props.source, e.id, body)
    editing.value = null
    inp.value?.focus()
    if (e.id === null) { const it = st.value.items.find((x) => x.id === id); if (it) pick(it) }
    else if (props.by === 'name' && selected.value === null && props.modelValue && st.value.items.find((x) => x.id === id)) emit('update:modelValue', e.name.trim())
  })
}
async function doDelete(i: LookupItem): Promise<void> {
  await run(async () => {
    await removeLookup(props.source, i.id)
    confirmDel.value = null
    inp.value?.focus()
    if (selected.value?.id === i.id || (props.by === 'id' && Number(props.modelValue) === i.id)) emit('update:modelValue', '')
  })
}
async function run(fn: () => Promise<void>): Promise<void> {
  busy.value = true; err.value = null
  try { await fn() } catch (e) { err.value = e instanceof Error ? e.message : String(e) } finally { busy.value = false }
}
const roleIcon = (i: { color?: string | null; icon?: string | null; name: string }) => iconUrl(iconOf('device', i.icon, i.name), `#${i.color ?? '64748b'}`, 18)
watch(() => props.modelValue, () => { if (!open.value) query.value = '' })
onBeforeUnmount(hide)
</script>

<template>
  <div ref="ctl" class="lk" :class="{ open, invalid }" @click="show(); inp?.focus()">
    <img v-if="src.extra === 'role' && selected" :src="roleIcon(selected)" class="lk-ico" alt="" />
    <input :id="inputId" ref="inp" class="lk-in" :value="open ? query : display" :placeholder="open && by === 'id' ? display || placeholder : placeholder" autocomplete="off"
      role="combobox" :aria-expanded="open" :aria-controls="`${inputId ?? source}-list`" aria-autocomplete="list" :aria-invalid="invalid"
      @focus="show" @input="onInput" @keydown="onKey" />
    <button v-if="!required && (by === 'id' ? selected : modelValue)" type="button" class="lk-x" aria-label="Limpar" tabindex="-1" @click.stop="clear"><AppIcon name="close" :size="13" /></button>
    <AppIcon name="chevron" :size="15" class="lk-chev" />
  </div>
  <Teleport to="body">
    <Transition name="lk">
      <div v-if="open" :id="`${inputId ?? source}-list`" ref="pop" class="lk-pop" :style="pos" role="listbox" @mousedown.prevent>
        <div class="lk-list">
          <p v-if="!st.loaded && !st.error" class="lk-empty">Carregando…</p>
          <p v-else-if="st.error" class="lk-empty lk-err">{{ st.error }}</p>
          <p v-else-if="!filtered.length && !canCreate" class="lk-empty">Nada na lista{{ query ? ` com “${query}”` : '' }}.</p>
          <template v-for="(i, k) in filtered" :key="i.id">
            <div v-if="editing && editing.id === i.id" class="ed" @mousedown.stop>
              <input v-model="editing.name" class="input" :aria-label="`Novo nome de ${i.name}`" @keydown.enter.prevent="saveEdit" @keydown.esc.stop.prevent="cancelEdit" />
              <template v-if="src.extra === 'role'">
                <input type="color" class="sw" :value="`#${editing.color.replace('#', '')}`" aria-label="Cor" @input="editing.color = ($event.target as HTMLInputElement).value.slice(1)" />
                <select v-model="editing.icon" class="input" aria-label="Desenho no mapa"><option value="">desenho automático</option><option v-for="o in choices?.role_icons ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select>
              </template>
              <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="saveEdit">Salvar</button>
              <button type="button" class="btn btn-sm" @click="cancelEdit">Cancelar</button>
            </div>
            <div v-else-if="confirmDel?.id === i.id" class="del">
              <span>Excluir <b>{{ i.name }}</b> da lista?</span>
              <button type="button" class="btn btn-danger btn-sm" :disabled="busy" @click.stop="doDelete(i)">Excluir</button>
              <button type="button" class="btn btn-sm" @click.stop="confirmDel = null; inp?.focus()">Cancelar</button>
            </div>
            <div v-else class="opt" role="option" :aria-selected="selected?.id === i.id" :data-active="k === active" :class="{ on: k === active, sel: selected?.id === i.id }"
              @mouseenter="active = k" @click="pick(i)">
              <img v-if="src.extra === 'role'" :src="roleIcon(i)" class="lk-ico" alt="" />
              <span v-else-if="i.color" class="dot" :style="{ background: `#${i.color}` }" />
              <span class="nm">{{ i.name }}</span>
              <span v-if="i.uses" class="uses">{{ src.usesLabel(i.uses) }}</span>
              <AppIcon v-if="selected?.id === i.id" name="check" :size="15" class="ok" />
              <span v-if="canEdit" class="acts">
                <button type="button" class="ib" :aria-label="`Editar ${i.name}`" title="Editar" @click.stop="startEdit(i)"><AppIcon name="edit" :size="13" /></button>
                <button type="button" class="ib" :aria-label="`Excluir ${i.name}`" :title="i.uses ? `Em uso (${src.usesLabel(i.uses)}) — não pode excluir` : 'Excluir'" :disabled="i.uses > 0"
                  @click.stop="confirmDel = i; editing = null; err = null"><AppIcon name="trash" :size="13" /></button>
              </span>
            </div>
          </template>
          <div v-if="editing && editing.id === null" class="ed" @mousedown.stop>
            <input v-model="editing.name" class="input" :placeholder="nounNew" @keydown.enter.prevent="saveEdit" @keydown.esc.stop.prevent="cancelEdit" />
            <template v-if="src.extra === 'role'">
              <input type="color" class="sw" :value="`#${editing.color.replace('#', '')}`" aria-label="Cor" @input="editing.color = ($event.target as HTMLInputElement).value.slice(1)" />
              <select v-model="editing.icon" class="input" aria-label="Desenho no mapa"><option value="">desenho automático</option><option v-for="o in choices?.role_icons ?? []" :key="o.value" :value="o.value">{{ o.label }}</option></select>
            </template>
            <button type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="saveEdit">Cadastrar</button>
            <button type="button" class="btn btn-sm" @click="cancelEdit">Cancelar</button>
          </div>
        </div>
        <p v-if="err" class="lk-err lk-msg" role="alert">{{ err }}</p>
        <div v-if="canEdit" class="lk-foot">
          <button v-if="canCreate" type="button" class="add" :disabled="busy" @click="create(query.trim())"><AppIcon name="plus" :size="14" /> Cadastrar “<b>{{ query.trim() }}</b>”</button>
          <button v-else type="button" class="add" @click="startEdit(null)"><AppIcon name="plus" :size="14" /> {{ nounNew }}</button>
          <span class="tip">✎ renomear · 🗑 excluir (só sem uso)</span>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.lk { position: relative; display: flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 10px 0 12px; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--bg-elev); cursor: text; transition: border-color .15s, box-shadow .15s; }
.lk:hover { border-color: color-mix(in srgb, var(--accent) 40%, var(--border)); }
.lk.open, .lk:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
.lk.invalid { border-color: var(--bad); }
.lk-in { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--text); font: inherit; padding: 9px 0; }
.lk-in::placeholder { color: var(--text-3); }
.lk-ico { height: 18px; width: auto; flex: none; }
.lk-x { all: unset; display: grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; color: var(--text-3); cursor: pointer; }
.lk-x:hover { background: var(--accent-soft); color: var(--text); }
.lk-chev { color: var(--text-3); transform: rotate(90deg); transition: transform .2s; flex: none; }
.lk.open .lk-chev { transform: rotate(-90deg); color: var(--accent); }
</style>
<style>
.lk-pop { position: fixed; z-index: 3000; display: flex; flex-direction: column; overflow: hidden; border-radius: 14px; border: 1px solid color-mix(in srgb, var(--accent) 30%, var(--border));
  background: color-mix(in srgb, var(--panel) 92%, transparent); backdrop-filter: blur(16px) saturate(1.4);
  box-shadow: 0 24px 48px -16px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .03) inset; font-size: 13.5px; color: var(--text); }
.lk-pop .lk-list { overflow-y: auto; padding: 6px; display: flex; flex-direction: column; gap: 1px; min-height: 0; }
.lk-pop .opt { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 9px; cursor: pointer; position: relative; }
.lk-pop .opt.on { background: var(--accent-soft); }
.lk-pop .opt.sel .nm { color: var(--accent-text, var(--accent)); font-weight: 600; }
.lk-pop .opt .nm { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lk-pop .opt .uses { font-size: 11px; color: var(--text-3); padding: 1px 7px; border-radius: 99px; background: var(--muted-soft, rgba(148, 163, 184, .12)); white-space: nowrap; }
.lk-pop .opt .ok { color: var(--accent); }
.lk-pop .opt .dot { width: 10px; height: 10px; border-radius: 50%; }
.lk-pop .opt .lk-ico { height: 16px; }
.lk-pop .acts { display: flex; gap: 2px; opacity: 0; transition: opacity .12s; }
.lk-pop .opt.on .acts, .lk-pop .opt:focus-within .acts { opacity: 1; }
@media (hover: none) { .lk-pop .acts { opacity: 1; } }
.lk-pop .ib { all: unset; display: grid; place-items: center; width: 26px; height: 26px; border-radius: 7px; color: var(--text-2); cursor: pointer; }
.lk-pop .ib:hover:not(:disabled) { background: var(--panel); color: var(--text); box-shadow: 0 0 0 1px var(--border); }
.lk-pop .ib:disabled { opacity: .35; cursor: not-allowed; }
.lk-pop .ed, .lk-pop .del { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 8px; border-radius: 10px; background: var(--bg-elev); border: 1px solid var(--border); }
.lk-pop .ed .input { flex: 1 1 140px; min-width: 0; padding: 6px 8px; }
.lk-pop .ed select.input { flex: 1 1 150px; }
.lk-pop .ed .sw { width: 34px; height: 32px; padding: 2px; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); cursor: pointer; }
.lk-pop .del { border-color: color-mix(in srgb, var(--bad) 45%, var(--border)); background: color-mix(in srgb, var(--bad) 8%, var(--bg-elev)); }
.lk-pop .del span { flex: 1 1 100%; }
.lk-pop .btn-sm { padding: 5px 10px; font-size: 12.5px; }
.lk-pop .lk-empty { margin: 0; padding: 10px; color: var(--text-3); }
.lk-pop .lk-err { color: var(--bad); }
.lk-pop .lk-msg { margin: 0; padding: 6px 12px; border-top: 1px solid var(--border); font-size: 12.5px; }
.lk-pop .lk-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 8px; border-top: 1px solid var(--border); background: color-mix(in srgb, var(--bg-elev) 70%, transparent); }
.lk-pop .add { all: unset; display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 8px; color: var(--accent-text, var(--accent)); cursor: pointer; font-weight: 600; font-size: 13px; }
.lk-pop .add:hover { background: var(--accent-soft); }
.lk-pop .tip { font-size: 11px; color: var(--text-3); }
.lk-enter-active, .lk-leave-active { transition: opacity .14s, transform .14s cubic-bezier(.2, .8, .2, 1); }
.lk-enter-from, .lk-leave-to { opacity: 0; transform: translateY(-4px) scale(.985); }
</style>
