(function (blocks, element, blockEditor) {
    var el = element.createElement;
    var registerBlockType = blocks.registerBlockType;
    var InspectorControls = blockEditor.InspectorControls;
    var TextControl = wp.components.TextControl;
    var PanelBody = wp.components.PanelBody;

    registerBlockType('modern-hotel-booking/booking-form', {
        title: wp.i18n.__('Hotel Booking Form', 'modern-hotel-booking'),
        icon: 'building',
        category: 'theme',
        attributes: {
            roomId: {
                type: 'number',
                default: 0
            }
        },
        edit: function (props) {
            var attributes = props.attributes;

            function onChangeRoomId(newRoomId) {
                props.setAttributes({ roomId: parseInt(newRoomId, 10) || 0 });
            }

            return [
                el('div', { className: props.className + ' mhb-block-preview' },
                    el('div', { className: 'mhb-block-icon' }, el(wp.components.Dashicon, { icon: 'building' })),
                    el('p', {}, wp.i18n.__('Hotel Booking Form Preview', 'modern-hotel-booking')),
                    el('small', {}, 'Shortcode: [modern_hotel_booking' + (attributes.roomId ? ' room_id="' + attributes.roomId + '"' : '') + ']')
                ),
                el(InspectorControls, {},
                    el(PanelBody, { title: wp.i18n.__('Booking Settings', 'modern-hotel-booking'), initialOpen: true },
                        el(TextControl, {
                            label: wp.i18n.__('Room ID (optional)', 'modern-hotel-booking'),
                            value: attributes.roomId,
                            onChange: onChangeRoomId,
                            help: wp.i18n.__('Leave as 0 to show the general search form.', 'modern-hotel-booking')
                        })
                    )
                )
            ];
        },
        save: function () {
            return null; // Rendered via PHP
        },
    });
})(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor
);
