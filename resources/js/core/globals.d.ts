export {}

interface StatamicAxios {
  post: (url: string, data?: unknown) => Promise<{ data: unknown }>
  get: (url: string, config?: { params?: unknown }) => Promise<{ data: unknown }>
}

declare global {
  const Statamic: {
    $axios?: StatamicAxios
    $toast: {
      success: (msg: string) => void
      error: (msg: string) => void
      info?: (msg: string) => void
    }
    $components: {
      register: (name: string, component: unknown) => void
      append: (
        name: string,
        options: { props: Record<string, unknown> },
      ) => {
        on: (event: string, handler: (...args: unknown[]) => void) => void
        destroy: () => void
      }
    }
    $callbacks: {
      add: (name: string, callback: (...args: unknown[]) => void) => void
    }
    booting: (callback: () => void) => void
  }

  const Vue: unknown

  function __(key: string, replacements?: Record<string, string | number>): string
  function cp_url(url: string): string
}
