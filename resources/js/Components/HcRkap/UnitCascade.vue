<template>
  <div class="flex flex-wrap items-end gap-2">
    <label class="block">
      <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Group</span>
      <select v-model="groupId" class="hc-select" @change="onGroup">
        <option :value="null">Semua group</option>
        <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.code }} — {{ g.name }}</option>
      </select>
    </label>
    <label v-if="departments.length" class="block">
      <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Department</span>
      <select v-model="departmentId" class="hc-select" @change="onDepartment">
        <option :value="null">Semua department</option>
        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.code }}</option>
      </select>
    </label>
    <label v-if="sections.length" class="block">
      <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Section</span>
      <select v-model="sectionId" class="hc-select" @change="emitValue">
        <option :value="null">Semua section</option>
        <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.code }}</option>
      </select>
    </label>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  units: { type: Array, required: true }, // flat: {id, code, name, type, parent_id}
  modelValue: { type: [Number, String], default: null }, // unit terpilih terdalam
})
const emit = defineEmits(['update:modelValue'])

const byId = computed(() => Object.fromEntries(props.units.map(u => [u.id, u])))
const groups = computed(() => props.units.filter(u => !u.parent_id))

const groupId = ref(null)
const departmentId = ref(null)
const sectionId = ref(null)

// pulihkan cascade dari nilai terpilih (mis. saat halaman dimuat dengan filter aktif)
watch(() => props.modelValue, (val) => {
  groupId.value = departmentId.value = sectionId.value = null
  let u = val ? byId.value[val] : null
  const chain = []
  while (u) { chain.unshift(u); u = u.parent_id ? byId.value[u.parent_id] : null }
  if (chain[0]) groupId.value = chain[0].id
  if (chain[1]) departmentId.value = chain[1].id
  if (chain[2]) sectionId.value = chain[2].id
}, { immediate: true })

const departments = computed(() =>
  groupId.value ? props.units.filter(u => u.parent_id === groupId.value) : [])
const sections = computed(() =>
  departmentId.value ? props.units.filter(u => u.parent_id === departmentId.value) : [])

function onGroup() { departmentId.value = null; sectionId.value = null; emitValue() }
function onDepartment() { sectionId.value = null; emitValue() }
function emitValue() {
  emit('update:modelValue', sectionId.value ?? departmentId.value ?? groupId.value ?? null)
}
</script>
