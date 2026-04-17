<!DOCTYPE html>
<html lang="en" x-data="probeApp()" x-init="init()" :class="darkMode ? 'dark' : ''">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Probe | Laravel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        probe: { 50: '#f0f4ff', 500: '#3D5A80', 600: '#2C3E50', 900: '#1A1A2E' }
                    }
                }
            }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .entry-row:hover { cursor: pointer; }
        pre { white-space: pre-wrap; word-break: break-all; }
        .tag-slow { background: #fef3c7; color: #92400e; }
        .tag-n1   { background: #fee2e2; color: #991b1b; }
        .tag-error, .tag-exception { background: #fee2e2; color: #991b1b; }
        .tag-hit  { background: #d1fae5; color: #065f46; }
        .tag-miss { background: #fef3c7; color: #92400e; }
        .dark .tag-slow { background: #451a03; color: #fbbf24; }
        .dark .tag-n1   { background: #450a0a; color: #f87171; }
        .dark .tag-hit  { background: #052e16; color: #4ade80; }
        .dark .tag-miss { background: #451a03; color: #fbbf24; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-950 text-gray-900 dark:text-gray-100 font-sans text-sm min-h-screen">

<div x-cloak class="flex h-screen overflow-hidden">

    {{-- ========== SIDEBAR ========== --}}
    <aside class="w-56 flex-shrink-0 bg-probe-900 dark:bg-gray-900 text-white flex flex-col">
        <div class="px-4 py-5 border-b border-probe-600">
            <div class="text-lg font-bold tracking-wide">Probe</div>
            <div class="text-xs text-gray-400">Laravel Debugger</div>
        </div>

        <nav class="flex-1 py-3 overflow-y-auto">
            <template x-for="item in navItems" :key="item.type">
                <button
                    @click="selectType(item.type)"
                    :class="activeType === item.type ? 'bg-probe-500 text-white' : 'text-gray-300 hover:bg-probe-600 hover:text-white'"
                    class="w-full flex items-center justify-between px-4 py-2 text-left transition"
                >
                    <div class="flex items-center gap-2">
                        <span x-text="item.icon" class="text-base"></span>
                        <span x-text="item.label" class="capitalize"></span>
                    </div>
                    <span
                        x-show="stats[item.type]"
                        x-text="stats[item.type] ?? ''"
                        class="text-xs bg-white/20 px-1.5 py-0.5 rounded-full"
                    ></span>
                </button>
            </template>
        </nav>

        <div class="px-4 py-3 border-t border-probe-600 flex items-center justify-between">
            <button @click="darkMode = !darkMode" class="text-gray-400 hover:text-white text-xs">
                <span x-text="darkMode ? '☀ Light' : '🌙 Dark'"></span>
            </button>
            <div class="flex items-center gap-1 text-xs">
                <span
                    :class="streaming ? 'bg-green-400' : 'bg-gray-500'"
                    class="w-2 h-2 rounded-full inline-block"
                ></span>
                <span x-text="streaming ? 'Live' : 'Offline'" class="text-gray-400"></span>
            </div>
        </div>
    </aside>

    {{-- ========== MAIN ========== --}}
    <div class="flex-1 flex flex-col overflow-hidden">

        {{-- Toolbar --}}
        <header class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-4 py-2 flex items-center gap-3">
            <input
                x-model.debounce.400ms="search"
                @input="loadEntries()"
                type="text"
                placeholder="Search entries…"
                class="flex-1 text-sm border border-gray-200 dark:border-gray-700 dark:bg-gray-800 rounded px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-probe-500"
            >
            <select
                x-model="tagFilter"
                @change="loadEntries()"
                class="text-sm border border-gray-200 dark:border-gray-700 dark:bg-gray-800 rounded px-2 py-1.5"
            >
                <option value="">All tags</option>
                <option value="slow">slow</option>
                <option value="n1">n+1</option>
                <option value="failed">failed</option>
                <option value="exception">exception</option>
            </select>
            <button
                @click="loadEntries()"
                class="px-3 py-1.5 text-xs bg-probe-500 text-white rounded hover:bg-probe-600 transition"
            >Refresh</button>
            <span x-text="activeType" class="text-xs text-gray-400 capitalize"></span>
        </header>

        <div class="flex flex-1 overflow-hidden">

            {{-- Entry list --}}
            <div class="w-2/5 border-r border-gray-200 dark:border-gray-700 flex flex-col overflow-hidden bg-white dark:bg-gray-900">

                <div x-show="loading" class="p-4 text-center text-gray-400 text-xs">Loading…</div>

                <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                    <template x-if="entries.length === 0 && !loading">
                        <div class="p-8 text-center text-gray-400 text-xs">No entries yet. Make some requests.</div>
                    </template>
                    <template x-for="entry in entries" :key="entry.id">
                        <div
                            @click="selectEntry(entry.id)"
                            :class="selectedId === entry.id ? 'bg-probe-50 dark:bg-probe-900/40 border-l-2 border-probe-500' : 'hover:bg-gray-50 dark:hover:bg-gray-800'"
                            class="entry-row px-3 py-2 transition"
                        >
                            <div class="flex items-start justify-between gap-1">
                                <div class="flex-1 min-w-0">
                                    <div class="font-mono text-xs truncate" x-text="entry.summary"></div>
                                    <div class="flex gap-1 mt-1 flex-wrap">
                                        <template x-for="tag in entry.tags" :key="tag">
                                            <span
                                                :class="'tag-' + tag"
                                                class="text-xs px-1.5 py-0.5 rounded-full font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300"
                                                x-text="tag"
                                            ></span>
                                        </template>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-400 whitespace-nowrap" x-text="timeAgo(entry.created_at)"></div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Pagination --}}
                <div x-show="pagination" class="border-t border-gray-100 dark:border-gray-800 px-3 py-2 flex items-center justify-between text-xs text-gray-500">
                    <span x-text="`${pagination?.total ?? 0} total`"></span>
                    <div class="flex gap-2">
                        <button
                            @click="goPage(pagination.current_page - 1)"
                            :disabled="pagination?.current_page <= 1"
                            class="px-2 py-1 rounded border border-gray-200 dark:border-gray-700 disabled:opacity-40"
                        >←</button>
                        <span x-text="`${pagination?.current_page} / ${pagination?.last_page}`"></span>
                        <button
                            @click="goPage(pagination.current_page + 1)"
                            :disabled="pagination?.current_page >= pagination?.last_page"
                            class="px-2 py-1 rounded border border-gray-200 dark:border-gray-700 disabled:opacity-40"
                        >→</button>
                    </div>
                </div>
            </div>

            {{-- Entry detail --}}
            <div class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-950 p-4">
                <div x-show="!detail && !detailLoading" class="text-center text-gray-400 text-xs mt-16">
                    Select an entry to inspect.
                </div>
                <div x-show="detailLoading" class="text-center text-gray-400 text-xs mt-16">Loading detail…</div>

                <template x-if="detail">
                    <div>
                        {{-- Header --}}
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <span class="text-xs text-gray-400 uppercase tracking-wide" x-text="detail.type"></span>
                                <div class="flex gap-1 mt-1 flex-wrap">
                                    <template x-for="tag in detail.tags" :key="tag">
                                        <span
                                            :class="'tag-' + tag"
                                            class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300"
                                            x-text="tag"
                                        ></span>
                                    </template>
                                </div>
                            </div>
                            <div class="text-xs text-gray-400" x-text="detail.created_at"></div>
                        </div>

                        {{-- N+1 alert --}}
                        <template x-if="detail.tags.includes('n1')">
                            <div class="mb-4 p-3 rounded bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-xs">
                                <strong>N+1 detected.</strong> This query fingerprint was executed more than the configured threshold in a single request. Consider eager-loading the related data.
                            </div>
                        </template>

                        {{-- Slow query alert --}}
                        <template x-if="detail.tags.includes('slow')">
                            <div class="mb-4 p-3 rounded bg-yellow-50 dark:bg-yellow-950 border border-yellow-200 dark:border-yellow-800 text-yellow-700 dark:text-yellow-300 text-xs">
                                <strong>Slow query.</strong> This query exceeded the configured slow threshold.
                            </div>
                        </template>

                        {{-- Sections --}}
                        <template x-for="[section, value] in Object.entries(detail.content)" :key="section">
                            <div class="mb-4">
                                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1" x-text="section.replace(/_/g, ' ')"></div>
                                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded p-3">
                                    <template x-if="typeof value === 'object' && value !== null && !Array.isArray(value)">
                                        <div>
                                            <template x-for="[k, v] in Object.entries(value)" :key="k">
                                                <div class="flex gap-2 py-0.5 border-b border-gray-50 dark:border-gray-800 last:border-0">
                                                    <span class="text-xs text-gray-400 w-40 flex-shrink-0 font-mono" x-text="k"></span>
                                                    <span class="text-xs font-mono break-all" x-text="JSON.stringify(v)"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="Array.isArray(value)">
                                        <pre class="text-xs font-mono" x-text="JSON.stringify(value, null, 2)"></pre>
                                    </template>
                                    <template x-if="typeof value !== 'object' || value === null">
                                        <pre class="text-xs font-mono" x-text="value"></pre>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

        </div>
    </div>
</div>

<script>
function probeApp() {
    const base = '{{ rtrim(config("probe.path"), "/") }}';

    return {
        darkMode: localStorage.getItem('probe-dark') === '1',
        activeType: 'requests',
        entries: [],
        pagination: null,
        selectedId: null,
        detail: null,
        loading: false,
        detailLoading: false,
        search: '',
        tagFilter: '',
        streaming: false,
        stats: {},
        currentPage: 1,

        navItems: [
            { type: 'requests',   label: 'Requests',   icon: '🌐' },
            { type: 'queries',    label: 'Queries',    icon: '🗄' },
            { type: 'exceptions', label: 'Exceptions', icon: '🔥' },
            { type: 'jobs',       label: 'Jobs',       icon: '⚙' },
            { type: 'cache',      label: 'Cache',      icon: '⚡' },
            { type: 'schedule',   label: 'Schedule',   icon: '🕐' },
        ],

        init() {
            this.$watch('darkMode', v => localStorage.setItem('probe-dark', v ? '1' : '0'));
            this.loadEntries();
            this.loadStats();
            this.startStream();
        },

        selectType(type) {
            this.activeType = type;
            this.currentPage = 1;
            this.search = '';
            this.tagFilter = '';
            this.detail = null;
            this.selectedId = null;
            this.loadEntries();
        },

        async loadEntries() {
            this.loading = true;
            const params = new URLSearchParams({
                type: this.activeType,
                page: this.currentPage,
                per_page: 50,
            });
            if (this.search)    params.set('search', this.search);
            if (this.tagFilter) params.set('tag', this.tagFilter);

            try {
                const res  = await fetch(`/${base}/api/entries?${params}`);
                const data = await res.json();
                this.entries    = data.data;
                this.pagination = {
                    current_page: data.current_page,
                    last_page:    data.last_page,
                    total:        data.total,
                };
            } finally {
                this.loading = false;
            }
        },

        async selectEntry(id) {
            this.selectedId    = id;
            this.detail        = null;
            this.detailLoading = true;
            try {
                const res  = await fetch(`/${base}/api/entries/${id}`);
                this.detail = await res.json();
            } finally {
                this.detailLoading = false;
            }
        },

        async loadStats() {
            const res  = await fetch(`/${base}/api/stats`);
            this.stats = await res.json();
        },

        goPage(page) {
            if (page < 1 || page > (this.pagination?.last_page ?? 1)) return;
            this.currentPage = page;
            this.loadEntries();
        },

        startStream() {
            const source = new EventSource(`/${base}/api/stream`);
            source.onopen    = () => { this.streaming = true; };
            source.onerror   = () => { this.streaming = false; };
            source.onmessage = (e) => {
                const entry = JSON.parse(e.data);
                if (entry.type !== this.activeType) {
                    this.stats[entry.type] = (this.stats[entry.type] ?? 0) + 1;
                    return;
                }
                this.stats[entry.type] = (this.stats[entry.type] ?? 0) + 1;
                // Prepend to list without refetching (best-effort).
                this.loadEntries();
            };
        },

        timeAgo(ts) {
            if (!ts) return '';
            const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
            if (diff < 60)   return `${diff}s ago`;
            if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
            return `${Math.floor(diff/3600)}h ago`;
        },
    };
}
</script>
</body>
</html>
