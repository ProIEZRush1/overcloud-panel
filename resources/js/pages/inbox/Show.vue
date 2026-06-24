<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { Send, Bot, User, ExternalLink } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'

interface ConversationListItem {
  id: number
  name: string | null
  phone: string | null
  is_group: boolean
  status: string
  ai_enabled: boolean
  account: string | null
  lead_uuid: string | null
  lead_name: string | null
}

interface Conversation {
  id: number
  name: string | null
  phone: string | null
  is_group: boolean
  status: string
  ai_enabled: boolean
  account: string | null
  lead_uuid: string | null
  lead_name: string | null
}

interface Message {
  id: number
  direction: string
  type: string
  body: string | null
  caption: string | null
  media_url: string | null
  media_mime: string | null
  is_from_me: boolean
  ai_generated: boolean
  status: string
  at: string
}

const props = defineProps<{
  conversations: ConversationListItem[]
  conversation: Conversation
  messages: Message[]
}>()

const form = useForm({ body: '' })

function isImage(mime: string | null): boolean {
  return !!mime && mime.startsWith('image/')
}

function toggleBot(): void {
  router.post('/inbox/' + props.conversation.id + '/toggle-bot', {}, { preserveScroll: true })
}

function submit(): void {
  form.post('/inbox/' + props.conversation.id + '/reply', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  })
}
</script>

<template>
  <Head title="Conversación" />

  <div class="p-4 flex flex-col gap-4">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-semibold text-foreground">Conversación</h1>
    </div>

    <div class="grid md:grid-cols-[300px_1fr] gap-4 h-[calc(100vh-9rem)]">
      <!-- LEFT: conversations list -->
      <Card class="overflow-hidden flex flex-col">
        <CardHeader class="py-3">
          <CardTitle class="text-base">Conversaciones</CardTitle>
          <CardDescription>{{ conversations.length }} en total</CardDescription>
        </CardHeader>
        <CardContent class="p-2 flex-1 overflow-y-auto">
          <div class="flex flex-col gap-1">
            <Link
              v-for="c in conversations"
              :key="c.id"
              :href="'/inbox/' + c.id"
              class="block rounded-lg border border-border px-3 py-2 transition-colors hover:bg-muted"
              :class="c.id === conversation.id ? 'bg-muted border-primary/40' : 'bg-card'"
            >
              <div class="flex items-center gap-2 min-w-0">
                <span
                  class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                >
                  <User class="h-4 w-4" />
                </span>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center justify-between gap-2">
                    <span class="truncate text-sm font-medium text-foreground">
                      {{ c.name || c.phone || 'Sin nombre' }}
                    </span>
                    <Badge v-if="c.ai_enabled" variant="secondary" class="shrink-0 text-[10px]">IA</Badge>
                  </div>
                  <p class="truncate text-xs text-muted-foreground">
                    {{ c.phone || '—' }}<template v-if="c.account"> · {{ c.account }}</template>
                  </p>
                </div>
              </div>
            </Link>
          </div>
        </CardContent>
      </Card>

      <!-- RIGHT: chat panel -->
      <Card class="flex flex-col overflow-hidden">
        <CardHeader class="border-b border-border py-3">
          <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
              >
                <User class="h-5 w-5" />
              </span>
              <div class="min-w-0">
                <CardTitle class="truncate text-base">
                  {{ conversation.name || conversation.phone || 'Sin nombre' }}
                </CardTitle>
                <CardDescription class="truncate">
                  {{ conversation.phone || '—' }}
                </CardDescription>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <Link
                v-if="conversation.lead_uuid"
                :href="'/leads/' + conversation.lead_uuid"
              >
                <Button variant="outline" size="sm">
                  <ExternalLink class="mr-1 h-4 w-4" />
                  Ver lead
                </Button>
              </Link>
              <Button
                :variant="conversation.ai_enabled ? 'default' : 'outline'"
                size="sm"
                @click="toggleBot"
              >
                <Bot class="mr-1 h-4 w-4" />
                Bot: {{ conversation.ai_enabled ? 'on' : 'off' }}
              </Button>
            </div>
          </div>
        </CardHeader>

        <!-- messages -->
        <CardContent class="flex-1 overflow-y-auto bg-muted/30 p-4">
          <div class="flex flex-col gap-3">
            <div
              v-for="m in messages"
              :key="m.id"
              class="flex flex-col"
              :class="m.is_from_me ? 'items-end' : 'items-start'"
            >
              <div
                class="max-w-[75%] rounded-xl px-3 py-2 text-sm shadow-sm"
                :class="m.is_from_me ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground'"
              >
                <img
                  v-if="m.media_url && isImage(m.media_mime)"
                  :src="m.media_url"
                  class="max-w-[200px] rounded"
                />
                <p v-if="m.body || m.caption" class="whitespace-pre-wrap break-words">
                  {{ m.body || m.caption }}
                </p>
                <a
                  v-else-if="m.media_url && !isImage(m.media_mime)"
                  :href="m.media_url"
                  target="_blank"
                  class="underline"
                >
                  Ver adjunto
                </a>
              </div>
              <div class="mt-1 flex items-center gap-1">
                <span class="text-[11px] text-muted-foreground">{{ m.at }}</span>
                <Badge v-if="m.ai_generated" variant="secondary" class="text-[10px]">IA</Badge>
              </div>
            </div>

            <p
              v-if="!messages.length"
              class="py-8 text-center text-sm text-muted-foreground"
            >
              No hay mensajes todavía.
            </p>
          </div>
        </CardContent>

        <!-- composer -->
        <CardFooter class="border-t border-border p-3">
          <form class="flex w-full items-center gap-2" @submit.prevent="submit">
            <Input
              v-model="form.body"
              placeholder="Escribe un mensaje..."
              class="flex-1"
              :disabled="form.processing"
            />
            <Button type="submit" :disabled="form.processing || !form.body">
              <Send class="mr-1 h-4 w-4" />
              Enviar
            </Button>
          </form>
        </CardFooter>
      </Card>
    </div>
  </div>
</template>
