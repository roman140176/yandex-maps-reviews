import { onUnmounted, ref } from 'vue'
import type { Organization } from '@/types'

/**
 * Polls a card while its parse is in flight.
 *
 * Parsing 600 reviews takes ten-odd seconds, which is long enough that the user
 * needs to see it moving and short enough that polling beats setting up a
 * websocket. Polling stops the moment the run finishes.
 */
export function useParseProgress(reload: () => Promise<Organization>, intervalMs = 2000) {
    const polling = ref(false)
    let timer: number | null = null

    function stop(): void {
        if (timer !== null) {
            window.clearTimeout(timer)
            timer = null
        }

        polling.value = false
    }

    function start(organization: Organization): void {
        stop()

        if (!organization.is_parsing) {
            return
        }

        polling.value = true

        const tick = async (): Promise<void> => {
            try {
                const fresh = await reload()

                if (!fresh.is_parsing) {
                    stop()

                    return
                }
            } catch {
                stop()

                return
            }

            timer = window.setTimeout(tick, intervalMs)
        }

        timer = window.setTimeout(tick, intervalMs)
    }

    onUnmounted(stop)

    return { polling, start, stop }
}
