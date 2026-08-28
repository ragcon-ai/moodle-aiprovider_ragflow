<?php
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
 * English language strings for aiprovider_ragflow.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action:explain_text:chatid'] = 'RAGflow chat assistant';
$string['action:explain_text:chatid_help'] = 'The RAGflow chat assistant to use. Its own configured model and knowledge base(s) are used (RAGflow ignores any model sent in the request). Pick an assistant with a knowledge base for retrieval-augmented answers, or one without a knowledge base to use RAGflow as a plain LLM proxy. The document-source and citation options below only take effect when the assistant has a knowledge base.';
$string['action:explain_text:systeminstruction'] = 'System instruction';
$string['action:explain_text:systeminstruction_help'] = 'Instructions prepended as a system message to steer the response.';
$string['action:generate_text:chatid'] = 'RAGflow chat assistant';
$string['action:generate_text:chatid_help'] = 'The RAGflow chat assistant to use. Its own configured model and knowledge base(s) are used (RAGflow ignores any model sent in the request). Pick an assistant with a knowledge base for retrieval-augmented answers, or one without a knowledge base to use RAGflow as a plain LLM proxy. The document-source and citation options below only take effect when the assistant has a knowledge base.';
$string['action:generate_text:systeminstruction'] = 'System instruction';
$string['action:generate_text:systeminstruction_help'] = 'Instructions prepended as a system message to steer the response.';
$string['action:summarise_text:chatid'] = 'RAGflow chat assistant';
$string['action:summarise_text:chatid_help'] = 'The RAGflow chat assistant to use. Its own configured model and knowledge base(s) are used (RAGflow ignores any model sent in the request). Pick an assistant with a knowledge base for retrieval-augmented answers, or one without a knowledge base to use RAGflow as a plain LLM proxy. The document-source and citation options below only take effect when the assistant has a knowledge base.';
$string['action:summarise_text:systeminstruction'] = 'System instruction';
$string['action:summarise_text:systeminstruction_help'] = 'Instructions prepended as a system message to steer the response.';
$string['actionhint'] = 'Answers are retrieval-augmented: they come from the selected RAGflow chat assistant and are grounded in its knowledge base. The assistant\'s own model is used (a requested model is ignored).';
$string['apikey'] = 'RAGflow API key';
$string['apikey_help'] = 'The RAGflow API key. Generate it in RAGflow under User settings → API (path /user-setting/api). It is sent as the Bearer token to the OpenAI-compatible endpoint and is used to list the available chat assistants.';
$string['baseurl'] = 'RAGflow base URL';
$string['baseurl_help'] = 'The base URL of your RAGflow instance, e.g. https://ragflow.example.com.';
$string['chatforgetconfirm'] = 'Delete everything the assistant has remembered about you? This cannot be undone.';
$string['chatforgetdone'] = 'Your remembered information has been deleted.';
$string['chatforgetmemory'] = 'Delete all memories about me';
$string['chatforgetmemory_help'] = 'Permanently deletes all facts the AI has remembered about you and ends the current conversation. This cannot be undone.';
$string['chatkblabel'] = '{$a->name} — {$a->count} knowledge base(s)';
$string['chatnewconversation'] = 'New conversation';
$string['chatnewconversation_help'] = 'The AI will remember facts from the conversation so they are available again in future conversations.';
$string['chatnewprivate'] = 'New private conversation';
$string['chatnewprivate_help'] = 'No data is pulled from earlier memories and nothing new is stored.';
$string['chatnokblabel'] = '{$a} — no knowledge base (LLM proxy only)';
$string['chatplaceholder'] = 'Ask a question…';
$string['chatrestoring'] = 'Restoring our last conversation…';
$string['chatsend'] = 'Send';
$string['coursemetadatafield'] = 'Course metadata field';
$string['coursemetadatafield_help'] = 'The RAGflow document metadata field that holds the Moodle course id. Default: course_id.';
$string['coursescope'] = 'Restrict to course(s)';
$string['coursescope:current'] = 'Current course';
$string['coursescope:off'] = 'No restriction';
$string['coursescope:usercourses'] = 'The user\'s enrolled courses';
$string['coursescope_help'] = 'Restrict the RAGflow knowledge base to documents whose metadata field (below) matches a Moodle course id, sent as an extra_body metadata_condition. Only available when the documents originate from this Moodle (source "This Moodle") – course ids are only meaningful within this site, so they never match documents from another Moodle. Requires your documents to carry that metadata; if no course applies, no restriction is sent.';
$string['createkb:emptyname'] = 'Please enter a name for the new knowledge base.';
$string['createkb:nameexists'] = 'A knowledge base or assistant named "{$a}" already exists. Please choose a different name.';
$string['datasource'] = 'Document source';
$string['datasource:external'] = 'External Moodle via Moodle Connector';
$string['datasource:locked'] = 'The document source is set when the block is created and cannot be changed afterwards. To use a different source, remove this block and add a new one.';
$string['datasource:summary:external'] = 'Connected to a knowledge base; only external-sharing documents are used, via the Moodle Connector.';
$string['datasource:summary:thiscourse'] = 'Files are managed from this block (its own knowledge base; no metadata filter).';
$string['datasource:summary:thismoodle'] = 'Connected to a knowledge base; filtered to this course via the Moodle Connector.';
$string['datasource:summary:wholekb'] = 'Connected to a knowledge base; no metadata filter (the whole knowledge base is searched).';
$string['datasource:thiscourse'] = 'This block instance';
$string['datasource:thismoodle'] = 'This Moodle via Moodle Connector';
$string['datasource:wholekb'] = 'RAGflow knowledge base';
$string['datasource_help'] = 'Where the knowledge base\'s documents come from — this decides which metadata filter (if any) is applied to every search:

* **This block instance** — a knowledge base managed in Moodle from this block. Its documents are added/removed from the block, and the whole knowledge base is used (it *is* the scope, so **no metadata filter**). This is the only source that offers file management.
* **RAGflow knowledge base** — **no metadata filter**; the assistant\'s entire knowledge base is searched. Use this for a knowledge base that was not populated from Moodle (its documents have no \'course_id\' / \'external_sharing\' metadata) — otherwise every document would be filtered out and answers come back empty.
* **This Moodle via Moodle Connector** — a shared site knowledge base populated by RAGflow\'s built-in **Moodle connector**. Answers are **restricted to the current course** by document metadata (\'course_id\' + site URL). **Requires the connector** — without it no document carries this metadata, so the tutor always answers "no relevant content found".
* **External Moodle via Moodle Connector** — documents from a *different* Moodle, imported by the connector. Only documents explicitly shared (metadata \'external_sharing = 1\') are used; there is **no course scoping**, and each source links back to its own Moodle. **Requires the connector.**

How a source file is opened — a Moodle activity link or a secure RAGflow proxy — is the separate "Serve source files via RAGflow proxy" option.';
$string['error:downloaddenied'] = 'You are not allowed to download this document here.';
$string['error:kbmissing'] = 'This search is currently unavailable. Please ask your site administrator to reconnect its knowledge base.';
$string['error:kbmissing_detail'] = 'The configured RAGflow knowledge base no longer exists (id {$a}). Select a valid knowledge base in the block settings.';
$string['error:nochatid'] = 'No RAGflow chat assistant is configured for this action. Set it in the provider\'s action settings.';
$string['error:notconfigured'] = 'The RAGflow provider is not fully configured.';
$string['error:ratelimited'] = 'Too many requests. Please wait a moment and try again.';
$string['error:referencemissing'] = 'This assistant is currently unavailable. Please ask your site administrator to reconnect it.';
$string['error:referencemissing_detail'] = 'The configured RAGflow assistant no longer exists (id {$a}). Requests using it will fail — select a valid assistant in the settings.';
$string['error:seedpending'] = 'The knowledge base seed is not parsed yet; will retry.';
$string['error:tokenexpired'] = 'This download link has expired. Re-run the action to get a fresh one.';
$string['error:tokeninvalid'] = 'Invalid or unauthorised download link.';
$string['error:unexpectedresponse'] = 'Unexpected response from RAGflow.';
$string['errordetails'] = 'Details';
$string['event:chatcompleted'] = 'RAGflow chat completed';
$string['event:chatfailed'] = 'RAGflow chat failed';
$string['event:searchperformed'] = 'RAGflow search performed';
$string['extraparams'] = 'Extra parameters (JSON)';
$string['extraparams_help'] = 'Optional JSON object merged into the request body sent to RAGflow. Use extra_body for RAGflow-specific options, e.g. {"extra_body": {"reference": true}} to return source citations, or {"extra_body": {"metadata_condition": {"logic": "and", "conditions": [{"name": "course_id", "comparison_operator": "in", "value": ["1","2"]}]}}} to restrict the knowledge base by metadata. Note: the model and generation settings (temperature etc.) are governed by the chat assistant, so standard sampling parameters may be ignored.';
$string['helpdeskchatid'] = 'Helpdesk chat assistant';
$string['helpdeskchatid_help'] = 'Optional. A separate RAGflow chat assistant used when the request runs outside a real course (the site front page or, if enabled, site-wide) – e.g. an organisation-wide Helpdesk knowledge base. Leave empty to use the assistant above everywhere. No course scope is applied in this mode.';
$string['helpdesklongtermmemory'] = 'Helpdesk long-term memory';
$string['helpdesklongtermmemory_help'] = 'On top of remembering a single conversation, carry durable facts about the user (name, role, language, preferences, recurring goals) across conversations – so a new conversation still knows the user. This uses RAGflow\'s native Memory: after each answer the turn is stored (RAGflow extracts the facts) and relevant memories are retrieved into a fresh conversation. Requires "Helpdesk conversation memory" plus a RAGflow memory id and agent id below. Note: this keeps more personal data in RAGflow (see the privacy information); it is cleared with the user\'s data and on account deletion.';
$string['helpdeskmemory'] = 'Helpdesk conversation memory';
$string['helpdeskmemory_help'] = 'Remember the conversation across turns and page reloads using a RAGflow session. Only applies to the Helpdesk (site/system context). Note: enabling this stores the conversation server-side in RAGflow (see the privacy information and the retention setting).';
$string['helpdeskmemoryid'] = 'RAGflow memory';
$string['helpdeskmemoryid_help'] = 'The RAGflow memory to use (create it in RAGflow with a "semantic" memory type so facts about the user are extracted). One shared memory serves all users; Moodle separates them per user. Required for long-term memory.';
$string['includesources'] = 'Include sources';
$string['includesources_help'] = 'Ask RAGflow to return the source documents (reference) and append them as a list at the end of the answer. Moodle\'s AI response is plain text, so sources are shown inline rather than as a separate citations panel. Each source links to its Moodle activity when known, otherwise to a secure download of the file streamed through Moodle (the RAGflow API key never reaches the browser). Because the generated text can be saved into Moodle content, these download links carry no expiring token: instead every click is authorised live – the user must be logged in AND have access to the context the content lives in, and the document must belong to this action\'s assistant knowledge base.';
$string['invalidjson'] = 'Invalid JSON.';
$string['logtomoodle'] = 'Write log data';
$string['logtomoodle_desc'] = 'When enabled, write a concise usage/error entry to the <strong>Moodle log</strong> (Site administration → Reports → Logs) for each request — metrics only, no message content. A short technical detail is added when site-wide developer debugging is on. This is independent of the optional RAGflow Dashboard and much slimmer than it.';
$string['memorypreamble'] = 'Context about me from earlier conversations – use it to answer naturally when relevant. Do not mention this note, memory, earlier conversations or the knowledge base, and do not state where the information came from:';
$string['metadatafilter'] = 'Metadata filtering';
$string['metadatafilter:external'] = 'External sharing';
$string['metadatafilter:none'] = 'No';
$string['metadatafilter:thismoodle'] = 'Moodle Connector';
$string['metadatafilter_help'] = 'When connecting to an existing knowledge base, restrict answers by document metadata:

* **No** — no filter; the whole knowledge base is searched.
* **Moodle Connector** — restrict to the current course (documents tagged with the course id + site URL by RAGflow\'s built-in Moodle connector).
* **External sharing** — only documents explicitly shared (metadata \'external_sharing = 1\'), imported from another Moodle by the connector.

The two connector options require RAGflow\'s built-in Moodle connector to have written that metadata; without it the tutor always answers "no relevant content found". Fixed once the block is created.';
$string['pluginname'] = 'RAGflow API provider';
$string['privacy:metadata:aiprovider_ragflow_session'] = 'RAGflow conversation sessions kept for the Helpdesk memory (so the conversation continues across turns and page reloads).';
$string['privacy:metadata:aiprovider_ragflow_session:chatid'] = 'The RAGflow chat assistant the session belongs to.';
$string['privacy:metadata:aiprovider_ragflow_session:sessionid'] = 'The RAGflow session identifier that references the stored conversation.';
$string['privacy:metadata:aiprovider_ragflow_session:timecreated'] = 'When the session was created.';
$string['privacy:metadata:aiprovider_ragflow_session:userid'] = 'The user the conversation session belongs to.';
$string['privacy:metadata:preference:privatemode'] = 'Whether the user has enabled private (incognito) mode for the Helpdesk chat, so nothing is stored to or recalled from long-term memory.';
$string['privacy:metadata:ragflow'] = 'Prompts (and, with memory enabled, the ongoing conversation and remembered facts) are sent to and stored in the configured RAGflow service.';
$string['privacy:metadata:ragflow:memory'] = 'With long-term memory enabled, conversation turns are stored in RAGflow\'s Memory (per user) so durable facts can be recalled in later conversations.';
$string['privacy:metadata:ragflow:prompt'] = 'The prompt/question sent to RAGflow.';
$string['ragflow:viewerrordetails'] = 'See the technical cause when a chat request fails';
$string['reference:notice_missing'] = 'The configured reference no longer exists in RAGflow. Requests using it will fail — select a different one. The saved value is kept until you change it.';
$string['reference:notice_unverified'] = 'RAGflow could not be reached to verify this reference. Your saved configuration is unchanged — this is a connection problem, not a configuration problem.';
$string['reference:option_missing'] = 'Unavailable — no longer in RAGflow ({$a})';
$string['reference:option_unverified'] = 'Current — could not be verified ({$a})';
$string['searchbutton'] = 'Search';
$string['searchexcerpt'] = 'Show excerpt';
$string['searching'] = 'Searching…';
$string['searchnoresults'] = 'No matching documents found.';
$string['searchplaceholder'] = 'Search the knowledge base…';
$string['searchscore'] = 'Match';
$string['serveviaproxy'] = 'Serve source files via RAGflow proxy';
$string['serveviaproxy_help'] = 'When enabled, each source link streams the underlying file from RAGflow through a secure, signed, time-limited Moodle proxy (download.php) instead of linking to a Moodle activity. The RAGflow API key never reaches the browser. Independent of the document source; only relevant when "Include sources" is on. Requires the cited documents to be real files stored in a RAGflow dataset, so the citation carries RAGflow\'s own dataset_id and document_id metadata fields — the proxy uses these to fetch the file. Sources missing them are shown without a link.';
$string['sourcesheading'] = 'Sources:';
$string['task:prunesessions'] = 'Prune stale RAGflow conversation sessions';
$string['tokenttl'] = 'Download link lifetime (seconds)';
$string['tokenttl_help'] = 'How long a signed source/file download link stays valid, in seconds (default 60; minimum 15). Download links are minted the moment the user clicks a source or file – not when the panel/answer is rendered – so a short lifetime is safe: it only has to cover the download itself. A shorter value limits the window if a link leaks. Keep it comfortably above the time a single download takes.';
