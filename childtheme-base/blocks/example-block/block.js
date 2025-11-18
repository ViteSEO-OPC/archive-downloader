(function(blocks, element, editor, components, i18n, data) {
    const { registerBlockType } = blocks;
    const { createElement: el, Fragment, useState, useEffect } = element;
    const { InspectorControls, useBlockProps, ColorPalette } = editor;
    const { PanelBody, TextControl, Button, SelectControl, RangeControl, ToggleControl } = components;
    const { __ } = i18n;
    const { useSelect } = data;

    // ============================================================
    // GUTENBERG BLOCK BLUEPRINT — BASE REUSABLE TEMPLATE
    // ============================================================

    registerBlockType('namespace/block-name', {
        title: __('Block Blueprint'),
        icon: 'admin-generic',
        category: 'widgets',
        description: __('Reusable base structure for custom Gutenberg blocks.'),
        keywords: ['boilerplate', 'template', 'blueprint'],

        // ------------------------------
        // ATTRIBUTE DEFINITIONS
        // ------------------------------
        attributes: {
            // Define basic attributes; expand as needed per project.
            // You can also dynamically map these via external config.
            exampleSetting: { type: 'string', default: 'default-value' },
            exampleToggle: { type: 'boolean', default: false },
            exampleColor: { type: 'string', default: '#5650ff' },
            exampleNumber: { type: 'number', default: 10 },
            alignment: { type: 'string', default: 'left' }
        },

        // ------------------------------
        // EDIT FUNCTION — EDITOR UI
        // ------------------------------
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const {
                exampleSetting,
                exampleToggle,
                exampleColor,
                exampleNumber,
                alignment
            } = attributes;

            const blockProps = useBlockProps({ className: 'module-block-editor' });

            // Helper: update attributes quickly
            function updateAttribute(key, value) {
                setAttributes({ [key]: value });
            }

            // Optional: Fetch dynamic data (e.g., categories)
            // const categories = useSelect(select => 
            //     select('core').getEntityRecords('taxonomy', 'category', { per_page: -1 })
            // , []);

            return el(Fragment, {},

                // === INSPECTOR CONTROLS (Sidebar) ===
                el(InspectorControls, {},

                    el(PanelBody, { title: __('General Settings'), initialOpen: true },
                        el(TextControl, {
                            label: __('Example Setting'),
                            value: exampleSetting,
                            onChange: (value) => updateAttribute('exampleSetting', value)
                        }),
                        el(ToggleControl, {
                            label: __('Example Toggle'),
                            checked: exampleToggle,
                            onChange: (value) => updateAttribute('exampleToggle', value)
                        }),
                        el(RangeControl, {
                            label: __('Example Number'),
                            value: exampleNumber,
                            onChange: (value) => updateAttribute('exampleNumber', value),
                            min: 0, max: 100
                        })
                    ),

                    el(PanelBody, { title: __('Appearance'), initialOpen: false },
                        el('h4', {}, __('Colors')),
                        el(ColorPalette, {
                            label: __('Primary Color'),
                            value: exampleColor,
                            onChange: (value) => updateAttribute('exampleColor', value || '#5650ff')
                        }),
                        el('h4', { style: { marginTop: '20px' } }, __('Alignment')),
                        el(SelectControl, {
                            label: __('Alignment'),
                            value: alignment,
                            options: [
                                { label: __('Left'), value: 'left' },
                                { label: __('Center'), value: 'center' },
                                { label: __('Right'), value: 'right' }
                            ],
                            onChange: (value) => updateAttribute('alignment', value)
                        })
                    )
                ),

                // === EDITOR PREVIEW AREA ===
                el('div', blockProps,
                    el('div', {
                        className: 'module-block-preview',
                        style: {
                            textAlign: alignment,
                            backgroundColor: exampleColor,
                            padding: '20px',
                            borderRadius: '8px',
                            color: '#fff'
                        }
                    },
                        el('h3', {}, __('Editable Block Preview')),
                        el('p', {}, __('This is a preview area. Customize this structure as needed.')),
                        el('p', {}, __('Example setting: ') + exampleSetting),
                        el('p', {}, __('Example number: ') + exampleNumber),
                        el('p', {}, exampleToggle ? __('Toggle is ON') : __('Toggle is OFF'))
                    )
                )
            );
        },

        // ------------------------------
        // SAVE FUNCTION — FRONTEND OUTPUT
        // ------------------------------
        save: function(props) {
            const { attributes } = props;
            const {
                exampleSetting,
                exampleToggle,
                exampleColor,
                exampleNumber,
                alignment
            } = attributes;

            const blockProps = useBlockProps.save({ className: 'module-block-frontend' });

            // NOTE:
            // Keep markup minimal; dynamic rendering can be handled via PHP or JS front-end logic.
            // This markup represents the structure passed to the front-end runtime.

            return el('div', blockProps,
                el('div', {
                    className: 'module-block-output',
                    style: {
                        textAlign: alignment,
                        backgroundColor: exampleColor,
                        padding: '20px',
                        borderRadius: '8px',
                        color: '#fff'
                    }
                },
                    el('h3', {}, __('Rendered Block Output')),
                    el('p', {}, __('Example Setting: ') + exampleSetting),
                    el('p', {}, __('Example Number: ') + exampleNumber),
                    el('p', {}, exampleToggle ? __('Toggle Enabled') : __('Toggle Disabled'))
                )
            );
        }
    });

})(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.i18n,
    window.wp.data
);
