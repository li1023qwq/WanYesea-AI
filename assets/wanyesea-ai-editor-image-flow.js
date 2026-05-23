/**
 * 写文章「生成特色图」：Alt 文本 / 导入媒体改用服务端缓存，避免重复 POST 数 MB base64 导致 invalid_json。
 */
( function ( wp ) {
	if ( ! wp?.apiFetch?.use ) {
		return;
	}

	var CACHE_FLAG = 'wanyesea_use_editor_flow_cache';

	wp.apiFetch.use( function ( options, next ) {
		var path = options.path || '';

		if ( typeof path !== 'string' || path.indexOf( '/wp-abilities/v1/abilities/' ) === -1 ) {
			return next( options );
		}

		if ( path.indexOf( 'ai/alt-text-generation/run' ) !== -1 ) {
			var altInput = options.data && options.data.input ? options.data.input : {};
			options.data = {
				input: {
					wanyesea_use_editor_flow_cache: true,
					context: altInput.context || '',
					image_meta: altInput.image_meta || '',
				},
			};
			return next( options );
		}

		if ( path.indexOf( 'ai/image-import/run' ) !== -1 ) {
			var importInput = options.data && options.data.input ? options.data.input : {};
			options.data = {
				input: Object.assign( {}, importInput, {
					wanyesea_use_editor_flow_cache: true,
					data: 'wanyesea_cache',
				} ),
			};
			return next( options );
		}

		return next( options );
	} );
} )( window.wp );
