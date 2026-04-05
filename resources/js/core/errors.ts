/** Normalized error structure matching the backend envelope. */
export interface NormalizedError {
  code: string
  message: string
  retryable: boolean
}

/** Known error codes and their i18n message keys. */
const ERROR_MESSAGE_KEYS: Record<string, string> = {
  provider_not_configured: 'error_provider_not_configured',
  provider_auth_failed: 'error_provider_auth_failed',
  provider_rate_limited: 'error_provider_rate_limited',
  provider_unavailable: 'error_provider_unavailable',
  provider_response_invalid: 'error_provider_response_invalid',
  translation_config_invalid: 'error_translation_config_invalid',
  translation_dispatch_failed: 'error_translation_dispatch_failed',
  resource_not_found: 'error_resource_not_found',
  forbidden: 'error_forbidden',
  unauthorized: 'error_unauthorized',
  validation_failed: 'error_validation_failed',
  unexpected_error: 'error_unexpected',
}

type StructuredError = {
  code: string
  message: string
  retryable?: boolean
}

type ErrorRecord = Record<string, unknown>

/**
 * Normalize an API error payload into a consistent shape.
 *
 * Handles:
 * - New structured errors: { code, message, retryable }
 * - Legacy string errors: "Some error message"
 * - Axios-style errors with response.data payloads
 * - HTTP errors (no JSON body): HTTP status text
 * - Missing/malformed payloads: generic fallback
 */
export function normalizeApiError(error: unknown): NormalizedError {
  // Already structured
  if (isStructuredError(error)) {
    return {
      code: error.code,
      message: resolveMessage(error.code, error.message),
      retryable: Boolean(error.retryable),
    }
  }

  // Legacy string
  if (typeof error === 'string') {
    return { code: 'unexpected_error', message: error, retryable: false }
  }

  // Payload with error field
  if (isRecord(error) && 'error' in error) {
    const inner = error.error
    if (isStructuredError(inner)) {
      return {
        code: inner.code,
        message: resolveMessage(inner.code, inner.message),
        retryable: Boolean(inner.retryable),
      }
    }
    if (typeof inner === 'string') {
      return { code: 'unexpected_error', message: inner, retryable: false }
    }
  }

  // Axios-style response payloads
  if (isRecord(error) && 'response' in error && isRecord(error.response)) {
    const response = error.response as ErrorRecord

    if ('data' in response) {
      return normalizeApiError(response.data)
    }

    const status = typeof response.status === 'number' ? response.status : null
    const statusText = typeof response.statusText === 'string' ? response.statusText : ''

    if (status !== null) {
      return {
        code: 'unexpected_error',
        message: statusText ? `HTTP ${status}: ${statusText}` : `HTTP ${status}`,
        retryable: false,
      }
    }
  }

  // Native Error instances
  if (error instanceof Error && error.message) {
    return { code: 'unexpected_error', message: error.message, retryable: false }
  }

  // Fallback
  return { code: 'unexpected_error', message: t('error_unexpected'), retryable: false }
}

function isStructuredError(val: unknown): val is StructuredError {
  return isRecord(val) && typeof val.code === 'string' && typeof val.message === 'string'
}

function isRecord(val: unknown): val is ErrorRecord {
  return val !== null && typeof val === 'object'
}

function resolveMessage(code: string, fallback: string): string {
  const key = ERROR_MESSAGE_KEYS[code]
  if (!key) return fallback

  const fullKey = 'magic-translator::messages.' + key
  const translated = t(key)

  return translated === fullKey ? fallback : translated
}

function t(key: string): string {
  const translator = (
    window as Window & {
      __?: (key: string, replacements?: Record<string, string | number>) => string
    }
  ).__

  const fullKey = 'magic-translator::messages.' + key

  return translator ? translator(fullKey) : fullKey
}
