export interface User {
    id: number
    name: string
    email: string
}

export type ParseStatus = 'queued' | 'running' | 'success' | 'partial' | 'blocked' | 'failed'

export interface ParseRun {
    id: number
    status: ParseStatus
    status_label: string
    trigger: string
    is_finished: boolean
    progress: {
        pages_done: number
        pages_expected: number
        percent: number
        reviews_seen: number
    }
    reviews_created: number
    reviews_updated: number
    error: { code: string; message: string } | null
    warnings: string[]
    attempt: number
    started_at: string | null
    finished_at: string | null
    duration_ms: number | null
    created_at: string | null
}

export interface Organization {
    id: number
    source: string
    external_id: string
    url: string
    name: string | null
    address: string | null
    category: string | null
    rating: {
        value: number | null
        /** How many people left a star rating — most of them write nothing. */
        ratings_count: number | null
        /** How many left a written review. Deliberately a separate number. */
        reviews_count: number | null
    }
    reviews_stored: number
    parse_status: ParseStatus | null
    parse_status_label: string | null
    is_parsing: boolean
    last_parsed_at: string | null
    latest_run?: ParseRun
    created_at: string | null
}

export interface Review {
    id: number
    external_id: string
    author: { name: string; avatar_url: string | null; level: string | null }
    /** Null when the author left text but no stars. */
    rating: number | null
    text: string | null
    published_at: string | null
    business_comment: { text: string; published_at: string | null } | null
    photos_count: number
    likes_count: number
    revisions_count?: number
}

export interface ReviewRevision {
    id: number
    recorded_at: string | null
    review: { id: number | null; author_name: string | null; external_id: string | null }
    changes: Record<string, { old: unknown; new: unknown }>
}

export interface Paginated<T> {
    data: T[]
    meta: {
        current_page: number
        last_page: number
        per_page: number
        total: number
        from: number | null
        to: number | null
    }
}
