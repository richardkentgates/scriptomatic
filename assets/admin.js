/**
 * Scriptomatic — Admin JavaScript
 *
 * Location-aware: reads `scriptomaticData.location` ('head'|'footer'|'general')
 * and uses it to build element IDs matching the rendered PHP page.
 *
 * Features:
 *   1. Live character counter on the script textarea.
 *   2. Chicklet URL manager for External Script URLs.
 *   3. AJAX history rollback with location awareness.
 *   4. Load Conditions UI: panel show/hide + ID and URL-pattern chicklet managers.
 *
 * Data injected via wp_localize_script( 'scriptomatic-admin-js', 'scriptomaticData', {...} )
 */
jQuery( document ).ready( function ( $ ) {
    var data   = window.scriptomaticData || {};
    var loc    = data.location || 'head';
    var maxLen = data.maxLength || 100000;
    var i18n   = data.i18n    || {};

    /* -------------------------------------------------------------------------
     * 1. Character counter
     * ---------------------------------------------------------------------- */
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

    /* -------------------------------------------------------------------------
     * 2. Chicklet URL manager (External Script URLs)
     * ---------------------------------------------------------------------- */
    var pfx          = '#scriptomatic-' + loc;
    var $chicklets   = $( pfx + '-url-chicklets' );
    var $urlInput    = $( pfx + '-new-url' );
    var $addBtn      = $( pfx + '-add-url' );
    var $hiddenInput = $( pfx + '-linked-scripts-input' );
    var $urlError    = $( pfx + '-url-error' );

    function getUrls() {
        try { return JSON.parse( $hiddenInput.val() ) || []; } catch ( e ) { return []; }
    }
    function setUrls( urls ) { $hiddenInput.val( JSON.stringify( urls ) ); }

    function makeUrlChicklet( url ) {
        var $c = $( '<span>' ).addClass( 'scriptomatic-chicklet' ).attr( 'data-url', url );
        $( '<span>' ).addClass( 'chicklet-label' ).attr( 'title', url ).text( url ).appendTo( $c );
        $( '<button>' ).attr( { type: 'button', 'aria-label': 'Remove URL' } )
            .addClass( 'scriptomatic-remove-url' ).html( '&times;' ).appendTo( $c );
        return $c;
    }

    function addUrl() {
        var url = $urlInput.val().trim();
        $urlError.hide().text( '' );
        if ( ! url.match( /^https?:\/\/.+/i ) ) {
            $urlError.text( i18n.invalidUrl ).show();
            $urlInput.trigger( 'focus' );
            return;
        }
        var urls = getUrls();
        if ( urls.indexOf( url ) !== -1 ) {
            $urlError.text( i18n.duplicateUrl ).show();
            $urlInput.trigger( 'focus' );
            return;
        }
        urls.push( url );
        setUrls( urls );
        $chicklets.append( makeUrlChicklet( url ) );
        $urlInput.val( '' ).trigger( 'focus' );
    }

    if ( $addBtn.length ) {
        $addBtn.on( 'click', addUrl );
        $urlInput.on( 'keydown', function ( e ) {
            if ( e.key === 'Enter' ) { e.preventDefault(); addUrl(); }
        } );
        $chicklets.on( 'click', '.scriptomatic-remove-url', function () {
            var $c = $( this ).closest( '.scriptomatic-chicklet' );
            setUrls( getUrls().filter( function ( u ) { return u !== $c.data( 'url' ); } ) );
            $c.remove();
        } );
    }

    /* -------------------------------------------------------------------------
     * 3. AJAX history rollback
     * ---------------------------------------------------------------------- */
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
    } );  /* end history restore handler */

    /* -------------------------------------------------------------------------
     * 4. Load Conditions UI
     * ---------------------------------------------------------------------- */
    function initConditions( $wrap ) {
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
    }

    $( '.scriptomatic-conditions-wrap' ).each( function () {
        initConditions( $( this ) );
    } );

} );  /* end document.ready */
