/**
 * Scriptomatic — Admin JavaScript
 *
 * Location-aware: reads `scriptomaticData.location` ('head'|'footer'|'general')
 * and uses it to build element IDs matching the rendered PHP page.
 *
 * Features:
 *   1. Live character counter on the script textarea.
 *   2. URL manager — per-entry load conditions for every external script URL.
 *   3. AJAX history rollback with location awareness.
 *   4. Load Conditions UI for the inline script textarea (page-level).
 *
 * Data injected via wp_localize_script( 'scriptomatic-admin-js', 'scriptomaticData', {...} )
 */
jQuery( document ).ready( function ( $ ) {
    var data   = window.scriptomaticData || {};
    var loc    = data.location || 'head';
    var maxLen = data.maxLength || 100000;
    var i18n   = data.i18n    || {};

    /* =========================================================================
     * 1. Character counter
     * ====================================================================== */
    var $textarea = $( '#scriptomatic-' + loc + '-script' );
    var $counter  = $( '#scriptomatic-' + loc + '-char-count' );

    function updateCounter( len ) {
        $counter.text( len.toLocaleString() );
        if ( len > maxLen * 0.9 ) {
            $counter.css( { color: '#dc3545', fontWeight: 'bold' } );
        } else if ( len > maxLen * 0.75 ) {
            $counter.css( { color: '#ffc107', fontWeight: 'bold' } );
        } else {
            $counter.css( { color: '', fontWeight: '' } );
        }
    }

    if ( $textarea.length && $counter.length ) {
        $textarea.on( 'input', function () { updateCounter( this.value.length ); } );
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

        $wrap.on( 'change', '.sm-pt-checkbox', syncJson );

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

        /* Shared remove handler for both ID and URL-pattern chicklets */
        $wrap.on(
            'click',
            '#' + condPfx + '-id-chicklets .scriptomatic-remove-url, ' +
            '#' + condPfx + '-url-chicklets .scriptomatic-remove-url',
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
            var $e   = $( $.parseHTML( html.trim() ) ).filter( '.sm-url-entry' );

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
                var rLoc = response.data.location || loc;
                var $ta  = $( '#scriptomatic-' + rLoc + '-script' );
                if ( $ta.length ) { $ta.val( response.data.content ).trigger( 'input' ); }
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
     * 4. Load Conditions — page-level wraps (inline script textarea only).
     *    Per-URL wraps (.sm-url-conditions-wrap) are initialised in section 2.
     * ====================================================================== */
    $( '.scriptomatic-conditions-wrap' ).not( '.sm-url-conditions-wrap' ).each( function () {
        initConditions( $( this ) );
    } );

} );  /* end document.ready */
