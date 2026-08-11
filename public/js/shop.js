/*
 * Shop item preview.
 *
 * Pointing at an item in the list repoints the status image at the same route with
 * ?item=, so it renders the member's stats as they would be with that item equipped.
 * Without this file the image simply shows the current loadout and the shop works
 * exactly as before.
 */
(function () {
    'use strict';

    var image = document.querySelector('[data-status-image]');
    if (!image) return;

    var caption = document.querySelector('[data-status-caption]');
    var base = image.getAttribute('data-status-base');
    var rows = document.querySelectorAll('[data-preview-item]');
    var current = null;

    function show(itemId, name) {
        if (current === itemId) return;
        current = itemId;

        image.src = itemId === null ? base : base + '?item=' + encodeURIComponent(itemId);
        if (caption) caption.textContent = itemId === null ? '' : 'Equipped with ' + name;
    }

    Array.prototype.forEach.call(rows, function (row) {
        var id = row.getAttribute('data-preview-item');
        var name = row.getAttribute('data-preview-name');

        row.addEventListener('mouseenter', function () { show(id, name); });
        // Keyboard and touch users get the same thing without a hover.
        row.addEventListener('focusin', function () { show(id, name); });
    });

    var list = document.querySelector('.shop-items');
    if (list) {
        list.addEventListener('mouseleave', function () { show(null, ''); });
    }
})();
