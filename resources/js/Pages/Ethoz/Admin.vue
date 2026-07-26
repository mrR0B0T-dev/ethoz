<template>
  <ModuleLayout title="Pengguna & Peran" :brand="brand" :accent="accent" :menus="menus">
    <template #header>
      <button v-if="tab === 'users'" class="hc-btn !bg-[#7c3aed] hover:!bg-[#6d28d9]" @click="openCreateUser">
        <PlusIcon class="h-4 w-4" /> Tambah Pengguna
      </button>
      <button v-else class="hc-btn !bg-[#7c3aed] hover:!bg-[#6d28d9]" @click="openCreateRole">
        <PlusIcon class="h-4 w-4" /> Tambah Peran
      </button>
    </template>

    <p class="mb-5 text-sm text-gray-500">Kelola pengguna, peran, dan hak akses modul ekosistem.</p>

    <!-- tabs -->
    <div class="mb-5 flex items-center gap-1 rounded-xl border border-gray-200 bg-white p-1.5">
      <button
        v-for="t in [['users', `Pengguna (${users.length})`], ['roles', `Peran (${roles.length})`]]" :key="t[0]"
        class="rounded-lg px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === t[0] ? 'bg-[#2a78d6] text-white' : 'text-gray-600 hover:bg-gray-100'"
        @click="tab = t[0]"
      >{{ t[1] }}</button>
    </div>

    <!-- ── Pengguna ─────────────────────────────────────────────────────── -->
    <div v-show="tab === 'users'" class="hc-card overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead class="bg-gray-50/60">
          <tr>
            <th class="hc-th">Nama</th>
            <th class="hc-th">Email</th>
            <th class="hc-th">Peran</th>
            <th class="hc-th text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <tr v-for="u in users" :key="u.id" class="hover:bg-gray-50/50">
            <td class="hc-td font-medium text-gray-900">
              {{ u.name }}
              <span v-if="u.id === me?.id" class="ml-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-[#1c5cab]">Kamu</span>
            </td>
            <td class="hc-td text-gray-600">{{ u.email }}</td>
            <td class="hc-td">
              <span v-if="!u.role_labels.length" class="text-xs text-gray-400">– tanpa peran –</span>
              <span
                v-for="label in u.role_labels" :key="label"
                class="mr-1 inline-flex rounded-full bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700"
              >{{ label }}</span>
            </td>
            <td class="hc-td text-right">
              <button class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600" title="Ubah" @click="openEditUser(u)">
                <PencilSquareIcon class="h-4 w-4" />
              </button>
              <button
                class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30"
                title="Hapus" :disabled="u.id === me?.id" @click="confirmDeleteUser(u)"
              >
                <TrashIcon class="h-4 w-4" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Peran ────────────────────────────────────────────────────────── -->
    <div v-show="tab === 'roles'" class="hc-card overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead class="bg-gray-50/60">
          <tr>
            <th class="hc-th">Peran</th>
            <th class="hc-th">Deskripsi</th>
            <th class="hc-th">Akses Modul</th>
            <th class="hc-th text-right">Pengguna</th>
            <th class="hc-th text-right">Aksi</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <tr v-for="r in roles" :key="r.id" class="hover:bg-gray-50/50">
            <td class="hc-td">
              <p class="font-medium text-gray-900">{{ r.label }}</p>
              <p class="font-mono text-[11px] text-gray-400">{{ r.name }}</p>
            </td>
            <td class="hc-td max-w-72 text-xs text-gray-500">{{ r.description ?? '–' }}</td>
            <td class="hc-td">
              <span
                v-if="r.modules.includes('*')"
                class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700"
              >Semua modul</span>
              <template v-else>
                <span v-if="!r.modules.length" class="text-xs text-gray-400">– tanpa akses –</span>
                <span
                  v-for="m in r.modules" :key="m"
                  class="mr-1 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-[#1c5cab]"
                >{{ moduleName(m) }}</span>
              </template>
            </td>
            <td class="hc-td text-right tabular-nums text-gray-600">{{ r.users_count }}</td>
            <td class="hc-td text-right">
              <button class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600" title="Ubah" @click="openEditRole(r)">
                <PencilSquareIcon class="h-4 w-4" />
              </button>
              <button
                class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30"
                title="Hapus" :disabled="r.name === 'super-admin'" @click="confirmDeleteRole(r)"
              >
                <TrashIcon class="h-4 w-4" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- modal pengguna -->
    <HcModal :show="userModal" :title="editingUser ? 'Ubah Pengguna' : 'Tambah Pengguna'" @close="userModal = false">
      <form class="space-y-3" @submit.prevent="submitUser">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Nama</span>
          <input v-model="userForm.name" type="text" required class="hc-input w-full" />
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Email</span>
          <input v-model="userForm.email" type="email" required class="hc-input w-full" />
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">
            Kata Sandi {{ editingUser ? '(kosongkan bila tidak diganti)' : '' }}
          </span>
          <input
            v-model="userForm.password" type="password" :required="!editingUser"
            minlength="8" autocomplete="new-password" class="hc-input w-full" placeholder="min. 8 karakter"
          />
        </label>
        <fieldset>
          <span class="mb-1 block text-xs font-medium text-gray-600">Peran</span>
          <div class="space-y-1.5 rounded-lg border border-gray-200 p-3">
            <label v-for="r in roles" :key="r.id" class="flex items-start gap-2 text-sm text-gray-700">
              <input v-model="userForm.roles" type="checkbox" :value="r.id" class="mt-0.5 rounded border-gray-300" />
              <span>
                {{ r.label }}
                <span class="block text-[11px] text-gray-400">{{ r.description }}</span>
              </span>
            </label>
          </div>
        </fieldset>
        <p v-if="errorMsg" class="text-xs font-medium text-red-600">{{ errorMsg }}</p>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="userModal = false">Batal</button>
          <button type="submit" class="hc-btn">{{ editingUser ? 'Simpan Perubahan' : 'Tambah' }}</button>
        </div>
      </form>
    </HcModal>

    <!-- modal peran -->
    <HcModal :show="roleModal" :title="editingRole ? 'Ubah Peran' : 'Tambah Peran'" @close="roleModal = false">
      <form class="space-y-3" @submit.prevent="submitRole">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Nama Peran</span>
          <input v-model="roleForm.label" type="text" required class="hc-input w-full" placeholder="mis. Analis HC" />
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-gray-600">Deskripsi</span>
          <input v-model="roleForm.description" type="text" maxlength="255" class="hc-input w-full" />
        </label>
        <fieldset>
          <span class="mb-1 block text-xs font-medium text-gray-600">Akses Modul</span>
          <div v-if="isSuperAdminRole" class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 text-xs text-emerald-700">
            Super Admin selalu memiliki akses ke <b>seluruh modul</b> — tidak dapat diubah.
          </div>
          <div v-else class="space-y-1.5 rounded-lg border border-gray-200 p-3">
            <label v-for="m in modules" :key="m.key" class="flex items-center gap-2 text-sm text-gray-700">
              <input v-model="roleForm.modules" type="checkbox" :value="m.key" class="rounded border-gray-300" />
              {{ m.name }}
            </label>
          </div>
        </fieldset>
        <p v-if="errorMsg" class="text-xs font-medium text-red-600">{{ errorMsg }}</p>
        <div class="flex justify-end gap-2 pt-2">
          <button type="button" class="hc-btn-secondary" @click="roleModal = false">Batal</button>
          <button type="submit" class="hc-btn">{{ editingRole ? 'Simpan Perubahan' : 'Tambah' }}</button>
        </div>
      </form>
    </HcModal>
  </ModuleLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import Swal from 'sweetalert2'
import { PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import ModuleLayout from '@/Layouts/ModuleLayout.vue'
import HcModal from '@/Components/HcRkap/HcModal.vue'
import { useAdminNav } from '@/composables/useAdminNav'

const props = defineProps({
  users: Array,
  roles: Array,
  modules: Array, // registri modul: [{ key, name }]
})

const { brand, accent, menus } = useAdminNav()

const me = computed(() => usePage().props.auth?.user
  ? props.users.find(u => u.email === usePage().props.auth.user.email)
  : null)

const tab = ref('users')
const errorMsg = ref('')
const moduleName = (key) => props.modules.find(m => m.key === key)?.name ?? key

const submitOpts = (closeModal) => ({
  preserveScroll: true,
  onSuccess: closeModal,
  onError: (errors) => { errorMsg.value = Object.values(errors)[0] ?? 'Periksa kembali isian formulir.' },
})

// ── pengguna ──────────────────────────────────────────────────────────────
const userModal = ref(false)
const editingUser = ref(null)
const userForm = reactive({ name: '', email: '', password: '', roles: [] })

function openCreateUser() {
  editingUser.value = null
  errorMsg.value = ''
  Object.assign(userForm, { name: '', email: '', password: '', roles: [] })
  userModal.value = true
}

function openEditUser(u) {
  editingUser.value = u
  errorMsg.value = ''
  Object.assign(userForm, { name: u.name, email: u.email, password: '', roles: [...u.role_ids] })
  userModal.value = true
}

function submitUser() {
  const close = () => { userModal.value = false }
  if (editingUser.value) {
    router.put(route('ethoz.admin.users.update', editingUser.value.id), { ...userForm }, submitOpts(close))
  } else {
    router.post(route('ethoz.admin.users.store'), { ...userForm }, submitOpts(close))
  }
}

function confirmDeleteUser(u) {
  Swal.fire({
    title: 'Hapus pengguna?', text: `${u.name} (${u.email})`, icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#d03b3b',
    confirmButtonText: 'Ya, hapus', cancelButtonText: 'Batal',
  }).then((res) => {
    if (res.isConfirmed) router.delete(route('ethoz.admin.users.destroy', u.id), { preserveScroll: true })
  })
}

// ── peran ─────────────────────────────────────────────────────────────────
const roleModal = ref(false)
const editingRole = ref(null)
const roleForm = reactive({ label: '', description: '', modules: [] })
const isSuperAdminRole = computed(() => editingRole.value?.name === 'super-admin')

function openCreateRole() {
  editingRole.value = null
  errorMsg.value = ''
  Object.assign(roleForm, { label: '', description: '', modules: [] })
  roleModal.value = true
}

function openEditRole(r) {
  editingRole.value = r
  errorMsg.value = ''
  Object.assign(roleForm, {
    label: r.label,
    description: r.description ?? '',
    modules: r.modules.filter(m => m !== '*'),
  })
  roleModal.value = true
}

function submitRole() {
  const close = () => { roleModal.value = false }
  if (editingRole.value) {
    router.put(route('ethoz.admin.roles.update', editingRole.value.id), { ...roleForm }, submitOpts(close))
  } else {
    router.post(route('ethoz.admin.roles.store'), { ...roleForm }, submitOpts(close))
  }
}

function confirmDeleteRole(r) {
  Swal.fire({
    title: 'Hapus peran?',
    text: `${r.label} — ${r.users_count} pengguna akan kehilangan peran ini.`,
    icon: 'warning', showCancelButton: true, confirmButtonColor: '#d03b3b',
    confirmButtonText: 'Ya, hapus', cancelButtonText: 'Batal',
  }).then((res) => {
    if (res.isConfirmed) router.delete(route('ethoz.admin.roles.destroy', r.id), { preserveScroll: true })
  })
}
</script>
