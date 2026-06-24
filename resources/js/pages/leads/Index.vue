<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { Users, Filter } from '@lucide/vue'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

defineProps<{
  leads: {
    uuid: string
    name: string
    phone: string
    company: string | null
    stage: string
    stage_label: string
    stage_color: string
    service: string | null
    quote_total: string | null
    updated: string | null
  }[]
  stages: { value: string; label: string; color: string }[]
  filter: string | null
}>()

function hueClass(color: string | null): string {
  const c = (color ?? '').toLowerCase()
  const map: Record<string, string> = {
    gray: 'bg-zinc-100 text-zinc-700 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:border-zinc-700',
    slate: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700',
    red: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-900/40 dark:text-red-300 dark:border-red-800',
    orange: 'bg-orange-100 text-orange-700 border-orange-200 dark:bg-orange-900/40 dark:text-orange-300 dark:border-orange-800',
    amber: 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-900/40 dark:text-amber-300 dark:border-amber-800',
    yellow: 'bg-yellow-100 text-yellow-700 border-yellow-200 dark:bg-yellow-900/40 dark:text-yellow-300 dark:border-yellow-800',
    green: 'bg-green-100 text-green-700 border-green-200 dark:bg-green-900/40 dark:text-green-300 dark:border-green-800',
    emerald: 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-900/40 dark:text-emerald-300 dark:border-emerald-800',
    teal: 'bg-teal-100 text-teal-700 border-teal-200 dark:bg-teal-900/40 dark:text-teal-300 dark:border-teal-800',
    cyan: 'bg-cyan-100 text-cyan-700 border-cyan-200 dark:bg-cyan-900/40 dark:text-cyan-300 dark:border-cyan-800',
    blue: 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-800',
    indigo: 'bg-indigo-100 text-indigo-700 border-indigo-200 dark:bg-indigo-900/40 dark:text-indigo-300 dark:border-indigo-800',
    violet: 'bg-violet-100 text-violet-700 border-violet-200 dark:bg-violet-900/40 dark:text-violet-300 dark:border-violet-800',
    purple: 'bg-purple-100 text-purple-700 border-purple-200 dark:bg-purple-900/40 dark:text-purple-300 dark:border-purple-800',
    fuchsia: 'bg-fuchsia-100 text-fuchsia-700 border-fuchsia-200 dark:bg-fuchsia-900/40 dark:text-fuchsia-300 dark:border-fuchsia-800',
    pink: 'bg-pink-100 text-pink-700 border-pink-200 dark:bg-pink-900/40 dark:text-pink-300 dark:border-pink-800',
    rose: 'bg-rose-100 text-rose-700 border-rose-200 dark:bg-rose-900/40 dark:text-rose-300 dark:border-rose-800',
  }
  return map[c] ?? map.gray
}
</script>

<template>
  <Head title="Leads" />

  <div class="p-4 flex flex-col gap-4">
    <div class="flex items-center gap-2">
      <Users class="size-6 text-muted-foreground" />
      <h1 class="text-xl font-semibold text-foreground">Leads</h1>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <span class="flex items-center gap-1 text-sm text-muted-foreground mr-1">
        <Filter class="size-4" />
        Filtrar:
      </span>
      <Link
        href="/leads"
        class="rounded-full border px-3 py-1 text-sm transition-colors"
        :class="filter === null
          ? 'bg-foreground text-background border-foreground'
          : 'bg-card text-foreground border-border hover:bg-muted'"
      >
        Todos
      </Link>
      <Link
        v-for="stage in stages"
        :key="stage.value"
        :href="'/leads?stage=' + stage.value"
        class="rounded-full border px-3 py-1 text-sm transition-colors"
        :class="filter === stage.value
          ? 'bg-foreground text-background border-foreground'
          : 'bg-card text-foreground border-border hover:bg-muted'"
      >
        {{ stage.label }}
      </Link>
    </div>

    <div v-if="leads.length === 0">
      <Card class="rounded-xl shadow-sm">
        <CardContent class="flex flex-col items-center justify-center gap-2 py-16 text-center">
          <Users class="size-10 text-muted-foreground" />
          <p class="text-foreground font-medium">Sin leads</p>
          <p class="text-sm text-muted-foreground">No hay leads que coincidan con este filtro.</p>
        </CardContent>
      </Card>
    </div>

    <template v-else>
      <div class="hidden overflow-hidden rounded-xl border border-border bg-card shadow-sm md:block">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border bg-muted text-left text-muted-foreground">
              <th class="px-4 py-3 font-medium">Nombre</th>
              <th class="px-4 py-3 font-medium">Teléfono</th>
              <th class="px-4 py-3 font-medium">Empresa</th>
              <th class="px-4 py-3 font-medium">Etapa</th>
              <th class="px-4 py-3 font-medium">Servicio</th>
              <th class="px-4 py-3 font-medium">Cotización</th>
              <th class="px-4 py-3 font-medium">Actualizado</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="lead in leads"
              :key="lead.uuid"
              class="border-b border-border last:border-0 transition-colors hover:bg-muted"
            >
              <td class="px-0 py-0">
                <Link :href="'/leads/' + lead.uuid" class="block px-4 py-3 font-medium text-foreground">
                  {{ lead.name }}
                </Link>
              </td>
              <td class="px-0 py-0">
                <Link :href="'/leads/' + lead.uuid" class="block px-4 py-3 text-muted-foreground">
                  {{ lead.phone }}
                </Link>
              </td>
              <td class="px-0 py-0">
                <Link :href="'/leads/' + lead.uuid" class="block px-4 py-3 text-muted-foreground">
                  {{ lead.company ?? '—' }}
                </Link>
              </td>
              <td class="px-0 py-0">
                <Link :href="'/leads/' + lead.uuid" class="block px-4 py-3">
                  <Badge variant="outline" :class="hueClass(lead.stage_color)">{{ lead.stage_label }}</Badge>
                </Link>
              </td>
              <td class="px-0 py-0">
                <Link :href="'/leads/' + lead.uuid" class="block px-4 py-3 text-muted-foreground">
                  {{ lead.service ?? '—' }}
                </Link>
              </td>
              <td class="px-0 py-0">
                <Link :href="'/leads/' + lead.uuid" class="block px-4 py-3 text-foreground">
                  {{ lead.quote_total ?? '—' }}
                </Link>
              </td>
              <td class="px-0 py-0">
                <Link :href="'/leads/' + lead.uuid" class="block px-4 py-3 text-muted-foreground">
                  {{ lead.updated ?? '—' }}
                </Link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="flex flex-col gap-3 md:hidden">
        <Link v-for="lead in leads" :key="lead.uuid" :href="'/leads/' + lead.uuid" class="block">
          <Card class="rounded-xl shadow-sm transition-colors hover:bg-muted">
            <CardContent class="flex flex-col gap-2 p-4">
              <div class="flex items-start justify-between gap-2">
                <div>
                  <p class="font-medium text-foreground">{{ lead.name }}</p>
                  <p class="text-sm text-muted-foreground">{{ lead.phone }}</p>
                </div>
                <Badge variant="outline" :class="hueClass(lead.stage_color)">{{ lead.stage_label }}</Badge>
              </div>
              <div class="grid grid-cols-2 gap-2 text-sm">
                <div>
                  <p class="text-muted-foreground">Empresa</p>
                  <p class="text-foreground">{{ lead.company ?? '—' }}</p>
                </div>
                <div>
                  <p class="text-muted-foreground">Servicio</p>
                  <p class="text-foreground">{{ lead.service ?? '—' }}</p>
                </div>
                <div>
                  <p class="text-muted-foreground">Cotización</p>
                  <p class="text-foreground">{{ lead.quote_total ?? '—' }}</p>
                </div>
                <div>
                  <p class="text-muted-foreground">Actualizado</p>
                  <p class="text-foreground">{{ lead.updated ?? '—' }}</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </Link>
      </div>
    </template>
  </div>
</template>
