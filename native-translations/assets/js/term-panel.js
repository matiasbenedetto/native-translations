/**
 * WP Native Translations — term edit-screen client.
 *
 * Categories/tags do not use the block editor, so this is a small vanilla-DOM
 * client over the same native-translations/v1 REST endpoints the sidebar panel uses.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! wp.domReady ) {
		return;
	}

	var apiFetch = wp.apiFetch;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( s ) { return s; };
	var sprintf = wp.i18n && wp.i18n.sprintf ? wp.i18n.sprintf : function ( s ) { return s; };
	var cfg = window.wpntTerm || {};

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
		this.linkOpen = false;
		this.linkSearch = '';
		this.linkCandidates = [];
		this.linkLoading = false;
		// Deferred language/original choices (#106): null = no pending change, so the
		// saved value is shown until the user edits and clicks Update.
		this.pendingLanguage = null;
		this.pendingOriginal = null;
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
				self.error = ( e && e.message ) || __( 'Could not load translations.', 'native-translations' );
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
				self.error = ( e && e.message ) || __( 'Request failed.', 'native-translations' );
			} )
			.finally( function () {
				self.busy = '';
				self.render();
			} );
	};

	TermPanel.prototype.searchLinkCandidates = function () {
		var self = this;
		self.linkLoading = true;
		self.error = '';
		self.render();
		request(
			'link-candidates?object_id=' + encodeURIComponent( cfg.termId ) +
				'&type=term&search=' + encodeURIComponent( self.linkSearch )
		)
			.then( function ( res ) {
				self.linkCandidates = res.candidates || [];
			} )
			.catch( function ( e ) {
				self.error = ( e && e.message ) || __( 'Could not search originals.', 'native-translations' );
			} )
			.finally( function () {
				self.linkLoading = false;
				self.render();
			} );
	};

	TermPanel.prototype.recommendOriginal = function () {
		var self = this;
		self.busy = 'ai-link';
		self.error = '';
		self.notice = '';
		self.render();
		request( 'recommend-original', { object_id: cfg.termId, type: 'term' } )
			.then( function ( res ) {
				self.confirm = { action: 'link-existing', candidate: res.candidate, reason: res.reason, ai: true };
			} )
			.catch( function ( e ) {
				self.error = ( e && e.message ) || __( 'Could not recommend an original.', 'native-translations' );
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
				el( 'p', { 'class': 'description', text: __( 'No AI provider is configured, so new translations cannot be generated.', 'native-translations' ) } )
			);
		}

		if ( ! this.data ) {
			mount.appendChild( el( 'p', { 'class': 'description', text: __( 'Loading translations…', 'native-translations' ) } ) );
			return;
		}

		// Saved state (drives the translations list + #104 Translate gating, which act
		// on the persisted language/original).
		var savedLang = this.data.language || '';
		var isOriginal = !! this.data.is_original;
		var translations = this.data.translations || {};
		// A grouped term's own language is fixed: changing it would drop its slot
		// from the group (and mislabel it). Lock the selector once the term has
		// sibling translations; the server enforces this too.
		var hasSiblings = Object.keys( translations ).length > 0;

		// Edited (dirty-aware) state: the language + original changes are deferred to
		// the term form's Update button (#106), staged in hidden fields and applied
		// server-side on edited_{taxonomy} — not written over REST on change.
		var effectiveLang = null !== this.pendingLanguage ? this.pendingLanguage : savedLang;
		var effectiveOriginal = null !== this.pendingOriginal ? this.pendingOriginal : isOriginal;
		var pendingChange =
			( null !== this.pendingLanguage && this.pendingLanguage !== savedLang ) ||
			( null !== this.pendingOriginal && this.pendingOriginal !== isOriginal );

		// Language selector. The placeholder is only offered while the term has no
		// language yet — clearing a language is an Overview action (#103), not here.
		var select = el( 'select', {} );
		if ( ! effectiveLang ) {
			select.appendChild( el( 'option', { value: '', text: __( '— Not set —', 'native-translations' ) } ) );
		}
		( cfg.languages || [] ).forEach( function ( l ) {
			var opt = el( 'option', { value: l.code, text: l.native ? l.name + ' (' + l.native + ')' : l.name } );
			if ( l.code === effectiveLang ) {
				opt.setAttribute( 'selected', 'selected' );
			}
			select.appendChild( opt );
		} );
		select.disabled = hasSiblings;
		select.addEventListener( 'change', function () {
			if ( ! select.value ) {
				return;
			}
			self.pendingLanguage = select.value;
			self.render();
		} );

		var langRow = el( 'p', {}, [
			el( 'label', { text: __( 'Language of this term', 'native-translations' ) + ' ' } ),
			select,
		] );
		mount.appendChild( langRow );

		if ( hasSiblings ) {
			mount.appendChild(
				el( 'p', { 'class': 'description', text: __( 'This term belongs to a translation group, so its language is fixed. Unlink its other translations to change it.', 'native-translations' ) } )
			);
		}

		// "Mark as original" toggle. Disabled until the term has a language (the server
		// refuses to mark a language-less item as original).
		var originalCheckbox = document.createElement( 'input' );
		originalCheckbox.type = 'checkbox';
		originalCheckbox.checked = effectiveOriginal;
		originalCheckbox.disabled = ! effectiveLang;
		originalCheckbox.addEventListener( 'change', function () {
			self.pendingOriginal = originalCheckbox.checked;
			self.render();
		} );
		var originalLabel = el( 'label', {}, [ originalCheckbox, document.createTextNode( ' ' + __( 'Mark as original', 'native-translations' ) ) ] );
		mount.appendChild( el( 'p', {}, [ originalLabel ] ) );
		mount.appendChild(
			el( 'p', { 'class': 'description', text: effectiveLang
				? __( 'Only originals can be translated into other languages.', 'native-translations' )
				: __( 'Set the language of this term above before marking it as an original.', 'native-translations' ) } )
		);

		// Hidden fields submitted with the term's Update button; the server applies
		// them through the store on edited_{taxonomy} (#106).
		mount.appendChild( el( 'input', { type: 'hidden', name: 'wpnt_editor_language', value: effectiveLang } ) );
		mount.appendChild( el( 'input', { type: 'hidden', name: 'wpnt_editor_is_original', value: effectiveOriginal ? '1' : '0' } ) );

		if ( pendingChange ) {
			mount.appendChild(
				el( 'p', { 'class': 'description', text: __( 'Unsaved changes — click Update to apply the language/original change.', 'native-translations' ) } )
			);
		}

		var linkWrap = el( 'div', { 'class': 'wpnt-link-existing' } );
		var manualBtn = el( 'button', { 'type': 'button', 'class': 'button button-secondary', text: __( 'Link existing translation', 'native-translations' ) } );
		manualBtn.disabled = ! savedLang;
		manualBtn.addEventListener( 'click', function () {
			self.linkOpen = ! self.linkOpen;
			if ( self.linkOpen && ! self.linkCandidates.length ) {
				self.searchLinkCandidates();
			} else {
				self.render();
			}
		} );
		var aiBtn = el( 'button', { 'type': 'button', 'class': 'button button-secondary', text: self.busy === 'ai-link' ? __( 'Working…', 'native-translations' ) : __( 'Link to original with AI', 'native-translations' ) } );
		aiBtn.disabled = ! savedLang || ! cfg.aiAvailable || self.busy === 'ai-link';
		aiBtn.addEventListener( 'click', function () {
			self.recommendOriginal();
		} );
		linkWrap.appendChild( manualBtn );
		linkWrap.appendChild( document.createTextNode( ' ' ) );
		linkWrap.appendChild( aiBtn );
		if ( ! savedLang ) {
			linkWrap.appendChild( el( 'p', { 'class': 'description', text: __( 'Save a language for this term before linking an existing translation.', 'native-translations' ) } ) );
		} else if ( ! cfg.aiAvailable ) {
			linkWrap.appendChild( el( 'p', { 'class': 'description', text: __( 'Configure an AI provider to get an original recommendation.', 'native-translations' ) } ) );
		}

		if ( self.confirm && 'link-existing' === self.confirm.action ) {
			var c = self.confirm.candidate || {};
			var confirmBox = el( 'div', { 'class': 'wpnt-link-confirm' } );
			confirmBox.appendChild( el( 'p', { 'class': 'description', text: self.confirm.ai && self.confirm.reason
				? sprintf( __( 'AI recommendation: %s', 'native-translations' ), self.confirm.reason )
				: __( 'Link this term to the selected original?', 'native-translations' ) } ) );
			confirmBox.appendChild( el( 'p', { text: ( c.label || '' ) + ( c.language_label ? ' — ' + c.language_label : '' ) } ) );
			if ( hasSiblings ) {
				confirmBox.appendChild( el( 'p', { 'class': 'description', text: __( 'This term is already in another translation group. Linking will move it to the selected original’s group.', 'native-translations' ) } ) );
			}
			var confirmBtn = el( 'button', { 'type': 'button', 'class': 'button button-primary', text: __( 'Confirm link', 'native-translations' ) } );
			confirmBtn.disabled = self.busy === 'link-existing';
			confirmBtn.addEventListener( 'click', function () {
				self.act( 'link-existing', { object_id: cfg.termId, original_id: c.id, type: 'term' }, 'link-existing', { reload: true, successMsg: __( 'Existing translation linked.', 'native-translations' ) } );
			} );
			var cancelLink = el( 'button', { 'type': 'button', 'class': 'button-link', text: ' ' + __( 'Cancel', 'native-translations' ) } );
			cancelLink.addEventListener( 'click', function () { self.confirm = null; self.render(); } );
			confirmBox.appendChild( confirmBtn );
			confirmBox.appendChild( cancelLink );
			linkWrap.appendChild( confirmBox );
		}

		if ( self.linkOpen ) {
			var searchBox = el( 'div', { 'class': 'wpnt-link-search' } );
			var searchLabel = el( 'label', { text: __( 'Search originals', 'native-translations' ) + ' ' } );
			var searchInput = document.createElement( 'input' );
			searchInput.type = 'search';
			searchInput.value = self.linkSearch;
			searchInput.addEventListener( 'input', function () {
				self.linkSearch = searchInput.value;
			} );
			searchLabel.appendChild( searchInput );
			var searchBtn = el( 'button', { 'type': 'button', 'class': 'button', text: self.linkLoading ? __( 'Searching…', 'native-translations' ) : __( 'Search', 'native-translations' ) } );
			searchBtn.disabled = self.linkLoading;
			searchBtn.addEventListener( 'click', function () {
				self.linkSearch = searchInput.value;
				self.searchLinkCandidates();
			} );
			searchBox.appendChild( searchLabel );
			searchBox.appendChild( document.createTextNode( ' ' ) );
			searchBox.appendChild( searchBtn );
			if ( self.linkLoading ) {
				searchBox.appendChild( el( 'p', { 'class': 'description', text: __( 'Searching originals…', 'native-translations' ) } ) );
			} else if ( ! self.linkCandidates.length ) {
				searchBox.appendChild( el( 'p', { 'class': 'description', text: __( 'No compatible originals found.', 'native-translations' ) } ) );
			} else {
				self.linkCandidates.forEach( function ( candidate ) {
					var canLink = !! candidate.language && !! candidate.is_original;
					var row = el( 'p', { 'class': 'wpnt-link-candidate' } );
					row.appendChild( el( 'strong', { text: candidate.label || '' } ) );
					row.appendChild( document.createTextNode( ' — ' ) );
					row.appendChild( el( 'span', { 'class': 'description', text: [
						candidate.kind,
						candidate.language_label || __( 'No language', 'native-translations' ),
						candidate.is_original ? __( 'Original', 'native-translations' ) : __( 'Not marked original', 'native-translations' ),
						candidate.group_languages && candidate.group_languages.length
							? sprintf( __( 'Group: %s', 'native-translations' ), candidate.group_languages.join( ', ' ) )
							: __( 'No group yet', 'native-translations' ),
					].join( ' · ' ) } ) );
					row.appendChild( document.createTextNode( ' ' ) );
					var linkBtn = el( 'button', { 'type': 'button', 'class': 'button button-secondary', text: __( 'Link', 'native-translations' ) } );
					linkBtn.disabled = ! canLink;
					linkBtn.addEventListener( 'click', function () {
						self.confirm = { action: 'link-existing', candidate: candidate };
						self.render();
					} );
					row.appendChild( linkBtn );
					searchBox.appendChild( row );
				} );
			}
			linkWrap.appendChild( searchBox );
		}
		mount.appendChild( linkWrap );

		// With no language yet, show a single prompt instead of one disabled Translate
		// row (with duplicated help) per language.
		if ( ! savedLang ) {
			mount.appendChild(
				el( 'div', { 'class': 'notice notice-info inline' }, [
					el( 'p', { text: __( 'Set the language of this term above to generate translations.', 'native-translations' ) } ),
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
					el( 'p', { text: __( 'Only originals can be translated. Mark this term as original above to create translations from it.', 'native-translations' ) } ),
				] )
			);
		}

		// Per-language actions.
		( cfg.languages || [] ).forEach( function ( l ) {
			if ( l.code === savedLang ) {
				return;
			}
			var existing = translations[ l.code ];
			if ( ! existing && ! isOriginal ) {
				return;
			}
			var working = self.busy === l.code || self.busy === 'unlink-' + l.code || self.busy === 'del-' + l.code;
			var row = el( 'p', {}, [ el( 'strong', { text: l.name + ' ' } ) ] );

			if ( existing ) {
				var confirming = self.confirm && self.confirm.code === l.code ? self.confirm.action : '';

				if ( 'recreate' === confirming ) {
					/* translators: %s: language name. */
					row.appendChild( el( 'span', { 'class': 'description', text: sprintf( __( 'Overwrite the %s translation? A revision is saved first. ', 'native-translations' ), l.name ) } ) );
					var rYes = el( 'button', { 'type': 'button', 'class': 'button button-primary', text: __( 'Yes, regenerate', 'native-translations' ) } );
					rYes.disabled = working;
					rYes.addEventListener( 'click', function () {
						/* translators: %s: language name. */
						self.act( 'recreate', { object_id: existing.id, type: 'term' }, l.code, { successMsg: sprintf( __( '%s translation regenerated.', 'native-translations' ), l.name ) } );
					} );
					var rNo = el( 'button', { 'type': 'button', 'class': 'button-link', text: ' ' + __( 'Cancel', 'native-translations' ) } );
					rNo.addEventListener( 'click', function () { self.confirm = null; self.render(); } );
					row.appendChild( rYes );
					row.appendChild( document.createTextNode( ' ' ) );
					row.appendChild( rNo );
					mount.appendChild( row );
					return;
				}
				if ( 'delete' === confirming ) {
					/* translators: %s: language name. */
					row.appendChild( el( 'span', { 'class': 'description', text: sprintf( __( 'Delete the %s translation and unlink it? ', 'native-translations' ), l.name ) } ) );
					var dYes = el( 'button', { 'type': 'button', 'class': 'button button-primary', text: __( 'Yes, delete', 'native-translations' ) } );
					dYes.disabled = working;
					dYes.addEventListener( 'click', function () {
						/* translators: %s: language name. */
						self.act( 'delete', { object_id: existing.id, type: 'term' }, 'del-' + l.code, { reload: true, successMsg: sprintf( __( '%s translation deleted.', 'native-translations' ), l.name ) } );
					} );
					var dNo = el( 'button', { 'type': 'button', 'class': 'button-link', text: ' ' + __( 'Cancel', 'native-translations' ) } );
					dNo.addEventListener( 'click', function () { self.confirm = null; self.render(); } );
					row.appendChild( dYes );
					row.appendChild( document.createTextNode( ' ' ) );
					row.appendChild( dNo );
					mount.appendChild( row );
					return;
				}

				if ( existing.edit_link ) {
					row.appendChild( el( 'a', { href: existing.edit_link, text: __( 'Edit', 'native-translations' ) } ) );
					row.appendChild( document.createTextNode( ' ' ) );
				}
				if ( existing.view_link ) {
					row.appendChild( el( 'a', { href: existing.view_link, text: __( 'View', 'native-translations' ) } ) );
					row.appendChild( document.createTextNode( ' ' ) );
				}
				var recreateBtn = el( 'button', {
					'type': 'button',
					'class': 'button button-secondary',
					text: working ? __( 'Working…', 'native-translations' ) : __( 'Recreate', 'native-translations' ),
				} );
				recreateBtn.disabled = working || ! cfg.aiAvailable;
				recreateBtn.addEventListener( 'click', function () {
					self.confirm = { action: 'recreate', code: l.code }; self.render();
				} );
				row.appendChild( recreateBtn );
				row.appendChild( document.createTextNode( ' ' ) );
				var unlinkBtn = el( 'button', { 'type': 'button', 'class': 'button-link', text: __( 'Unlink', 'native-translations' ) } );
				unlinkBtn.addEventListener( 'click', function () {
					/* translators: %s: language name. */
					self.act( 'unlink', { object_id: existing.id, type: 'term' }, 'unlink-' + l.code, { reload: true, successMsg: sprintf( __( '%s translation unlinked.', 'native-translations' ), l.name ) } );
				} );
				row.appendChild( unlinkBtn );
				row.appendChild( document.createTextNode( ' ' ) );
				var deleteBtn = el( 'button', { 'type': 'button', 'class': 'button-link', text: __( 'Delete', 'native-translations' ) } );
				deleteBtn.style.color = '#b32d2e';
				deleteBtn.addEventListener( 'click', function () {
					self.confirm = { action: 'delete', code: l.code }; self.render();
				} );
				row.appendChild( deleteBtn );
			} else {
				var translateBtn = el( 'button', {
					'type': 'button',
					'class': 'button button-primary',
					text: working ? __( 'Working…', 'native-translations' ) : __( 'Translate', 'native-translations' ),
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
		var mount = document.getElementById( 'wpnt-term-panel' );
		if ( mount && cfg.termId ) {
			new TermPanel( mount );
		}
	} );
}( window.wp ) );
