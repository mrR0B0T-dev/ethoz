<template>
  <Head title="Masuk" />

  <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
    <div class="w-full max-w-sm">
      <div class="mb-6 text-center">
        <img src="/favicon.svg" alt="Ethoz" class="mx-auto mb-3 h-14 w-14" />
        <h1 class="text-2xl font-bold tracking-tight text-gray-800">Ethoz</h1>
        <p class="mt-1 text-sm text-gray-500">Grow with Ethoz</p>
        <p class="mt-0.5 text-xs text-gray-400">Masuk untuk mengakses modul sesuai peran Kamu</p>
      </div>

      <form class="hc-card space-y-4 p-6" @submit.prevent="submit">
        <label class="block">
          <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Email</span>
          <input
            v-model="form.email"
            type="email"
            required
            autofocus
            autocomplete="username"
            class="hc-input w-full"
            placeholder="admin@rkap-hc.test"
          />
        </label>

        <label class="block">
          <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Kata Sandi</span>
          <input
            v-model="form.password"
            type="password"
            required
            autocomplete="current-password"
            class="hc-input w-full"
            placeholder="••••••••"
          />
        </label>

        <p v-if="form.errors.email" class="text-sm text-red-600">{{ form.errors.email }}</p>

        <label class="flex items-center gap-2 text-sm text-gray-600">
          <input v-model="form.remember" type="checkbox" class="rounded border-gray-300" />
          Ingat saya
        </label>

        <button type="submit" class="hc-btn w-full justify-center" :disabled="form.processing">
          Masuk
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

function submit() {
  form.post(route('login.store'), {
    onFinish: () => form.reset('password'),
  })
}
</script>
