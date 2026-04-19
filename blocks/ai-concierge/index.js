/**
 * AI Concierge block — editor script.
 *
 * @package modern-hotel-booking
 * @since   2.4.0
 */

/* global wp */

const { registerBlockType } = wp.blocks;
const { InspectorControls, useBlockProps } = wp.blockEditor;
const { PanelBody, SelectControl, TextControl } = wp.components;
const { __ } = wp.i18n;

registerBlockType('modern-hotel-booking/ai-concierge', {
    edit({ attributes, setAttributes }) {
        const { variant, position, welcomeMessage, theme } = attributes;
        const blockProps = useBlockProps({
            className: 'mhbo-block-ai-concierge-editor',
        });

        return (
            wp.element.createElement('div', blockProps,
                wp.element.createElement(InspectorControls, null,
                    wp.element.createElement(PanelBody, {
                        title: __('AI Concierge Settings', 'modern-hotel-booking'),
                        initialOpen: true,
                    },
                        wp.element.createElement(SelectControl, {
                            label:    __('Widget Variant', 'modern-hotel-booking'),
                            value:    variant,
                            options:  [
                                { label: __('Floating (FAB button)', 'modern-hotel-booking'), value: 'floating' },
                                { label: __('Inline (embedded)', 'modern-hotel-booking'),    value: 'inline' },
                            ],
                            onChange: (val) => setAttributes({ variant: val }),
                        }),
                        wp.element.createElement(SelectControl, {
                            label:    __('Position', 'modern-hotel-booking'),
                            value:    position,
                            options:  [
                                { label: __('Bottom Right', 'modern-hotel-booking'), value: 'bottom-right' },
                                { label: __('Bottom Left',  'modern-hotel-booking'), value: 'bottom-left' },
                            ],
                            onChange: (val) => setAttributes({ position: val }),
                        }),
                        wp.element.createElement(TextControl, {
                            label:    __('Welcome Message', 'modern-hotel-booking'),
                            value:    welcomeMessage,
                            help:     __('Leave blank to use the global setting.', 'modern-hotel-booking'),
                            onChange: (val) => setAttributes({ welcomeMessage: val }),
                        }),
                    )
                ),
                // Editor preview placeholder.
                wp.element.createElement('div', {
                    style: {
                        background: '#f1f5f9',
                        border: '2px dashed #cbd5e1',
                        borderRadius: '8px',
                        padding: '24px',
                        textAlign: 'center',
                        color: '#475569',
                        fontFamily: 'system-ui, sans-serif',
                    }
                },
                    wp.element.createElement('div', { style: { fontSize: '32px', marginBottom: '8px' } }, '🤖'),
                    wp.element.createElement('strong', null, __('Hotel: AI Concierge', 'modern-hotel-booking')),
                    wp.element.createElement('p', { style: { margin: '4px 0 0', fontSize: '13px' } },
                        __('The chat widget will appear on the frontend.', 'modern-hotel-booking') +
                        ' ' + __('Variant', 'modern-hotel-booking') + ': ' + variant +
                        ' | ' + __('Position', 'modern-hotel-booking') + ': ' + position
                    )
                )
            )
        );
    },

    save({ attributes }) {
        const { variant, position, welcomeMessage, theme } = attributes;
        const blockProps = wp.blockEditor.useBlockProps.save();

        const dataAttrs = {
            'data-variant': variant,
            'data-position': position,
        };
        if (welcomeMessage) {
            dataAttrs['data-welcome-message'] = welcomeMessage;
        }
        if (theme) {
            dataAttrs['data-theme'] = theme;
        }

        return wp.element.createElement('div',
            { ...blockProps, ...dataAttrs, className: (blockProps.className || '') + ' mhbo-chat-widget' }
        );
    },
});
