import './bootstrap';
import { marked } from 'marked';

marked.setOptions({
    gfm: true,
    breaks: true,
});

window.markedParse = (text) => marked.parse(text || '');
