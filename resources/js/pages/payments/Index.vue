<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { CreditCard, Check, X, FileImage } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'

interface PaymentRequest {
  id: number
  lead: { uuid: string | null; name: string | null }
  type: string
  type_label: string
  amount: string
  status: string
  status_label: string
  reference: string | null
  proof_url: string | null
  proof_mime: string | null
  created: string
}

const props = defineProps<{
  requests: PaymentRequest[]
  to_review: number
}>()

function statusClasses(status: string): string {
  switch (status) {
    case 'proof_submitted':
      return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/20'
    case 'verified':
      return 'bg-green-500/15 text-green-600 dark:text-green-400 border-green-500/20'
    case 'rejected':
      return 'bg-red-500/15 text-red-600 dark:text-red-400 border-red-500/20'
    default:
      return 'bg-muted text-muted-foreground border-border'
  }
}

function isImage(mime: string | null): boolean {
  return !!mime && mime.startsWith('image')
}

function canReview(status: string): boolean {
  return status === 'proof_submitted' || status === 'pending'
}

function verify(id: number): void {
  router.post('/payments/' + id + '/verify', {}, { preserveScroll: true })
}

function reject(id: number): void {
  router.post('/payments/' + id + '/reject', { notes: '' }, { preserveScroll: true })
}
</script>

<template>
  <Head title="Pagos" />

  <div class="p-4 flex flex-col gap-4">
    <div class="flex items-center justify-between gap-2">
      <h1 class="text-xl font-semibold text-foreground flex items-center gap-2">
        <CreditCard class="size-5 text-muted-foreground" />
        Pagos
      </h1>
      <Badge variant="secondary" class="rounded-full">
        {{ props.to_review }} por revisar
      </Badge>
    </div>

    <div v-if="props.requests.length === 0" class="rounded-xl border border-border bg-card text-muted-foreground text-sm p-8 text-center shadow-sm">
      No hay pagos para mostrar.
    </div>

    <div v-else class="flex flex-col gap-4">
      <Card v-for="req in props.requests" :key="req.id" class="rounded-xl shadow-sm">
        <CardHeader>
          <div class="flex items-start justify-between gap-3">
            <div class="flex flex-col gap-1">
              <CardTitle class="text-base">
                <Link
                  v-if="req.lead.uuid"
                  :href="'/leads/' + req.lead.uuid"
                  class="hover:underline text-foreground"
                >
                  {{ req.lead.name ?? 'Sin nombre' }}
                </Link>
                <span v-else class="text-foreground">{{ req.lead.name ?? 'Sin nombre' }}</span>
              </CardTitle>
              <CardDescription>{{ req.type_label }}</CardDescription>
            </div>
            <Badge variant="outline" :class="statusClasses(req.status)">
              {{ req.status_label }}
            </Badge>
          </div>
        </CardHeader>

        <CardContent class="flex flex-col gap-3">
          <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="flex flex-col">
              <span class="text-xs text-muted-foreground">Monto</span>
              <span class="text-lg font-bold text-foreground">{{ req.amount }}</span>
            </div>
            <div v-if="req.reference" class="flex flex-col text-right">
              <span class="text-xs text-muted-foreground">Referencia</span>
              <span class="text-sm text-foreground font-mono">{{ req.reference }}</span>
            </div>
          </div>

          <template v-if="req.proof_url">
            <Separator />
            <div>
              <a
                v-if="isImage(req.proof_mime)"
                :href="req.proof_url"
                target="_blank"
                rel="noopener"
                class="inline-block rounded-md border border-border overflow-hidden bg-muted hover:opacity-90 transition"
              >
                <img :src="req.proof_url" alt="Comprobante" class="h-32 w-auto object-cover" />
              </a>
              <a
                v-else
                :href="req.proof_url"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-2 text-sm text-foreground hover:underline"
              >
                <FileImage class="size-4 text-muted-foreground" />
                Ver comprobante
              </a>
            </div>
          </template>
        </CardContent>

        <CardFooter class="flex items-center justify-between gap-2">
          <span class="text-xs text-muted-foreground">{{ req.created }}</span>
          <div v-if="canReview(req.status)" class="flex items-center gap-2">
            <Button size="sm" @click="verify(req.id)">
              <Check class="size-4" />
              Verificar
            </Button>
            <Button size="sm" variant="outline" @click="reject(req.id)">
              <X class="size-4" />
              Rechazar
            </Button>
          </div>
        </CardFooter>
      </Card>
    </div>
  </div>
</template>
