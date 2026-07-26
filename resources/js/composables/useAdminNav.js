import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { UsersIcon, ClipboardDocumentListIcon } from '@heroicons/vue/24/outline'

/**
 * Identitas & navigasi sidebar modul Administrator, dipakai bersama oleh
 * halaman Pengguna & Peran dan Log Aktivitas agar sidebar konsisten.
 * Menu "Log Aktivitas" hanya tampil untuk Super Admin (RBAC di sisi server
 * ditegakkan oleh middleware `ethoz.super`).
 */
export function useAdminNav() {
  const page = usePage()
  const isSuperAdmin = computed(() => page.props.auth?.isSuperAdmin === true)

  const brand = {
    badge: 'AD',
    name: 'Administrator',
    subtitle: 'Pengguna, Peran & Audit',
    note: 'Kelola akses ekosistem\nEthoz — Grow with Ethoz',
  }

  const accent = '#7c3aed'

  const menus = computed(() => [
    { label: 'Pengguna & Peran', icon: UsersIcon, route: 'ethoz.admin', exact: true },
    {
      label: 'Log Aktivitas',
      icon: ClipboardDocumentListIcon,
      route: 'ethoz.admin.activity',
      show: isSuperAdmin.value,
    },
  ])

  return { brand, accent, menus, isSuperAdmin }
}
