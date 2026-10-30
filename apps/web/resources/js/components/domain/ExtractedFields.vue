<script setup lang="ts">
import { ScanText, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { enumLabel } from '@/lib/domain/status';
import { humanize, t, tc, tOr } from '@/lib/i18n';
import type { ExtractedField, PaymentRequestShowProps } from '@/types/domain';

const props = defineProps<{
    extraction: PaymentRequestShowProps['extraction'];
}>();

const fmt = useFormat();

type Row = {
    key: string;
    value: string;
    field: ExtractedField<unknown>;
    mono: boolean;
};

const rows = computed<Row[]>(() => {
    const fields = props.extraction.fields;
    const out: Row[] = [];
    const push = (
        key: string,
        value: string,
        field: ExtractedField<unknown> | undefined,
        mono = false,
    ) => {
        if (field) {
            out.push({ key, value, field, mono });
        }
    };

    push('vendor_name', fields.vendor_name?.value ?? '', fields.vendor_name);
    push(
        'invoice_number',
        fields.invoice_number?.value ?? '',
        fields.invoice_number,
        true,
    );
    push(
        'invoice_date',
        fields.invoice_date ? fmt.value.date(fields.invoice_date.value) : '',
        fields.invoice_date,
    );
    push(
        'due_date',
        fields.due_date ? fmt.value.date(fields.due_date.value) : '',
        fields.due_date,
    );
    push('amount', fields.amount?.value.formatted ?? '', fields.amount);
    push(
        'payee_last4',
        fields.payee_last4 ? `•••• ${fields.payee_last4.value}` : '',
        fields.payee_last4,
        true,
    );

    return out;
});

function confidenceTone(value: number): string {
    if (value >= 85) {
        return 'bg-success';
    }

    return value >= 60 ? 'bg-warning' : 'bg-destructive';
}

function sourceLabel(source: string): string {
    return tOr(`scampause.extraction.sources.${source}`, humanize(source));
}
</script>

<template>
    <div class="space-y-4" data-test="extracted-fields">
        <div
            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
        >
            <span
                v-if="extraction.status"
                class="inline-flex h-6 items-center gap-1 rounded-sm border bg-muted px-2 font-medium"
            >
                <ScanText class="size-3.5" aria-hidden="true" />
                {{ enumLabel('extraction_status', extraction.status) }}
            </span>
            <span v-if="extraction.pages">{{
                tc('scampause.files.pages', extraction.pages)
            }}</span>
            <span v-if="extraction.ocr_used"
                >· {{ t('scampause.extraction.ocr') }}</span
            >
            <span v-if="extraction.llm_assisted"
                >· {{ t('scampause.extraction.assisted') }}</span
            >
        </div>

        <dl v-if="rows.length" class="divide-y rounded-lg border">
            <div
                v-for="row in rows"
                :key="row.key"
                class="grid grid-cols-1 gap-1 px-3 py-2.5 sm:grid-cols-[11rem_1fr_auto] sm:items-center sm:gap-3"
                :data-field="row.key"
            >
                <dt class="text-sm text-muted-foreground">
                    {{ t(`scampause.extraction.fields.${row.key}`) }}
                </dt>
                <dd
                    :class="[
                        'min-w-0 truncate font-medium',
                        row.mono ? 'font-mono' : '',
                    ]"
                >
                    {{ row.value || '—' }}
                </dd>
                <dd
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <span
                        class="h-1.5 w-12 overflow-hidden rounded-full bg-muted"
                        role="img"
                        :aria-label="
                            t('scampause.extraction.confidence', {
                                value: row.field.confidence,
                            })
                        "
                    >
                        <span
                            :class="[
                                'block h-full',
                                confidenceTone(row.field.confidence),
                            ]"
                            :style="{
                                width: `${Math.min(100, Math.max(0, row.field.confidence))}%`,
                            }"
                        />
                    </span>
                    <span class="font-mono tabular-nums"
                        >{{ row.field.confidence }}%</span
                    >
                    <span>{{ sourceLabel(row.field.source) }}</span>
                </dd>
            </div>
        </dl>
        <p v-else class="text-sm text-muted-foreground">
            {{ t('scampause.extraction.none') }}
        </p>

        <ul v-if="extraction.warnings.length" class="space-y-1.5">
            <li
                v-for="warning in extraction.warnings"
                :key="warning"
                class="flex items-start gap-2 text-sm text-hazard-ink"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ warning }}
            </li>
        </ul>
    </div>
</template>
