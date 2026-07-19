<template>
  <EthozLayout title="Portal">
    <div class="py-4 sm:py-8">
      <template v-if="user">
        <p class="text-sm text-gray-500">Selamat datang, <b class="text-gray-700">{{ user.name }}</b> 👋</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900">Pilih modul untuk mulai bekerja</h1>
        <p class="mt-1 text-sm text-gray-500">
          Modul dengan tanda kunci berada di luar hak akses peran Kamu.
        </p>
      </template>
      <template v-else>
        <p class="text-sm text-gray-500">Selamat datang di Ethoz 👋</p>
        <h1 class="mt-1 text-2xl font-bold text-gray-900">Jelajahi modul Ethoz</h1>
        <p class="mt-1 text-sm text-gray-500">
          Pilih modul yang ingin dibuka — Kamu akan diminta masuk terlebih dahulu untuk membukanya.
        </p>
      </template>

      <div v-if="modules.length" class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Link
          v-for="mod in modules" :key="mod.key"
          :href="mod.href"
          class="group hc-card flex flex-col p-5 transition-shadow hover:shadow-md"
          :class="mod.accessible === false ? 'opacity-75 grayscale-[35%]' : ''"
        >
          <div class="mb-4 flex items-start justify-between">
            <div
              class="flex h-11 w-11 items-center justify-center rounded-xl text-white"
              :style="{ backgroundColor: mod.color }"
            >
              <component :is="iconOf(mod.icon)" class="h-6 w-6" />
            </div>
            <span
              v-if="mod.accessible === false"
              class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-500"
            >
              <LockClosedIcon class="h-3 w-3" /> Tidak ada akses
            </span>
          </div>
          <h2 class="text-base font-semibold text-gray-900">{{ mod.name }}</h2>
          <p class="mt-1 flex-1 text-sm leading-relaxed text-gray-500">{{ mod.description }}</p>

          <!-- CTA sesuai tahap alur: tamu → masuk dulu; punya akses → buka; tanpa akses → 403 -->
          <span v-if="mod.accessible === null" class="mt-4 inline-flex items-center gap-1 text-sm font-medium" :style="{ color: mod.color }">
            <LockClosedIcon class="h-4 w-4" /> Masuk untuk membuka
            <ArrowRightIcon class="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
          </span>
          <span v-else-if="mod.accessible" class="mt-4 inline-flex items-center gap-1 text-sm font-medium" :style="{ color: mod.color }">
            Buka modul
            <ArrowRightIcon class="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
          </span>
          <span v-else class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-gray-400">
            Hubungi administrator untuk meminta akses
          </span>
        </Link>
      </div>

      <div v-else class="hc-card mt-8 p-10 text-center">
        <CubeIcon class="mx-auto h-10 w-10 text-gray-300" />
        <p class="mt-3 text-sm font-medium text-gray-600">Belum ada modul terdaftar di ekosistem.</p>
      </div>
    </div>
  </EthozLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import {
  ArrowRightIcon, ArrowsRightLeftIcon, ChartBarSquareIcon, CubeIcon,
  LockClosedIcon, ShieldCheckIcon, UsersIcon,
} from '@heroicons/vue/24/outline'
import EthozLayout from '@/Layouts/EthozLayout.vue'

defineProps({ modules: Array })

const user = computed(() => usePage().props.auth?.user)

// key ikon di config/ethoz.php → komponen heroicons
const ICONS = {
  chart: ChartBarSquareIcon,
  shield: ShieldCheckIcon,
  users: UsersIcon,
  exchange: ArrowsRightLeftIcon,
}
const iconOf = (key) => ICONS[key] ?? CubeIcon
</script>
