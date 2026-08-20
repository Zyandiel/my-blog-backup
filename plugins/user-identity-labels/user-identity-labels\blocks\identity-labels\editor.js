( function ( blocks, element, components, blockEditor, i18n ) {
	'use strict';

	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var __ = i18n.__;

	blocks.registerBlockType( 'uil/identity-labels', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'uil-block-placeholder' } );

			return el(
				element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( '身份标签设置', 'user-identity-labels' ), initialOpen: true },
						el( TextControl, {
							label: __( '指定用户 ID（0 表示当前文章作者）', 'user-identity-labels' ),
							type: 'number',
							min: 0,
							value: attributes.userId || 0,
							onChange: function ( value ) {
								setAttributes( { userId: Math.max( 0, parseInt( value, 10 ) || 0 ) } );
							}
						} ),
						el( ToggleControl, {
							label: __( '同时显示数字用户 ID', 'user-identity-labels' ),
							checked: !! attributes.showId,
							onChange: function ( value ) {
								setAttributes( { showId: !! value } );
							}
						} ),
						el( TextControl, {
							label: __( '无标签时显示的文字（可留空）', 'user-identity-labels' ),
							value: attributes.emptyText || '',
							onChange: function ( value ) {
								setAttributes( { emptyText: value } );
							}
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'span', { className: 'dashicons dashicons-id-alt', 'aria-hidden': 'true' } ),
					el( 'strong', null, __( '用户身份标签', 'user-identity-labels' ) ),
					el( 'small', null, __( '前台会显示当前文章作者或指定用户的标签。', 'user-identity-labels' ) )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor, window.wp.i18n ) );
