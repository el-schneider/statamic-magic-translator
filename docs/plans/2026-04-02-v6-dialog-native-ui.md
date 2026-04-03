# v6 Dialog: Native UI Component Rewrite

## Goal

Rewrite the v6 TranslationDialog and TranslatorFieldtype to use Statamic v6's native `@statamic/cms/ui` component library instead of raw HTML/Tailwind. The dialog should feel like a first-class part of the Statamic CP.

## Build Tooling Changes

### package.json

Add `@statamic/cms` as a dependency (resolved from vendor):

```json
"dependencies": {
    "@statamic/cms": "file:./vendor/statamic/cms/resources/dist-package"
}
```

### vite.config.js

Add the `statamic()` vite plugin:

```js
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({ ... }),
        statamic(),
    ],
});
```

Remove the standalone `@vitejs/plugin-vue` — the `statamic()` plugin includes Vue support.

### Important

The `@statamic/cms` package only exists when `statamic/cms ^6.0` is installed via composer. The v5 bundle must NOT import from `@statamic/cms`. The two bundles are already separate entry points, so this is naturally isolated.

## Component Mapping

### TranslationDialog.vue

The `<script setup>` logic stays **identical** — only the `<template>` changes.

| Current (raw HTML) | v6 UI Component | Notes |
|---|---|---|
| `<div class="fixed inset-0 z-[200]...">` + backdrop div | `<Modal :open="true" :title="dialogTitle" @dismissed="cancel">` | Modal handles backdrop, z-index, transitions, focus trapping, esc-to-close |
| `<h2>` dialog heading | Modal `title` prop | |
| `<button>&times;</button>` close button | Modal handles dismiss internally | |
| `<label>` + `<select>` for source locale | `<Label>` + `<Select :options="..." v-model="...">` | Options as `[{label, value}]` array |
| `<input type="checkbox">` per locale row | `<Checkbox :label="site.name" v-model="..." :disabled="...">` | |
| `<input type="checkbox">` for generate slug | `<Checkbox :label="t('generate_slugs')" v-model="generateSlug">` | |
| `<input type="checkbox">` for overwrite | `<Checkbox :label="t('overwrite_existing')" v-model="overwrite">` | |
| `<button class="btn">Cancel</button>` | `<Button variant="ghost" :text="cancelText" @click="cancel">` | Inside `<template #footer>` |
| `<button class="btn-primary">Translate</button>` | `<Button variant="primary" :text="translateText" :loading="isTranslating" :disabled="..." @click="translate">` | Button has built-in loading state |
| SVG spinner inline | `<Icon name="loading">` | |
| Error summary `<div class="bg-red-50...">` | `<Alert>` or keep simple styled div | Alert component if suitable |
| Status dot `<span class="little-dot...">` | Keep as-is or use `<StatusIndicator>` | Check if StatusIndicator fits |
| Footer `<div class="flex...border-t...">` | `<template #footer>` slot on Modal | Modal provides a footer slot |

### Template structure

```vue
<template>
  <Modal :open="true" :title="dialogTitle" @dismissed="cancel">
    <!-- Source locale -->
    <div class="space-y-4">
      <div>
        <Label :text="t('source')" />
        <Select
          :options="sourceOptions"
          :model-value="selectedSource"
          :disabled="isTranslating"
          @update:modelValue="selectedSource = $event"
        />
      </div>

      <!-- Target locales -->
      <div class="space-y-1">
        <div v-for="site in targetSites" :key="site.handle" class="flex items-center justify-between py-1.5">
          <Checkbox
            :label="site.name"
            :model-value="selectedLocales.includes(site.handle)"
            :disabled="isLocaleDisabled(site)"
            @update:modelValue="toggleLocale(site.handle, $event)"
          />
          <!-- Job status indicators stay as custom markup -->
        </div>
      </div>

      <!-- Error summary (if any) -->

      <!-- Options -->
      <Separator />
      <div class="space-y-2">
        <Checkbox :label="t('generate_slugs')" v-model="generateSlug" :disabled="isTranslating" />
        <Checkbox :label="t('overwrite_existing')" v-model="overwrite" :disabled="isTranslating" />
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-end gap-3 pt-3 pb-1">
        <ModalClose asChild>
          <Button variant="ghost" :text="cancelText" />
        </ModalClose>
        <Button
          variant="primary"
          :text="translateText"
          :loading="isTranslating && !allDone"
          :disabled="isTranslating || selectedLocales.length === 0"
          @click="translate"
        />
      </div>
    </template>
  </Modal>
</template>
```

### TranslatorFieldtype.vue

Minimal changes:
- Replace `<button class="btn btn-sm w-full">` with `<Button variant="default" size="sm" :text="..." class="w-full" @click="openDialog" />`

### Imports

Both v6 components import from `@statamic/cms/ui`:

```ts
import { Modal, ModalClose, Button, Checkbox, Select, Label, Separator, Icon, Badge } from '@statamic/cms/ui';
```

## Checkbox v-model Adaptation

The native `<Checkbox>` component uses `modelValue` (boolean) not array-based v-model. Our current code uses `v-model="selectedLocales"` with `value="site.handle"` (native HTML checkbox array pattern). We need a toggle helper:

```ts
function toggleLocale(handle: string, checked: boolean): void {
  if (checked) {
    selectedLocales.value = [...selectedLocales.value, handle];
  } else {
    selectedLocales.value = selectedLocales.value.filter(h => h !== handle);
  }
}
```

## Select Options Format

The `<Select>` component expects `options` as `[{ label: string, value: string }]`:

```ts
const sourceOptions = computed(() =>
  props.sites.map(s => ({ label: s.name, value: s.handle }))
);
```

## Files to Modify

1. `package.json` — add `@statamic/cms` dependency
2. `vite.config.js` — add `statamic()` plugin, remove `@vitejs/plugin-vue`
3. `resources/js/v6/components/TranslationDialog.vue` — rewrite template
4. `resources/js/v6/components/TranslatorFieldtype.vue` — use Button component
5. `resources/js/v6/addon.ts` — no changes needed (registration is the same)

## Files NOT to Modify

- `resources/js/v5/` — completely untouched
- `resources/js/core/` — shared logic, no UI components
- Any PHP files

## Verification

1. `npm install` succeeds
2. `npm run build` succeeds (both v5 and v6 bundles)
3. v6 sandbox: dialog opens with native styling, all interactions work
4. v5 sandbox: still works as before (regression check)
5. `./vendor/bin/pest` — all tests pass
