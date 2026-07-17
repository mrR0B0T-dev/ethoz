<template>
  <EthozLayout title="403 — Akses Ditolak">
    <div class="flex min-h-[60vh] items-center justify-center">
      <div class="hc-card w-full max-w-md p-10 text-center">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-red-50">
          <NoSymbolIcon class="h-8 w-8 text-red-500" />
        </div>
        <p class="mt-4 text-xs font-bold uppercase tracking-widest text-red-500">403 Forbidden</p>
        <h1 class="mt-1 text-xl font-bold text-gray-900">Akses Ditolak</h1>
        <p class="mt-2 text-sm leading-relaxed text-gray-500">
          Anda tidak memiliki peran dengan hak akses ke modul
          <b class="text-gray-700">{{ module }}</b>.
          Hubungi administrator bila Anda seharusnya memiliki akses.
        </p>
        <Link :href="route('ethoz.home')" class="hc-btn mt-6 inline-flex">
          <ArrowLeftIcon class="h-4 w-4" /> Kembali ke Portal
        </Link>
      </div>
    </div>
  </EthozLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { ArrowLeftIcon, NoSymbolIcon } from '@heroicons/vue/24/outline'
import EthozLayout from '@/Layouts/EthozLayout.vue'

defineProps({ module: String })
</script>
