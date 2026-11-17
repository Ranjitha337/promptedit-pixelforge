<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import GenerationForm from '@/components/GenerationForm.vue';

interface Generation {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    status: string;
    estimated_cost_usd: string | null;
    actual_cost_usd: string | null;
    image_url: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
}

const generations = ref<Generation[]>([]);
const loading = ref(true);
const error = ref('');

let intervalId: ReturnType<typeof setInterval> | undefined;
let isFetching = false;

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Generations', href: '/generations' },
];

async function loadGenerations() {
    if (isFetching) return;

    isFetching = true;

    try {
        const response = await fetch('/generations/history', {
            headers: {
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Unable to load generations.');
        }

        const result = await response.json();

        generations.value = result.data;
        error.value = '';
    } catch {
        error.value = 'Unable to load history. Please try again.';
    } finally {
        loading.value = false;
        isFetching = false;
    }
}

function statusLabel(status: string): string {
    const labels: Record<string, string> = {
        pending_submission: 'Pending submission',
        queued: 'Queued',
        processing: 'Processing',
        succeeded: 'Succeeded',
        failed: 'Failed',
        submission_unknown: 'Checking submission',
    };

    return labels[status] ?? status;
}

function statusClass(status: string): string {
    if (status === 'succeeded') {
        return 'bg-green-100 text-green-800';
    }

    if (status === 'failed') {
        return 'bg-red-100 text-red-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function formatCost(amount: string | null): string {
    return amount === null ? 'Pending' : '$' + amount;
}

onMounted(() => {
    void loadGenerations();

    intervalId = setInterval(() => {
        void loadGenerations();
    }, 5000);
});

onUnmounted(() => {
    if (intervalId !== undefined) {
        clearInterval(intervalId);
    }
});
</script>

<template>
    <Head title="Image Generations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">

            <div>
                <h1 class="text-2xl font-bold">
                    Image Generations
                </h1>

                <p class="mt-1 text-sm text-muted-foreground">
                    Your generated images and their costs.
                    Statuses refresh every 5 seconds.
                </p>
            </div>
            <GenerationForm @created="loadGenerations" />
            <div v-if="error"
                 class="rounded-lg bg-red-50 p-4 text-red-700">
                {{ error }}
            </div>

            <div v-if="loading" class="text-muted-foreground">
                Loading generations...
            </div>

            <div v-else-if="generations.length === 0"
                 class="rounded-xl border p-10 text-center">
                No generations yet.
            </div>

            <div v-else
                 class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">

                <div
                    v-for="generation in generations"
                    :key="generation.id"
                    class="overflow-hidden rounded-xl border bg-card"
                >
                    <div
                        class="flex aspect-square items-center justify-center bg-muted"
                    >
                        <img
                            v-if="generation.image_url"
                            :src="generation.image_url"
                            :alt="generation.prompt"
                            class="h-full w-full object-contain"
                        />

                        <span v-else class="text-sm text-muted-foreground">
                            {{ statusLabel(generation.status) }}
                        </span>
                    </div>

                    <div class="space-y-4 p-4">

                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs text-muted-foreground">
                                Generation #{{ generation.id }}
                            </span>

                            <span
                                :class="statusClass(generation.status)"
                                class="rounded-full px-3 py-1 text-xs font-medium"
                            >
                                {{ statusLabel(generation.status) }}
                            </span>
                        </div>

                        <p class="line-clamp-3 text-sm font-medium">
                            {{ generation.prompt }}
                        </p>

                        <div class="text-xs text-muted-foreground">
                            {{ generation.size }}
                            ·
                            {{ generation.quality }}
                        </div>

                        <div class="space-y-2 border-t pt-3 text-sm">
                            <div class="flex justify-between gap-2">
                                <span>Estimated cost</span>
                                <span class="font-mono text-xs">
                                    {{ formatCost(generation.estimated_cost_usd) }}
                                </span>
                            </div>

                            <div class="flex justify-between gap-2">
                                <span>Actual charge</span>
                                <span class="font-mono text-xs font-semibold">
                                    {{ formatCost(generation.actual_cost_usd) }}
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="generation.status === 'failed'"
                            class="rounded-lg bg-red-50 p-3 text-xs text-red-700"
                        >
                            {{ generation.error_message || 'Generation failed.' }}
                        </div>

                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
