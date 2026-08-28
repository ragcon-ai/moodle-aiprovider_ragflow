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

namespace aiprovider_ragflow\form;

use core_ai\form\action_settings_form;

/**
 * Action settings form for RAGflow text actions.
 *
 * The admin only picks a RAGflow **chat assistant** (dropdown, fetched live). The model is NOT asked
 * for: RAGflow's OpenAI-compatible endpoint ignores the request's `model` and always uses the
 * assistant's own LLM – so the plugin derives the llm_id from the selected chat and stores it.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_generate_text_form extends action_settings_form {
    /**
     * Read the provider instance config (baseurl + apikey) via the providerid in customdata.
     *
     * @return array ['baseurl' => string, 'apikey' => string]
     */
    protected function get_provider_config(): array {
        global $DB;
        $providerid = (int) ($this->_customdata['providerid'] ?? 0);
        if ($providerid) {
            $record = $DB->get_record('ai_providers', ['id' => $providerid]);
            if ($record) {
                $config = json_decode($record->config, true) ?: [];
                return ['baseurl' => $config['baseurl'] ?? '', 'apikey' => $config['apikey'] ?? ''];
            }
        }
        return ['baseurl' => '', 'apikey' => ''];
    }

    #[\Override]
    protected function definition(): void {
        $mform = $this->_form;
        $actionname = $this->_customdata['actionname'];
        $action = $this->_customdata['action'];
        $actionconfig = $this->_customdata['actionconfig']['settings'] ?? [];

        // The former 'ragflow' document source meant "documents from this Moodle, served via the proxy".
        // Proxy downloads are no longer embedded in generated text, so map any legacy value to 'thismoodle'.
        $savedsource = $actionconfig['datasource'] ?? 'thismoodle';
        if ($savedsource === 'ragflow') {
            $savedsource = 'thismoodle';
        }
        $actionconfig['datasource'] = $savedsource;

        $mform->addElement('header', 'generalsettingsheader', get_string('general', 'core'));

        // Make it clear on the provider's action pages that answers are retrieval-augmented (RAG).
        $mform->addElement('html', \html_writer::div(
            get_string('actionhint', 'aiprovider_ragflow'),
            'alert alert-info',
        ));

        // Chat assistant: dropdown of the tenant's assistants (fetched live), else free text. Each
        // option is annotated with its knowledge-base status, so an assistant with no KB can be picked
        // deliberately to use RAGflow as a plain LLM proxy (no retrieval).
        $pc = $this->get_provider_config();
        $chats = \aiprovider_ragflow\helper::get_chats_detailed($pc['baseurl'], $pc['apikey']);
        $currentchat = $actionconfig['chatid'] ?? '';

        if (!empty($chats)) {
            $options = ['' => get_string('choosedots')];
            foreach ($chats as $id => $chat) {
                $options[$id] = ($chat->kb > 0)
                    ? get_string(
                        'chatkblabel',
                        'aiprovider_ragflow',
                        (object) ['name' => $chat->name, 'count' => $chat->kb]
                    )
                    : get_string('chatnokblabel', 'aiprovider_ragflow', $chat->name);
            }
            // Keep a stale/unknown saved id selectable, labelled by its checker state (missing vs
            // unverified) so it is never a bare hash and never mislabels an unreachable id as deleted.
            if ($currentchat !== '' && !isset($options[$currentchat])) {
                $status = \aiprovider_ragflow\local\health\checker::instance()->check_assistant($currentchat);
                $options[$currentchat] = \aiprovider_ragflow\local\health\checker::stale_option_label($status);
            }
            $mform->addElement('select', 'chatid', get_string("action:{$actionname}:chatid", 'aiprovider_ragflow'), $options);
            $mform->setDefault('chatid', $currentchat);
        } else {
            $mform->addElement(
                'text',
                'chatid',
                get_string("action:{$actionname}:chatid", 'aiprovider_ragflow'),
                'maxlength="255" size="40"',
            );
            $mform->setType('chatid', PARAM_ALPHANUMEXT);
            $mform->setDefault('chatid', $currentchat);
        }
        $mform->addRule('chatid', null, 'required', null, 'client');
        $mform->addHelpButton('chatid', "action:{$actionname}:chatid", 'aiprovider_ragflow');

        // System instruction.
        $mform->addElement(
            'textarea',
            'systeminstruction',
            get_string("action:{$actionname}:systeminstruction", 'aiprovider_ragflow'),
            'wrap="virtual" rows="5" cols="20"',
        );
        $mform->setType('systeminstruction', PARAM_TEXT);
        $mform->setDefault('systeminstruction', $actionconfig['systeminstruction'] ?? $action::get_system_instruction());
        $mform->addHelpButton('systeminstruction', "action:{$actionname}:systeminstruction", 'aiprovider_ragflow');

        // Where the documents come from – governs cross-Moodle exposure and how sources are linked.
        // The data source decides the metadata filter: the whole knowledge base (no filter), this Moodle
        // (with optional course scoping) or an external Moodle. How the source file is opened (activity link
        // vs. RAGflow proxy) is the separate checkbox below.
        $mform->addElement('select', 'datasource', get_string('datasource', 'aiprovider_ragflow'), [
            'wholekb' => get_string('datasource:wholekb', 'aiprovider_ragflow'),
            'thismoodle' => get_string('datasource:thismoodle', 'aiprovider_ragflow'),
            'external' => get_string('datasource:external', 'aiprovider_ragflow'),
        ]);
        $mform->setType('datasource', PARAM_ALPHA);
        $mform->setDefault('datasource', $savedsource);
        $mform->addHelpButton('datasource', 'datasource', 'aiprovider_ragflow');

        // Scope the RAGflow knowledge base to course(s) via a metadata condition.
        $mform->addElement('select', 'coursescope', get_string('coursescope', 'aiprovider_ragflow'), [
            '' => get_string('coursescope:off', 'aiprovider_ragflow'),
            'current' => get_string('coursescope:current', 'aiprovider_ragflow'),
            'usercourses' => get_string('coursescope:usercourses', 'aiprovider_ragflow'),
        ]);
        $mform->setType('coursescope', PARAM_ALPHA);
        $mform->setDefault('coursescope', $actionconfig['coursescope'] ?? '');
        $mform->addHelpButton('coursescope', 'coursescope', 'aiprovider_ragflow');
        // Course scope only makes sense for documents from THIS Moodle: course ids are not unique across
        // Moodles (hidden for 'External Moodle'), and 'Whole knowledge base' applies no filter at all.
        $mform->hideIf('coursescope', 'datasource', 'eq', 'external');
        $mform->hideIf('coursescope', 'datasource', 'eq', 'wholekb');

        $mform->addElement('text', 'coursemetadatafield', get_string('coursemetadatafield', 'aiprovider_ragflow'), 'size="30"');
        $mform->setType('coursemetadatafield', PARAM_ALPHANUMEXT);
        $mform->setDefault('coursemetadatafield', $actionconfig['coursemetadatafield'] ?? 'course_id');
        $mform->addHelpButton('coursemetadatafield', 'coursemetadatafield', 'aiprovider_ragflow');
        // Shown only for documents from this Moodle; the metadata field name is irrelevant for an external
        // Moodle or when the whole knowledge base is searched unfiltered.
        $mform->hideIf('coursemetadatafield', 'datasource', 'eq', 'external');
        $mform->hideIf('coursemetadatafield', 'datasource', 'eq', 'wholekb');

        // Return the source documents (appended to the generated answer). Generated text can be inserted
        // and saved into Moodle content, so the source list only ever links to the Moodle activity from the
        // document metadata (never a signed proxy download token, which would linger in stored content) –
        // there is deliberately no "serve via proxy" option here. Live proxy downloads exist only in the
        // chat / search widgets, minted on click.
        $mform->addElement('advcheckbox', 'includesources', get_string('includesources', 'aiprovider_ragflow'));
        $mform->setType('includesources', PARAM_INT);
        $mform->setDefault('includesources', $actionconfig['includesources'] ?? 0);
        $mform->addHelpButton('includesources', 'includesources', 'aiprovider_ragflow');

        // Optional extra JSON params merged into the request body.
        $mform->addElement(
            'textarea',
            'modelextraparams',
            get_string('extraparams', 'aiprovider_ragflow'),
            'wrap="virtual" rows="3" cols="20"',
        );
        $mform->setType('modelextraparams', PARAM_TEXT);
        $mform->addHelpButton('modelextraparams', 'extraparams', 'aiprovider_ragflow');

        // Hidden plumbing.
        $mform->addElement('hidden', 'action', $action);
        $mform->setType('action', PARAM_TEXT);
        $mform->addElement('hidden', 'provider', $this->_customdata['providername'] ?? 'aiprovider_ragflow');
        $mform->setType('provider', PARAM_TEXT);
        $mform->addElement('hidden', 'providerid', $this->_customdata['providerid'] ?? 0);
        $mform->setType('providerid', PARAM_INT);

        $this->set_data($actionconfig);
    }

    #[\Override]
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!empty($data['modelextraparams'])) {
            json_decode($data['modelextraparams']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors['modelextraparams'] = get_string('invalidjson', 'aiprovider_ragflow');
            }
        }
        return $errors;
    }

    #[\Override]
    public function get_data(): ?\stdClass {
        $data = parent::get_data();
        if ($data && !empty($data->chatid)) {
            // Derive and store the model (llm_id) from the selected chat assistant.
            $pc = $this->get_provider_config();
            $data->model = \aiprovider_ragflow\helper::get_chat_llmid($pc['baseurl'], $pc['apikey'], $data->chatid);
        }
        return $data;
    }
}
