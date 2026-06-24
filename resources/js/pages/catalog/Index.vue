<script setup lang="ts">
import { computed, reactive } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { Package, Save, Building2, Settings } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'

interface Service {
  id: number
  key: string
  name: string
  category: string | null
  base_price: number
  base_maintenance: number
  per_page: number
  per_language: number
  included_pages: number
  is_active: boolean
}

interface Func {
  id: number
  name: string
  category: string | null
  build: number
  maintenance: number
  applies_to: string[] | null
  is_active: boolean
}

interface Bank {
  id: number
  label: string
  bank: string | null
  beneficiary: string | null
  account_number: string | null
  clabe: string | null
  is_default: boolean
  is_active: boolean
}

interface SettingsShape {
  company_name: string | null
  brand_primary: string | null
  default_deposit_percent: number | null
  quote_valid_days: number | null
}

const props = defineProps<{
  services: Service[]
  functions: Func[]
  banks: Bank[]
  settings: SettingsShape
}>()

const functionsByCategory = computed(() => {
  const groups: Record<string, Func[]> = {}
  for (const f of props.functions) {
    const cat = f.category ?? 'Otros'
    ;(groups[cat] ??= []).push(f)
  }
  return groups
})

const currency = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })

const serviceRows = reactive(props.services.map((s) => ({ ...s })))
const bankRows = reactive(props.banks.map((b) => ({ ...b })))
const settingsForm = reactive<SettingsShape>({ ...props.settings })

function saveService(s: Service): void {
  router.put(
    '/catalog/services/' + s.id,
    {
      base_price: s.base_price,
      base_maintenance: s.base_maintenance,
      per_page: s.per_page,
      per_language: s.per_language,
      included_pages: s.included_pages,
      is_active: s.is_active,
    },
    { preserveScroll: true },
  )
}

function saveBank(b: Bank): void {
  router.put(
    '/catalog/banks/' + b.id,
    {
      label: b.label,
      bank: b.bank,
      beneficiary: b.beneficiary,
      account_number: b.account_number,
      clabe: b.clabe,
      is_default: b.is_default,
      is_active: b.is_active,
    },
    { preserveScroll: true },
  )
}

function saveSettings(): void {
  router.put(
    '/catalog/settings',
    {
      company_name: settingsForm.company_name,
      brand_primary: settingsForm.brand_primary,
      default_deposit_percent: settingsForm.default_deposit_percent,
      quote_valid_days: settingsForm.quote_valid_days,
    },
    { preserveScroll: true },
  )
}
</script>

<template>
  <Head title="Catálogo" />

  <div class="p-4 flex flex-col gap-4">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-semibold text-foreground">Catálogo</h1>
    </div>

    <!-- Servicios -->
    <Card class="rounded-xl shadow-sm">
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Package class="size-5 text-muted-foreground" />
          Servicios
        </CardTitle>
        <CardDescription>Edita precios base y reglas de cada servicio.</CardDescription>
      </CardHeader>
      <CardContent>
        <div class="overflow-x-auto rounded-xl border border-border">
          <table class="w-full text-sm">
            <thead class="bg-muted text-muted-foreground">
              <tr class="text-left">
                <th class="px-3 py-2 font-medium">Servicio</th>
                <th class="px-3 py-2 font-medium">Precio base</th>
                <th class="px-3 py-2 font-medium">Mant. base/mes</th>
                <th class="px-3 py-2 font-medium">Por página</th>
                <th class="px-3 py-2 font-medium">Por idioma</th>
                <th class="px-3 py-2 font-medium">Págs. incluidas</th>
                <th class="px-3 py-2 font-medium">Activo</th>
                <th class="px-3 py-2 font-medium text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="s in serviceRows"
                :key="s.id"
                class="border-t border-border align-middle"
              >
                <td class="px-3 py-2">
                  <div class="font-medium text-foreground">{{ s.name }}</div>
                  <div class="text-xs text-muted-foreground">
                    {{ s.key }}<span v-if="s.category"> · {{ s.category }}</span>
                  </div>
                </td>
                <td class="px-3 py-2">
                  <Input v-model.number="s.base_price" type="number" class="w-28" />
                </td>
                <td class="px-3 py-2">
                  <Input v-model.number="s.base_maintenance" type="number" class="w-24" />
                </td>
                <td class="px-3 py-2">
                  <Input v-model.number="s.per_page" type="number" class="w-24" />
                </td>
                <td class="px-3 py-2">
                  <Input v-model.number="s.per_language" type="number" class="w-24" />
                </td>
                <td class="px-3 py-2">
                  <Input v-model.number="s.included_pages" type="number" class="w-24" />
                </td>
                <td class="px-3 py-2">
                  <input v-model="s.is_active" type="checkbox" class="size-4 rounded border-border" />
                </td>
                <td class="px-3 py-2 text-right">
                  <Button size="sm" @click="saveService(s)">
                    <Save class="size-4" />
                    Guardar
                  </Button>
                </td>
              </tr>
              <tr v-if="serviceRows.length === 0">
                <td colspan="8" class="px-3 py-6 text-center text-muted-foreground">
                  No hay servicios registrados.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>

    <!-- Catálogo de funciones -->
    <Card class="rounded-xl shadow-sm">
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Package class="size-5 text-muted-foreground" />
          Catálogo de funciones
        </CardTitle>
        <CardDescription>
          El precio de cada proyecto y su mantenimiento mensual se calculan según las funciones incluidas — sin planes fijos.
        </CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-5">
        <div v-for="(items, cat) in functionsByCategory" :key="cat" class="flex flex-col gap-2">
          <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ cat }}</p>
          <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <div
              v-for="f in items"
              :key="f.id"
              class="flex items-center justify-between gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"
            >
              <span class="text-foreground">{{ f.name }}</span>
              <span class="whitespace-nowrap text-right leading-tight">
                <span class="font-medium text-foreground">{{ currency.format(f.build) }}</span>
                <span v-if="f.maintenance" class="block text-xs text-muted-foreground">+{{ currency.format(f.maintenance) }}/mes</span>
              </span>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Cuentas bancarias -->
    <Card class="rounded-xl shadow-sm">
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Building2 class="size-5 text-muted-foreground" />
          Cuentas bancarias
        </CardTitle>
        <CardDescription>Datos para recibir depósitos y transferencias.</CardDescription>
      </CardHeader>
      <CardContent class="flex flex-col gap-4">
        <div class="grid gap-4 lg:grid-cols-2">
          <Card v-for="b in bankRows" :key="b.id" class="rounded-xl border-border bg-card shadow-sm">
            <CardHeader>
              <div class="flex items-center justify-between">
                <CardTitle class="text-base">{{ b.label || 'Cuenta' }}</CardTitle>
                <Badge v-if="b.is_default">Predeterminada</Badge>
              </div>
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
              <div class="flex flex-col gap-1.5">
                <Label :for="'label-' + b.id">Etiqueta</Label>
                <Input :id="'label-' + b.id" v-model="b.label" />
              </div>
              <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                  <Label :for="'bank-' + b.id">Banco</Label>
                  <Input :id="'bank-' + b.id" v-model="b.bank" />
                </div>
                <div class="flex flex-col gap-1.5">
                  <Label :for="'beneficiary-' + b.id">Beneficiario</Label>
                  <Input :id="'beneficiary-' + b.id" v-model="b.beneficiary" />
                </div>
              </div>
              <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                  <Label :for="'account-' + b.id">Número de cuenta</Label>
                  <Input :id="'account-' + b.id" v-model="b.account_number" />
                </div>
                <div class="flex flex-col gap-1.5">
                  <Label :for="'clabe-' + b.id">CLABE</Label>
                  <Input :id="'clabe-' + b.id" v-model="b.clabe" />
                </div>
              </div>
              <label class="flex items-center gap-2 text-sm text-foreground">
                <input v-model="b.is_default" type="checkbox" class="size-4 rounded border-border" />
                Establecer como predeterminada
              </label>
            </CardContent>
            <CardFooter class="justify-end">
              <Button size="sm" @click="saveBank(b)">
                <Save class="size-4" />
                Guardar
              </Button>
            </CardFooter>
          </Card>
        </div>
        <p v-if="bankRows.length === 0" class="text-sm text-muted-foreground">No hay cuentas registradas.</p>
      </CardContent>
    </Card>

    <!-- Configuración -->
    <Card class="rounded-xl shadow-sm">
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Settings class="size-5 text-muted-foreground" />
          Configuración
        </CardTitle>
        <CardDescription>Ajustes generales del negocio y cotizaciones.</CardDescription>
      </CardHeader>
      <CardContent>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="flex flex-col gap-1.5">
            <Label for="company_name">Nombre de la empresa</Label>
            <Input id="company_name" v-model="settingsForm.company_name" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="brand_primary">Color primario</Label>
            <div class="flex items-center gap-2">
              <span
                class="size-9 shrink-0 rounded-md border border-border"
                :style="{ backgroundColor: settingsForm.brand_primary || 'transparent' }"
              />
              <Input id="brand_primary" v-model="settingsForm.brand_primary" placeholder="#000000" />
            </div>
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="default_deposit_percent">Anticipo predeterminado (%)</Label>
            <Input id="default_deposit_percent" v-model.number="settingsForm.default_deposit_percent" type="number" />
          </div>
          <div class="flex flex-col gap-1.5">
            <Label for="quote_valid_days">Vigencia de cotización (días)</Label>
            <Input id="quote_valid_days" v-model.number="settingsForm.quote_valid_days" type="number" />
          </div>
        </div>
      </CardContent>
      <Separator />
      <CardFooter class="justify-end pt-4">
        <Button @click="saveSettings">
          <Save class="size-4" />
          Guardar
        </Button>
      </CardFooter>
    </Card>
  </div>
</template>
