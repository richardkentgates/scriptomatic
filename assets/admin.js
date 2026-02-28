/**
 * Scriptomatic — Admin JavaScript
 *
 * Location-aware: reads `scriptomaticData.location` ('head'|'footer'|'general')
 * and uses it to build element IDs matching the rendered PHP page.
 *
 * Features:
 *   1. CodeMirror code editor (JS mode + WP/jQuery hints) with counter fallback.
 *   2. URL manager — per-entry load conditions for every external script URL.
 *   3. AJAX history rollback with location awareness.
 *   4. Load Conditions UI for the inline script textarea (page-level).
 *
 * Data injected via wp_localize_script( 'scriptomatic-admin-js', 'scriptomaticData', {...} )
 *   codeEditorSettings — value returned by wp_enqueue_code_editor() or false.
 */
jQuery( document ).ready( function ( $ ) {
    var data   = window.scriptomaticData || {};
    var loc    = data.location || 'head';
    var maxLen = data.maxLength || 100000;
    var i18n   = data.i18n    || {};

    // On the files page, honour the server's upload limit instead of the
    // inline-script 100 KB cap.
    if ( loc === 'files' && data.maxUploadSize ) {
        maxLen = parseInt( data.maxUploadSize, 10 ) || maxLen;
    }

    /* =========================================================================
     * 1. Code editor (CodeMirror via wp.codeEditor) + character counter
     *
     * Initialises a full JS code editor on the inline-script textarea when
     * wp.codeEditor is available and the user has not disabled syntax
     * highlighting in their WP profile.  Falls back to a plain <textarea>.
     * The files page feeds the same init path; its textarea id follows the
     * same pattern: #scriptomatic-files-script.
     * ====================================================================== */
    var $textarea     = $( '#scriptomatic-' + loc + '-script' );
    var $counter      = $( '#scriptomatic-' + loc + '-char-count' );
    var cmEditor      = null;
    var codeEditorCfg = data.codeEditorSettings || false;

    /* Format raw byte count as a human-readable string (files page). */
    function formatBytes( n ) {
        if ( n < 1024 ) { return n + ' B'; }
        if ( n < 1024 * 1024 ) { return ( n / 1024 ).toFixed( 1 ) + ' KB'; }
        return ( n / ( 1024 * 1024 ) ).toFixed( 2 ) + ' MB';
    }    var wpHintWords = [
        'jQuery', 'jQuery(document).ready(', 'jQuery(function($){',
        '$', '$.ajax(', '$.post(', '$.get(', '$.fn',
        'wp', 'wp.ajax', 'wp.ajax.post(', 'wp.ajax.send(',
        'wp.hooks', 'wp.hooks.addFilter(', 'wp.hooks.addAction(',
        'wp.hooks.applyFilters(', 'wp.hooks.doAction(',
        'wp.data', 'wp.data.select(', 'wp.data.dispatch(',
        'wp.element', 'wp.components',
        'wp.i18n', 'wp.i18n.__(', 'wp.i18n._n(',
        'wp.apiFetch(', 'wp.url',
        'ajaxurl', 'pagenow', 'typenow',
        'console.log(', 'console.warn(', 'console.error('
    ];

    function updateCounter( len ) {
        var display = ( loc === 'files' ) ? formatBytes( len ) : len.toLocaleString();
        $counter.text( display );
        if ( len > maxLen * 0.9 ) {
            $counter.css( { color: '#dc3545', fontWeight: 'bold' } );
        } else if ( len > maxLen * 0.75 ) {
            $counter.css( { color: '#ffc107', fontWeight: 'bold' } );
        } else {
            $counter.css( { color: '', fontWeight: '' } );
        }
    }

    if ( $textarea.length ) {
        if ( codeEditorCfg && typeof wp !== 'undefined' && wp.codeEditor ) {

            /* Augment the WP-supplied CodeMirror settings. */
            codeEditorCfg.codemirror                  = codeEditorCfg.codemirror || {};
            codeEditorCfg.codemirror.lineNumbers       = true;
            codeEditorCfg.codemirror.matchBrackets     = true;
            codeEditorCfg.codemirror.autoCloseBrackets = true;
            codeEditorCfg.codemirror.extraKeys         = $.extend(
                {}, codeEditorCfg.codemirror.extraKeys || {},
                { 'Ctrl-Space': 'autocomplete' }
            );

            /* Custom hint: JS built-in words merged with WP/jQuery globals. */
            var cmHintFn = function ( cm ) {
                var builtIn = ( CodeMirror.hint && CodeMirror.hint.javascript )
                    ? CodeMirror.hint.javascript( cm )
                    : null;
                var cur   = cm.getCursor();
                var token = cm.getTokenAt( cur );
                var word  = token.string.replace( /^\./, '' ).toLowerCase();
                var extra = [];
                if ( word.length >= 1 ) {
                    wpHintWords.forEach( function ( w ) {
                        if ( w.toLowerCase().indexOf( word ) === 0 ) {
                            extra.push( w );
                        }
                    } );
                }
                if ( ! builtIn && ! extra.length ) { return; }
                var from = CodeMirror.Pos( cur.line, token.start );
                var to   = CodeMirror.Pos( cur.line, token.end );
                if ( builtIn ) {
                    builtIn.list = builtIn.list.concat(
                        extra.filter( function ( w ) {
                            return builtIn.list.indexOf( w ) === -1;
                        } )
                    );
                    return builtIn;
                }
                return { list: extra, from: from, to: to };
            };
            codeEditorCfg.codemirror.hintOptions = { hint: cmHintFn, completeSingle: false };

            var editorInst = wp.codeEditor.initialize( $textarea[ 0 ], codeEditorCfg );
            cmEditor = editorInst.codemirror;

            /* Live character counter driven by the CM instance. */
            cmEditor.on( 'change', function ( cm ) {
                updateCounter( cm.getValue().length );
            } );
            updateCounter( cmEditor.getValue().length );

            /* Sync CM content → hidden textarea before the form POSTs. */
            $textarea.closest( 'form' ).on( 'submit', function () {
                $textarea.val( cmEditor.getValue() );
            } );

        } else {
            /* Graceful fallback: accessibility mode or WP < 4.9. */
            if ( $counter.length ) {
                $textarea.on( 'input', function () { updateCounter( this.value.length ); } );
                updateCounter( $textarea.val().length );
            }
        }
    }

    /* =========================================================================
     * Load Conditions helper — defined before section 2 so it can be called
     * by the URL manager when initialising per-entry condition wraps.
     * ====================================================================== */

    /**
     * Initialise one conditions wrap (select + panels + chicklet managers).
     *
     * @param {jQuery}        $wrap    The `.scriptomatic-conditions-wrap` element.
     * @param {Function|null} onUpdate Optional callback fired after every syncJson().
     */
    function initConditions( $wrap, onUpdate ) {
        var condPfx = $wrap.data( 'prefix' );
        var $type   = $( '#' + condPfx + '-type' );
        var $json   = $( '#' + condPfx + '-json' );

        function syncJson() {
            var t      = $type.val();
            var values = [];

            if ( t === 'post_type' ) {
                $wrap.find( '.sm-pt-checkbox:checked' ).each( function () {
                    values.push( $( this ).val() );
                } );
            } else if ( t === 'page_id' ) {
                $( '#' + condPfx + '-id-chicklets .scriptomatic-chicklet' ).each( function () {
                    values.push( parseInt( $( this ).data( 'val' ), 10 ) );
                } );
            } else if ( t === 'url_contains' ) {
                $( '#' + condPfx + '-url-chicklets .scriptomatic-chicklet' ).each( function () {
                    values.push( $( this ).data( 'val' ) );
                } );
            } else if ( t === 'by_date' ) {
                var dateFrom = $( '#' + condPfx + '-date-from' ).val();
                var dateTo   = $( '#' + condPfx + '-date-to' ).val();
                if ( dateFrom ) { values.push( dateFrom ); }
                if ( dateTo )   { values.push( dateTo ); }
            } else if ( t === 'by_datetime' ) {
                var dtFrom = $( '#' + condPfx + '-dt-from' ).val();
                var dtTo   = $( '#' + condPfx + '-dt-to' ).val();
                if ( dtFrom ) { values.push( dtFrom ); }
                if ( dtTo )   { values.push( dtTo ); }
            } else if ( t === 'week_number' ) {
                $( '#' + condPfx + '-week-chicklets .scriptomatic-chicklet' ).each( function () {
                    values.push( parseInt( $( this ).data( 'val' ), 10 ) );
                } );
            } else if ( t === 'by_month' ) {
                $wrap.find( '.sm-month-checkbox:checked' ).each( function () {
                    values.push( parseInt( $( this ).val(), 10 ) );
                } );
            }

            $json.val( JSON.stringify( { type: t, values: values } ) );

            if ( typeof onUpdate === 'function' ) {
                onUpdate();
            }
        }

        function showPanel( t ) {
            $wrap.find( '.sm-cond-panel' ).attr( 'hidden', true );
            var $panel = $wrap.find( '.sm-cond-panel[data-panel="' + t + '"]' );
            if ( $panel.length ) {
                $panel.removeAttr( 'hidden' );
            }
        }

        $type.on( 'change', function () {
            showPanel( $( this ).val() );
            syncJson();
        } );
        showPanel( $type.val() );

        $wrap.on( 'change', '.sm-pt-checkbox, .sm-month-checkbox', syncJson );
        $wrap.on( 'change', '.sm-date-from, .sm-date-to, .sm-dt-from, .sm-dt-to', syncJson );

        /* -- ID chicklet manager -- */
        var $idList  = $( '#' + condPfx + '-id-chicklets' );
        var $idInput = $( '#' + condPfx + '-id-new' );
        var $idAdd   = $( '#' + condPfx + '-id-add' );
        var $idError = $( '#' + condPfx + '-id-error' );

        function makeChicklet( val, label ) {
            var $c = $( '<span>' ).addClass( 'scriptomatic-chicklet' ).attr( 'data-val', val );
            $( '<span>' ).addClass( 'chicklet-label' ).attr( 'title', label ).text( label ).appendTo( $c );
            $( '<button>' ).attr( { type: 'button', 'aria-label': 'Remove' } )
                .addClass( 'scriptomatic-remove-url' ).html( '&times;' ).appendTo( $c );
            return $c;
        }

        function addId() {
            var id = parseInt( $idInput.val(), 10 );
            $idError.hide().text( '' );
            if ( ! id || id < 1 ) {
                $idError.text( i18n.invalidId || 'Please enter a valid positive integer ID.' ).show();
                $idInput.trigger( 'focus' );
                return;
            }
            if ( $idList.find( '[data-val="' + id + '"]' ).length ) {
                $idError.text( i18n.duplicateId || 'This ID has already been added.' ).show();
                $idInput.trigger( 'focus' );
                return;
            }
            $idList.append( makeChicklet( id, String( id ) ) );
            $idInput.val( '' ).trigger( 'focus' );
            syncJson();
        }

        if ( $idAdd.length ) {
            $idAdd.on( 'click', addId );
            $idInput.on( 'keydown', function ( e ) {
                if ( e.key === 'Enter' ) { e.preventDefault(); addId(); }
            } );
        }

        /* -- URL-pattern chicklet manager -- */
        var $urlPatList  = $( '#' + condPfx + '-url-chicklets' );
        var $urlPatInput = $( '#' + condPfx + '-url-new' );
        var $urlPatAdd   = $( '#' + condPfx + '-url-add' );
        var $urlPatError = $( '#' + condPfx + '-url-error' );

        function addPattern() {
            var pat = $.trim( $urlPatInput.val() );
            $urlPatError.hide().text( '' );
            if ( ! pat ) {
                $urlPatError.text( i18n.emptyPattern || 'Please enter a URL path or pattern.' ).show();
                $urlPatInput.trigger( 'focus' );
                return;
            }
            if ( $urlPatList.find( '[data-val="' + pat.replace( /"/g, '\\"' ) + '"]' ).length ) {
                $urlPatError.text( i18n.duplicatePattern || 'This pattern has already been added.' ).show();
                $urlPatInput.trigger( 'focus' );
                return;
            }
            $urlPatList.append( makeChicklet( pat, pat ) );
            $urlPatInput.val( '' ).trigger( 'focus' );
            syncJson();
        }

        if ( $urlPatAdd.length ) {
            $urlPatAdd.on( 'click', addPattern );
            $urlPatInput.on( 'keydown', function ( e ) {
                if ( e.key === 'Enter' ) { e.preventDefault(); addPattern(); }
            } );
        }

        /* -- Week-number chicklet manager -- */
        var $weekList  = $( '#' + condPfx + '-week-chicklets' );
        var $weekInput = $( '#' + condPfx + '-week-new' );
        var $weekAdd   = $( '#' + condPfx + '-week-add' );
        var $weekError = $( '#' + condPfx + '-week-error' );

        function addWeek() {
            var wk = parseInt( $weekInput.val(), 10 );
            $weekError.hide().text( '' );
            if ( ! wk || wk < 1 || wk > 53 ) {
                $weekError.text( i18n.invalidWeek || 'Please enter a valid week number (1\u201353).' ).show();
                $weekInput.trigger( 'focus' );
                return;
            }
            if ( $weekList.find( '[data-val="' + wk + '"]' ).length ) {
                $weekError.text( i18n.duplicateWeek || 'This week number has already been added.' ).show();
                $weekInput.trigger( 'focus' );
                return;
            }
            $weekList.append( makeChicklet( wk, 'Week ' + wk ) );
            $weekInput.val( '' ).trigger( 'focus' );
            syncJson();
        }

        if ( $weekAdd.length ) {
            $weekAdd.on( 'click', addWeek );
            $weekInput.on( 'keydown', function ( e ) {
                if ( e.key === 'Enter' ) { e.preventDefault(); addWeek(); }
            } );
        }

        /* Shared remove handler for ID, URL-pattern, and week-number chicklets */
        $wrap.on(
            'click',
            '#' + condPfx + '-id-chicklets .scriptomatic-remove-url, ' +
            '#' + condPfx + '-url-chicklets .scriptomatic-remove-url, ' +
            '#' + condPfx + '-week-chicklets .scriptomatic-remove-url',
            function () {
                $( this ).closest( '.scriptomatic-chicklet' ).remove();
                syncJson();
            }
        );

        syncJson();
    }  /* end initConditions */

    /* =========================================================================
     * 2. URL manager — per-entry load conditions
     *
     * Data model: [{url, conditions}, ...] stored in the main hidden input.
     * Each entry card shows the URL + an always-visible conditions picker.
     * ====================================================================== */
    var $urlMgr = $( '#scriptomatic-' + loc + '-url-manager' );

    if ( $urlMgr.length ) {
        var $entryList   = $urlMgr.find( '.sm-url-entry-list' );
        var $addUrlInput = $( '#scriptomatic-' + loc + '-new-url' );
        var $addUrlBtn   = $( '#scriptomatic-' + loc + '-add-url' );
        var $mainHidden  = $( '#scriptomatic-' + loc + '-linked-scripts-input' );
        var $urlAddErr   = $( '#scriptomatic-' + loc + '-url-error' );
        var tmplEl       = document.getElementById( 'scriptomatic-' + loc + '-url-entry-template' );
        var entryIdx     = $entryList.children( '.sm-url-entry' ).length;

        /**
         * Rebuild the main hidden input from the current DOM state.
         * Called after every add, remove, or conditions change.
         */
        function syncLinked() {
            var entries = [];
            $entryList.children( '.sm-url-entry' ).each( function () {
                var url  = String( $( this ).data( 'url' ) || '' );
                var cond = { type: 'all', values: [] };
                var $cj  = $( this ).find( 'input[data-entry-cond-json]' );
                if ( $cj.length ) {
                    try { cond = JSON.parse( $cj.val() ) || cond; } catch ( e ) { /* keep default */ }
                }
                if ( url ) {
                    entries.push( { url: url, conditions: cond } );
                }
            } );
            $mainHidden.val( JSON.stringify( entries ) );
        }

        /**
         * Wire up the conditions wrap and remove button for a single entry card.
         *
         * @param {jQuery} $entry  The `.sm-url-entry` wrapper element.
         */
        function initEntry( $entry ) {
            var $condWrap = $entry.find( '.sm-url-conditions-wrap' );
            if ( $condWrap.length ) {
                initConditions( $condWrap, syncLinked );
            }
            $entry.find( '.sm-url-entry__remove' ).off( 'click' ).on( 'click', function () {
                $entry.remove();
                syncLinked();
            } );
        }

        /* Initialise all entries that were server-rendered on page load. */
        $entryList.children( '.sm-url-entry' ).each( function () {
            initEntry( $( this ) );
        } );

        /**
         * Validate the URL input, clone the template, append and initialise the
         * new entry card, then sync the hidden input.
         */
        function addUrl() {
            var url = $.trim( $addUrlInput.val() );
            $urlAddErr.hide().text( '' );

            if ( ! /^https?:\/\/.+/i.test( url ) ) {
                $urlAddErr.text( i18n.invalidUrl || 'Please enter a valid http:// or https:// URL.' ).show();
                $addUrlInput.trigger( 'focus' );
                return;
            }

            /* Duplicate check */
            var dup = false;
            $entryList.children( '.sm-url-entry' ).each( function () {
                if ( String( $( this ).data( 'url' ) ) === url ) { dup = true; return false; }
            } );
            if ( dup ) {
                $urlAddErr.text( i18n.duplicateUrl || 'This URL has already been added.' ).show();
                $addUrlInput.trigger( 'focus' );
                return;
            }

            if ( ! tmplEl ) { return; }

            var idx  = entryIdx++;
            /* Clone template: replace __IDX__ placeholder with real index. */
            var html = tmplEl.innerHTML.replace( /__IDX__/g, String( idx ) );
            var $e   = $( '<div>' ).html( html ).children( '.sm-url-entry' );

            $e.attr( { 'data-url': url, 'data-index': String( idx ) } );
            $e.find( '.sm-url-entry__label' ).text( url ).attr( 'title', url );
            $e.find( 'input[data-entry-cond-json]' ).val( JSON.stringify( { type: 'all', values: [] } ) );

            $entryList.append( $e );
            initEntry( $e );
            syncLinked();
            $addUrlInput.val( '' ).trigger( 'focus' );
        }

        $addUrlBtn.on( 'click', addUrl );
        $addUrlInput.on( 'keydown', function ( e ) {
            if ( e.key === 'Enter' ) { e.preventDefault(); addUrl(); }
        } );

        syncLinked(); /* initial sync — ensures hidden input reflects server-rendered state */
    }

    /* =========================================================================
     * 3. AJAX history rollback
     * ====================================================================== */
    $( document ).on( 'click', '.scriptomatic-history-restore', function () {
        if ( ! confirm( i18n.rollbackConfirm ) ) { return; }
        var $btn     = $( this );
        var index    = $btn.data( 'index' );
        var entryLoc = $btn.data( 'location' ) || loc;
        var orig     = $btn.data( 'original-text' ) || 'Restore';
        $btn.prop( 'disabled', true ).text( i18n.restoring || 'Restoring\u2026' );
        $.post( data.ajaxUrl, {
            action:   'scriptomatic_rollback',
            nonce:    data.rollbackNonce,
            index:    index,
            location: entryLoc
        }, function ( response ) {
            if ( response.success ) {
                var rLoc    = response.data.location || loc;
                var $ta     = $( '#scriptomatic-' + rLoc + '-script' );
                var content = response.data.content || '';
                if ( cmEditor && rLoc === loc ) {
                    cmEditor.setValue( content );
                } else if ( $ta.length ) {
                    $ta.val( content ).trigger( 'input' );
                }
                $( '<div>' ).addClass( 'notice notice-success is-dismissible' )
                    .html( '<p>' + i18n.rollbackSuccess + '</p>' )
                    .insertAfter( '.wp-header-end' );
                setTimeout( function () { location.reload(); }, 800 );
            } else {
                var msg = ( response.data && response.data.message ) ? response.data.message : '';
                alert( i18n.rollbackError + ( msg ? ' ' + msg : '' ) );
                $btn.prop( 'disabled', false ).text( orig );
            }
        } ).fail( function () {
            alert( i18n.rollbackError );
            $btn.prop( 'disabled', false ).text( orig );
        } );
    } );

    /* =========================================================================
     * 3.5  History "View" lightbox
     * ====================================================================== */
    var $lightbox = $( '#sm-history-lightbox' );

    if ( $lightbox.length ) {
        function closeLightbox() {
            $lightbox.removeClass( 'is-open' );
            $( 'body' ).css( 'overflow', '' );
        }

        $lightbox.find( '.sm-history-lightbox__close' ).on( 'click', closeLightbox );

        $lightbox.on( 'click', function ( e ) {
            if ( $( e.target ).is( $lightbox ) ) { closeLightbox(); }
        } );

        $( document ).on( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && $lightbox.hasClass( 'is-open' ) ) { closeLightbox(); }
        } );

        $( document ).on( 'click', '.scriptomatic-history-view', function () {
            var $btn     = $( this );
            var index    = $btn.data( 'index' );
            var entryLoc = $btn.data( 'location' ) || loc;
            var label    = $btn.data( 'label' ) || '';
            var orig     = $btn.text();

            $btn.prop( 'disabled', true ).text( i18n.loading || 'Loading…' );

            $.post( data.ajaxUrl, {
                action:   'scriptomatic_get_history_content',
                nonce:    data.rollbackNonce,
                index:    index,
                location: entryLoc
            }, function ( response ) {
                $btn.prop( 'disabled', false ).text( orig );
                if ( response.success ) {
                    var content = response.data.content || '';
                    $lightbox.find( '.sm-history-lightbox__title' ).text( i18n.viewTitle || 'Revision Preview' );
                    $lightbox.find( '.sm-history-lightbox__meta' ).text( label );
                    var $pre = $lightbox.find( '.sm-history-lightbox__pre' );
                    if ( content ) {
                        $pre.text( content ).removeClass( 'sm-history-lightbox__empty' );
                    } else {
                        $pre.text( i18n.emptyScript || '(empty)' ).addClass( 'sm-history-lightbox__empty' );
                    }
                    $lightbox.addClass( 'is-open' );
                    $( 'body' ).css( 'overflow', 'hidden' );
                } else {
                    var msg = ( response.data && response.data.message ) ? response.data.message : '';
                    alert( ( i18n.viewError || 'Could not load revision.' ) + ( msg ? ' ' + msg : '' ) );
                }
            } ).fail( function () {
                $btn.prop( 'disabled', false ).text( orig );
                alert( i18n.viewError || 'Could not load revision.' );
            } );
        } );

        /* =====================================================================
         * 3.6  File Activity Log — "sm-file-restore" and "sm-file-view" buttons
         *
         * Mirror the inline rollback/view pattern but use the files AJAX
         * actions and filesNonce so they work on the JS Files edit page.
         * ==================================================================== */
        $( document ).on( 'click', '.sm-file-restore', function () {
            if ( ! confirm( i18n.rollbackConfirm ) ) { return; }
            var $btn   = $( this );
            var index  = $btn.data( 'index' );
            var fileId = $btn.data( 'file-id' );
            var orig   = $btn.data( 'original-text' ) || 'Restore';
            $btn.prop( 'disabled', true ).text( i18n.restoring || 'Restoring\u2026' );

            $.post( data.ajaxUrl, {
                action:  'scriptomatic_rollback_js_file',
                nonce:   data.filesNonce,
                index:   index,
                file_id: fileId
            }, function ( response ) {
                if ( response.success ) {
                    var content = response.data.content || '';
                    var $ta     = $( '#scriptomatic-files-script' );
                    if ( cmEditor && loc === 'files' ) {
                        cmEditor.setValue( content );
                    } else if ( $ta.length ) {
                        $ta.val( content ).trigger( 'input' );
                    }
                    $( '<div>' ).addClass( 'notice notice-success is-dismissible' )
                        .html( '<p>' + ( i18n.rollbackSuccess || 'Restored successfully.' ) + '</p>' )
                        .insertAfter( '.wp-header-end' );
                    setTimeout( function () { location.reload(); }, 800 );
                } else {
                    var msg = ( response.data && response.data.message ) ? response.data.message : '';
                    alert( ( i18n.rollbackError || 'Restore failed.' ) + ( msg ? ' ' + msg : '' ) );
                    $btn.prop( 'disabled', false ).text( orig );
                }
            } ).fail( function () {
                alert( i18n.rollbackError || 'Restore failed.' );
                $btn.prop( 'disabled', false ).text( orig );
            } );
        } );

        $( document ).on( 'click', '.sm-file-view', function () {
            var $btn   = $( this );
            var index  = $btn.data( 'index' );
            var fileId = $btn.data( 'file-id' );
            var label  = $btn.data( 'label' ) || '';
            var orig   = $btn.text();

            $btn.prop( 'disabled', true ).text( i18n.loading || 'Loading\u2026' );

            $.post( data.ajaxUrl, {
                action:  'scriptomatic_get_file_activity_content',
                nonce:   data.filesNonce,
                index:   index,
                file_id: fileId
            }, function ( response ) {
                $btn.prop( 'disabled', false ).text( orig );
                if ( response.success ) {
                    var content = response.data.content || '';
                    $lightbox.find( '.sm-history-lightbox__title' ).text( i18n.viewTitle || 'Revision Preview' );
                    $lightbox.find( '.sm-history-lightbox__meta' ).text( label );
                    var $pre = $lightbox.find( '.sm-history-lightbox__pre' );
                    if ( content ) {
                        $pre.text( content ).removeClass( 'sm-history-lightbox__empty' );
                    } else {
                        $pre.text( i18n.emptyScript || '(empty)' ).addClass( 'sm-history-lightbox__empty' );
                    }
                    $lightbox.addClass( 'is-open' );
                    $( 'body' ).css( 'overflow', 'hidden' );
                } else {
                    var msg = ( response.data && response.data.message ) ? response.data.message : '';
                    alert( ( i18n.viewError || 'Could not load revision.' ) + ( msg ? ' ' + msg : '' ) );
                }
            } ).fail( function () {
                $btn.prop( 'disabled', false ).text( orig );
                alert( i18n.viewError || 'Could not load revision.' );
            } );
        } );
    }

    /* =========================================================================
     * 4. Load Conditions — page-level wraps (inline script textarea only).
     *    Per-URL wraps (.sm-url-conditions-wrap) are initialised in section 2.
     * ====================================================================== */
    $( '.scriptomatic-conditions-wrap' ).not( '.sm-url-conditions-wrap' ).each( function () {
        initConditions( $( this ) );
    } );

    /* =========================================================================
     * 5. JS Files page
     * ====================================================================== */
    if ( loc === 'files' ) {

        /* 5a. Auto-slug: mirror label → filename while the filename has not
         *     been manually edited.  Stops syncing the moment the user types
         *     in the filename field themselves. */
        var $fileLabel = $( '#sm-file-label' );
        var $fileSlug  = $( '#sm-file-name' );
        var slugEdited = $fileSlug.length && $fileSlug.val() !== '';

        if ( $fileLabel.length && $fileSlug.length ) {
            $fileLabel.on( 'input', function () {
                if ( ! slugEdited ) {
                    var slug = $fileLabel.val()
                        .toLowerCase()
                        .replace( /[^a-z0-9]+/g, '-' )
                        .replace( /^-+|-+$/g, '' );
                    $fileSlug.val( slug ? slug + '.js' : '' );
                }
            } );
            $fileSlug.on( 'input', function () {
                slugEdited = true;
            } );
        }

        /* 5b. AJAX delete: confirm, POST, remove row on success. */
        $( document ).on( 'click', '.sm-file-delete', function ( e ) {
            e.preventDefault();
            var $btn   = $( this );
            var fileId = $btn.data( 'file-id' );
            var label  = $btn.data( 'label' ) || fileId;
            var confirmMsg = ( i18n.deleteFileConfirm || 'Delete "$1"? This cannot be undone.' )
                .replace( '$1', label );

            if ( ! confirm( confirmMsg ) ) { return; }

            var origText = $btn.text();
            $btn.prop( 'disabled', true ).text( i18n.deleting || 'Deleting…' );

            $.post( data.ajaxUrl, {
                action:  'scriptomatic_delete_js_file',
                nonce:   data.filesNonce,
                file_id: fileId
            }, function ( response ) {
                if ( response.success ) {
                    $btn.closest( 'tr' ).fadeOut( 300, function () {
                        $( this ).remove();
                    } );
                } else {
                    var msg = ( response.data && response.data.message ) ? response.data.message : '';
                    alert( ( i18n.deleteFileError || 'Delete failed.' ) + ( msg ? ' ' + msg : '' ) );
                    $btn.prop( 'disabled', false ).text( origText );
                }
            } ).fail( function () {
                alert( i18n.deleteFileError || 'Delete failed.' );
                $btn.prop( 'disabled', false ).text( origText );
            } );
        } );

    } /* end loc === 'files' */

} );  /* end document.ready */
