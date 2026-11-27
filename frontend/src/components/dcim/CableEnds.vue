<script setup lang="ts">
import AppIcon from '../AppIcon.vue'
import type { CableEnd } from '../../lib/dcim'

// Uma ponta do cabo: equipamento + porta(s), ou circuito.
defineProps<{ ends: CableEnd[] }>()
</script>

<template>
  <span v-if="!ends.length" class="muted">—</span>
  <span v-else-if="ends[0]?.port_id" class="end">
    <RouterLink :to="`/dcim/devices/${ends[0].device_id}`" class="dc-link" @click.stop>{{ ends[0].device }}</RouterLink>
    <span class="mono"> {{ ends.map((e) => e.port).join(', ') }}</span>
    <span class="muted dc-small"> {{ [ends[0].rack, ends[0].location].filter(Boolean).join(' · ') }}</span>
  </span>
  <span v-else class="end"><AppIcon name="globe" :size="13" />
    <RouterLink :to="`/circuits/${ends[0]?.circuit_id}`" class="dc-link" @click.stop>{{ ends[0]?.provider }} {{ ends[0]?.cid }}</RouterLink> <span class="muted">lado {{ ends[0]?.term_side }}</span></span>
</template>

<style scoped>
.end { font-size: 13px; }
</style>
