<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppShell from './components/AppShell.vue'

const route = useRoute()
const bare = computed(() => route.meta.public === true)
</script>

<template>
  <RouterView v-if="bare" />
  <AppShell v-else>
    <RouterView :key="route.fullPath.startsWith('/assets/') ? route.fullPath : route.path" />
  </AppShell>
</template>
