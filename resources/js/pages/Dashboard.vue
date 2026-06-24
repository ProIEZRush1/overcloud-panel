<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { Users, FileText, CreditCard, Rocket, TrendingUp } from '@lucide/vue'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

const props = defineProps<{
  stats: { leads_open: number; quotes_sent: number; payments_to_review: number; active_projects: number; mrr_cents: number }
  pipeline: { stage: string; label: string; color: string; count: number }[]
  recent: { id: number; name: string | null; preview: string | null; at: string | null; unread: number; lead_uuid: string | null }[]
}>()

const currency = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })

const statCards = [
  { key: 'leads', label: 'Leads abiertos', icon: Users, value: String(props.stats.leads_open) },
  { key: 'quotes', label: 'Cotizaciones enviadas', icon: FileText, value: String(props.stats.quotes_sent) },
  { key: 'payments', label: 'Pagos por revisar', icon: CreditCard, value: String(props.stats.payments_to_review) },
  { key: 'projects', label: 'Proyectos activos', icon: Rocket, value: String(props.stats.active_projects) },
  { key: 'mrr', label: 'MRR', icon: TrendingUp, value: currency.format(props.stats.mrr_cents / 100) },
]

const colorMap: Record<string, string> = {
  blue: 'bg-blue-500',
  amber: 'bg-amber-500',
  green: 'bg-green-500',
  indigo: 'bg-indigo-500',
  violet: 'bg-violet-500',
  red: 'bg-red-500',
}

function chipColor(color: string): string {
  return colorMap[color] ?? 'bg-muted-foreground'
}
</script>

<template>
  <Head title="Panel" />

  <div class="p-4 flex flex-col gap-4">
    <h1 class="text-xl font-semibold text-foreground">Panel</h1>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
      <Card v-for="card in statCards" :key="card.key" class="rounded-xl border-border shadow-sm">
        <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardTitle class="text-sm font-medium text-muted-foreground">{{ card.label }}</CardTitle>
          <component :is="card.icon" class="h-4 w-4 text-muted-foreground" />
        </CardHeader>
        <CardContent>
          <div class="text-2xl font-semibold text-foreground">{{ card.value }}</div>
        </CardContent>
      </Card>
    </div>

    <Card class="rounded-xl border-border shadow-sm">
      <CardHeader>
        <CardTitle class="text-base font-semibold text-foreground">Embudo</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="flex flex-wrap gap-2">
          <div
            v-for="stage in props.pipeline"
            :key="stage.stage"
            class="inline-flex items-center gap-2 rounded-full border border-border bg-muted px-3 py-1.5 text-sm"
          >
            <span :class="['h-2.5 w-2.5 rounded-full', chipColor(stage.color)]" />
            <span class="text-foreground">{{ stage.label }}</span>
            <span class="font-semibold text-muted-foreground">{{ stage.count }}</span>
          </div>
          <p v-if="props.pipeline.length === 0" class="text-sm text-muted-foreground">
            Sin etapas en el embudo.
          </p>
        </div>
      </CardContent>
    </Card>

    <Card class="rounded-xl border-border shadow-sm">
      <CardHeader>
        <CardTitle class="text-base font-semibold text-foreground">Conversaciones recientes</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="flex flex-col">
          <Link
            v-for="item in props.recent"
            :key="item.id"
            :href="'/inbox/' + item.id"
            class="flex items-center justify-between gap-4 rounded-lg px-3 py-3 transition-colors hover:bg-muted"
          >
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2">
                <span class="truncate font-medium text-foreground">{{ item.name ?? 'Sin nombre' }}</span>
                <Badge v-if="item.unread > 0" class="shrink-0">{{ item.unread }}</Badge>
              </div>
              <p class="truncate text-sm text-muted-foreground">{{ item.preview ?? 'Sin mensajes' }}</p>
            </div>
            <span v-if="item.at" class="shrink-0 text-xs text-muted-foreground">{{ item.at }}</span>
          </Link>
          <p v-if="props.recent.length === 0" class="text-sm text-muted-foreground">
            No hay conversaciones recientes.
          </p>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
