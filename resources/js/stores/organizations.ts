import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/api/client'
import type { Organization, Paginated, Review, ReviewRevision, ParseRun } from '@/types'

export const useOrganizationsStore = defineStore('organizations', () => {
    const items = ref<Organization[]>([])
    const loading = ref(false)
    const error = ref<string | null>(null)

    const hasAny = computed(() => items.value.length > 0)

    async function fetchAll(): Promise<void> {
        loading.value = true
        error.value = null

        try {
            const { data } = await api.get<{ data: Organization[] }>('/api/organizations')
            items.value = data
        } catch (e) {
            error.value = e instanceof Error ? e.message : 'Не удалось загрузить список.'
        } finally {
            loading.value = false
        }
    }

    async function connect(url: string): Promise<Organization> {
        const { data } = await api.post<{ data: Organization }>('/api/organizations', { url })

        const index = items.value.findIndex((item) => item.id === data.id)
        index === -1 ? items.value.unshift(data) : (items.value[index] = data)

        return data
    }

    async function remove(id: number): Promise<void> {
        await api.delete(`/api/organizations/${id}`)
        items.value = items.value.filter((item) => item.id !== id)
    }

    async function refresh(id: number): Promise<Organization> {
        const response = await api.post<{ queued: boolean; organization: { data: Organization } }>(
            `/api/organizations/${id}/refresh`,
        )

        return replace(response.organization.data)
    }

    async function fetchOne(id: number): Promise<Organization> {
        const { data } = await api.get<{ data: Organization }>(`/api/organizations/${id}`)

        return replace(data)
    }

    function replace(organization: Organization): Organization {
        const index = items.value.findIndex((item) => item.id === organization.id)
        index === -1 ? items.value.unshift(organization) : (items.value[index] = organization)

        return organization
    }

    function fetchReviews(id: number, page: number, sort: string): Promise<Paginated<Review>> {
        return api.get<Paginated<Review>>(
            `/api/organizations/${id}/reviews?page=${page}&sort=${encodeURIComponent(sort)}`,
        )
    }

    function fetchChanges(id: number): Promise<Paginated<ReviewRevision>> {
        return api.get<Paginated<ReviewRevision>>(`/api/organizations/${id}/changes`)
    }

    function fetchRuns(id: number): Promise<{ data: ParseRun[] }> {
        return api.get<{ data: ParseRun[] }>(`/api/organizations/${id}/runs`)
    }

    return {
        items, loading, error, hasAny,
        fetchAll, connect, remove, refresh, fetchOne, fetchReviews, fetchChanges, fetchRuns,
    }
})
