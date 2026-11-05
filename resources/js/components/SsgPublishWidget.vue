<script setup>
import { Widget } from '@statamic/cms/ui';
import { ref } from 'vue';
import { cpJson } from '../api';

const props = defineProps({
    authorized: { type: Boolean, default: false },
    edition: String,
    pro: Boolean,
    utilityUrl: String,
    publishUrl: String,
    target: { type: Object, default: null },
    changeCount: { type: Number, default: null },
});

const publishing = ref(false);
const message = ref(null);
const tone = ref(null);
const target = ref(props.target);

async function publish() {
    if (!props.authorized || publishing.value || !props.publishUrl) {
        return;
    }

    if (!target.value?.configured) {
        tone.value = 'failure';
        const missing = target.value?.missing?.join(', ') || 'its settings';
        message.value = `This target is not ready. Missing ${missing}. Add them on the server. The value is not shown here.`;
        return;
    }

    publishing.value = true;
    message.value = null;

    const { ok, status, data } = await cpJson(props.publishUrl, {
        method: 'POST',
        body: JSON.stringify({ target: target.value.handle }),
    });

    publishing.value = false;

    if (status === 403) {
        tone.value = 'failure';
        message.value = 'You do not have permission to publish the static site.';
        return;
    }

    const next = data.page?.targets?.find((item) => item.handle === target.value.handle);
    if (next) {
        target.value = next;
    }

    tone.value = ok ? 'success' : 'failure';
    message.value = data.message || 'The publish request failed.';
}
</script>

<template>
    <Widget title="SSG Publish" :href="utilityUrl">
        <div class="px-4 py-3 ssg-publish__widget">
            <p v-if="!authorized">You do not have permission to publish the static site.</p>
            <template v-else-if="!target">
                <p>No deploy target is configured yet.</p>
                <p v-if="utilityUrl"><a :href="utilityUrl">Open SSG Publish</a></p>
            </template>
            <template v-else>
                <div class="ssg-publish__widget-row">
                    <div>
                        <strong>{{ target.label }}</strong>
                        <p class="ssg-publish__meta">{{ target.driver_label }} · {{ edition }}</p>
                    </div>
                    <span class="ssg-publish__action-button" :data-ready="target.configured ? 'true' : 'false'">
                        <ui-button
                            variant="primary"
                            :disabled="!target.configured || publishing"
                            @click="publish"
                        >
                            {{ publishing ? 'Publishing…' : 'Publish' }}
                        </ui-button>
                    </span>
                </div>
                <p v-if="!target.configured">
                    Missing {{ target.missing.join(', ') }}. Add {{ target.missing.length === 1 ? 'it' : 'them' }} on the server. The value is not shown here.
                </p>
                <p v-else-if="pro && target.status" class="ssg-publish__status" :data-state="target.status.state">
                    <span class="ssg-publish__dot" aria-hidden="true"></span>
                    {{ target.status.label }}
                    <a v-if="target.status.url" :href="target.status.url" target="_blank" rel="noreferrer">View run</a>
                </p>
                <p v-if="message" class="ssg-publish__alert" :data-tone="tone" role="status">{{ message }}</p>
                <p v-if="pro && changeCount !== null">{{ changeCount }} change{{ changeCount === 1 ? '' : 's' }} since the last successful publish.</p>
            </template>
        </div>
    </Widget>
</template>
