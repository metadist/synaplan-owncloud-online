/**
 * Build each frontend entry as a self-contained IIFE bundle in js/.
 *
 * ownCloud.online loads app scripts as classic <script> tags via Util::addScript,
 * not ES modules, so we cannot ship a code-split ESM bundle. Instead we build
 * one standalone IIFE per entry (Vue is bundled in each) and inject component
 * CSS through JS, since ownCloud.online's addScript only pulls the .js file.
 */
import { build } from 'vite'
import vue from '@vitejs/plugin-vue'
import cssInjectedByJs from 'vite-plugin-css-injected-by-js'

const entries = {
  'files-init': 'src/files-init.ts',
  'chat-launcher': 'src/chat-launcher.ts',
  research: 'src/research.ts',
  settings: 'src/settings.ts',
  'personal-settings': 'src/personal-settings.ts',
  notices: 'src/notices.ts',
}

const watch = process.argv.includes('--watch')

for (const [name, entry] of Object.entries(entries)) {
  await build({
    configFile: false,
    // public/ holds pages ownCloud.online serves as they are, not build input.
    publicDir: false,
    logLevel: 'info',
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      __VUE_OPTIONS_API__: 'false',
      __VUE_PROD_DEVTOOLS__: 'false',
      __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
    },
    plugins: [vue(), cssInjectedByJs()],
    build: {
      outDir: 'js',
      emptyOutDir: false,
      cssCodeSplit: false,
      watch: watch ? {} : null,
      lib: {
        entry,
        formats: ['iife'],
        name: `OcoSynaplan_${name.replace(/-/g, '_')}`,
        fileName: () => `${name}.js`,
      },
      rollupOptions: {
        output: { inlineDynamicImports: true },
      },
    },
  })
}
