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

namespace aiprovider_ragflow;

use core_ai\hook\after_ai_provider_form_hook;

/**
 * Hook listener for the RAGflow provider instance form.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_listener {
    /**
     * Add the RAGflow API key field to the provider instance setup form.
     *
     * @param after_ai_provider_form_hook $hook The hook.
     */
    public static function set_form_definition_for_aiprovider_ragflow(after_ai_provider_form_hook $hook): void {
        if ($hook->plugin !== 'aiprovider_ragflow') {
            return;
        }

        $mform = $hook->mform;

        // RAGflow API key (used as the Bearer token for the OpenAI-compatible endpoint).
        $mform->addElement(
            'passwordunmask',
            'apikey',
            get_string('apikey', 'aiprovider_ragflow'),
            ['size' => 75],
        );
        $mform->addHelpButton('apikey', 'apikey', 'aiprovider_ragflow');
        $mform->addRule('apikey', get_string('required'), 'required', null, 'client');

        // RAGflow base URL of the instance (the chat/completions URL is built from base + chat id).
        $mform->addElement(
            'text',
            'baseurl',
            get_string('baseurl', 'aiprovider_ragflow'),
            ['size' => 50],
        );
        $mform->setType('baseurl', PARAM_URL);
        $mform->addHelpButton('baseurl', 'baseurl', 'aiprovider_ragflow');
        $mform->addRule('baseurl', get_string('required'), 'required', null, 'client');

        // Lifetime (seconds) of a signed source/file download link. Short = safer if a link leaks, but a
        // link must outlive the moment it is rendered so the user can still click it (see help).
        $mform->addElement(
            'text',
            'tokenttl',
            get_string('tokenttl', 'aiprovider_ragflow'),
            ['size' => 10],
        );
        $mform->setType('tokenttl', PARAM_INT);
        $mform->setDefault('tokenttl', \aiprovider_ragflow\helper::DOWNLOAD_TOKEN_DEFAULT_TTL);
        $mform->addHelpButton('tokenttl', 'tokenttl', 'aiprovider_ragflow');
    }
}
