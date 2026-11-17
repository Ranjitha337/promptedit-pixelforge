<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';

const emit = defineEmits<{
    created: [];
}>();

const prompt = ref('');
const size = ref('1024x1024');
const quality = ref('medium');
const referenceUrls = ref('');

const estimatedCharge = ref<string | null>(null);
const estimating = ref(false);
const submitting = ref(false);
const error = ref('');
const success = ref('');

const referenceImages = computed(() =>
    referenceUrls.value
        .split(/\r?\n/)
        .map((url) => url.trim())
        .filter(Boolean),
);

function isHttpUrl(value: string): boolean {
    try {
        const url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

const validForm = computed(() =>
    prompt.value.trim().length > 0 &&
    prompt.value.length <= 4000 &&
    referenceImages.value.length <= 4 &&
    referenceImages.value.every(isHttpUrl),
);

let estimateTimer: ReturnType<typeof setTimeout> | undefined;
let estimateVersion = 0;

// Recalculate the estimate whenever the input changes.
watch([prompt, size, quality, referenceUrls], () => {
    estimateVersion++;
    const version = estimateVersion;

    clearTimeout(estimateTimer);
    estimatedCharge.value = null;
    estimating.value = false;
    error.value = '';
    success.value = '';

    if (!validForm.value) return;

    estimating.value = true;

    // Debounce to avoid unnecessary API requests.
    estimateTimer = setTimeout(async () => {
        try {
            const params = new URLSearchParams({
                prompt: prompt.value.trim(),
                size: size.value,
                quality: quality.value,
            });

            referenceImages.value.forEach((url) => {
                params.append('reference_images[]', url);
            });

            const response = await fetch(
                `/generations/estimate?${params.toString()}`,
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            if (!response.ok) {
                throw new Error('Unable to calculate estimate.');
            }

            const result = await response.json();

            // Ignore responses for older form values.
            if (version !== estimateVersion) return;

            estimatedCharge.value =
                result.estimated_user_cost_usd;
        } catch {
            if (version === estimateVersion) {
                error.value = 'Unable to calculate the price estimate.';
            }
        } finally {
            if (version === estimateVersion) {
                estimating.value = false;
            }
        }
    }, 500);
});

function getXsrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        throw new Error(
            'CSRF token missing. Refresh the page and try again.',
        );
    }

    return decodeURIComponent(cookie.substring('XSRF-TOKEN='.length));
}

async function submitGeneration() {
    if (!validForm.value ||
        !estimatedCharge.value ||
        estimating.value ||
        submitting.value) {
        return;
    }

    submitting.value = true;
    error.value = '';
    success.value = '';

    try {
        const token = getXsrfToken();

        const response = await fetch('/generations', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': token,
            },
            body: JSON.stringify({
                prompt: prompt.value.trim(),
                size: size.value,
                quality: quality.value,
                reference_images: referenceImages.value,
            }),
        });

        const result = await response.json();

        if (!response.ok) {
            const validationErrors = result.errors
                ? Object.values(result.errors).flat()
                : [];

            throw new Error(
                String(
                    validationErrors[0] ??
                    result.message ??
                    'Unable to create generation.',
                ),
            );
        }

        success.value =
            `Generation #${result.generation.id} queued successfully.`;

        emit('created');

        // Clear inputs only after successful submission.
        prompt.value = '';
        referenceUrls.value = '';
        estimatedCharge.value = null;
    } catch (e) {
        error.value = e instanceof Error
            ? e.message
            : 'Something went wrong.';
    } finally {
        submitting.value = false;
    }
}

onUnmounted(() => {
    estimateVersion++;
    clearTimeout(estimateTimer);
});
</script>

<template>
    <form
        @submit.prevent="submitGeneration"
        class="space-y-5 rounded-xl border bg-card p-6"
    >
        <div>
            <h2 class="text-xl font-semibold">
                Generate New Image
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Describe your image and choose its settings.
            </p>
        </div>

        <div class="space-y-2">
            <label for="image-prompt" class="text-sm font-medium">
                Image Prompt
            </label>

            <textarea
                id="image-prompt"
                v-model="prompt"
                rows="4"
                maxlength="4000"
                placeholder="A beautiful mountain landscape at sunrise..."
                class="w-full rounded-lg border bg-background p-3 text-sm"
            />

            <p class="text-right text-xs text-muted-foreground">
                {{ prompt.length }} / 4000 characters
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-2">
                <label for="image-size" class="text-sm font-medium">
                    Image Size
                </label>

                <select
                    id="image-size"
                    v-model="size"
                    class="w-full rounded-lg border bg-background p-3 text-sm"
                >
                    <option value="1024x1024">
                        Square — 1024 × 1024
                    </option>
                    <option value="1536x1024">
                        Landscape — 1536 × 1024
                    </option>
                    <option value="1024x1536">
                        Portrait — 1024 × 1536
                    </option>
                </select>
            </div>

            <div class="space-y-2">
                <label for="image-quality" class="text-sm font-medium">
                    Quality
                </label>

                <select
                    id="image-quality"
                    v-model="quality"
                    class="w-full rounded-lg border bg-background p-3 text-sm"
                >
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>
            </div>
        </div>

        <div class="space-y-2">
            <label for="references" class="text-sm font-medium">
                Reference Image URLs (Optional)
            </label>

            <textarea
                id="references"
                v-model="referenceUrls"
                rows="3"
                placeholder="https://example.com/image.png"
                class="w-full rounded-lg border bg-background p-3 text-sm"
            />

            <p class="text-xs text-muted-foreground">
                Enter one URL per line. Maximum 4 images.
            </p>
        </div>

        <div class="rounded-lg border bg-muted/40 p-4">
            <p class="text-sm font-medium">
                Estimated Customer Charge
            </p>

            <p v-if="estimating" class="mt-2 text-sm">
                Calculating price...
            </p>

            <template v-else-if="estimatedCharge">
                <p class="mt-2 text-2xl font-bold">
                    ${{ Number(estimatedCharge).toFixed(4) }}
                </p>

                <p class="mt-1 text-xs text-muted-foreground">
                    Exact estimate: ${{ estimatedCharge }} USD
                </p>
            </template>

            <p v-else class="mt-2 text-sm text-muted-foreground">
                Enter a valid prompt to see pricing.
            </p>

            <p class="mt-2 text-xs text-muted-foreground">
                Includes a 25% markup.
                Final price is based on actual token usage.
                Failed generations cost $0.
            </p>
        </div>

        <p
            v-if="error"
            role="alert"
            class="rounded-lg bg-red-50 p-3 text-sm text-red-700"
        >
            {{ error }}
        </p>

        <p
            v-if="success"
            role="status"
            class="rounded-lg bg-green-50 p-3 text-sm text-green-700"
        >
            {{ success }}
        </p>

        <button
            type="submit"
            :disabled="
                !validForm ||
                !estimatedCharge ||
                estimating ||
                submitting
            "
            class="w-full rounded-lg bg-primary px-5 py-3 font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
        >
            {{ submitting ? 'Submitting...' : 'Generate Image' }}
        </button>
    </form>
</template>
