<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground"
    >
        <AppLogoIcon class="size-5 fill-current text-white dark:text-black" />
    </div>
    <div class="ml-1 grid flex-1 text-left">
        <span class="truncate font-semibold leading-tight">Overcloud</span>
        <span class="truncate text-xs text-muted-foreground leading-tight">Agencia digital</span>
    </div>
</template>
