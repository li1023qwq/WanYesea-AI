/**
 * 编辑建议（Notes）正文中的 [READABILITY] 等英文类型标签 → 中文（DOM 兜底）。
 */
( function () {
	var cfg = window.wanyeseaAiEditorialNotesI18n || {};
	var prefixMap = cfg.prefixMap && typeof cfg.prefixMap === 'object' ? cfg.prefixMap : {};

	if ( !Object.keys( prefixMap ).length ) {
		return;
	}

	function translatePrefixes( text ) {
		if ( typeof text !== 'string' || text === '' ) {
			return text;
		}

		var out = text;
		Object.keys( prefixMap ).forEach( function ( en ) {
			var zh = prefixMap[ en ];
			if ( typeof zh !== 'string' || zh === '' ) {
				return;
			}

			out = out.split( en ).join( zh );

			var label = en.replace( /^\[|\]$/g, '' );
			if ( label ) {
				var re = new RegExp( '\\[' + label.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\]', 'gi' );
				out = out.replace( re, zh );
			}
		} );

		return out;
	}

	function walk( root ) {
		if ( !root ) {
			return;
		}

		var walker = document.createTreeWalker(
			root,
			NodeFilter.SHOW_TEXT,
			{
				acceptNode: function ( node ) {
					if ( !node.nodeValue || !node.nodeValue.trim() ) {
						return NodeFilter.FILTER_REJECT;
					}
					var parent = node.parentNode;
					if ( !parent || /^(SCRIPT|STYLE|TEXTAREA|INPUT)$/i.test( parent.nodeName ) ) {
						return NodeFilter.FILTER_REJECT;
					}
					return NodeFilter.FILTER_ACCEPT;
				},
			}
		);

		var nodes = [];
		while ( walker.nextNode() ) {
			nodes.push( walker.currentNode );
		}

		nodes.forEach( function ( node ) {
			var next = translatePrefixes( node.nodeValue );
			if ( next !== node.nodeValue ) {
				node.nodeValue = next;
			}
		} );
	}

	function run() {
		var roots = [
			document.querySelector( '.editor-collab-sidebar' ),
			document.querySelector( '.interface-complementary-area' ),
			document.body,
		];

		roots.forEach( function ( root ) {
			if ( root ) {
				walk( root );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}

	var mo = new MutationObserver( function () {
		window.clearTimeout( mo._t );
		mo._t = window.setTimeout( run, 40 );
	} );
	mo.observe( document.body, { childList: true, subtree: true, characterData: true } );
} )();
