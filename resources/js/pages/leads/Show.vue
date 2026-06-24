<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { FileText, FileSignature, Send, Check, MessageSquare, Save } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'

interface Lead {
  uuid: string
  name: string | null
  phone: string
  email: string | null
  company: string | null
  stage: string
  stage_label: string
  stage_color: string
  service_id: number | null
  service: string | null
  maintenance_plan_id: number | null
  pages: number | null
  languages: string[]
  deposit_percent: number
  summary: string | null
  notes: string | null
  score: number | null
  conversation_id: number | null
}

interface Spec {
  uuid: string
  version: number
  title: string
  status: string
  status_label: string
  pdf_url: string | null
  created: string
}

interface QuoteItem {
  description: string
  quantity: number
  total: string
}

interface Quote {
  uuid: string
  number: string
  status: string
  status_label: string
  total: string
  deposit: string
  maintenance: string | null
  valid_until: string | null
  pdf_url: string | null
  items: QuoteItem[]
}

interface Payment {
  id: number
  type: string
  type_label: string
  amount: string
  status: string
  status_label: string
  reference: string | null
  proof_url: string | null
}

interface ProjectRef {
  uuid: string
  name: string
  status: string
}

interface ServiceOption {
  id: number
  key: string
  name: string
  base_price_cents: number
}

interface FeatureOption {
  id: number
  name: string
  price_cents: number
}

interface PlanOption {
  id: number
  name: string
  monthly_price_cents: number
}

interface StageOption {
  value: string
  label: string
}

interface Options {
  services: ServiceOption[]
  features: FeatureOption[]
  plans: PlanOption[]
  stages: StageOption[]
  banks: number
}

const props = defineProps<{
  lead: Lead
  specs: Spec[]
  quotes: Quote[]
  payments: Payment[]
  project: ProjectRef | null
  options: Options
}>()

const currency = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })
const money = (cents: number): string => currency.format(cents / 100)

// Quote builder state
const featureIds = ref<number[]>([])
const builderPages = ref<number>(props.lead.pages ?? 1)
const builderLanguages = ref<number>(props.lead.languages.length || 1)
const discount = ref<number>(0)

const toggleFeature = (id: number, checked: boolean): void => {
  if (checked) {
    if (!featureIds.value.includes(id)) featureIds.value.push(id)
  } else {
    featureIds.value = featureIds.value.filter((f) => f !== id)
  }
}

const generateSpec = (): void => {
  router.post('/leads/' + props.lead.uuid + '/spec', {}, { preserveScroll: true })
}

const generateQuote = (): void => {
  router.post(
    '/leads/' + props.lead.uuid + '/quote',
    {
      feature_ids: featureIds.value,
      pages: builderPages.value,
      languages: builderLanguages.value,
      discount_cents: Math.round(discount.value * 100),
    },
    { preserveScroll: true },
  )
}

const sendQuote = (uuid: string): void => {
  router.post('/quotes/' + uuid + '/send', {}, { preserveScroll: true })
}

const acceptQuote = (uuid: string): void => {
  router.post('/quotes/' + uuid + '/accept', {}, { preserveScroll: true })
}

// Edit form
const form = useForm({
  name: props.lead.name ?? '',
  email: props.lead.email ?? '',
  company: props.lead.company ?? '',
  stage: props.lead.stage,
  service_id: props.lead.service_id,
  maintenance_plan_id: props.lead.maintenance_plan_id,
  pages: props.lead.pages ?? 0,
  deposit_percent: props.lead.deposit_percent,
  languages: props.lead.languages.join(', '),
  summary: props.lead.summary ?? '',
  notes: props.lead.notes ?? '',
})

const saveLead = (): void => {
  form
    .transform((data) => ({
      ...data,
      languages: String(data.languages)
        .split(',')
        .map((s) => s.trim())
        .filter((s) => s.length > 0),
    }))
    .put('/leads/' + props.lead.uuid, { preserveScroll: true })
}
</script>

<template>
  <Head :title="lead.name ?? 'Lead'" />

  <div class="p-4 flex flex-col gap-4">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div class="flex items-center gap-3">
        <h1 class="text-xl font-semibold text-foreground">{{ lead.name ?? 'Sin nombre' }}</h1>
        <Badge variant="secondary">{{ lead.stage_label }}</Badge>
      </div>
      <Link
        v-if="lead.conversation_id"
        :href="'/inbox/' + lead.conversation_id"
        class="inline-flex items-center gap-2 text-sm font-medium text-foreground border border-border rounded-md px-3 py-1.5 hover:bg-muted transition-colors"
      >
        <MessageSquare class="size-4" />
        Abrir chat
      </Link>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-4 items-start">
      <!-- MAIN COLUMN -->
      <div class="flex flex-col gap-4">
        <!-- Acciones -->
        <Card class="bg-card rounded-xl shadow-sm">
          <CardHeader>
            <CardTitle>Acciones</CardTitle>
            <CardDescription>Genera alcances y cotizaciones para este lead.</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-4">
            <div>
              <Button variant="default" @click="generateSpec">
                <FileText class="size-4" />
                Generar alcance
              </Button>
            </div>

            <Separator />

            <div class="flex flex-col gap-3">
              <p class="text-sm font-medium text-foreground">Cotización</p>

              <div class="flex flex-col gap-2">
                <Label class="text-muted-foreground">Funcionalidades</Label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  <label
                    v-for="feature in options.features"
                    :key="feature.id"
                    class="flex items-center gap-2 text-sm border border-border rounded-md px-3 py-2 bg-muted/40 cursor-pointer hover:bg-muted transition-colors"
                  >
                    <input
                      type="checkbox"
                      class="size-4 rounded border-border"
                      :checked="featureIds.includes(feature.id)"
                      @change="toggleFeature(feature.id, ($event.target as HTMLInputElement).checked)"
                    />
                    <span class="flex-1 text-foreground">{{ feature.name }}</span>
                    <span class="text-muted-foreground">{{ money(feature.price_cents) }}</span>
                  </label>
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="flex flex-col gap-1.5">
                  <Label for="builder-pages" class="text-muted-foreground">Páginas</Label>
                  <Input id="builder-pages" type="number" min="0" v-model.number="builderPages" />
                </div>
                <div class="flex flex-col gap-1.5">
                  <Label for="builder-langs" class="text-muted-foreground">Idiomas</Label>
                  <Input id="builder-langs" type="number" min="1" v-model.number="builderLanguages" />
                </div>
                <div class="flex flex-col gap-1.5">
                  <Label for="builder-discount" class="text-muted-foreground">Descuento (MXN)</Label>
                  <Input id="builder-discount" type="number" min="0" step="0.01" v-model.number="discount" />
                </div>
              </div>

              <div>
                <Button variant="default" @click="generateQuote">
                  <FileSignature class="size-4" />
                  Generar cotización
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Alcances -->
        <Card class="bg-card rounded-xl shadow-sm">
          <CardHeader>
            <CardTitle>Alcances</CardTitle>
            <CardDescription>Documentos de alcance generados.</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-2">
            <p v-if="specs.length === 0" class="text-sm text-muted-foreground">Aún no hay alcances.</p>
            <div
              v-for="spec in specs"
              :key="spec.uuid"
              class="flex items-center justify-between gap-3 border border-border rounded-lg px-3 py-2.5 bg-muted/30"
            >
              <div class="flex items-center gap-3 min-w-0">
                <Badge variant="outline">v{{ spec.version }}</Badge>
                <div class="min-w-0">
                  <p class="text-sm font-medium text-foreground truncate">{{ spec.title }}</p>
                  <p class="text-xs text-muted-foreground">{{ spec.created }}</p>
                </div>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <Badge variant="secondary">{{ spec.status_label }}</Badge>
                <a
                  v-if="spec.pdf_url"
                  :href="spec.pdf_url"
                  target="_blank"
                  class="text-sm font-medium text-foreground border border-border rounded-md px-2.5 py-1 hover:bg-muted transition-colors"
                >
                  Ver PDF
                </a>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Cotizaciones -->
        <Card class="bg-card rounded-xl shadow-sm">
          <CardHeader>
            <CardTitle>Cotizaciones</CardTitle>
            <CardDescription>Propuestas económicas enviadas al lead.</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-3">
            <p v-if="quotes.length === 0" class="text-sm text-muted-foreground">Aún no hay cotizaciones.</p>
            <div
              v-for="quote in quotes"
              :key="quote.uuid"
              class="border border-border rounded-lg p-3 bg-muted/30 flex flex-col gap-3"
            >
              <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3">
                  <p class="text-sm font-semibold text-foreground">{{ quote.number }}</p>
                  <Badge variant="secondary">{{ quote.status_label }}</Badge>
                </div>
                <div class="flex items-center gap-2">
                  <a
                    v-if="quote.pdf_url"
                    :href="quote.pdf_url"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-foreground border border-border rounded-md px-2.5 py-1 hover:bg-muted transition-colors"
                  >
                    <FileText class="size-4" />
                    Ver PDF
                  </a>
                  <Button variant="outline" size="sm" @click="sendQuote(quote.uuid)">
                    <Send class="size-4" />
                    Enviar
                  </Button>
                  <Button variant="default" size="sm" @click="acceptQuote(quote.uuid)">
                    <Check class="size-4" />
                    Aceptar
                  </Button>
                </div>
              </div>

              <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
                <div>
                  <p class="text-xs text-muted-foreground">Total</p>
                  <p class="font-medium text-foreground">{{ quote.total }}</p>
                </div>
                <div>
                  <p class="text-xs text-muted-foreground">Anticipo</p>
                  <p class="font-medium text-foreground">{{ quote.deposit }}</p>
                </div>
                <div>
                  <p class="text-xs text-muted-foreground">Mantenimiento</p>
                  <p class="font-medium text-foreground">{{ quote.maintenance ?? '—' }}</p>
                </div>
                <div>
                  <p class="text-xs text-muted-foreground">Válida hasta</p>
                  <p class="font-medium text-foreground">{{ quote.valid_until ?? '—' }}</p>
                </div>
              </div>

              <div v-if="quote.items.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-left text-muted-foreground border-b border-border">
                      <th class="py-1.5 pr-2 font-medium">Descripción</th>
                      <th class="py-1.5 px-2 font-medium text-center">Cant.</th>
                      <th class="py-1.5 pl-2 font-medium text-right">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(item, idx) in quote.items" :key="idx" class="border-b border-border/50 last:border-0">
                      <td class="py-1.5 pr-2 text-foreground">{{ item.description }}</td>
                      <td class="py-1.5 px-2 text-center text-muted-foreground">{{ item.quantity }}</td>
                      <td class="py-1.5 pl-2 text-right text-foreground">{{ item.total }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Pagos -->
        <Card class="bg-card rounded-xl shadow-sm">
          <CardHeader>
            <CardTitle>Pagos</CardTitle>
            <CardDescription>Pagos y comprobantes registrados.</CardDescription>
          </CardHeader>
          <CardContent class="flex flex-col gap-2">
            <p v-if="payments.length === 0" class="text-sm text-muted-foreground">Aún no hay pagos.</p>
            <div
              v-for="payment in payments"
              :key="payment.id"
              class="flex items-center justify-between gap-3 border border-border rounded-lg px-3 py-2.5 bg-muted/30"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium text-foreground">{{ payment.type_label }}</p>
                <p v-if="payment.reference" class="text-xs text-muted-foreground truncate">
                  Ref: {{ payment.reference }}
                </p>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <span class="text-sm font-medium text-foreground">{{ payment.amount }}</span>
                <Badge variant="secondary">{{ payment.status_label }}</Badge>
                <a
                  v-if="payment.proof_url"
                  :href="payment.proof_url"
                  target="_blank"
                  class="text-sm font-medium text-foreground border border-border rounded-md px-2.5 py-1 hover:bg-muted transition-colors"
                >
                  Ver comprobante
                </a>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- SIDEBAR COLUMN -->
      <Card class="bg-card rounded-xl shadow-sm lg:sticky lg:top-4">
        <CardHeader>
          <CardTitle>Editar lead</CardTitle>
          <CardDescription>Actualiza la información del lead.</CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-3">
          <div class="flex flex-col gap-1.5">
            <Label for="name">Nombre</Label>
            <Input id="name" v-model="form.name" />
            <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="email">Correo</Label>
            <Input id="email" type="email" v-model="form.email" />
            <p v-if="form.errors.email" class="text-xs text-destructive">{{ form.errors.email }}</p>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="company">Empresa</Label>
            <Input id="company" v-model="form.company" />
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="stage">Etapa</Label>
            <select
              id="stage"
              v-model="form.stage"
              class="border border-border rounded-md bg-background text-foreground text-sm h-9 px-3"
            >
              <option v-for="s in options.stages" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="service_id">Servicio</Label>
            <select
              id="service_id"
              v-model="form.service_id"
              class="border border-border rounded-md bg-background text-foreground text-sm h-9 px-3"
            >
              <option :value="null">Sin servicio</option>
              <option v-for="svc in options.services" :key="svc.id" :value="svc.id">{{ svc.name }}</option>
            </select>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="maintenance_plan_id">Plan de mantenimiento</Label>
            <select
              id="maintenance_plan_id"
              v-model="form.maintenance_plan_id"
              class="border border-border rounded-md bg-background text-foreground text-sm h-9 px-3"
            >
              <option :value="null">Sin plan</option>
              <option v-for="plan in options.plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div class="flex flex-col gap-1.5">
              <Label for="pages">Páginas</Label>
              <Input id="pages" type="number" min="0" v-model.number="form.pages" />
            </div>
            <div class="flex flex-col gap-1.5">
              <Label for="deposit_percent">Anticipo (%)</Label>
              <Input id="deposit_percent" type="number" min="0" max="100" v-model.number="form.deposit_percent" />
            </div>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="languages">Idiomas</Label>
            <Input id="languages" v-model="form.languages" placeholder="es, en, fr" />
            <p class="text-xs text-muted-foreground">Separa los idiomas con comas.</p>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="summary">Resumen</Label>
            <textarea
              id="summary"
              v-model="form.summary"
              rows="3"
              class="border border-border rounded-md bg-background text-foreground text-sm px-3 py-2 resize-y"
            ></textarea>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="notes">Notas</Label>
            <textarea
              id="notes"
              v-model="form.notes"
              rows="3"
              class="border border-border rounded-md bg-background text-foreground text-sm px-3 py-2 resize-y"
            ></textarea>
          </div>
        </CardContent>
        <CardFooter>
          <Button class="w-full" :disabled="form.processing" @click="saveLead">
            <Save class="size-4" />
            Guardar
          </Button>
        </CardFooter>
      </Card>
    </div>
  </div>
</template>
