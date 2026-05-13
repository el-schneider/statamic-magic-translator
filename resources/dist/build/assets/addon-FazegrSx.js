import{i as b,a as p,r as h,b as m,g as i,s as f,w as y,c as v,d as k,e as S,f as x,m as w,h as C,j as u,k as I,l as _}from"./store-DBllWM2y.js";function l(e,t={}){return __("magic-translator::messages."+e,t)}const T={name:"MagicTranslatorDialog",props:{entryId:{type:String,default:null},entryIds:{type:Array,default:()=>[]},sourceSite:{type:String,required:!0},sites:{type:Array,required:!0}},data(){return{selectedSource:this.sourceSite,selectedLocales:[],generateSlug:!1,overwrite:!1,session:null,unsubscribeFn:null,markedCurrentHandles:[],markCurrentPending:{},markCurrentErrors:{},unsubscribeMarked:null}},computed:{isBulk(){return this.entryIds.length>1},allEntryIds(){const e=this;return e.entryIds.length>0?e.entryIds:e.entryId?[e.entryId]:[]},dialogTitle(){const e=this;return e.isBulk?l("dialog_title_bulk",{count:e.allEntryIds.length}):l("dialog_title_single")},targetSites(){const e=this;return e.sites.filter(t=>t.handle!==e.selectedSource)},translationSessionKey(){return _(this.allEntryIds)},singleEntryId(){const e=this;return e.allEntryIds.length===1?e.allEntryIds[0]??null:null},localeState(){var t;return((t=this.session)==null?void 0:t.localeState)??{}},isTranslating(){var t;return((t=this.session)==null?void 0:t.isTranslating)??!1},allDone(){var t;return((t=this.session)==null?void 0:t.isComplete)??!1},hasFailed(){var t;return((t=this.session)==null?void 0:t.hasFailed)??!1}},created(){const e=this;e.applySessionSnapshot(u(e.translationSessionKey)),e.subscribeToSession(),I(e.translationSessionKey);const t=u(e.translationSessionKey);(!t||!t.isTranslating)&&e.syncSelectedLocales();const s=e.singleEntryId;s!==null&&(e.markedCurrentHandles=[...i(s)],e.unsubscribeMarked=f(s,()=>{e.markedCurrentHandles=[...i(s)]}))},watch:{selectedSource(){const e=this;e.isTranslating||e.syncSelectedLocales()},overwrite(e){const t=this;if(t.isTranslating||e)return;const s=new Set(t.targetSites.filter(a=>!!a.exists).map(a=>a.handle));t.selectedLocales=t.selectedLocales.filter(a=>!s.has(a))}},beforeDestroy(){var t,s;const e=this;(t=e.unsubscribeFn)==null||t.call(e),(s=e.unsubscribeMarked)==null||s.call(e)},methods:{t(e,t={}){return __("magic-translator::messages."+e,t)},hasExistingTranslation(e){return!!e.exists},isLocaleDisabled(e){const t=this;return t.isTranslating||t.hasExistingTranslation(e)&&!t.overwrite},isMarkedCurrent(e){return this.markedCurrentHandles.includes(e)},isEffectivelyStale(e){return this.isMarkedCurrent(e.handle)?!1:!!e.is_stale},hasEffectiveTranslation(e){return this.isMarkedCurrent(e.handle)?!0:!!e.last_translated_at},shouldShowMarkCurrentButton(e){const t=this;return t.allEntryIds.length!==1||t.localeState[e.handle]||!t.hasExistingTranslation(e)||t.isMarkedCurrent(e.handle)?!1:t.isEffectivelyStale(e)||!t.hasEffectiveTranslation(e)},async handleMarkCurrentClick(e){var a;const t=this,s=t.allEntryIds[0];if(s){t.markCurrentPending={...t.markCurrentPending,[e]:!0},t.markCurrentErrors={...t.markCurrentErrors,[e]:""};try{const r=await w(s,e);if(!r.success){const n=((a=r.error)==null?void 0:a.message)??l("mark_current_failed");t.markCurrentErrors={...t.markCurrentErrors,[e]:n},console.error("[magic-translator] Mark current failed:",r.error??r);return}C(s,e),Statamic.$toast.success(l("mark_current_success"))}catch(r){const n=r&&typeof r=="object"&&"message"in r?String(r.message):l("mark_current_failed");t.markCurrentErrors={...t.markCurrentErrors,[e]:n},console.error("[magic-translator] Mark current request failed:",r)}finally{t.markCurrentPending={...t.markCurrentPending,[e]:!1}}}},syncSelectedLocales(){const e=this;e.selectedLocales=e.targetSites.filter(t=>!t.exists||e.overwrite).map(t=>t.handle)},applySessionSnapshot(e){const t=this;t.session=e,e&&(t.selectedSource=e.sourceSite,t.selectedLocales=[...e.selectedLocales],t.generateSlug=e.options.generateSlug,t.overwrite=e.options.overwrite)},subscribeToSession(){var t;const e=this;(t=e.unsubscribeFn)==null||t.call(e),e.unsubscribeFn=x(e.translationSessionKey,s=>{e.applySessionSnapshot(s)})},cancel(){this.$emit("close")},async translate(){const e=this;!e.selectedLocales.length||e.isTranslating||await S({entryIds:e.allEntryIds,sourceSite:e.selectedSource,selectedLocales:e.selectedLocales,options:{generateSlug:e.generateSlug,overwrite:e.overwrite}})},async retryLocale(e){await k(this.translationSessionKey,e)}},template:`
        <div class="fixed inset-0 z-200 flex items-center justify-center">
            <!-- Backdrop -->
            <div class="absolute inset-0" style="background:rgba(0,0,0,0.5)" @click="cancel"></div>

            <!-- Dialog panel -->
            <div class="relative bg-white dark:bg-dark-550 rounded-lg shadow-2xl w-full max-w-lg mx-4 overflow-hidden">

                <!-- Header -->
                <div class="flex items-center justify-between px-6 py-4 border-b dark:border-dark-900">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ dialogTitle }}
                    </h2>
                    <button
                        type="button"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 text-xl leading-none w-6 h-6 flex items-center justify-center"
                        @click="cancel"
                    >&times;</button>
                </div>

                <!-- Body -->
                <div class="p-6 space-y-5 max-h-[65vh] overflow-y-auto">

                    <div class="flex gap-4">
                        <!-- Source locale selector -->
                        <div class="w-1/2 space-y-2">
                            <label class="publish-field-label font-medium text-gray-800 dark:text-dark-150">
                                {{ t('source') }}
                            </label>
                            <div class="select-input-container">
                                <select
                                    v-model="selectedSource"
                                    :disabled="isTranslating"
                                    class="select-input"
                                >
                                    <option v-for="site in sites" :key="site.handle" :value="site.handle">
                                        {{ site.name }}
                                    </option>
                                </select>
                                <div class="select-input-toggle">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <!-- Options (top-right) -->
                        <div class="w-1/2 bg-gray-50 dark:bg-dark-600 rounded border border-gray-300 dark:border-dark-800 p-4 space-y-3">
                            <label class="publish-field-label font-medium text-gray-800 dark:text-dark-150">
                                {{ t('options') }}
                            </label>
                            <div class="space-y-2">
                                <label class="flex items-center gap-2 text-sm cursor-pointer" :class="{ 'opacity-60': isTranslating }">
                                    <input v-model="generateSlug" type="checkbox" :disabled="isTranslating" class="rounded text-blue"/>
                                    {{ t('generate_slugs') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm cursor-pointer" :class="{ 'opacity-60': isTranslating }">
                                    <input v-model="overwrite" type="checkbox" :disabled="isTranslating" class="rounded text-blue"/>
                                    {{ t('overwrite_existing') }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Target locale rows -->
                    <div class="space-y-2">
                        <label class="publish-field-label font-medium text-gray-800 dark:text-dark-150">
                            {{ t('sites_panel_label') }}
                        </label>

                        <div class="border border-gray-300 dark:border-dark-800 rounded overflow-hidden">
                        <div
                            v-for="(site, index) in targetSites"
                            :key="site.handle"
                            class="text-sm flex items-center gap-3 px-4 py-2.5 border-b border-gray-200 dark:border-dark-800"
                            :class="[
                                index === targetSites.length - 1 ? 'border-b-0' : '',
                                isLocaleDisabled(site)
                                    ? 'bg-gray-300 dark:bg-dark-600 text-gray-700 dark:text-dark-150 cursor-not-allowed'
                                    : selectedLocales.includes(site.handle)
                                        ? 'bg-gray-200 dark:bg-dark-300'
                                        : 'bg-white dark:bg-dark-550 hover:bg-gray-100 dark:hover:bg-dark-500'
                            ]"
                        >
                            <input
                                :id="'ct-locale-' + site.handle"
                                v-model="selectedLocales"
                                type="checkbox"
                                :value="site.handle"
                                :disabled="isLocaleDisabled(site)"
                                class="rounded text-blue"
                                :class="{ 'opacity-50': isLocaleDisabled(site) }"
                            />

                            <label
                                :for="'ct-locale-' + site.handle"
                                class="flex-1 text-sm cursor-pointer select-none flex items-center gap-2"
                                :class="{ 'font-medium': selectedLocales.includes(site.handle) }"
                            >
                                <span
                                    class="little-dot shrink-0"
                                    :class="{
                                        'bg-orange': isEffectivelyStale(site),
                                        'bg-green-600': hasExistingTranslation(site) && !isEffectivelyStale(site),
                                        'bg-red-500': !hasExistingTranslation(site)
                                    }"
                                ></span>

                                {{ site.name }}
                            </label>

                            <div v-if="!localeState[site.handle]" class="flex items-center gap-2 shrink-0">
                                <button
                                    v-if="shouldShowMarkCurrentButton(site)"
                                    type="button"
                                    class="text-2xs text-blue underline hover:no-underline disabled:opacity-60 disabled:no-underline"
                                    :disabled="Boolean(markCurrentPending[site.handle])"
                                    @click="handleMarkCurrentClick(site.handle)"
                                >
                                    {{ t('mark_current_button') }}
                                </button>

                                <span
                                    v-if="isEffectivelyStale(site)"
                                    class="badge-sm bg-orange dark:bg-orange-dark"
                                >
                                    {{ t('badge_outdated') }}
                                </span>

                                <span
                                    v-else-if="hasEffectiveTranslation(site)"
                                    class="badge-sm bg-blue dark:bg-dark-blue-175"
                                >
                                    {{ t('badge_translated') }}
                                </span>

                                <span v-if="markCurrentErrors[site.handle]" class="text-2xs text-red-600 dark:text-red-400">
                                    {{ markCurrentErrors[site.handle] }}
                                </span>
                            </div>

                            <!-- Job status indicator -->
                            <div v-if="localeState[site.handle]" class="flex items-center gap-2 shrink-0">
                                <svg
                                    v-if="localeState[site.handle].status === 'pending' || localeState[site.handle].status === 'running'"
                                    class="w-4 h-4 animate-spin text-blue"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>

                                <span
                                    v-if="localeState[site.handle].status === 'completed'"
                                    class="badge-sm bg-green-600"
                                >
                                    {{ t('status_completed') }}
                                </span>

                                <template v-if="localeState[site.handle].status === 'failed'">
                                    <span class="badge-sm bg-red-500">
                                        {{ t('status_failed') }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-2xs text-blue underline hover:no-underline"
                                        @click="retryLocale(site.handle)"
                                    >{{ t('retry') }}</button>
                                </template>

                                <span
                                    v-if="isBulk && localeState[site.handle].totalCount > 1"
                                    class="text-2xs text-gray-500"
                                >
                                    {{ localeState[site.handle].completedCount }}/{{ localeState[site.handle].totalCount }}
                                </span>
                            </div>
                        </div>

                        <p v-if="targetSites.length === 0" class="text-sm text-gray-500 px-4 py-4 text-center">
                            {{ t('no_target_sites') }}
                        </p>
                    </div>
                    </div>

                    <!-- Error summary -->
                    <div
                        v-if="hasFailed"
                        class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded p-3 space-y-1"
                    >
                        <template v-for="(state, handle) in localeState">
                            <p v-if="state.status === 'failed'" :key="handle" class="text-xs text-red-700 dark:text-red-400">
                                <strong>{{ handle }}:</strong> {{ state.error || t('translation_failed') }}
                            </p>
                        </template>
                    </div>

                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-3 px-6 py-4 border-t dark:border-dark-900 bg-gray-50 dark:bg-dark-600">
                    <button type="button" class="btn" @click="cancel">
                        {{ allDone ? t('close') : t('cancel') }}
                    </button>
                    <button
                        type="button"
                        class="btn-primary flex items-center gap-1.5"
                        :disabled="isTranslating || !selectedLocales.length"
                        @click="translate"
                    >
                        <svg
                            v-if="isTranslating && !allDone"
                            class="w-4 h-4 animate-spin"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span v-if="isTranslating && !allDone">{{ t('translating') }}</span>
                        <span v-else>{{ t('translate_selected') }}</span>
                    </button>
                </div>
            </div>
        </div>
    `},E={name:"MagicTranslatorFieldtype",inject:["storeName"],props:{handle:String,value:null,meta:{type:Object,required:!0},config:Object},data(){return{badgeInjected:v(),buttonInjected:y(),observer:null,injecting:!1,markedCurrentHandles:[],unsubscribeMarked:null}},computed:{sites(){var e;return((e=this.meta)==null?void 0:e.sites)??[]},currentSite(){var e;return((e=this.meta)==null?void 0:e.current_site)??null},originSite(){var e;return((e=this.meta)==null?void 0:e.origin_site)??null},entryId(){var e;return((e=this.meta)==null?void 0:e.entry_id)??null},targetSites(){const e=this;return e.sites.filter(t=>t.handle!==e.currentSite)},hasTargets(){return this.targetSites.length>0},effectiveSites(){const e=this;return e.sites.map(t=>e.markedCurrentHandles.includes(t.handle)?{...t,is_stale:!1,last_translated_at:new Date().toISOString()}:t)}},watch:{effectiveSites:{handler(){this.tryInjectBadges()},deep:!0}},mounted(){const e=this;if(!e.hasTargets){e.hideEntireField();return}e.hideFieldLabelChrome(),e.tryInjectBadges(),e.observer=new MutationObserver(()=>{e.injecting||e.badgeInjected&&e.hasInjectedBadgesInDom()&&e.buttonInjected&&e.hasInjectedTranslateButtonInDom()||e.tryInjectBadges()}),e.observer.observe(document.body,{childList:!0,subtree:!0});const t=this.entryId;if(t!==null){const s=this;s.markedCurrentHandles=[...i(t)],s.unsubscribeMarked=f(t,()=>{s.markedCurrentHandles=[...i(t)]})}},beforeDestroy(){var t;const e=this;(t=e.observer)==null||t.disconnect(),e.unsubscribeMarked&&(e.unsubscribeMarked(),e.unsubscribeMarked=null),h(),m()},methods:{t(e,t={}){return __("magic-translator::messages."+e,t)},hideFieldLabelChrome(){const e=this,t=[e.$el.closest("[data-ui-input-group]"),e.$el.closest(".publish-field")].filter(s=>s!==null);for(const s of t)s.querySelectorAll("[data-ui-field-header], [data-ui-field-text], .publish-field-label").forEach(a=>{a.style.display="none"})},hideEntireField(){const e=this,t=[e.$el.closest("[data-ui-input-group]"),e.$el.closest(".publish-field")].filter(s=>s!==null);for(const s of t)s.style.display="none"},hasInjectedBadgesInDom(){return document.querySelector("[data-ct-badge]")!==null},hasInjectedTranslateButtonInDom(){return document.querySelector("[data-ct-translate-button]")!==null},tryInjectBadges(){const e=this;if(!(e.injecting||!e.effectiveSites.length)){e.injecting=!0;try{e.badgeInjected=b(e.effectiveSites,"v5"),e.buttonInjected=p(e.effectiveSites,"v5",{onClick:()=>e.openDialog(),disabled:!e.hasTargets})}finally{e.injecting=!1}}},openDialog(){var r;const e=this;if(!e.entryId){console.warn("[magic-translator] Cannot open dialog: entry_id not available.");return}const t=new Set(e.sites.map(n=>n.handle)),s=(e.originSite&&t.has(e.originSite)?e.originSite:null)??(e.currentSite&&t.has(e.currentSite)?e.currentSite:null)??((r=e.sites[0])==null?void 0:r.handle)??"",a=Statamic.$components.append("magic-translator-dialog",{props:{entryId:e.entryId,sourceSite:s,sites:e.effectiveSites}});a.on("close",()=>{a.destroy()})}},template:`
        <div class="magic-translator-fieldtype">
            <template v-if="hasTargets">
                <button
                    v-if="!buttonInjected"
                    type="button"
                    class="btn btn-sm w-full"
                    :disabled="!hasTargets"
                    @click="openDialog"
                >
                    {{ t('translate_button') }}
                </button>

                <!-- Fallback status list when badge injection has not succeeded yet -->
                <div v-if="!badgeInjected && effectiveSites.length > 0" class="mt-3 space-y-1">
                    <div v-for="site in effectiveSites" :key="site.handle" class="text-xs flex items-center gap-1.5 py-0.5">
                        <span
                            class="little-dot shrink-0"
                            :class="{
                                'bg-green-600': site.exists && !site.is_stale,
                                'bg-orange': site.is_stale,
                                'bg-red-500': !site.exists
                            }"
                        ></span>
                        <span class="flex-1 truncate">{{ site.name }}</span>
                        <span v-if="site.is_stale" class="text-orange">⚠</span>
                        <span v-else-if="site.last_translated_at" class="text-gray-400">✓</span>
                        <span v-else class="text-gray-400">—</span>
                    </div>
                </div>
            </template>
        </div>
    `};var g;(g=Statamic.booting)==null||g.call(Statamic,()=>{var e,t,s;(e=Statamic.$components)==null||e.register("magic_translator-fieldtype",E),(t=Statamic.$components)==null||t.register("magic-translator-dialog",T),(s=Statamic.$callbacks)==null||s.add("openTranslationDialog",(a,r)=>{var d;const n=Array.isArray(a)?a:[],o=Array.isArray(r)?r:[];if(n.length===0||!Statamic.$components)return;const c=Statamic.$components.append("magic-translator-dialog",{props:{entryIds:n,sourceSite:((d=o[0])==null?void 0:d.handle)??"",sites:o}});c.on("close",()=>{c.destroy()})})});
