<script setup lang="ts">
import { ref } from 'vue'
import AppIcon from './AppIcon.vue'
import AuthImage from './AuthImage.vue'
import { api, fetchBlobUrl } from '../lib/api'
import { bytes, dateTime } from '../lib/format'
import { notify } from '../lib/toast'
import type { Attachment } from '../lib/types'

// Lista + upload (arrastar/selecionar) + download + exclusão de anexos de qualquer registro.
const props = defineProps<{ ownerType: 'asset' | 'contract' | 'supplier' | 'license' | 'branch'; owner: string | number; items: Attachment[]; title?: string }>()
const emit = defineEmits<{ changed: [] }>()
const busy = ref(false)
const dragging = ref(false)
const input = ref<HTMLInputElement | null>(null)

async function send(files: FileList | null): Promise<void> {
  if (!files?.length) return
  busy.value = true
  try {
    for (const f of Array.from(files)) {
      await api.upload(props.ownerType, props.owner, f, 'document')
    }
    notify('ok', files.length > 1 ? `${files.length} arquivos anexados` : 'Arquivo anexado')
    emit('changed')
  } catch (e) {
    notify('warn', 'Falha no upload', e instanceof Error ? e.message : String(e))
  } finally {
    busy.value = false
    if (input.value) input.value.value = ''
  }
}

async function open(a: Attachment, download = false): Promise<void> {
  const url = await fetchBlobUrl(`/attachments/${a.uuid}`)
  const link = document.createElement('a')
  link.href = url
  if (download || !(a.mime_type.startsWith('image/') || a.mime_type === 'application/pdf')) link.download = a.filename
  else link.target = '_blank'
  link.click()
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

async function remove(a: Attachment): Promise<void> {
  if (!window.confirm(`Excluir o anexo "${a.filename}"?`)) return
  await api.deleteAttachment(a.uuid)
  notify('ok', 'Anexo excluído')
  emit('changed')
}
</script>

<template>
  <section class="panel">
    <div class="panel-head">
      <h2>{{ title ?? 'Anexos' }} <span class="muted">({{ items.length }})</span></h2>
      <button class="btn" type="button" :disabled="busy" @click="input?.click()"><AppIcon name="upload" :size="16" /> {{ busy ? 'Enviando…' : 'Anexar arquivo' }}</button>
      <input ref="input" type="file" multiple class="sr-only" aria-label="Selecionar arquivos" @change="send(($event.target as HTMLInputElement).files)" />
    </div>
    <div
      class="drop" :class="{ on: dragging }"
      @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="dragging = false; send($event.dataTransfer?.files ?? null)"
    >
      <p v-if="!items.length" class="muted">Arraste arquivos aqui (PDF, imagens, Word, Excel — até 20 MB).</p>
      <ul v-else class="files">
        <li v-for="a in items" :key="a.uuid">
          <span class="thumb">
            <AuthImage v-if="a.mime_type.startsWith('image/')" :uuid="a.uuid" :alt="a.filename" />
            <AppIcon v-else name="file" :size="20" />
          </span>
          <span class="meta">
            <button type="button" class="link" @click="open(a)">{{ a.filename }}</button>
            <span class="muted small">{{ a.kind === 'photo' ? 'foto · ' : '' }}{{ bytes(a.size_bytes) }} · {{ dateTime(a.created_at) }}</span>
          </span>
          <button class="btn btn-icon" type="button" :aria-label="`Baixar ${a.filename}`" @click="open(a, true)"><AppIcon name="download" :size="16" /></button>
          <button class="btn btn-icon" type="button" :aria-label="`Excluir ${a.filename}`" @click="remove(a)"><AppIcon name="trash" :size="16" /></button>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.drop { margin: 0 20px 20px; border: 1px dashed var(--border); border-radius: var(--radius-sm); padding: 12px; }
.drop.on { border-color: var(--accent); background: var(--accent-soft); }
.drop p { margin: 8px; text-align: center; font-size: 13px; }
.files { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.files li { display: flex; align-items: center; gap: 10px; }
.thumb { width: 40px; height: 40px; border-radius: 6px; background: var(--panel-hover); display: grid; place-items: center; overflow: hidden; color: var(--text-3); flex: none; }
.thumb :deep(img) { width: 100%; height: 100%; object-fit: cover; }
.meta { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.link { all: unset; cursor: pointer; color: var(--accent-text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.link:hover { text-decoration: underline; }
.link:focus-visible { outline: 2px solid var(--accent); border-radius: 4px; }
.small { font-size: 12px; }
</style>
