<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { fetchBlobUrl } from '../lib/api'

// <img> para arquivos protegidos: busca com o token (o navegador não manda Authorization em <img src>).
const props = defineProps<{ uuid: string | null | undefined; alt: string }>()
const url = ref<string | null>(null)

async function load(): Promise<void> {
  if (url.value) URL.revokeObjectURL(url.value)
  url.value = null
  if (!props.uuid) return
  try { url.value = await fetchBlobUrl(`/attachments/${props.uuid}`) } catch { url.value = null }
}
watch(() => props.uuid, load, { immediate: true })
onBeforeUnmount(() => { if (url.value) URL.revokeObjectURL(url.value) })
</script>

<template>
  <img v-if="url" :src="url" :alt="alt" />
  <slot v-else name="fallback" />
</template>
