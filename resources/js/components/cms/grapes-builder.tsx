import { useEffect, useRef } from 'react';
import grapesjs, { type Editor } from 'grapesjs';
import 'grapesjs/dist/css/grapes.min.css';
import gjsBlocksBasic from 'grapesjs-blocks-basic';
import gjsPresetWebpage from 'grapesjs-preset-webpage';

/**
 * GrapesJS page builder (open-source). A full drag-and-drop editor with a left
 * blocks panel, right style manager (background, border, spacing, typography),
 * layer tree and desktop/tablet/mobile device preview. It owns its own DOM, so
 * React never interferes. The parent grabs the editor via `onEditor` and reads
 * `editor.getHtml()` / `editor.getCss()` on save.
 */
export function GrapesBuilder({ initialHtml, initialCss, onEditor }: { initialHtml: string; initialCss: string; onEditor?: (e: Editor) => void }) {
    'use no memo';

    const hostRef = useRef<HTMLDivElement>(null);
    const editorRef = useRef<Editor | null>(null);

    useEffect(() => {
        if (!hostRef.current || editorRef.current) return;

        const editor = grapesjs.init({
            container: hostRef.current,
            height: '100%',
            width: 'auto',
            fromElement: false,
            storageManager: false,
            plugins: [gjsBlocksBasic, gjsPresetWebpage],
            pluginsOpts: {
                'grapesjs-blocks-basic': { flexGrid: true },
                'grapesjs-preset-webpage': { modalImportTitle: 'Import' },
            },
            deviceManager: {
                devices: [
                    { id: 'desktop', name: 'Desktop', width: '' },
                    { id: 'tablet', name: 'Tablet', width: '768px', widthMedia: '992px' },
                    { id: 'mobile', name: 'Mobile', width: '390px', widthMedia: '575px' },
                ],
            },
        });

        editor.setComponents(initialHtml || '<section style="padding:64px 24px;text-align:center"><h1>Start building</h1><p>Drag blocks from the left panel, then style them on the right.</p></section>');
        if (initialCss) editor.setStyle(initialCss);

        editorRef.current = editor;
        onEditor?.(editor);

        return () => { try { editor.destroy(); } catch { /* noop */ } editorRef.current = null; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return <div ref={hostRef} className="h-full" />;
}
