/**
 * 文本类 AI Ability REST：强制 POST，避免 core-abilities 误用 GET 触发 rest_ability_invalid_method。
 */
( function ( wp ) {
	if ( ! wp?.apiFetch?.use ) {
		return;
	}

	var RUN_PREFIX = '/wp-abilities/v1/abilities/';
	var RUN_SUFFIX = '/run';

	wp.apiFetch.use( function ( options, next ) {
		var path = options.path || '';

		if ( typeof path !== 'string' || path.indexOf( RUN_PREFIX ) === -1 || path.indexOf( RUN_SUFFIX ) === -1 ) {
			return next( options );
		}

		if ( path.indexOf( '/abilities/ai/' ) === -1 ) {
			return next( options );
		}

		var cleanPath = path.split( '?' )[ 0 ];
		var inputFromQuery = null;

		if ( path.indexOf( '?' ) !== -1 ) {
			try {
				var parsed = new URL( path, window.location.origin );
				if ( parsed.searchParams.has( 'input' ) ) {
					var raw = parsed.searchParams.get( 'input' );
					try {
						inputFromQuery = JSON.parse( raw );
					} catch ( e ) {
						inputFromQuery = raw;
					}
				}
			} catch ( e ) {
				inputFromQuery = null;
			}
		}

		options.method = 'POST';
		options.path = cleanPath;

		if ( inputFromQuery !== null && ( ! options.data || options.data.input === undefined ) ) {
			options.data = { input: inputFromQuery };
		}

		return next( options );
	} );
} )( window.wp );
