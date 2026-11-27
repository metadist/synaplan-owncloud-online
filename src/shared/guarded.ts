/**
 * Opens a Synaplan dialog, or the consent card first while the person has
 * not agreed to send files to Synaplan.
 */
import type { Component } from 'vue'
import ConsentCard from '../components/ConsentCard.vue'
import { isBlocked, loadConsent } from './consent'
import { openModal } from './mount'

export async function openGuarded(component: Component, props: Record<string, unknown>): Promise<void> {
  const state = await loadConsent()
  if (!state || !isBlocked(state)) {
    openModal(component, props)
    return
  }
  const gate = openModal(ConsentCard, {
    state,
    onGranted: () => {
      gate.close()
      openModal(component, props)
    },
  })
}
