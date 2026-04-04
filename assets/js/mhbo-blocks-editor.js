/**
 * MHBO Business Blocks – Gutenberg Editor
 *
 * Handles edit() UI for dynamic blocks.
 *
 * @package ModernHotelBooking
 * @since   2.1.0
 */

( function () {
    'use strict';

    var registerBlockType = wp.blocks.registerBlockType;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody         = wp.components.PanelBody;
    var ToggleControl     = wp.components.ToggleControl;
    var SelectControl     = wp.components.SelectControl;
    var TextControl       = wp.components.TextControl;
    var el                = wp.element.createElement;
    var __                = wp.i18n.__;
    var ServerSideRender  = wp.serverSideRender;

    /* ── Company Info ─────────────────────────────────────────── */

    registerBlockType( 'mhbo/company-info', {
        title: __( 'Company Info (Hotel)', 'modern-hotel-booking' ),
        icon: 'building',
        category: 'mhbo-hotel',
        attributes: {
            showLogo: { type: 'boolean', default: true },
            showAddress: { type: 'boolean', default: true },
            showContact: { type: 'boolean', default: true },
            showRegistration: { type: 'boolean', default: false },
            layout: { type: 'string', default: 'vertical' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Display Settings', 'modern-hotel-booking' ) },
                        el( ToggleControl, {
                            label: __( 'Show Logo', 'modern-hotel-booking' ),
                            checked: props.attributes.showLogo,
                            onChange: function ( val ) { props.setAttributes( { showLogo: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: __( 'Show Address', 'modern-hotel-booking' ),
                            checked: props.attributes.showAddress,
                            onChange: function ( val ) { props.setAttributes( { showAddress: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: __( 'Show Contact Details', 'modern-hotel-booking' ),
                            checked: props.attributes.showContact,
                            onChange: function ( val ) { props.setAttributes( { showContact: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: __( 'Show Registration Info', 'modern-hotel-booking' ),
                            checked: props.attributes.showRegistration,
                            onChange: function ( val ) { props.setAttributes( { showRegistration: val } ); }
                        } ),
                        el( SelectControl, {
                            label: __( 'Layout', 'modern-hotel-booking' ),
                            value: props.attributes.layout,
                            options: [
                                { label: __( 'Vertical', 'modern-hotel-booking' ), value: 'vertical' },
                                { label: __( 'Horizontal', 'modern-hotel-booking' ), value: 'horizontal' },
                            ],
                            onChange: function ( val ) { props.setAttributes( { layout: val } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block: 'mhbo/company-info',
                    attributes: props.attributes,
                    key: 'preview'
                } )
            ];
        },
        save: function () { return null; }
    } );

    /* ── WhatsApp Button ───────────────────────────────────────── */

    registerBlockType( 'mhbo/whatsapp-button', {
        title: __( 'WhatsApp Button (Hotel)', 'modern-hotel-booking' ),
        icon: 'phone',
        category: 'mhbo-hotel',
        attributes: {
            style: { type: 'string', default: 'button' },
            text: { type: 'string', default: '' },
            message: { type: 'string', default: '' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Button Settings', 'modern-hotel-booking' ) },
                        el( SelectControl, {
                            label: __( 'Style', 'modern-hotel-booking' ),
                            value: props.attributes.style,
                            options: [
                                { label: __( 'Inline Button', 'modern-hotel-booking' ), value: 'button' },
                                { label: __( 'Floating Button', 'modern-hotel-booking' ), value: 'floating' },
                                { label: __( 'Text Link', 'modern-hotel-booking' ), value: 'link' },
                            ],
                            onChange: function ( val ) { props.setAttributes( { style: val } ); }
                        } ),
                        el( TextControl, {
                            label: __( 'Override Button Text', 'modern-hotel-booking' ),
                            value: props.attributes.text,
                            onChange: function ( val ) { props.setAttributes( { text: val } ); }
                        } ),
                        el( TextControl, {
                            label: __( 'Pre-filled Message', 'modern-hotel-booking' ),
                            value: props.attributes.message,
                            onChange: function ( val ) { props.setAttributes( { message: val } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block: 'mhbo/whatsapp-button',
                    attributes: props.attributes,
                    key: 'preview'
                } )
            ];
        },
        save: function () { return null; }
    } );

    /* ── Banking Details ───────────────────────────────────────── */

    registerBlockType( 'mhbo/banking-details', {
        title: __( 'Banking Details (Hotel)', 'modern-hotel-booking' ),
        icon: 'money-alt',
        category: 'mhbo-hotel',
        attributes: {
            showInstructions: { type: 'boolean', default: true },
            layout: { type: 'string', default: 'card' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Display Settings', 'modern-hotel-booking' ) },
                        el( ToggleControl, {
                            label: __( 'Show Instructions', 'modern-hotel-booking' ),
                            checked: props.attributes.showInstructions,
                            onChange: function ( val ) { props.setAttributes( { showInstructions: val } ); }
                        } ),
                        el( SelectControl, {
                            label: __( 'Layout', 'modern-hotel-booking' ),
                            value: props.attributes.layout,
                            options: [
                                { label: __( 'Card', 'modern-hotel-booking' ), value: 'card' },
                                { label: __( 'Inline', 'modern-hotel-booking' ), value: 'inline' },
                            ],
                            onChange: function ( val ) { props.setAttributes( { layout: val } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block: 'mhbo/banking-details',
                    attributes: props.attributes,
                    key: 'preview'
                } )
            ];
        },
        save: function () { return null; }
    } );

    /* ── Revolut Details ───────────────────────────────────────── */

    registerBlockType( 'mhbo/revolut-details', {
        title: __( 'Revolut Details (Hotel)', 'modern-hotel-booking' ),
        icon: 'money',
        category: 'mhbo-hotel',
        attributes: {
            showQR: { type: 'boolean', default: true },
            showLink: { type: 'boolean', default: true },
            layout: { type: 'string', default: 'card' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Display Settings', 'modern-hotel-booking' ) },
                        el( ToggleControl, {
                            label: __( 'Show QR Code', 'modern-hotel-booking' ),
                            checked: props.attributes.showQR,
                            onChange: function ( val ) { props.setAttributes( { showQR: val } ); }
                        } ),
                        el( ToggleControl, {
                            label: __( 'Show Payment Link', 'modern-hotel-booking' ),
                            checked: props.attributes.showLink,
                            onChange: function ( val ) { props.setAttributes( { showLink: val } ); }
                        } ),
                        el( SelectControl, {
                            label: __( 'Layout', 'modern-hotel-booking' ),
                            value: props.attributes.layout,
                            options: [
                                { label: __( 'Card', 'modern-hotel-booking' ), value: 'card' },
                                { label: __( 'Inline', 'modern-hotel-booking' ), value: 'inline' },
                            ],
                            onChange: function ( val ) { props.setAttributes( { layout: val } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block: 'mhbo/revolut-details',
                    attributes: props.attributes,
                    key: 'preview'
                } )
            ];
        },
        save: function () { return null; }
    } );

    /* ── Business Card ─────────────────────────────────────────── */

    registerBlockType( 'mhbo/business-card', {
        title: __( 'Business Card (Hotel)', 'modern-hotel-booking' ),
        icon: 'id',
        category: 'mhbo-hotel',
        attributes: {
            sections: { type: 'string', default: 'company,whatsapp,banking,revolut' },
        },
        edit: function ( props ) {
            return [
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, { title: __( 'Card Settings', 'modern-hotel-booking' ) },
                        el( TextControl, {
                            label: __( 'Visible Sections (comma-separated)', 'modern-hotel-booking' ),
                            value: props.attributes.sections,
                            placeholder: 'company,whatsapp,banking,revolut',
                            onChange: function ( val ) { props.setAttributes( { sections: val } ); }
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block: 'mhbo/business-card',
                    attributes: props.attributes,
                    key: 'preview'
                } )
            ];
        },
        save: function () { return null; }
    } );

} )( window.wp );
