<script setup lang="ts">
import { computed, ref } from 'vue'
import AppIcon from '../AppIcon.vue'
import AppModal from '../AppModal.vue'
import AuthImage from '../AuthImage.vue'
import { api } from '../../lib/api'
import { canEdit } from '../../lib/session'
import { notify } from '../../lib/toast'
import type { Attachment } from '../../lib/types'

// Fotos de local, rack, equipamento, tipo, circuito ou cabo (anexos kind=photo). Câmera no celular.
const props = defineProps<{ ownerType: string; owner: number; items: Attachment[]; title?: string; extra?: { uuid: string; label: string } | null }>()
const emit = defineEmits<{ changed: [] }>()
const photos = computed(() => props.items.filter((a) => a.kind === 'photo'))
const input = ref<HTMLInputElement | null>(null)
const busy = ref(false)
const open = ref<{ uuid: string; label: string } | null>(null)

async function send(files: FileList | null): Promise<void> {
  if (!files?.length) return
  busy.value = true
  try {
    for (const f of Array.from(files)) await api.upload(props.ownerType, props.owner, f, 'photo')
    notify('ok', files.length > 1 ? `${files.length} fotos enviadas` : 'Foto enviada')
    emit('changed')
  } catch (e) { notify('warn', 'Falha no envio da foto', e instanceof Error ? e.message : String(e)) }
  finally { busy.value = false; if (input.value) input.value.value = '' }
}
async function remove(a: Attachment): Promise<void> {
  if (!window.confirm('Excluir esta foto?')) return
  await api.deleteAttachment(a.uuid)
  open.value = null
  notify('ok', 'Foto excluída')
  emit('changed')
}
</script>

<template>
  <section class="panel">
    <div class="panel-head">
      <h2>{{ title ?? 'Fotos' }} <span class="muted">({{ photos.length }})</span></h2>
      <button class="edit-only btn" type="button" :disabled="busy" @click="input?.click()"><AppIcon name="camera" :size="16" /> {{ busy ? 'Enviando…' : 'Adicionar foto' }}</button>
      <input ref="input" type="file" accept="image/*" capture="environment" multiple class="sr-only" aria-label="Selecionar fotos" @change="send(($event.target as HTMLInputElement).files)" />
    </div>
    <div class="grid-photos">
      <button v-if="extra" type="button" class="ph" :title="extra.label" @click="open = extra"><AuthImage :uuid="extra.uuid" :alt="extra.label" /><span class="cap">{{ extra.label }}</span></button>
      <button v-for="p in photos" :key="p.uuid" type="button" class="ph" :title="p.filename" @click="open = { uuid: p.uuid, label: p.filename }"><AuthImage :uuid="p.uuid" :alt="p.filename" /></button>
      <p v-if="!photos.length && !extra" class="muted none">{{ canEdit ? 'Nenhuma foto. Tire pelo celular: o botão abre a câmera.' : 'Nenhuma foto.' }}</p>
    </div>
    <AppModal v-if="open" :title="open.label" xwide @close="open = null">
      <div class="big"><AuthImage :uuid="open.uuid" :alt="open.label" /></div>
      <div class="form-actions">
        <button v-if="photos.some((p) => p.uuid === open?.uuid)" class="edit-only btn btn-danger" type="button" @click="remove(photos.find((p) => p.uuid === open?.uuid)!)"><AppIcon name="trash" :size="15" /> Excluir foto</button>
        <button class="btn" type="button" @click="open = null">Fechar</button>
      </div>
    </AppModal>
  </section>
</template>

<style scoped>
.grid-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; padding: 0 20px 20px; }
.ph { position: relative; aspect-ratio: 4 / 3; border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; padding: 0; background: var(--bg-elev); cursor: zoom-in; }
.ph:hover { border-color: var(--accent); }
.ph :deep(img) { width: 100%; height: 100%; object-fit: cover; display: block; }
.cap { position: absolute; left: 0; right: 0; bottom: 0; font-size: 11px; padding: 2px 6px; background: rgba(0, 0, 0, .55); color: #fff; text-align: left; }
.none { grid-column: 1 / -1; margin: 4px 0; font-size: 13px; }
.big { display: grid; place-items: center; max-height: 75vh; overflow: auto; }
.big :deep(img) { max-width: 100%; max-height: 72vh; object-fit: contain; border-radius: var(--radius-sm); }
</style>
