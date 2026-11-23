( function () {
	const registerBlockType = wp.blocks.registerBlockType;
	const el = wp.element.createElement;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const useBlockProps = wp.blockEditor.useBlockProps;
	const PanelBody = wp.components.PanelBody;
	const TextControl = wp.components.TextControl;
	const ToggleControl = wp.components.ToggleControl;
	const __ = wp.i18n.__;

	registerBlockType( 'modern-hotel-booking/room-calendar', {
		title: __( 'Hotel: Room Calendar', 'modern-hotel-booking' ),
		icon: 'calendar-alt',
		category: 'hotel-booking',
		transforms: {
			from: [
				{
					type: 'shortcode',
					tag: 'mhbo_room_calendar',
					attributes: {
						roomId: {
							type: 'number',
							shortcode( attributes ) {
								return attributes.named.room_id
									? parseInt( attributes.named.room_id, 10 )
									: 0;
							},
						},
					},
				},
			],
		},
		attributes: {
			roomId: {
				type: 'number',
				default: 0,
			},
			showTitle: {
				type: 'boolean',
				default: true,
			},
			showBadge: {
				type: 'boolean',
				default: true,
			},
		},
		edit( props ) {
			const attributes = props.attributes;
			const blockProps = useBlockProps( {
				className: 'mhbo-block-calendar-preview',
			} );

			function onChangeRoomId( newId ) {
				props.setAttributes( { roomId: parseInt( newId ) || 0 } );
			}

			return [
				el(
					InspectorControls,
					{ key: 'controls' },
					el(
						PanelBody,
						{
							title: __(
								'Calendar Settings',
								'modern-hotel-booking'
							),
							initialOpen: true,
						},
						el( TextControl, {
							label: __( 'Room ID', 'modern-hotel-booking' ),
							value: attributes.roomId,
							onChange: onChangeRoomId,
							help: __(
								'Enter the ID of the room to display the calendar for.',
								'modern-hotel-booking'
							),
						} ),
						el( ToggleControl, {
							label: __(
								'Show Room Title',
								'modern-hotel-booking'
							),
							checked: attributes.showTitle !== false,
							onChange( val ) {
								props.setAttributes( { showTitle: val } );
							},
						} ),
						el( ToggleControl, {
							label: __(
								'Show Room Badge',
								'modern-hotel-booking'
							),
							checked: attributes.showBadge !== false,
							onChange( val ) {
								props.setAttributes( { showBadge: val } );
							},
						} )
					)
				),
				el(
					'div',
					Object.assign( { key: 'preview' }, blockProps ),
					el(
						'div',
						{ className: 'mhbo-preview-header' },
						el( 'div', { className: 'mhbo-preview-month' }, '— —' ),
						el( 'div', { className: 'mhbo-preview-nav' }, '‹ ›' )
					),
					el(
						'div',
						{ className: 'mhbo-preview-grid' },
						// Render 7 empty days as a mock
						[ 1, 2, 3, 4, 5, 6, 7 ].map( function ( d ) {
							return el(
								'div',
								{
									className:
										'mhbo-preview-day' +
										( d === 3 ? ' is-selected' : '' ),
								},
								el( 'span', { className: 'mhbo-day-num' }, d ),
								el(
									'span',
									{ className: 'mhbo-day-price' },
									'$199'
								)
							);
						} )
					),
					el(
						'div',
						{ className: 'mhbo-preview-overlay' },
						el( 'div', {
							className:
								'mhbo-block-icon dashicons dashicons-calendar-alt',
						} ),
						el(
							'p',
							{},
							__(
								'Room Availability Calendar',
								'modern-hotel-booking'
							)
						),
						el(
							'p',
							{ className: 'mhbo-preview-room-id' },
							attributes.roomId > 0
								? wp.i18n.sprintf(
										__(
											'Room ID: %d',
											'modern-hotel-booking'
										),
										attributes.roomId
								  )
								: __(
										'Select Room ID in Sidebar',
										'modern-hotel-booking'
								  )
						)
					)
				),
			];
		},
		save() {
			return null; // Rendered via PHP
		},
	} );
} )();
