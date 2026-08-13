import Quill from 'quill';

let BlockEmbed = Quill.import('blots/block/embed');

class WinkImageBlot extends BlockEmbed {
    static create(value) {
        let node = super.create();
        let img = document.createElement('img');

        node.setAttribute('contenteditable', false);
        node.dataset.layout = value.layout;

        // Alt text is stored independently of the visible caption. When no
        // dedicated alt text is provided we fall back to the caption so existing
        // posts and quick uploads still get a meaningful alt attribute.
        let alt = (value.alt !== undefined && value.alt !== null && value.alt !== '')
            ? value.alt
            : (value.caption || '');

        img.setAttribute('alt', alt);
        img.setAttribute('src', value.url);
        node.appendChild(img);

        if (value.caption) {
            let caption = document.createElement('p');
            caption.innerHTML = value.caption;
            node.appendChild(caption);
        }

        return node;
    }

    static value(node) {
        let img = node.querySelector('img');
        let caption = node.querySelector('p');

        return {
            layout: node.dataset.layout,
            // The visible caption comes from the appended <p>, not the alt
            // attribute, so the two fields stay independent when editing.
            caption: caption ? caption.innerHTML : '',
            alt: img.getAttribute('alt') || '',
            url: img.getAttribute('src')
        };
    }
}

WinkImageBlot.tagName = 'div';
WinkImageBlot.blotName = 'captioned-image';
WinkImageBlot.className = 'embedded_image';

export default WinkImageBlot;
