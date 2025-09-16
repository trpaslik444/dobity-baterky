(function() {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, SelectControl, ToggleControl, TextControl } = wp.components;
    const { useSelect } = wp.data;

    registerBlockType('dobity-baterky/cpt-display', {
        title: __('Dobitý Baterky - Zobrazení záznamu', 'dobity-baterky'),
        description: __('Zobrazuje jednotlivý záznam z pluginu Dobitý Baterky', 'dobity-baterky'),
        category: 'widgets',
        icon: 'location',
        keywords: [
            __('dobitý baterky', 'dobity-baterky'),
            __('nabíjecí stanice', 'dobity-baterky'),
            __('rv místa', 'dobity-baterky'),
            __('poi', 'dobity-baterky'),
            __('mapa', 'dobity-baterky')
        ],
        supports: {
            html: false,
            align: ['wide', 'full']
        },
        attributes: {
            postType: {
                type: 'string',
                default: 'charging_location'
            },
            postId: {
                type: 'number',
                default: 0
            },
            showMap: {
                type: 'boolean',
                default: true
            },
            showConnectors: {
                type: 'boolean',
                default: true
            },
            showServices: {
                type: 'boolean',
                default: true
            },
            showOpeningHours: {
                type: 'boolean',
                default: true
            }
        },

        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { postType, postId, showMap, showConnectors, showServices, showOpeningHours } = attributes;

            // Získání seznamu dostupných postů
            const posts = useSelect(select => {
                return select('core').getEntityRecords('postType', postType, {
                    per_page: -1,
                    _fields: ['id', 'title']
                });
            }, [postType]);

            const blockProps = useBlockProps();

            // Kontrola, zda se má zobrazit specifický post
            const shouldShowSpecificPost = postId > 0;

            // Renderování preview
            let previewContent = '';
            if (shouldShowSpecificPost && posts) {
                const selectedPost = posts.find(post => post.id === postId);
                if (selectedPost) {
                    previewContent = `
                        <div style="padding: 20px; border: 2px dashed #ccc; background: #f9f9f9; text-align: center;">
                            <h3 style="margin: 0 0 10px 0; color: #333;">${selectedPost.title.rendered}</h3>
                            <p style="margin: 0; color: #666;">Typ: ${postType}</p>
                            <p style="margin: 10px 0 0 0; font-size: 12px; color: #999;">
                                Tento blok bude zobrazovat záznam "${selectedPost.title.rendered}" na frontendu.
                            </p>
                        </div>
                    `;
                }
            } else {
                previewContent = `
                    <div style="padding: 20px; border: 2px dashed #ccc; background: #f9f9f9; text-align: center;">
                        <h3 style="margin: 0 0 10px 0; color: #333;">Dobitý Baterky - Zobrazení záznamu</h3>
                        <p style="margin: 0; color: #666;">Typ: ${postType}</p>
                        <p style="margin: 10px 0 0 0; font-size: 12px; color: #999;">
                            ${postId > 0 ? 'Zobrazí se záznam s ID: ' + postId : 'Zobrazí se aktuální záznam ze stránky'}
                        </p>
                    </div>
                `;
            }

            return [
                <InspectorControls key="inspector">
                    <PanelBody title={__('Nastavení záznamu', 'dobity-baterky')}>
                        <SelectControl
                            label={__('Typ záznamu', 'dobity-baterky')}
                            value={postType}
                            options={[
                                { label: __('Nabíjecí lokality', 'dobity-baterky'), value: 'charging_location' },
                                { label: __('RV místa', 'dobity-baterky'), value: 'rv_spot' },
                                { label: __('Body zájmu (POI)', 'dobity-baterky'), value: 'poi' }
                            ]}
                            onChange={(value) => setAttributes({ postType: value, postId: 0 })}
                        />
                        
                        <TextControl
                            label={__('ID záznamu (0 = aktuální)', 'dobity-baterky')}
                            type="number"
                            value={postId}
                            onChange={(value) => setAttributes({ postId: parseInt(value) || 0 })}
                            help={__('Zadejte 0 pro zobrazení aktuálního záznamu ze stránky', 'dobity-baterky')}
                        />

                        {postType === 'charging_location' && (
                            <ToggleControl
                                label={__('Zobrazit konektory', 'dobity-baterky')}
                                checked={showConnectors}
                                onChange={(value) => setAttributes({ showConnectors: value })}
                            />
                        )}

                        {postType === 'rv_spot' && (
                            <ToggleControl
                                label={__('Zobrazit služby', 'dobity-baterky')}
                                checked={showServices}
                                onChange={(value) => setAttributes({ showServices: value })}
                            />
                        )}

                        {postType === 'poi' && (
                            <ToggleControl
                                label={__('Zobrazit otevírací dobu', 'dobity-baterky')}
                                checked={showOpeningHours}
                                onChange={(value) => setAttributes({ showOpeningHours: value })}
                            />
                        )}

                        <ToggleControl
                            label={__('Zobrazit mapu', 'dobity-baterky')}
                            checked={showMap}
                            onChange={(value) => setAttributes({ showMap: value })}
                        />
                    </PanelBody>
                </InspectorControls>,

                <div {...blockProps} key="content">
                    <div dangerouslySetInnerHTML={{ __html: previewContent }} />
                </div>
            ];
        },

        save: function() {
            // Blok se renderuje dynamicky na serveru
            return null;
        }
    });
})();
