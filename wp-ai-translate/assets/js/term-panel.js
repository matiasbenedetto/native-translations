/**
 * AI Translate — term edit-screen client.
 *
 * Categories/tags do not use the block editor, so this is a small vanilla-DOM
 * client over the same wp-ai-translate/v1 REST endpoints the sidebar panel uses.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! wp.domReady ) {
		return;
	}

	var apiFetch = wp.apiFetch;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( s ) { return s; };
	var sprintf = wp.i18n && wp.i18n.sprintf ? wp.i18n.sprintf : function ( s ) { return s; };
	var cfg = window.wpaitTerm || {};

	function el( tag, props, children ) {
		var node = document.createElement( tag );
		props = props || {};
		Object.keys( props ).forEach( function ( key ) {
			if ( 'text' === key ) {
				node.textContent = props[ key ];
			} else if ( 'onClick' === key ) {
				node.addEventListener( 'click', props[ key ] );
			} else {
				node.setAttribute( key, props[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function request( path, body ) {
		var opts = { path: '/' + cfg.namespace + '/' + path };
		if ( body ) {
			opts.method = 'POST';
			opts.data = body;
		}
		return apiFetch( opts );
	}

	function TermPanel( mount ) {
		this.mount = mount;
		this.data = null;
		this.error = '';
		this.notice = '';
		this.busy = '';
		this.confirm = null;
		this.load();
	}

	TermPanel.prototype.load = function () {
		var self = this;
		request(
			'translations?object_id=' + encodeURIComponent( cfg.termId ) + '&type=term'
		)
			.then( function ( res ) {
				self.data = res;
				self.error = '';
				self.render();
			} )
			.catch( function ( e ) {
				self.error = ( e && e.message ) || __( 'Could not load translations.', 'wp-ai-translate' );
				self.render();
			} );
	};

	TermPanel.prototype.act = function ( path, body, key, opts ) {
		var self = this;
		opts = opts || {};
		self.busy = key;
		self.error = '';
		self.notice = '';
		self.confirm = null;
		self.render();
		return request( path, body )
			.then( function ( res ) {
				if ( opts.reload ) {
					self.load();
				} else {
					self.data = res;
				}
				if ( opts.successMsg ) {
					self.notice = opts.successMsg;
				}
			} )
			.catch( function ( e ) {
				self.error = ( e && e.message ) || __( 'Request failed.', 'wp-ai-translate' );
			} )
			.finally( function () {
				self.busy = '';
				self.render();
			} );
	};

	TermPanel.prototype.render = function () {
		var self = this;
		var mount = this.mount;
		mount.innerHTML = '';

		if ( this.error ) {
			mount.appendChild( el( 'div', { 'class': 'notice notice-error inline' }, [ el( 'p', { text: this.error } ) ] ) );
		}

		if ( this.notice ) {
			mount.appendChild( el( 'div', { 'class': 'notice notice-success inline' }, [ el( 'p', { text: this.notice } ) ] ) );
		}

		if ( ! cfg.aiAvailable ) {
			mount.appendChild(
				el( 'p', { 'class': 'description', text: __( 'No AI provider is configured, so new translations cannot be generated.', 'wp-ai-translate' ) } )
			);
		}

		if ( ! this.data ) {
			mount.appendChild( el( 'p', { 'class': 'description', text: __( 'Loading translations…', 'wp-ai-translate' ) } ) );
			return;
		}

		var currentLang = this.data.language || '';
		var translations = this.data.translations || {};
		var isOriginal = !! this.data.is_original;
		// A grouped term's own language is fixed: changing it would drop its slot
		// from the group (and mislabel it). Lock the selector once the term has
		// sibling translations; the server enforces this too.
		var hasSiblings = Object.keys( translations ).length > 0;

		// Language selector. The placeholder is only offered while the term has no
		// language yet — set-language cannot clear one, so it is not selectable.
		var select = el( 'select', {} );
		if ( ! currentLang ) {
			select.appendChild( el( 'option', { value: '', text: __( '— Not set —', 'wp-ai-translate' ) } ) );
		}
		( cfg.languages || [] ).forEach( function ( l ) {
			var opt = el( 'option', { value: l.code, text: l.native ? l.name + ' (' + l.native + ')' : l.name } );
			if ( l.code === currentLang ) {
				opt.setAttribute( 'selected', 'selected' );
			}
			select.appendChild( opt );
		} );
		select.disabled = 'lang' === this.busy || hasSiblings;
		select.addEventListener( 'change', function () {
			if ( ! select.value ) {
				return;
			}
			self.act( 'set-language', { object_id: cfg.termId, code: select.value, type: 'term' }, 'lang' );
		} );

		var langRow = el( 'p', {}, [
			el( 'label', { text: __( 'Language of this term', 'wp-ai-translate' ) + ' ' } ),
			select,
		] );
		mount.appendChild( langRow );

		if ( hasSiblings ) {
			mount.appendChild(
				el( 'p', { 'class': 'description', text: __( 'This term belongs to a translation group, so its language is fixed. Unlink its other translations to change it.', 'wp-ai-translate' ) } )
			);
		}

		// "Mark as original" toggle. Disabled until the term has a language, since the
		// server refuses to mark an item with no language as an original.
		var originalCheckbox = document.createElement( 'input' );
		originalCheckbox.type = 'checkbox';
		originalCheckbox.checked = !! this.data.is_original;
		originalCheckbox.disabled = 'original' === this.busy || ! currentLang;
		originalCheckbox.addEventListener( 'change', function () {
			self.act(
				'overview-original',
				{ object_id: cfg.termId, type: 'term', is_original: originalCheckbox.checked },
				'original',
				{ reload: true, successMsg: originalCheckbox.checked
					? __( 'Marked as the translation original.', 'wp-ai-translate' )
					: __( 'No longer marked as an original.', 'wp-ai-translate' ) }
			);
		} );
		var originalLabel = el( 'label', {}, [ originalCheckbox, document.createTextNode( ' ' + __( 'Mark as original', 'wp-ai-translate' ) ) ] );
		mount.appendChild( el( 'p', {}, [ originalLabel ] ) );
		mount.appendChild(
			el( 'p', { 'class': 'description', text: currentLang
				? __( 'Only originals can be translated into other languages.', 'wp-ai-translate' )
				: __( 'Set the language of this term above before marking it as an original.', 'wp-ai-translate' ) } )
		);

		// With no language yet, show a single prompt instead of one disabled Translate
		// row (with duplicated help) per language.
		if ( ! currentLang ) {
			mount.appendChild(
				el( 'div', { 'class': 'notice notice-info inline' }, [
					el( 'p', { text: __( 'Set the language of this term above to generate translations.', 'wp-ai-translate' ) } ),
				] )
			);
			return;
		}

		// Translations can only be created from an original (#104). Surface a single
		// notice and disable the per-language Translate buttons when this term is not
		// marked original; existing translations remain manageable.
		if ( ! isOriginal ) {
			mount.appendChild(
				el( 'div', { 'class': 'notice notice-info inline' }, [
					el( 'p', { text: __( 'Only originals can be translated. Mark this term as original above to create translations from it.', 'wp-ai-translate' ) } ),
				] )
			);
		}

		// Per-language actions.
		( cfg.languages || [] ).forEach( function ( l ) {
			if ( l.code === currentLang ) {
				return;
			}
			var existing = translations[ l.code ];
			var working = self.busy === l.code || self.busy === 'unlink-' + l.code || self.busy === 'del-' + l.code;
			var row = el( 'p', {}, [ el( 'strong', { text: l.name + ' ' } ) ] );

			if ( existing ) {
				var confirming = self.confirm && self.confirm.code === l.code ? self.confirm.action : '';

				if ( 'recreate' === confirming ) {
					/* translators: %s: language name. */
					row.appendChild( el( 'span', { 'class': 'description', text: sprintf( __( 'Overwrite the %s translation? A revision is saved first. ', 'wp-ai-translate' ), l.name ) } ) );
					var rYes = el( 'button', { 'type': 'button', 'class': 'button button-primary', text: __( 'Yes, regenerate', 'wp-ai-translate' ) } );
					rYes.disabled = working;
					rYes.addEventListener( 'click', function () {
						/* translators: %s: language name. */
						self.act( 'recreate', { object_id: existing.id, type: 'term' }, l.code, { successMsg: sprintf( __( '%s translation regenerated.', 'wp-ai-translate' ), l.name ) } );
					} );
					var rNo = el( 'button', { 'type': 'button', 'class': 'button-link', text: ' ' + __( 'Cancel', 'wp-ai-translate' ) } );
					rNo.addEventListener( 'click', function () { self.confirm = null; self.render(); } );
					row.appendChild( rYes );
					row.appendChild( document.createTextNode( ' ' ) );
					row.appendChild( rNo );
					mount.appendChild( row );
					return;
				}
				if ( 'delete' === confirming ) {
					/* translators: %s: language name. */
					row.appendChild( el( 'span', { 'class': 'description', text: sprintf( __( 'Delete the %s translation and unlink it? ', 'wp-ai-translate' ), l.name ) } ) );
					var dYes = el( 'button', { 'type': 'button', 'class': 'button button-primary', text: __( 'Yes, delete', 'wp-ai-translate' ) } );
					dYes.disabled = working;
					dYes.addEventListener( 'click', function () {
						/* translators: %s: language name. */
						self.act( 'delete', { object_id: existing.id, type: 'term' }, 'del-' + l.code, { reload: true, successMsg: sprintf( __( '%s translation deleted.', 'wp-ai-translate' ), l.name ) } );
					} );
					var dNo = el( 'button', { 'type': 'button', 'class': 'button-link', text: ' ' + __( 'Cancel', 'wp-ai-translate' ) } );
					dNo.addEventListener( 'click', function () { self.confirm = null; self.render(); } );
					row.appendChild( dYes );
					row.appendChild( document.createTextNode( ' ' ) );
					row.appendChild( dNo );
					mount.appendChild( row );
					return;
				}

				if ( existing.edit_link ) {
					row.appendChild( el( 'a', { href: existing.edit_link, text: __( 'Edit', 'wp-ai-translate' ) } ) );
					row.appendChild( document.createTextNode( ' ' ) );
				}
				if ( existing.view_link ) {
					row.appendChild( el( 'a', { href: existing.view_link, text: __( 'View', 'wp-ai-translate' ) } ) );
					row.appendChild( document.createTextNode( ' ' ) );
				}
				var recreateBtn = el( 'button', {
					'type': 'button',
					'class': 'button button-secondary',
					text: working ? __( 'Working…', 'wp-ai-translate' ) : __( 'Recreate', 'wp-ai-translate' ),
				} );
				recreateBtn.disabled = working || ! cfg.aiAvailable;
				recreateBtn.addEventListener( 'click', function () {
					self.confirm = { action: 'recreate', code: l.code }; self.render();
				} );
				row.appendChild( recreateBtn );
				row.appendChild( document.createTextNode( ' ' ) );
				var unlinkBtn = el( 'button', { 'type': 'button', 'class': 'button-link', text: __( 'Unlink', 'wp-ai-translate' ) } );
				unlinkBtn.addEventListener( 'click', function () {
					/* translators: %s: language name. */
					self.act( 'unlink', { object_id: existing.id, type: 'term' }, 'unlink-' + l.code, { reload: true, successMsg: sprintf( __( '%s translation unlinked.', 'wp-ai-translate' ), l.name ) } );
				} );
				row.appendChild( unlinkBtn );
				row.appendChild( document.createTextNode( ' ' ) );
				var deleteBtn = el( 'button', { 'type': 'button', 'class': 'button-link', text: __( 'Delete', 'wp-ai-translate' ) } );
				deleteBtn.style.color = '#b32d2e';
				deleteBtn.addEventListener( 'click', function () {
					self.confirm = { action: 'delete', code: l.code }; self.render();
				} );
				row.appendChild( deleteBtn );
			} else {
				var translateBtn = el( 'button', {
					'type': 'button',
					'class': 'button button-primary',
					text: working ? __( 'Working…', 'wp-ai-translate' ) : __( 'Translate', 'wp-ai-translate' ),
				} );
				translateBtn.disabled = working || ! cfg.aiAvailable || ! isOriginal;
				translateBtn.addEventListener( 'click', function () {
					self.act( 'translate', { source_id: cfg.termId, target_code: l.code, type: 'term' }, l.code );
				} );
				row.appendChild( translateBtn );
			}
			mount.appendChild( row );
		} );
	};

	wp.domReady( function () {
		var mount = document.getElementById( 'wpait-term-panel' );
		if ( mount && cfg.termId ) {
			new TermPanel( mount );
		}
	} );
}( window.wp ) );
