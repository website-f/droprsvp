import { useSyncExternalStore } from 'react';

/**
 * The Messages badge, shared between the sidebar and the chat page without
 * either knowing about the other. Whichever is polling writes the count; both
 * read it. `chatPageOpen` lets the sidebar stand down its own (slow) poller
 * while the chat page is running its (faster) one, so a tab never polls twice.
 */
type State = { unread: number; chatPageOpen: boolean };

let state: State = { unread: 0, chatPageOpen: false };
const listeners = new Set<() => void>();

export const chatStore = {
    get: (): State => state,
    set(patch: Partial<State>) {
        const next = { ...state, ...patch };

        if (next.unread === state.unread && next.chatPageOpen === state.chatPageOpen) {
            return;
        }

        state = next;
        listeners.forEach((l) => l());
    },
    subscribe(listener: () => void) {
        listeners.add(listener);

        return () => listeners.delete(listener);
    },
};

export function useChatStore(): State {
    return useSyncExternalStore(chatStore.subscribe, chatStore.get, chatStore.get);
}
