import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/v5/addon.ts', 'resources/js/v6/addon.ts'],
            publicDirectory: 'resources/dist',
        }),
    ],
    server: {
        cors: true,
    },
})
