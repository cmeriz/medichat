<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';

const messages = ref([]);
const draft = ref('');
const isSending = ref(false);
const sessionId = ref(null);
const messagesEnd = ref(null);
let channel = null;

onMounted(async () => {
    try {
        const { data } = await window.axios.get('/chat/session');

        sessionId.value = data.session_id;
        messages.value.push(data.message);

        channel = window.Echo.channel(`chat-session.${sessionId.value}`);
        channel.listen('.chat.message', (event) => {
            messages.value.push(event.message);
            scrollToBottom();
        });

        scrollToBottom();
    } catch (error) {
        ElMessage.error('The chat could not be started.');
    }
});

onBeforeUnmount(() => {
    if (sessionId.value) {
        window.Echo.leave(`chat-session.${sessionId.value}`);
    }
});

async function sendMessage() {
    const content = draft.value.trim();

    if (!content || isSending.value) {
        return;
    }

    messages.value.push({
        id: messageId(),
        role: 'user',
        content,
        created_at: new Date().toISOString(),
    });

    draft.value = '';
    isSending.value = true;
    scrollToBottom();

    try {
        await window.axios.post('/chat/message', { content });
    } catch (error) {
        ElMessage.error('Your message could not be sent.');
    } finally {
        isSending.value = false;
    }
}

function scrollToBottom() {
    nextTick(() => {
        messagesEnd.value?.scrollIntoView({ behavior: 'smooth' });
    });
}

function messageId() {
    return window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
}

function renderMarkdown(markdown) {
    const lines = escapeHtml(markdown).split(/\r?\n/);
    const html = [];
    let listType = null;

    for (const line of lines) {
        const trimmed = line.trim();
        const unordered = trimmed.match(/^[-*]\s+(.+)/);
        const ordered = trimmed.match(/^\d+\.\s+(.+)/);

        if (!trimmed) {
            closeList();
            continue;
        }

        if (unordered || ordered) {
            const nextListType = unordered ? 'ul' : 'ol';

            if (listType !== nextListType) {
                closeList();
                html.push(`<${nextListType}>`);
                listType = nextListType;
            }

            html.push(`<li>${renderInline(unordered?.[1] ?? ordered[1])}</li>`);
            continue;
        }

        closeList();

        if (trimmed.startsWith('### ')) {
            html.push(`<h3>${renderInline(trimmed.slice(4))}</h3>`);
        } else if (trimmed.startsWith('## ')) {
            html.push(`<h2>${renderInline(trimmed.slice(3))}</h2>`);
        } else if (trimmed.startsWith('# ')) {
            html.push(`<h1>${renderInline(trimmed.slice(2))}</h1>`);
        } else {
            html.push(`<p>${renderInline(trimmed)}</p>`);
        }
    }

    closeList();

    return html.join('');

    function closeList() {
        if (listType) {
            html.push(`</${listType}>`);
            listType = null;
        }
    }
}

function renderInline(text) {
    return text
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/\*([^*]+)\*/g, '<em>$1</em>');
}

function escapeHtml(text) {
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
</script>

<template>
    <main class="min-h-screen bg-slate-100 text-slate-950">
        <section class="mx-auto flex min-h-screen w-full max-w-4xl flex-col px-4 py-6 sm:px-6">
            <header class="mb-4 flex items-center justify-between border-b border-slate-200 pb-4">
                <div>
                    <p class="text-sm font-semibold text-teal-700">MediChat Demo</p>
                    <h1 class="text-2xl font-bold tracking-normal">Medical Exam Chat</h1>
                </div>
                <el-tag type="success" effect="plain">Websocket demo</el-tag>
            </header>

            <div class="flex min-h-0 flex-1 flex-col rounded border border-slate-200 bg-white">
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4 sm:p-6">
                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="flex"
                        :class="message.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[78%] rounded px-4 py-3 text-sm leading-6 shadow-sm"
                            :class="message.role === 'user'
                                ? 'bg-teal-600 text-white'
                                : 'border border-slate-200 bg-slate-50 text-slate-800'"
                        >
                            <span v-if="message.role === 'user'">{{ message.content }}</span>
                            <div
                                v-else
                                class="markdown-message"
                                v-html="renderMarkdown(message.content)"
                            ></div>
                        </div>
                    </div>
                    <div ref="messagesEnd"></div>
                </div>

                <form class="border-t border-slate-200 p-4" @submit.prevent="sendMessage">
                    <div class="flex gap-3">
                        <el-input
                            v-model="draft"
                            size="large"
                            placeholder="Type your message"
                            :disabled="isSending"
                            @keyup.enter="sendMessage"
                        />
                        <el-button
                            native-type="submit"
                            size="large"
                            type="primary"
                            :loading="isSending"
                        >
                            Send
                        </el-button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</template>

<style scoped>
.markdown-message :deep(p) {
    margin: 0 0 0.65rem;
}

.markdown-message :deep(p:last-child) {
    margin-bottom: 0;
}

.markdown-message :deep(ul),
.markdown-message :deep(ol) {
    margin: 0.35rem 0 0.65rem 1.25rem;
    padding: 0;
}

.markdown-message :deep(ul) {
    list-style: disc;
}

.markdown-message :deep(ol) {
    list-style: decimal;
}

.markdown-message :deep(li) {
    margin: 0.2rem 0;
}

.markdown-message :deep(strong) {
    font-weight: 700;
}

.markdown-message :deep(em) {
    font-style: italic;
}

.markdown-message :deep(code) {
    border-radius: 4px;
    background: rgb(226 232 240);
    padding: 0.1rem 0.3rem;
    font-size: 0.85em;
}

.markdown-message :deep(h1),
.markdown-message :deep(h2),
.markdown-message :deep(h3) {
    margin: 0 0 0.5rem;
    font-weight: 700;
    line-height: 1.3;
}

.markdown-message :deep(h1) {
    font-size: 1rem;
}

.markdown-message :deep(h2),
.markdown-message :deep(h3) {
    font-size: 0.95rem;
}
</style>
