<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8" @click.self="$emit('close')">
      <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
          <h3 class="text-sm font-semibold text-gray-900">{{ title }}</h3>
          <button class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" @click="$emit('close')">
            <XMarkIcon class="h-5 w-5" />
          </button>
        </div>
        <div class="px-5 py-4">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { XMarkIcon } from '@heroicons/vue/24/outline'

defineProps({ show: Boolean, title: String })
defineEmits(['close'])
</script>
