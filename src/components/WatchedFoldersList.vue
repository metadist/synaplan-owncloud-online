<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { glyphMarkup } from '../cloud_state/glyphs'
import type { WatchRow } from '../shared/api'
import { translate as t } from '../shared/oc'
import { controlsFor, policyStopped } from '../shared/watchControls'
import { listWatches, resumeWatch, setWatchState, stopWatch } from '../shared/watches'

const eyesSvg = glyphMarkup({ eyes: true, size: 20 })
const watches = ref<WatchRow[]>([])
const busy = ref<number | null>(null)

async function load(): Promise<void> {
  try {
    watches.value = await listWatches()
  } catch {
    // The page says nothing until the person uses Synaplan.
  }
}

function formatted(seconds: number): string {
  return new Date(seconds * 1000).toLocaleString()
}

async function change(item: WatchRow, next: 'paused' | 'active'): Promise<void> {
  busy.value = item.id
  try {
    const now = next === 'active' ? await resumeWatch(item.id) : await setWatchState(item.id, next)
    if (now) {
      watches.value = watches.value.map((row) =>
        row.id === item.id ? { ...row, state: now.state, sentence: now.sentence } : row,
      )
    }
  } finally {
    busy.value = null
  }
}

async function end(item: WatchRow): Promise<void> {
  busy.value = item.id
  try {
    if (await stopWatch(item.id, item.name)) {
      watches.value = watches.value.filter((row) => row.id !== item.id)
    }
  } finally {
    busy.value = null
  }
}

onMounted(() => void load())
</script>

<template>
  <div v-if="watches.length > 0" class="synaplan-watched" data-testid="synaplan-watched-folders">
    <div class="synaplan-watched-head">
      <strong>{{ t('Watched folders') }}</strong>
    </div>
    <ul class="synaplan-watched-list">
      <li v-for="item in watches" :key="item.id" class="synaplan-watched-row" data-testid="synaplan-watched-row">
        <!-- eslint-disable-next-line vue/no-v-html -- glyphMarkup without overrides is a constant of the bundle -->
        <span class="synaplan-watched-eyes" v-html="eyesSvg" />
        <div class="synaplan-watched-meta">
          <span class="synaplan-watched-name">{{ item.name }}</span>
          <span class="synaplan-watched-sentence">
            {{ item.policyOff ? policyStopped(item.policyOff) : item.sentence }}
          </span>
          <span v-if="item.lastRunAt" class="synaplan-watched-when">
            {{ t('Last activity {time}', { time: formatted(item.lastRunAt) }) }}
          </span>
        </div>
        <div class="synaplan-watched-actions">
          <button
            v-if="controlsFor(item.state, item.policyOff).pause"
            type="button"
            class="synaplan-watched-action"
            :disabled="busy === item.id"
            data-testid="synaplan-watched-pause"
            @click="change(item, 'paused')"
          >
            {{ t('Pause') }}
          </button>
          <button
            v-if="controlsFor(item.state, item.policyOff).resume"
            type="button"
            class="synaplan-watched-action"
            :disabled="busy === item.id"
            data-testid="synaplan-watched-resume"
            @click="change(item, 'active')"
          >
            {{ t('Resume') }}
          </button>
          <button
            v-if="controlsFor(item.state, item.policyOff).stop"
            type="button"
            class="synaplan-watched-action synaplan-watched-stop"
            :disabled="busy === item.id"
            data-testid="synaplan-watched-stop"
            @click="end(item)"
          >
            {{ t('Stop watching') }}
          </button>
          <a
            v-if="item.openUrl"
            :href="item.openUrl"
            class="synaplan-watched-action"
            data-testid="synaplan-watched-open"
          >
            {{ item.kind === 'file' ? t('Open file') : t('Open folder') }}
          </a>
        </div>
      </li>
    </ul>
  </div>
</template>

<style>
.synaplan-watched {
  width: 100%;
  max-width: 800px;
  margin: 0 auto 24px;
  padding: 16px 20px;
  border-radius: 14px;
  border: 1px solid var(--color-border, #ddd);
  background: var(--color-background-dark, #f5f5f5);
  box-sizing: border-box;
}
.synaplan-watched-head {
  margin-bottom: 10px;
}
.synaplan-watched-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.synaplan-watched-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.synaplan-watched-eyes {
  display: inline-flex;
  flex-shrink: 0;
  color: #059669;
}
.synaplan-watched-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  /* Without a basis the actions never wrap and squeeze the text to a word. */
  flex: 1 1 16rem;
  min-width: 0;
}
.synaplan-watched-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.synaplan-watched-sentence,
.synaplan-watched-when {
  color: var(--color-text-maxcontrast, #767676);
}
.synaplan-watched-when {
  font-size: 0.85em;
}
.synaplan-watched-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.synaplan-watched-action {
  background: transparent;
  border: none;
  padding: 0;
  font-weight: 600;
  color: var(--color-primary, #1a67a3);
  cursor: pointer;
}
.synaplan-watched-stop {
  color: #b91c1c;
}
</style>
