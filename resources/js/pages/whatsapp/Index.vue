<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref, onUnmounted } from 'vue'
import { MessageCircle, QrCode, LogOut, Trash2, Plus } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'

interface Account {
    id: number
    label: string
    session_name: string
    phone: string | null
    status: string
    status_label: string
    is_default: boolean
    auto_reply: boolean
    last_connected_at: string | null
}

defineProps<{ accounts: Account[] }>()

const form = useForm({ label: '' })

function addNumber() {
    form.post('/whatsapp', { preserveScroll: true, onSuccess: () => form.reset() })
}

function statusBadgeClass(status: string): string {
    if (status === 'connected') return 'bg-emerald-500/15 text-emerald-600 border-emerald-500/30'
    if (status === 'qr_pending' || status === 'connecting') return 'bg-amber-500/15 text-amber-600 border-amber-500/30'
    if (status === 'logged_out') return 'bg-red-500/15 text-red-600 border-red-500/30'
    return 'bg-muted text-muted-foreground border-border'
}

const modalAccount = ref<Account | null>(null)
const qrState = ref<{ status: string; status_label: string; qr: string | null }>({
    status: '',
    status_label: '',
    qr: null,
})
const pollId = ref<number | null>(null)

function stopPolling() {
    if (pollId.value !== null) {
        clearInterval(pollId.value)
        pollId.value = null
    }
}

async function pollStatus(id: number) {
    try {
        const r = await fetch('/whatsapp/' + id + '/status', { headers: { Accept: 'application/json' } })
        const j = await r.json()
        qrState.value = {
            status: j.status,
            status_label: j.status_label,
            qr: j.qr ?? null,
        }
        if (j.status === 'connected') {
            stopPolling()
        }
    } catch (e) {
        // ignore transient errors, keep polling
    }
}

function openModal(account: Account) {
    modalAccount.value = account
    qrState.value = { status: account.status, status_label: account.status_label, qr: null }
    router.post('/whatsapp/' + account.id + '/connect', {}, { preserveScroll: true })
    pollStatus(account.id)
    stopPolling()
    pollId.value = window.setInterval(() => {
        if (modalAccount.value) pollStatus(modalAccount.value.id)
    }, 2000)
}

function closeModal() {
    stopPolling()
    modalAccount.value = null
    qrState.value = { status: '', status_label: '', qr: null }
}

function logout(id: number) {
    router.post('/whatsapp/' + id + '/logout', {}, { preserveScroll: true })
}

function remove(id: number) {
    router.delete('/whatsapp/' + id, { preserveScroll: true })
}

onUnmounted(() => {
    stopPolling()
})
</script>

<template>
    <Head title="WhatsApp" />

    <div class="p-4 flex flex-col gap-4">
        <h1 class="text-xl font-semibold text-foreground">Números de WhatsApp</h1>

        <Card class="bg-card border-border rounded-xl shadow-sm">
            <CardHeader>
                <CardTitle class="text-base">Agregar número</CardTitle>
                <CardDescription>Crea una nueva sesión de WhatsApp para vincular un número.</CardDescription>
            </CardHeader>
            <CardContent>
                <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="addNumber">
                    <div class="flex flex-col gap-1.5 flex-1">
                        <Label for="label">Etiqueta</Label>
                        <Input
                            id="label"
                            v-model="form.label"
                            type="text"
                            placeholder="Ej. Ventas, Soporte..."
                        />
                    </div>
                    <Button type="submit" :disabled="form.processing || !form.label">
                        <Plus class="size-4" />
                        Agregar número
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Separator />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="account in accounts"
                :key="account.id"
                class="bg-card border-border rounded-xl shadow-sm flex flex-col"
            >
                <CardHeader>
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                                <MessageCircle class="size-5 text-muted-foreground" />
                            </span>
                            <div class="min-w-0">
                                <CardTitle class="truncate text-base">{{ account.label }}</CardTitle>
                                <CardDescription class="truncate">
                                    {{ account.phone ?? 'sin conectar' }}
                                </CardDescription>
                            </div>
                        </div>
                        <Badge v-if="account.is_default" variant="secondary" class="shrink-0">
                            Predeterminado
                        </Badge>
                    </div>
                </CardHeader>

                <CardContent class="flex flex-col gap-2">
                    <div>
                        <Badge :class="statusBadgeClass(account.status)" variant="outline">
                            {{ account.status_label }}
                        </Badge>
                    </div>
                    <p v-if="account.last_connected_at" class="text-xs text-muted-foreground">
                        Última conexión: {{ account.last_connected_at }}
                    </p>
                </CardContent>

                <CardFooter class="mt-auto flex flex-wrap gap-2">
                    <Button size="sm" @click="openModal(account)">
                        <QrCode class="size-4" />
                        Conectar / Ver QR
                    </Button>
                    <Button size="sm" variant="outline" @click="logout(account.id)">
                        <LogOut class="size-4" />
                        Cerrar sesión
                    </Button>
                    <Button size="sm" variant="destructive" @click="remove(account.id)">
                        <Trash2 class="size-4" />
                        Eliminar
                    </Button>
                </CardFooter>
            </Card>

            <p
                v-if="accounts.length === 0"
                class="text-sm text-muted-foreground col-span-full rounded-xl border border-border border-dashed bg-muted/30 p-8 text-center"
            >
                No hay números configurados todavía. Agrega uno para comenzar.
            </p>
        </div>

        <div
            v-if="modalAccount"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeModal"
        >
            <div class="w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-sm">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-foreground">{{ modalAccount.label }}</h2>
                        <p class="text-sm text-muted-foreground">Vincular dispositivo</p>
                    </div>
                    <Button size="sm" variant="ghost" @click="closeModal">Cerrar</Button>
                </div>

                <Separator class="my-4" />

                <div class="flex flex-col items-center gap-3 py-2">
                    <template v-if="qrState.status === 'connected'">
                        <div class="flex size-16 items-center justify-center rounded-full bg-emerald-500/15">
                            <MessageCircle class="size-8 text-emerald-600" />
                        </div>
                        <p class="text-lg font-semibold text-foreground">¡Conectado!</p>
                        <p class="text-sm text-muted-foreground">
                            {{ modalAccount.phone ?? 'Dispositivo vinculado correctamente.' }}
                        </p>
                    </template>

                    <template v-else-if="qrState.qr">
                        <img :src="qrState.qr" class="w-64 h-64 rounded-lg border border-border" alt="Código QR" />
                        <p class="text-center text-sm text-muted-foreground">
                            Escanea este código con WhatsApp &gt; Dispositivos vinculados
                        </p>
                    </template>

                    <template v-else>
                        <div class="flex size-16 items-center justify-center rounded-full bg-muted">
                            <QrCode class="size-8 text-muted-foreground" />
                        </div>
                        <p class="text-sm text-muted-foreground">Generando QR...</p>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
