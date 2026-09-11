/** Shared formatting so numbers and dates read the same on every screen. */

const dateFormatter = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
})

const dateTimeFormatter = new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
})

export function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—'
}

export function formatDateTime(value: string | null): string {
    return value ? dateTimeFormatter.format(new Date(value)) : '—'
}

export function formatNumber(value: number | null): string {
    return value === null ? '—' : new Intl.NumberFormat('ru-RU').format(value)
}

export function formatRating(value: number | null): string {
    return value === null ? '—' : value.toFixed(1).replace('.', ',')
}

/** "1 отзыв / 2 отзыва / 5 отзывов" */
export function plural(count: number, forms: [string, string, string]): string {
    const mod10 = count % 10
    const mod100 = count % 100

    if (mod10 === 1 && mod100 !== 11) return forms[0]
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return forms[1]

    return forms[2]
}
