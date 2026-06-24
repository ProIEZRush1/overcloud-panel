<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { Inbox, Users, Bot } from '@lucide/vue'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'

interface Conversation {
  id: number
  name: string | null
  preview: string | null
  at: string | null
  unread: number
  is_group: boolean
  ai_enabled: boolean
  stage: string | null
}

defineProps<{
  conversations: Conversation[]
}>()

function initials(name: string | null): string {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}
</script>

<template>
  <Head title="Conversaciones" />

  <div class="p-4 flex flex-col gap-4">
    <div class="flex items-center gap-2">
      <Inbox class="size-5 text-muted-foreground" />
      <h1 class="text-xl font-semibold text-foreground">Conversaciones</h1>
    </div>

    <Card class="rounded-xl shadow-sm">
      <CardContent class="p-0">
        <div
          v-if="conversations.length === 0"
          class="flex flex-col items-center justify-center gap-2 py-16 text-center"
        >
          <Inbox class="size-10 text-muted-foreground" />
          <p class="text-sm font-medium text-foreground">No hay conversaciones</p>
          <p class="text-sm text-muted-foreground">
            Cuando recibas mensajes, aparecerán aquí.
          </p>
        </div>

        <div v-else class="divide-y divide-border">
          <Link
            v-for="conv in conversations"
            :key="conv.id"
            :href="'/inbox/' + conv.id"
            class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted"
          >
            <div
              class="flex size-11 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold text-foreground"
            >
              <Users v-if="conv.is_group" class="size-5 text-muted-foreground" />
              <span v-else>{{ initials(conv.name) }}</span>
            </div>

            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2">
                <span class="truncate text-sm font-medium text-foreground">
                  {{ conv.name ?? 'Sin nombre' }}
                </span>
                <Badge v-if="conv.stage" variant="secondary" class="shrink-0">
                  {{ conv.stage }}
                </Badge>
              </div>
              <p class="truncate text-sm text-muted-foreground">
                {{ conv.preview ?? 'Sin mensajes' }}
              </p>
            </div>

            <div class="flex shrink-0 flex-col items-end gap-1.5">
              <span class="text-xs text-muted-foreground">{{ conv.at ?? '' }}</span>
              <div class="flex items-center gap-1.5">
                <Badge v-if="conv.unread > 0" class="shrink-0">
                  {{ conv.unread }}
                </Badge>
                <Badge
                  :variant="conv.ai_enabled ? 'default' : 'outline'"
                  class="shrink-0 gap-1"
                >
                  <Bot class="size-3" />
                  {{ conv.ai_enabled ? 'Bot' : 'Manual' }}
                </Badge>
              </div>
            </div>
          </Link>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
