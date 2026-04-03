/**
 * TypeScript module declaration for .vue single-file components.
 *
 * Allows TypeScript to import .vue files without type errors. The actual
 * compilation is handled by @vitejs/plugin-vue at build time.
 */
declare module '*.vue' {
    import type { DefineComponent } from 'vue'
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, any>
    export default component
}
