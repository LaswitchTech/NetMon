/**
 * NetMon — shared DataTables initializer
 *
 * Provides two entry points:
 *   NetMon.dt.init(selector, options)        — full table (search, pagination, length, buttons)
 *   NetMon.dt.initCompact(selector, options) — summary table (no pagination/search/length)
 *
 * Both merge the supplied options on top of their respective defaults so
 * per-table overrides (e.g. custom column order, pageLength) are easy.
 *
 * Depends on: jQuery, DataTables 1.13.x, DataTables Buttons 2.4.x
 */
(function (window, $) {
    'use strict';

    window.NetMon = window.NetMon || {};

    // ── Layout ──────────────────────────────────────────────────────────────
    // Top row  : [Buttons / actions (left)]  [Search ~25% (right)]
    // Table    : rt
    // Bottom row: [Show N (left)]  [Info (centre)]  [Pagination (right)]
    var DOM_FULL =
        "<'row g-2 align-items-center mb-2'<'col-auto'B><'col-sm-4 ms-auto'f>>" +
        "rt" +
        "<'row g-2 align-items-center mt-2'<'col-sm-4'l><'col-sm-4 text-center'i><'col-sm-4'p>>";

    // ── Defaults ─────────────────────────────────────────────────────────────
    var DEFAULTS = {
        pageLength : 25,
        lengthMenu : [10, 25, 50, 100],
        responsive : true,
        dom        : DOM_FULL,
        buttons    : [],   // reserved for future export / action buttons
        language   : {
            emptyTable        : 'No records to display.',
            zeroRecords       : 'No matching records found.',
            info              : 'Showing _START_\u2013_END_ of _TOTAL_',
            infoEmpty         : 'No entries',
            infoFiltered      : '(filtered from _MAX_)',
            lengthMenu        : 'Show _MENU_',
            search            : '',
            searchPlaceholder : 'Search\u2026',
            paginate          : {
                first    : '\u00AB',
                previous : '\u2039',
                next     : '\u203A',
                last     : '\u00BB',
            },
        },
    };

    // Compact preset: styled table only, no controls at all.
    //
    // buttons: null  — prevents the Buttons extension from initialising.
    //   With buttons:[] (inherited from DEFAULTS) the extension's init.dt
    //   handler fires and creates a dead container even though dom:'t' has
    //   no B slot.  null is falsy so the handler skips.
    //
    // responsive: false  — prevents the Responsive extension from wrapping
    //   compact tables in extra divs; these tables have few columns and
    //   don't need column-collapse behaviour.
    var COMPACT_DEFAULTS = $.extend(true, {}, DEFAULTS, {
        paging    : false,
        searching : false,
        info      : false,
        dom       : 't',
        buttons   : null,
        responsive: false,
    });

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Initialize a full DataTable (search + pagination + length + buttons).
     * @param  {string|jQuery} selector
     * @param  {object}        [options]  Per-table overrides
     * @return {DataTables.Api}
     *
     * IMPORTANT — injecting content into the Buttons area:
     *   Do NOT use the `initComplete` callback for this.  In DataTables 1.13.x,
     *   `_fnCallbackFire` calls initComplete with `this = settings.oApi`
     *   (internal _fn* functions), NOT the public DataTables API object.
     *   Calling `this.table()` inside initComplete therefore throws:
     *     TypeError: this.table is not a function
     *
     *   Instead, capture the return value of init() and use the public API:
     *     var dt = NetMon.dt.init('#my-table', { ... });
     *     dt.buttons().container().prepend('<button>...</button>');
     */
    function init(selector, options) {
        return $(selector).DataTable($.extend(true, {}, DEFAULTS, options || {}));
    }

    /**
     * Initialize a compact summary DataTable (table only, no controls).
     * @param  {string|jQuery} selector
     * @param  {object}        [options]  Per-table overrides
     * @return {DataTables.Api}
     */
    function initCompact(selector, options) {
        return $(selector).DataTable($.extend(true, {}, COMPACT_DEFAULTS, options || {}));
    }

    window.NetMon.dt = { init: init, initCompact: initCompact };

}(window, jQuery));
