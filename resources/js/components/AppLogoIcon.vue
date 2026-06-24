<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            d="M6.5 20A4.5 4.5 0 0 1 5.9 11.06 6 6 0 0 1 17.7 9.2 4.25 4.25 0 0 1 17.5 20h-11Z"
        />
    </svg>
</template>
