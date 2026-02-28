(function () {
    var registerBlockType = wp.blocks.registerBlockType;
    var el = wp.element.createElement;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var __ = wp.i18n.__;

    registerBlockType('modern-hotel-booking/room-calendar', {
        title: __('Room Availability Calendar', 'modern-hotel-booking'),
        icon: 'calendar-alt',
        category: 'theme',
        attributes: {
            roomId: {
                type: 'number',
                default: 0
            }
        },
        edit: function (props) {
            var attributes = props.attributes;

            function onChangeRoomId(newId) {
                props.setAttributes({ roomId: parseInt(newId) || 0 });
            }

            return [
                el(InspectorControls, { key: 'controls' },
                    el(PanelBody, { title: __('Calendar Settings', 'modern-hotel-booking'), initialOpen: true },
                        el(TextControl, {
                            label: __('Room ID', 'modern-hotel-booking'),
                            value: attributes.roomId,
                            onChange: onChangeRoomId,
                            help: __('Enter the ID of the room to display the calendar for.', 'modern-hotel-booking')
                        })
                    )
                ),
                el('div', { key: 'preview', className: 'wp-block-modern-hotel-booking-room-calendar mhb-block-preview' },
                    el('div', { className: 'mhb-block-icon dashicons dashicons-calendar-alt' }),
                    el('p', {}, __('Room Availability Calendar', 'modern-hotel-booking')),
                    el('p', { style: { fontSize: '13px', color: '#666' } },
                        attributes.roomId > 0
                            ? __('Room ID: ', 'modern-hotel-booking') + attributes.roomId
                            : __('Please select a Room ID in the sidebar.', 'modern-hotel-booking')
                    ),
                    el('small', {}, __('(Preview only - real calendar shows on frontend)', 'modern-hotel-booking'))
                )
            ];
        },
        save: function () {
            return null; // Rendered via PHP
        },
    });
})();
