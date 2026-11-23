<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

return [
    'routes' => [
        // Pages
        ['name' => 'page#research', 'url' => '/research', 'verb' => 'GET'],

        // Settings API
        ['name' => 'settings#getSettings', 'url' => '/api/v1/settings', 'verb' => 'GET'],
        ['name' => 'settings#saveSettings', 'url' => '/api/v1/settings', 'verb' => 'PUT'],
        ['name' => 'settings#testConnection', 'url' => '/api/v1/settings/test', 'verb' => 'POST'],
        ['name' => 'settings#registerInstance', 'url' => '/api/v1/settings/register-instance', 'verb' => 'POST'],
        ['name' => 'settings#forgetInstance', 'url' => '/api/v1/settings/forget-instance', 'verb' => 'POST'],
        ['name' => 'settings#instanceStatus', 'url' => '/api/v1/settings/instance-status', 'verb' => 'GET'],

        // Document AI API
        ['name' => 'api#summarize', 'url' => '/api/v1/summarize/{fileId}', 'verb' => 'POST'],
        ['name' => 'api#translate', 'url' => '/api/v1/translate/{fileId}', 'verb' => 'POST'],

        // Knowledge (file upload + vectorization + lifecycle)
        ['name' => 'api#uploadToKnowledge', 'url' => '/api/v1/knowledge/upload/{fileId}', 'verb' => 'POST'],
        ['name' => 'api#knowledgeStatus', 'url' => '/api/v1/knowledge/status/{fileId}', 'verb' => 'GET'],
        ['name' => 'api#removeFromKnowledge', 'url' => '/api/v1/knowledge/{fileId}', 'verb' => 'DELETE'],
        ['name' => 'api#getGroups', 'url' => '/api/v1/knowledge/groups', 'verb' => 'GET'],
        ['name' => 'api#getModels', 'url' => '/api/v1/knowledge/models', 'verb' => 'GET'],

        // What Synaplan made of the person's files: the bird, the Synaplan tab, the searchable list
        ['name' => 'cloudStatus#status', 'url' => '/api/v1/cloud/status', 'verb' => 'POST'],
        ['name' => 'cloudStatus#detail', 'url' => '/api/v1/cloud/files/{fileId}', 'verb' => 'GET'],
        ['name' => 'cloudStatus#searchable', 'url' => '/api/v1/cloud/searchable', 'verb' => 'GET'],

        // Client runtime config (language + memory availability) for the chat UI
        ['name' => 'api#clientConfig', 'url' => '/api/v1/client-config', 'verb' => 'GET'],
        // What the Files app offers, by what the connected Synaplan supports
        ['name' => 'api#features', 'url' => '/api/v1/features', 'verb' => 'GET'],

        // Per-user consent (per-user modes only)
        ['name' => 'consent#getConsent', 'url' => '/api/v1/ai-consent', 'verb' => 'GET'],
        ['name' => 'consent#setConsent', 'url' => '/api/v1/ai-consent', 'verb' => 'POST'],

        // Connecting a person's own Synaplan account (link mode)
        ['name' => 'link#start', 'url' => '/link/start', 'verb' => 'GET'],
        ['name' => 'link#callback', 'url' => '/link/callback', 'verb' => 'GET'],
        ['name' => 'link#disconnect', 'url' => '/link/disconnect', 'verb' => 'POST'],
        ['name' => 'link#status', 'url' => '/api/v1/link/status', 'verb' => 'GET'],

        // Admin control panel: activated AI users (admin-only)
        ['name' => 'adminAiUsers#list', 'url' => '/api/v1/admin/ai-users', 'verb' => 'GET'],
        ['name' => 'adminAiUsers#usage', 'url' => '/api/v1/admin/ai-users/{uid}/usage', 'verb' => 'GET'],
        ['name' => 'adminAiUsers#deactivate', 'url' => '/api/v1/admin/ai-users/{uid}/deactivate', 'verb' => 'POST'],

        // Chat API
        ['name' => 'chat#chat', 'url' => '/api/v1/chat', 'verb' => 'POST'],
        ['name' => 'chat#chatStream', 'url' => '/api/v1/chat/stream', 'verb' => 'POST'],

        // Media generation API
        ['name' => 'media#generate', 'url' => '/api/v1/media/generate', 'verb' => 'POST'],
        ['name' => 'media#save', 'url' => '/api/v1/media/save', 'verb' => 'POST'],
        ['name' => 'media#proxy', 'url' => '/api/v1/media/proxy', 'verb' => 'GET'],

        // Synaplan's signed notifications (copies), and what a person set for them
        ['name' => 'inbound#receive', 'url' => '/api/v1/inbound', 'verb' => 'POST'],
        ['name' => 'preferences#get', 'url' => '/api/v1/preferences', 'verb' => 'GET'],
        ['name' => 'preferences#save', 'url' => '/api/v1/preferences', 'verb' => 'PUT'],
        ['name' => 'preferences#allowFolder', 'url' => '/api/v1/preferences/read-folders', 'verb' => 'POST'],
        ['name' => 'notices#list', 'url' => '/api/v1/notices', 'verb' => 'GET'],
        ['name' => 'notices#clear', 'url' => '/api/v1/notices', 'verb' => 'DELETE'],

        // New → From Synaplan… in the Files app
        ['name' => 'fromSynaplan#list', 'url' => '/api/v1/from-synaplan', 'verb' => 'GET'],
        ['name' => 'fromSynaplan#copy', 'url' => '/api/v1/from-synaplan', 'verb' => 'POST'],
    ],
];
