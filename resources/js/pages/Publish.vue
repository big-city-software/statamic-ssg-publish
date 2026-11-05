<script setup>
import { Head } from '@statamic/cms/inertia';
import { computed, onBeforeUnmount, ref } from 'vue';
import { cpJson } from '../api';

const props = defineProps({
    edition: String,
    pro: Boolean,
    publishUrl: String,
    statusUrl: String,
    targets: { type: Array, default: () => [] },
    changes: { type: Object, default: () => ({ enabled: false, items: [] }) },
    auto: { type: Object, default: () => ({}) },
});

const targets = ref(props.targets);
const changes = ref(props.changes);
const alert = ref(null);
const publishing = ref(null);
let timer = null;

const publishable = computed(() => targets.value.filter((target) => !target.locked && target.configured));
const primary = computed(() => publishable.value[0] ?? null);
const watching = computed(() => props.pro && targets.value.some((target) => ['queued', 'in_progress'].includes(target.status?.state)));

function toneFor(state) {
    if (state === 'success') return 'success';
    if (state === 'rate_limited' || state === 'queued') return 'rate_limited';
    return 'failure';
}

async function publish(target) {
    if (publishing.value || !props.publishUrl) {
        return;
    }

    if (!target || !target.configured) {
        const missing = target?.missing?.length ? target.missing.join(', ') : 'its settings';
        alert.value = {
            tone: 'failure',
            message: `This target is not ready. Missing ${missing}. Add ${target?.missing?.length === 1 ? 'it' : 'them'} on the server. The value is not shown here.`,
        };
        return;
    }

    publishing.value = target.handle;
    alert.value = null;

    const { ok, status, data } = await cpJson(props.publishUrl, {
        method: 'POST',
        body: JSON.stringify({ target: target.handle }),
    });

    publishing.value = null;

    if (data.page) {
        targets.value = data.page.targets;
        changes.value = data.page.changes;
    }

    if (status === 403) {
        alert.value = { tone: 'failure', message: 'You do not have permission to publish the static site.' };
        return;
    }

    alert.value = {
        tone: ok ? toneFor(data.state) : 'failure',
        message: data.message || 'The publish request failed.',
    };
}

async function refresh() {
    if (!props.pro || !props.statusUrl) {
        return;
    }

    const { ok, data } = await cpJson(props.statusUrl);
    if (ok && data.targets) {
        targets.value = data.targets;
        changes.value = data.changes;
    }
}

function startWatching() {
    if (timer || !watching.value) {
        return;
    }

    timer = setInterval(refresh, 15000);
}

startWatching();

onBeforeUnmount(() => {
    if (timer) {
        clearInterval(timer);
    }
});
</script>

<template>
    <Head title="SSG Publish" />

    <ui-header title="SSG Publish">
        <template #actions>
            <span class="ssg-publish__action-button" :data-ready="primary ? 'true' : 'false'">
                <ui-button
                    variant="primary"
                    :disabled="!primary || publishing"
                    @click="publish(primary)"
                >
                    {{ publishing === primary?.handle ? 'Publishing…' : 'Publish' }}
                </ui-button>
            </span>
        </template>
    </ui-header>

    <div class="ssg-publish">
        <p class="ssg-publish__lead">
            Rebuild the static site when entries, terms, or globals change. The request is sent
            from the server. Tokens and hook URLs stay in the environment.
        </p>

        <p v-if="alert" class="ssg-publish__alert" :data-tone="alert.tone" role="status">
            {{ alert.message }}
        </p>

        <ui-panel v-if="targets.length === 0">
            <p class="ssg-publish__empty">
                No deploy targets yet. Publish <code>config/ssg-publish.php</code> and add a GitHub
                Actions workflow or a Cloudflare Pages, Netlify, or Vercel deploy hook.
            </p>
        </ui-panel>

        <ui-panel v-for="target in targets" :key="target.handle">
            <div class="ssg-publish__target-head">
                <div>
                    <strong>{{ target.label }}</strong>
                    <p class="ssg-publish__meta">{{ target.driver_label }}</p>
                </div>
                <div class="ssg-publish__target-head">
                    <span class="ssg-publish__status" :data-state="target.status.state">
                        <span class="ssg-publish__dot" aria-hidden="true"></span>
                        {{ target.status.label }}
                    </span>
                    <ui-button
                        :disabled="target.locked || !target.configured || publishing === target.handle"
                        @click="publish(target)"
                    >
                        {{ publishing === target.handle ? 'Publishing…' : 'Publish' }}
                    </ui-button>
                </div>
            </div>

            <p v-if="!target.configured" class="ssg-publish__note">
                Missing {{ target.missing.join(', ') }}. Add {{ target.missing.length === 1 ? 'it' : 'them' }} on the server. The value is not shown here.
            </p>
            <p v-else-if="target.status.message" class="ssg-publish__note">
                {{ target.status.message }}
                <a v-if="target.status.url" :href="target.status.url" target="_blank" rel="noreferrer">View run</a>
                <template v-if="target.status.run_number"> · Run {{ target.status.run_number }}</template>
                <template v-if="target.status.triggered_at"> · Last trigger {{ target.status.triggered_at }}</template>
                <template v-if="target.status.http_status"> · HTTP {{ target.status.http_status }}</template>
            </p>
            <p v-if="target.status.response_excerpt" class="ssg-publish__note">
                {{ target.status.response_excerpt }}
            </p>
        </ui-panel>

        <ui-panel>
            <template v-if="changes.enabled">
                <strong>Changed since the last successful publish</strong>
                <p v-if="changes.items.length === 0" class="ssg-publish__note">
                    Nothing has changed since the last successful publish.
                </p>
                <ul v-else class="ssg-publish__changes">
                    <li v-for="item in changes.items" :key="item.key" class="ssg-publish__change">
                        <span class="ssg-publish__action">{{ item.action }}</span>
                        <a v-if="item.url" :href="item.url">{{ item.title }}</a>
                        <span v-else>{{ item.title }}</span>
                        <span v-if="item.container" class="ssg-publish__meta">{{ item.container }}</span>
                    </li>
                </ul>
            </template>
            <template v-else>
                <strong>Change list</strong>
                <p class="ssg-publish__note">
                    The free edition publishes the first target when you press the button. Build status,
                    the change list, scheduled publish, and extra targets are part of SSG Publish Pro.
                </p>
            </template>
            <p v-if="auto.on_save || auto.scheduled" class="ssg-publish__note">
                <template v-if="auto.on_save">Saves publish {{ auto.debounce_seconds }} seconds after the last change.</template>
                <template v-if="auto.scheduled"> Scheduled entries publish after their date passes.</template>
                Both wait for <code>php please schedule:run</code>.
            </p>
        </ui-panel>
    </div>
</template>
