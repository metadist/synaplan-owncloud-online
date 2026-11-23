import { beforeEach, describe, expect, it, vi } from 'vitest'

const { openModal } = vi.hoisted(() => ({ openModal: vi.fn() }))

vi.mock('../../src/components/ChatPanel.vue', () => ({ default: {} }))
vi.mock('../../src/components/ConsentCard.vue', () => ({ default: {} }))
vi.mock('../../src/components/FilesSidebarTab.vue', () => ({ default: {} }))
vi.mock('../../src/components/FromSynaplanModal.vue', () => ({ default: { name: 'FromSynaplanModal' } }))
vi.mock('../../src/components/KnowledgeModal.vue', () => ({ default: {} }))
vi.mock('../../src/components/SummaryModal.vue', () => ({ default: {} }))
vi.mock('../../src/components/TranslateModal.vue', () => ({ default: {} }))
vi.mock('../../src/shared/consent', () => ({ loadConsent: async () => null, isBlocked: () => false }))
vi.mock('../../src/shared/mount', () => ({ openModal }))

beforeEach(() => {
  openModal.mockClear()
  document.head.innerHTML = ''
  document.body.innerHTML = ''
})

/** The actions the Files app holds once the page asked what Synaplan offers. */
async function actionsAfter(features: unknown): Promise<string[]> {
  const registerAction = vi.fn()
  const fetch = vi.fn(async () => (features instanceof Error ? Promise.reject(features) : Response.json(features)))
  vi.stubGlobal('OCA', { Files: { fileActions: { registerAction } } })
  vi.stubGlobal('OC', {
    generateUrl: (path: string) => '/index.php' + path,
    imagePath: (app: string, file: string) => `/apps/${app}/img/${file}`,
    requestToken: 'request-token',
    PERMISSION_READ: 1,
    Notification: { showTemporary: vi.fn(), show: vi.fn() },
  })
  vi.stubGlobal('fetch', fetch)
  vi.resetModules()

  await import('../../src/files-init')
  await vi.waitFor(() =>
    expect(fetch).toHaveBeenCalledWith('/index.php/apps/oco_synaplan/api/v1/features', expect.anything()),
  )
  await new Promise((resolve) => setTimeout(resolve, 0))

  return [...new Set(registerAction.mock.calls.map(([action]) => action.name as string))].sort()
}

describe('the file actions', () => {
  it('offer Translate where Synaplan translates', async () => {
    expect(await actionsAfter({ translation: true })).toEqual([
      'synaplanChat',
      'synaplanKnowledge',
      'synaplanSummarize',
      'synaplanTranslate',
    ])
  })

  it('leave Translate out where Synaplan does not translate', async () => {
    expect(await actionsAfter({ translation: false })).toEqual([
      'synaplanChat',
      'synaplanKnowledge',
      'synaplanSummarize',
    ])
  })

  it('leave Translate out when the page cannot tell', async () => {
    expect(await actionsAfter(new TypeError('offline'))).toEqual([
      'synaplanChat',
      'synaplanKnowledge',
      'synaplanSummarize',
    ])
  })

  it('offer "Let Synaplan read this folder" where the administrator lets people name folders', async () => {
    expect(await actionsAfter({ translation: false, read_folders: true })).toContain('synaplanReadFolder')
    expect(await actionsAfter({ translation: false, read_folders: false })).not.toContain('synaplanReadFolder')
  })
})

/** The folder action, once the page heard that folders may be named; `answer` replies to its request. */
async function readFolderAction(answer: () => Response) {
  const registerAction = vi.fn()
  const showTemporary = vi.fn()
  const fetch = vi.fn(async (url: string, _init?: RequestInit) =>
    url.endsWith('/api/v1/features') ? Response.json({ translation: false, read_folders: true }) : answer(),
  )
  vi.stubGlobal('OCA', { Files: { fileActions: { registerAction } } })
  vi.stubGlobal('OC', {
    generateUrl: (path: string) => '/index.php' + path,
    imagePath: (app: string, file: string) => `/apps/${app}/img/${file}`,
    requestToken: 'request-token',
    PERMISSION_READ: 1,
    Notification: { showTemporary, show: vi.fn() },
  })
  vi.stubGlobal('fetch', fetch)
  vi.resetModules()
  await import('../../src/files-init')
  await vi.waitFor(() =>
    expect(registerAction.mock.calls.map(([action]) => action.name)).toContain('synaplanReadFolder'),
  )
  const action = registerAction.mock.calls
    .map(([registered]) => registered)
    .find((registered) => registered.name === 'synaplanReadFolder')

  return { action, fetch, showTemporary }
}

describe('Let Synaplan read this folder', () => {
  it('names the folder by its id and its place in the person’s files', async () => {
    const { action, fetch, showTemporary } = await readFolderAction(() => Response.json({ success: true }))
    expect(action.mime).toBe('dir')

    action.actionHandler('Contracts', { fileInfoModel: { id: 42 }, dir: '/HR' })

    await vi.waitFor(() => expect(showTemporary).toHaveBeenCalledWith('Synaplan may read this folder.'))
    const call = fetch.mock.calls.find(([url]) => url.endsWith('/read-folders'))
    expect(call?.[0]).toBe('/index.php/apps/oco_synaplan/api/v1/preferences/read-folders')
    expect(call?.[1]?.method).toBe('POST')
    expect(JSON.parse(String(call?.[1]?.body))).toEqual({ fileId: '42', path: 'HR/Contracts' })
  })

  it.each([
    ['cap', 409, 'Synaplan may already read as many folders as your administrator allows.'],
    ['ceiling', 403, 'Your administrator lets Synaplan read only the folders you watch.'],
  ])('says so when the administrator’s limit (%s) stops it', async (error, status, sentence) => {
    const { action, showTemporary } = await readFolderAction(() => Response.json({ success: false, error }, { status }))

    action.actionHandler('HR', { fileInfoModel: { id: 7 }, dir: '/' })

    await vi.waitFor(() => expect(showTemporary).toHaveBeenCalledWith(sentence))
  })
})

describe('folderPath', () => {
  it.each([
    ['/', 'HR', 'HR'],
    ['/HR', 'Contracts', 'HR/Contracts'],
    ['/HR/', 'Contracts', 'HR/Contracts'],
    [undefined, 'HR', 'HR'],
  ])('puts %s and %s together as %s', async (dir, name, path) => {
    const { folderPath } = await import('../../src/files-init')

    expect(folderPath(dir, name)).toBe(path)
  })
})

/** The Files app's New menu as the plugin sees it, after the plugin attached. */
async function newMenu() {
  let plugin: { attach(menu: unknown): void } | undefined
  vi.stubGlobal('OCA', { Files: { fileActions: { registerAction: vi.fn() } } })
  vi.stubGlobal('OC', {
    generateUrl: (path: string) => '/index.php' + path,
    imagePath: (app: string, file: string) => `/apps/${app}/img/${file}`,
    requestToken: 'request-token',
    PERMISSION_READ: 1,
    Notification: { showTemporary: vi.fn(), show: vi.fn() },
    hideMenus: vi.fn(),
    Plugins: {
      register: (target: string, registered: typeof plugin) => {
        if (target === 'OCA.Files.NewFileMenu') {
          plugin = registered
        }
      },
    },
  })
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => Response.json({})),
  )
  vi.resetModules()
  await import('../../src/files-init')
  const modal = (await import('../../src/components/FromSynaplanModal.vue')).default
  const menu = {
    addMenuEntry: vi.fn(),
    _onClickAction: vi.fn(),
    delegateEvents: vi.fn(),
    fileList: { getCurrentDirectory: () => '/Projects/Q3', reload: vi.fn() },
  }
  const askForName = menu._onClickAction
  plugin?.attach(menu)

  return { menu, askForName, modal }
}

/** A click on the icon inside a New menu entry. */
function clickOn(action: string): Event {
  const item = document.createElement('a')
  item.className = 'menuitem'
  item.dataset.action = action
  const icon = document.createElement('span')
  item.append(icon)
  document.body.append(item)
  const event = new MouseEvent('click', { cancelable: true })
  Object.defineProperty(event, 'target', { value: icon })

  return event
}

describe('New → From Synaplan…', () => {
  it('is an entry of the New menu, with the app icon', async () => {
    const { menu } = await newMenu()

    expect(menu.addMenuEntry).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 'synaplan-from-synaplan',
        displayName: 'From Synaplan…',
        iconClass: 'icon-oco-synaplan',
      }),
    )
    expect(menu.delegateEvents).toHaveBeenCalled()
    expect(document.getElementById('oco-synaplan-menu-icon')?.textContent).toContain('/apps/oco_synaplan/img/app.svg')
  })

  it('opens the picker for the open folder instead of asking for a file name', async () => {
    const { menu, askForName, modal } = await newMenu()
    const click = clickOn('synaplan-from-synaplan')

    menu._onClickAction(click)

    await vi.waitFor(() => expect(openModal).toHaveBeenCalled())
    const [component, props] = openModal.mock.calls[0]
    expect(component).toBe(modal)
    expect(props.folder).toBe('/Projects/Q3')
    expect(askForName).not.toHaveBeenCalled()
    expect(click.defaultPrevented).toBe(true)
    expect(OC.hideMenus).toHaveBeenCalled()

    props.onAdded()
    expect(menu.fileList.reload).toHaveBeenCalled()
  })

  it('leaves the other entries to the Files app', async () => {
    const { menu, askForName } = await newMenu()
    const click = clickOn('folder')

    menu._onClickAction(click)

    expect(askForName).toHaveBeenCalledWith(click)
    expect(openModal).not.toHaveBeenCalled()
  })

  it('stays away where the page has no plugins', async () => {
    await actionsAfter({ translation: false })

    expect(document.getElementById('oco-synaplan-menu-icon')).toBeNull()
  })
})
