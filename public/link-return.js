/**
 * Synaplan sends the person back here after they allow the connection.
 *
 * That redirect is a cross-site navigation, so the browser leaves out
 * ownCloud.online's SameSite=Strict session cookie, and any PHP page would
 * answer it with a fresh cookie and sign the person out. This page runs no
 * PHP; its same-site hop to the callback carries the session.
 */
(function () {
    'use strict';

    var KEY = 'oco_synaplan.link_return';
    var ROUTE = '/apps/oco_synaplan/link/callback';
    var origin = window.location.origin;
    var target = '';

    try {
        target = window.localStorage.getItem(KEY) || '';
        window.localStorage.removeItem(KEY);
    } catch (e) {
        target = '';
    }

    if (target.indexOf(origin + '/') !== 0 || target.slice(-ROUTE.length) !== ROUTE) {
        // <web root>/<apps folder>/oco_synaplan/public/link-return.html
        var folder = window.location.pathname.replace(/\/[^/]*$/, '');
        target = origin + folder.replace(/\/[^/]+\/[^/]+\/public$/, '') + '/index.php' + ROUTE;
    }

    window.location.replace(target + window.location.search);
})();
