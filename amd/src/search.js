// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * RAGflow semantic-search widget (used by the search block).
 *
 * @module     aiprovider_ragflow/search
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {getString} from 'core/str';

/**
 * Render the result list into the container.
 *
 * @param {HTMLElement} container
 * @param {Object} data The web-service response ({results: [...]}).
 * @return {Promise}
 */
/**
 * Render a single result row and append it to the container.
 *
 * @param {HTMLElement} container
 * @param {Object} r A single result from the web-service response.
 * @return {Promise}
 */
const renderOne = (container, r) => Templates.renderForPromise('aiprovider_ragflow/searchresult', {
    name: r.name,
    icon: fileIcon(r.name),
    snippet: r.snippet,
    hassnippet: !!(r.snippet && String(r.snippet).trim()),
    similaritypct: Math.round((r.similarity || 0) * 100),
    url: safeUrl(r.url),
    hasurl: !!safeUrl(r.url),
    dataset: r.dataset || '',
    document: r.document || '',
    hasdoc: !!(r.dataset && r.document),
}).then(({html}) => {
    container.insertAdjacentHTML('beforeend', html);
    return true;
});

const renderResults = (container, data) => {
    container.innerHTML = '';
    if (!data.results.length) {
        // A server-supplied notice (e.g. the knowledge base no longer exists) explains the empty result;
        // otherwise fall back to the generic "no matches" string.
        if (data.notice) {
            container.textContent = data.notice;
            return Promise.resolve(true);
        }
        return getString('searchnoresults', 'aiprovider_ragflow').then((s) => {
            container.textContent = s;
            return true;
        });
    }
    // One combined list of all matches - documents and media together - ordered by match score, so the
    // result panel is a single, clear ranking of matches.
    const all = data.results.slice().sort((a, b) => (b.similarity || 0) - (a.similarity || 0));
    return all.reduce((c, r) => c.then(() => renderOne(container, r)), Promise.resolve());
};

// Only http(s) or root-relative URLs are safe to place in an href (Moodle's clean_param(PARAM_URL) already
// strips others server-side); anything else is dropped.
const safeUrl = (url) => (/^(https?:\/\/|\/)/i).test(url || '') ? url : '';

// Map a document's file extension to a Font Awesome icon class, for a quick visual scan of the result list.
const fileIcon = (name) => {
    const ext = (String(name || '').split('.').pop() || '').toLowerCase();
    const map = {
        pdf: 'fa-file-pdf',
        doc: 'fa-file-word', docx: 'fa-file-word', odt: 'fa-file-word', rtf: 'fa-file-word',
        xls: 'fa-file-excel', xlsx: 'fa-file-excel', ods: 'fa-file-excel', csv: 'fa-file-csv',
        ppt: 'fa-file-powerpoint', pptx: 'fa-file-powerpoint', odp: 'fa-file-powerpoint',
        jpg: 'fa-file-image', jpeg: 'fa-file-image', png: 'fa-file-image', gif: 'fa-file-image',
        webp: 'fa-file-image', bmp: 'fa-file-image', svg: 'fa-file-image', tif: 'fa-file-image',
        tiff: 'fa-file-image', heic: 'fa-file-image', heif: 'fa-file-image',
        mp4: 'fa-file-video', mov: 'fa-file-video', avi: 'fa-file-video', webm: 'fa-file-video',
        mp3: 'fa-file-audio', wav: 'fa-file-audio', ogg: 'fa-file-audio',
        zip: 'fa-file-zipper', rar: 'fa-file-zipper',
        txt: 'fa-file-lines', md: 'fa-file-lines', html: 'fa-file-lines', htm: 'fa-file-lines',
    };
    return map[ext] || 'fa-file';
};

/**
 * Initialise a search widget.
 *
 * @param {String} uid The root element id.
 * @param {Number} contextid The page context id.
 * @param {Number} blockinstanceid The search block instance id (drives the knowledge base + scope).
 */
export const init = (uid, contextid, blockinstanceid) => {
    const root = document.getElementById(uid);
    if (!root || root.dataset.initialised) {
        return;
    }
    root.dataset.initialised = '1';

    const form = root.querySelector('[data-region="form"]');
    const input = root.querySelector('[data-region="input"]');
    const results = root.querySelector('[data-region="results"]');
    if (!form) {
        return;
    }

    // Proxy source downloads are minted on click (token lives only seconds), so no signed URL sits in the
    // rendered results. A popup opened synchronously (before the async call) avoids the browser blocking it.
    results.addEventListener('click', (e) => {
        const link = e.target.closest('[data-download]');
        if (!link) {
            return;
        }
        e.preventDefault();
        const win = window.open('', '_blank');
        Ajax.call([{
            methodname: 'aiprovider_ragflow_download_url',
            args: {
                contextid,
                blockinstanceid,
                dataset: link.getAttribute('data-dataset') || '',
                document: link.getAttribute('data-document') || '',
            },
        }])[0].then((res) => {
            const url = safeUrl(res.url);
            if (url) {
                if (win) {
                    win.location = url;
                } else {
                    window.location = url;
                }
            } else if (win) {
                win.close();
            }
            return url;
        }).catch(() => {
            if (win) {
                win.close();
            }
        });
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const query = (input.value || '').trim();
        if (!query) {
            return;
        }
        getString('searching', 'aiprovider_ragflow').then((s) => {
            results.textContent = s;
            return true;
        }).catch(() => null);
        const request = Ajax.call([{
            methodname: 'aiprovider_ragflow_search',
            args: {contextid, query, blockinstanceid},
        }])[0];
        request.then((data) => renderResults(results, data)).catch((error) => {
            results.textContent = error.message || String(error);
        });
    });
};
