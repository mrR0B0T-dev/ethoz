<template>
  <Head>
    <title>{{ title }} | Ethoz</title>
  </Head>

  <div class="flex min-h-screen flex-col bg-[#f9f9f7]">
    <header class="sticky top-0 z-20 border-b border-gray-200 bg-white/90 backdrop-blur">
      <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3 sm:px-6">
        <Link :href="route('ethoz.home')" class="flex items-center gap-2.5">
          <img src="/favicon.svg" alt="Ethoz" class="h-8 w-8" />
          <span class="leading-tight">
            <span class="block text-sm font-bold text-gray-900">{{ appName }}</span>
            <span class="block text-[10px] uppercase tracking-widest text-gray-400">{{ appTagline }}</span>
          </span>
        </Link>

        <!-- tombol kembali ke portal (tampil saat berada di dalam modul) -->
        <Link
          v-if="!isPortalHome"
          :href="route('ethoz.home')"
          class="ml-1 flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-blue-200 hover:bg-blue-50 hover:text-[#1c5cab]"
        >
          <ArrowUturnLeftIcon class="h-3.5 w-3.5" />
          <span class="hidden sm:inline">Kembali ke Portal</span>
        </Link>

        <div class="ml-auto flex items-center gap-3">
          <template v-if="user">
            <div class="hidden text-right sm:block">
              <p class="text-xs font-semibold text-gray-700">{{ user.name }}</p>
              <p class="text-[11px] text-gray-400">{{ user.email }}</p>
            </div>
            <button
              class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-red-50 hover:text-red-600"
              @click="logout"
            >
              <ArrowRightStartOnRectangleIcon class="h-4 w-4" /> Keluar
            </button>
          </template>
          <Link v-else :href="route('login')" class="hc-btn">
            <ArrowRightEndOnRectangleIcon class="h-4 w-4" /> Masuk
          </Link>
        </div>
      </div>
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6">
      <slot />
    </main>

    <footer class="border-t border-gray-100 py-4 text-center text-[11px] text-gray-400">
      {{ appName }} — {{ appTagline }}
    </footer>
  </div>
</template>

<script setup>
import { computed, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import {
  ArrowRightEndOnRectangleIcon, ArrowRightStartOnRectangleIcon, ArrowUturnLeftIcon,
} from '@heroicons/vue/24/outline'

defineProps({ title: { type: String, default: 'Portal' } })

const page = usePage()
const user = computed(() => page.props.auth?.user)
// halaman landing portal sendiri tidak perlu tombol "kembali ke portal"
const isPortalHome = computed(() => page.url.split('?')[0].replace(/\/$/, '') === '/ethoz')
const appName = computed(() => page.props.app?.name ?? 'Ethoz')
const appTagline = computed(() => page.props.app?.tagline ?? 'Grow with Ethoz')

function logout() {
  router.post(route('logout'))
}

// Toast flash message — title di-escape agar teks tak tepercaya tidak
// dieksekusi sebagai HTML (cegah XSS), sama seperti HcLayout.
const toast = Swal.mixin({
  toast: true, position: 'top-end', showConfirmButton: false,
  timer: 2500, timerProgressBar: true,
})
const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => (
  { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
))
watch(() => page.props.flash, (flash) => {
  if (flash?.success) toast.fire({ icon: 'success', title: escapeHtml(flash.success) })
  if (flash?.error) toast.fire({ icon: 'error', title: escapeHtml(flash.error) })
}, { deep: true, immediate: true })
</script>
