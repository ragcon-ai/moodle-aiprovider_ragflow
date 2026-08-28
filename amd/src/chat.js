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
 * Shared RAGflow chat drawer, used by every RAGflow placement (Tutor, Helpdesk).
 *
 * @module     aiprovider_ragflow/chat
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';
import Policy from 'core_ai/policy';
import PolicyModal from 'core_ai/policymodal';
import CustomEvents from 'core/custom_interaction_events';

// The same rotating status messages the TinyMCE AI placement shows while generating.
const loadingStrings = [
    {key: 'loading_processing', component: 'tiny_aiplacement'},
    {key: 'loading_generating', component: 'tiny_aiplacement'},
    {key: 'loading_applying', component: 'tiny_aiplacement'},
    {key: 'loading_almostdone', component: 'tiny_aiplacement'},
];

/**
 * Escape plain text for safe HTML insertion.
 *
 * @param {String} text The text to escape.
 * @return {String} The escaped HTML.
 */
const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

/**
 * Render minimal markdown (bold + line breaks) on top of already-safe HTML.
 *
 * SECURITY INVARIANT: every answer passed here has been sanitised server-side with clean_text(FORMAT_HTML)
 * — both the live path (external\chat_generate) and the history-restore path (chat_engine::history) — so it
 * contains only safe HTML. This function therefore intentionally does NOT escape its input (that would
 * double-escape and break the safe source anchors/formatting); it only adds bold/line-break markup. User
 * messages, which are NOT server-sanitised, are escaped separately via escapeHtml() at their call sites.
 *
 * @param {String} text The server-sanitised answer.
 * @return {String} HTML.
 */
const renderMarkdown = (text) => text
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n/g, '<br>');

/**
 * Rewrite the server's footnote sentinels [[n]] into per-answer references [<answer>.<n>] (e.g. [1.2]),
 * so citations stay unambiguous across the stacked, multi-answer Sources panel.
 *
 * @param {String} text The answer text containing [[n]] sentinels.
 * @param {Number} answerNo The 1-based number of this answer within the conversation.
 * @return {String} The answer text with [answerNo.n] references.
 */
const applyCiteNumbers = (text, answerNo) =>
    text.replace(/\[\[(\d+)\]\]/g, (match, n) => `[${answerNo}.${n}]`);

/** How many prior user questions to carry as lightweight context in 'usersonly' mode. */
const USER_CONTEXT_TURNS = 4;

/**
 * Fold the running transcript into the prompt (stateless chat has no server-side history).
 *
 * @param {Array} history Prior turns as {role, text}.
 * @param {String} question The new user message.
 * @param {String} mode 'none' (server keeps history in a RAGflow session – send only the question),
 *   'full' (fold the whole transcript) or 'usersonly' (fold only the last few *user* questions).
 * @return {String} The prompt to send.
 *
 * 'usersonly' is used by the block: RAGflow embeds the whole query with the knowledge base's embedding
 * model (often a small, e.g. 512-token, model) to run retrieval, so folding long assistant answers back
 * in overflows that limit and the request fails. Prior user questions are short and keep topical context
 * (e.g. "do we have a handout for that?") without blowing the embedding budget.
 */
const buildPrompt = (history, question, mode) => {
    if (mode === 'none' || !history.length) {
        return question;
    }
    if (mode === 'usersonly') {
        const priorqs = history
            .filter((m) => m.role === 'user')
            .slice(-USER_CONTEXT_TURNS)
            .map((m) => `- ${m.text}`);
        // Present the prior questions as context only, and tell the assistant to answer just the current
        // one — otherwise it treats each folded "User:" line as a new question and re-answers them.
        if (!priorqs.length) {
            return question;
        }
        const intro = 'Earlier questions in this conversation, for context only — do NOT answer them again:';
        const tail = 'Answer only this question:';
        return `${intro}\n${priorqs.join('\n')}\n\n${tail} ${question}`;
    }
    const prior = history.map((m) => `${m.role === 'user' ? 'User' : 'Assistant'}: ${m.text}`).join('\n');
    return `${prior}\nUser: ${question}`;
};

/**
 * Append a message bubble to the transcript.
 *
 * @param {HTMLElement} list The messages container.
 * @param {String} role 'user' | 'assistant' | 'error'.
 * @param {String} html The bubble inner HTML.
 * @return {Promise} Resolves when rendered.
 */
const appendMessage = (list, role, html) =>
    Templates.renderForPromise('aiprovider_ragflow/message', {role, html}).then(({html: node}) => {
        list.insertAdjacentHTML('beforeend', node);
        list.scrollTop = list.scrollHeight;
        return node;
    });

/**
 * Show a loading bubble that cycles the status strings every 6s.
 *
 * @param {HTMLElement} list The messages container.
 * @return {Object} A handle with stop().
 */
const startLoading = (list) => {
    const msg = document.createElement('div');
    msg.className = 'rfchat-msg rfchat-assistant rfchat-loading';
    const bubble = document.createElement('div');
    bubble.className = 'rfchat-bubble';
    const spinner = document.createElement('span');
    spinner.className = 'rfchat-spinner';
    const text = document.createElement('span');
    bubble.appendChild(spinner);
    bubble.appendChild(text);
    msg.appendChild(bubble);
    list.appendChild(msg);
    list.scrollTop = list.scrollHeight;

    let i = 0;
    const update = () => {
        const s = loadingStrings[i % loadingStrings.length];
        i++;
        getString(s.key, s.component).then((str) => {
            text.textContent = str;
            return str;
        }).catch(() => null);
    };
    update();
    const timer = setInterval(update, 6000);

    return {
        stop: () => {
            clearInterval(timer);
            if (msg.parentNode) {
                msg.parentNode.removeChild(msg);
            }
        },
    };
};

/**
 * Show a "restoring the last conversation" indicator with a spinner while the transcript loads.
 *
 * @param {HTMLElement} list The messages container.
 * @return {Object} A handle with stop().
 */
const startRestoring = (list) => {
    const msg = document.createElement('div');
    msg.className = 'rfchat-msg rfchat-assistant rfchat-loading rfchat-restoring';
    const bubble = document.createElement('div');
    bubble.className = 'rfchat-bubble';
    const spinner = document.createElement('span');
    spinner.className = 'rfchat-spinner';
    const text = document.createElement('span');
    bubble.appendChild(spinner);
    bubble.appendChild(text);
    msg.appendChild(bubble);
    list.appendChild(msg);
    getString('chatrestoring', 'aiprovider_ragflow').then((str) => {
        text.textContent = str;
        return str;
    }).catch(() => null);

    return {
        stop: () => {
            if (msg.parentNode) {
                msg.parentNode.removeChild(msg);
            }
        },
    };
};

/**
 * Show the core AI policy modal and resolve once the user accepts (the modal persists acceptance).
 *
 * @return {Promise} Resolves true when accepted.
 */
const awaitPolicyModal = () => PolicyModal.create().then((modal) => new Promise((resolve) => {
    modal.getModal().on(CustomEvents.events.activate, modal.getActionSelector('save'), () => {
        resolve(true);
    });
}));

/**
 * Ensure the core AI policy is accepted before the first request; shows the core policy modal and
 * resolves once accepted (the modal persists acceptance itself).
 *
 * @param {Number} userid The user id.
 * @return {Promise} Resolves when the policy is accepted.
 */
const ensurePolicy = (userid) => Policy.getPolicyStatus(userid).then((accepted) => {
    if (accepted) {
        return true;
    }
    return awaitPolicyModal();
});

/**
 * Initialise the chat drawer.
 *
 * @param {Number} contextid The page context id.
 * @param {Number} userid The current user id.
 * @param {String} component The calling placement component (for the capability check).
 * @param {Boolean} memory Whether server-side conversation memory (RAGflow session) is active.
 * @param {Boolean} longterm Whether long-term memory (per-user facts) is active.
 * @param {Number} blockinstanceid The chat block instance id (block path; 0 for the placement path).
 */
export const init = (contextid, userid, component, memory, longterm, blockinstanceid = 0) => {
    const drawer = document.getElementById('ragflowchat-drawer');
    if (!drawer || drawer.dataset.initialised) {
        return;
    }
    drawer.dataset.initialised = '1';

    const toggle = document.querySelector('[data-action="ragflowchat-toggle"]');

    // In floating-button mode (a placement/block, not the in-page panel), the drawer is position:fixed
    // and must float over the whole page. When rendered inside a block it would otherwise be trapped in
    // the right block drawer (a scrolling/stacking context). Relocate the button + drawer under `#page`
    // (a sibling of the block drawer, no transform → still viewport-fixed) so they escape that context
    // AND react to Moodle's `#page.show-drawer-right` state, shifting left when the block drawer opens
    // (see styles.css) just like Moodle's own footer-popover button. Fall back to <body> if `#page` is
    // absent. Hide the now-empty host block (except while editing, so the teacher can open its settings).
    if (toggle) {
        const hostBlock = drawer.closest('.block');
        const host = document.getElementById('page') || document.body;
        host.appendChild(toggle);
        host.appendChild(drawer);
        if (hostBlock && !document.body.classList.contains('editing')) {
            hostBlock.style.display = 'none';
        }
    }

    const closeBtn = drawer.querySelector('[data-action="ragflowchat-close"]');
    const resetBtn = drawer.querySelector('[data-action="ragflowchat-reset"]');
    const resetPrivateBtn = drawer.querySelector('[data-action="ragflowchat-reset-private"]');
    const forgetBtn = drawer.querySelector('[data-action="ragflowchat-forget"]');
    const form = drawer.querySelector('[data-region="ragflowchat-form"]');
    const input = drawer.querySelector('[data-region="ragflowchat-input"]');
    const submitBtn = drawer.querySelector('[data-region="ragflowchat-form"] button[type="submit"]');
    const list = drawer.querySelector('[data-region="ragflowchat-messages"]');
    const sourcesPanel = drawer.querySelector('[data-region="ragflowchat-sources"]');
    const sourcesList = drawer.querySelector('[data-region="ragflowchat-sources-list"]');
    const history = [];
    // The initial transcript (just the greeting, if any) so "New conversation" can restore it.
    const initialHtml = list.innerHTML;

    // Persistent "Sources" panel: each answer's documents form their own group, newest group on top, with
    // a stable light/dark zebra background per group so it is clear which files belong to which answer. It
    // grows as answers cite documents and becomes scrollable past a handful of entries (CSS max-height).
    let sourceGroupCount = 0;
    // 1-based counter of assistant answers in this conversation; drives the [<answer>.<n>] footnote and
    // source numbering (incremented for every rendered answer, live and on restore).
    let answerCount = 0;
    // Only allow safe http(s)/relative source URLs as a link target (defence in depth; the server already
    // cleans them). Anything else (e.g. a javascript: scheme) is rendered as plain text, not a link.
    const safeUrl = (url) => (/^(https?:\/\/|\/)/i).test(url || '') ? url : '';
    // Open a proxy source document: mint a short-lived signed URL on click, then navigate to it. A popup
    // opened synchronously (before the async call) avoids the browser blocking window.open in a callback.
    const openProxyDownload = (datasetid, documentid) => {
        const win = window.open('', '_blank');
        Ajax.call([{
            methodname: 'aiprovider_ragflow_download_url',
            args: {contextid, component, blockinstanceid, dataset: datasetid, document: documentid},
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
    };
    const addSources = (sources, answerNo) => {
        const group = document.createElement('ul');
        group.className = 'rfchat-source-group';
        const seen = new Set();
        (sources || []).forEach((s) => {
            const key = (s.kb || '') + '|' + (s.document || s.url || s.name);
            if ((!s.url && !s.name && !s.document) || seen.has(key)) {
                return;
            }
            seen.add(key);
            const li = document.createElement('li');
            // Footnote reference [<answer>.<n>] matching the markers in the answer text (e.g. [1.2]); the
            // block numbers its list too even when the model added no inline markers (fallback sources).
            if (s.number) {
                const num = document.createElement('span');
                num.className = 'rfchat-source-num';
                num.textContent = '[' + answerNo + '.' + s.number + '] ';
                li.appendChild(num);
            }
            // Prefix with the knowledge-base name so the origin is clear (esp. with several KBs).
            if (s.kb) {
                li.appendChild(document.createTextNode(s.kb + ': '));
            }
            const href = safeUrl(s.url);
            const label = s.name || s.url || s.document;
            if (s.dataset && s.document) {
                // Proxy document: the download URL is minted on click (token lives only seconds), so no
                // signed URL is embedded in the page.
                const a = document.createElement('a');
                a.href = '#';
                a.textContent = label;
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    openProxyDownload(s.dataset, s.document);
                });
                li.appendChild(a);
            } else if (href) {
                const a = document.createElement('a');
                a.href = href;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = label;
                li.appendChild(a);
            } else {
                li.appendChild(document.createTextNode(label));
            }
            group.appendChild(li);
        });
        if (!group.children.length) {
            return;
        }
        // Stable per-group shade (assigned at creation, so prepending later groups never re-colours it).
        if (sourceGroupCount % 2 === 1) {
            group.classList.add('rfchat-source-group--alt');
        }
        sourceGroupCount += 1;
        // Newest answer's sources on top.
        sourcesList.insertBefore(group, sourcesList.firstChild);
        if (sourcesPanel) {
            sourcesPanel.hidden = false;
        }
    };
    const clearSources = () => {
        sourceGroupCount = 0;
        sourcesList.innerHTML = '';
        if (sourcesPanel) {
            sourcesPanel.hidden = true;
        }
    };

    // Enable/disable the composer (used while restoring the transcript).
    const setComposerEnabled = (enabled) => {
        input.disabled = !enabled;
        if (submitBtn) {
            submitBtn.disabled = !enabled;
        }
    };

    // Replay a stored transcript into the list, one message at a time (sequential rendering).
    const replayMessages = (messages) => messages.reduce((chain, m) => chain.then(() => {
        history.push({role: m.role, text: m.content});
        // Keep the per-answer footnote numbering consistent with live sends (sources themselves are not
        // restored server-side, but the [<answer>.<n>] references in the text stay meaningful).
        const html = m.role === 'user'
            ? escapeHtml(m.content)
            : renderMarkdown(applyCiteNumbers(m.content, ++answerCount));
        return appendMessage(list, m.role, html);
    }), Promise.resolve());

    // In memory mode, restore the prior conversation (kept server-side in a RAGflow session) so the
    // user can resume it after leaving/reloading the page. Lock the composer with a spinner meanwhile,
    // so the user does not type into a conversation that is still loading.
    if (memory) {
        setComposerEnabled(false);
        const restoring = startRestoring(list);
        Ajax.call([{
            methodname: 'aiprovider_ragflow_chat_history',
            args: {contextid, component},
        }])[0].then((res) => replayMessages((res && res.messages) || [])).catch(() => null).then(() => {
            restoring.stop();
            setComposerEnabled(true);
            if (!drawer.hidden) {
                input.focus();
            }
            return true;
        }).catch(() => null);
    }

    const open = () => {
        drawer.hidden = false;
        input.focus();
    };
    const close = () => {
        drawer.hidden = true;
    };
    if (toggle) {
        toggle.addEventListener('click', () => (drawer.hidden ? open() : close()));
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', close);
    }
    if (!form) {
        return;
    }

    // Start a new conversation. isPrivate = incognito (nothing stored/recalled): first set the private
    // preference (server enforces it), then reset the RAGflow session and clear the transcript.
    const newConversation = (btn, isPrivate) => {
        btn.disabled = true;
        const calls = [];
        if (longterm) {
            calls.push({methodname: 'aiprovider_ragflow_private_set', args: {contextid, enabled: isPrivate ? 1 : 0}});
        }
        calls.push({methodname: 'aiprovider_ragflow_chat_reset', args: {contextid, component}});
        Promise.all(Ajax.call(calls)).then(() => {
            history.length = 0;
            answerCount = 0;
            list.innerHTML = initialHtml;
            clearSources();
            input.focus();
            return true;
        }).catch(() => null).then(() => {
            btn.disabled = false;
            return true;
        }).catch(() => null);
    };
    if (resetBtn) {
        resetBtn.addEventListener('click', () => newConversation(resetBtn, false));
    }
    if (resetPrivateBtn && longterm) {
        resetPrivateBtn.addEventListener('click', () => newConversation(resetPrivateBtn, true));
    }

    // Forget the user's long-term memory server-side; the server also ends the conversation, so clear
    // the transcript and confirm.
    const runForget = (doneMsg) => Ajax.call([{
        methodname: 'aiprovider_ragflow_memory_forget',
        args: {contextid, component},
    }])[0].then(() => {
        history.length = 0;
        answerCount = 0;
        list.innerHTML = initialHtml;
        return appendMessage(list, 'assistant', escapeHtml(doneMsg));
    }).then(() => {
        forgetBtn.disabled = false;
        return true;
    });

    // "Delete memories about me": forget the user's long-term memory (with a confirmation).
    if (forgetBtn && longterm) {
        forgetBtn.addEventListener('click', () => {
            getStrings([
                {key: 'chatforgetconfirm', component: 'aiprovider_ragflow'},
                {key: 'chatforgetdone', component: 'aiprovider_ragflow'},
            ]).then(([confirmMsg, doneMsg]) => {
                if (!window.confirm(confirmMsg)) { // eslint-disable-line no-alert
                    return false;
                }
                forgetBtn.disabled = true;
                return runForget(doneMsg);
            }).catch(() => {
                forgetBtn.disabled = false;
                return null;
            });
        });
    }

    // Error rendering with an optional collapsible "Details". The server only ever fills `errordetails`
    // for site admins (empty for everyone else – enforced server-side), so a non-admin simply never gets a
    // details toggle. The label is loaded lazily; a plain fallback is used until it resolves.
    let errorDetailsLabel = 'Details';
    getString('errordetails', 'aiprovider_ragflow').then((str) => {
        errorDetailsLabel = str;
        return str;
    }).catch(() => null);
    const renderError = (message, details) => {
        let html = escapeHtml(message || '');
        if (details) {
            html += '<details class="rfchat-errordetails"><summary>' + escapeHtml(errorDetailsLabel)
                + '</summary><pre class="rfchat-errordetails-body">' + escapeHtml(details) + '</pre></details>';
        }
        return html;
    };

    // Memory placements keep history server-side (send only the question); the block folds just recent
    // user questions (embedding-budget safe); other placements fold the whole transcript.
    let promptMode = 'full';
    if (memory) {
        promptMode = 'none';
    } else if (blockinstanceid > 0) {
        promptMode = 'usersonly';
    }
    const doSend = (question) => {
        input.value = '';
        input.disabled = true;
        const prompttext = buildPrompt(history, question, promptMode);
        history.push({role: 'user', text: question});

        // Call the generator and render the result (or error), stopping the loader either way.
        const generate = () => {
            const loader = startLoading(list);
            const request = Ajax.call([{
                methodname: 'aiprovider_ragflow_chat_generate',
                args: {contextid, prompttext, component, blockinstanceid, question},
            }])[0];
            return request.then((result) => {
                loader.stop();
                if (result.success) {
                    const answerNo = ++answerCount;
                    history.push({role: 'assistant', text: result.generatedcontent});
                    addSources(result.sources, answerNo);
                    return appendMessage(list, 'assistant',
                        renderMarkdown(applyCiteNumbers(result.generatedcontent, answerNo)));
                }
                return appendMessage(list, 'error', renderError(result.errormessage, result.errordetails));
            }).catch((error) => {
                loader.stop();
                return appendMessage(list, 'error', escapeHtml(error.message || String(error)));
            });
        };

        appendMessage(list, 'user', escapeHtml(question)).then(generate).then(() => {
            input.disabled = false;
            input.focus();
            return true;
        }).catch(() => null);
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const question = (input.value || '').trim();
        if (!question) {
            return;
        }
        ensurePolicy(userid).then(() => {
            doSend(question);
            return true;
        }).catch(() => null);
    });

    // Enter sends the message; Shift+Enter inserts a newline. Skip while an IME is composing so
    // confirming a composition with Enter does not send.
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', {cancelable: true}));
            }
        }
    });
};
